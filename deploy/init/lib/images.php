<?php
declare(strict_types=1);

/**
 * Planowanie galerii, trwałe mapowanie URL na Image i zapis artefaktów zdjęć.
 *
 * Wywoływana po imporcie produktów, z lokalnymi JPEG w scraper-results/images i rozszerzeniem GD.
 *
 * Tabela import_image_map w bazie oraz pliki img/p w wolumenie sklepu umożliwiają wznowienie generacji.
 * Odczyt źródeł pozostaje lokalny.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';

const SOURCE_IMAGE_DIR = '/opt/shop/scraper-results/images';
const IMAGE_MAP_TABLE = 'import_image_map';
const SOURCE_IMAGE_MAX_BYTES = 300000;
const SOURCE_IMAGE_MIN_EDGE = 800;
const SOURCE_IMAGE_MAX_EDGE = 1600;

/**
 * Wyznacza lokalną nazwę JPEG z adresu źródłowego zgodnie z konwencją scrapera.
 *
 * @param string $url Źródłowy adres zdjęcia HTTP lub HTTPS.
 * @return string Nazwa JPEG zgodna z lokalnym eksportem zdjęć scrapera.
 * @throws \RuntimeException Przy nieobsługiwanym URL lub niepoprawnej strukturze ścieżki obrazu.
 */
function sourceImageFilename(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Invalid source image URL.');
    }
    $path = rtrim($parts['path'] ?? '', '/');
    $segments = explode('/', $path);
    if (count($segments) < 3 || str_contains($path, '\\')) {
        throw new RuntimeException('Invalid source image path: ' . $path);
    }
    $filename = $segments[count($segments) - 2] . '_' . $segments[count($segments) - 1];
    return pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
}

/**
 * Weryfikuje format, rozmiar i dekodowanie JPEG, zwracając SHA-256 oraz wymiary.
 *
 * @param string $path Ścieżka pliku wejściowego.
 * @return array{hash: string, width: int, height: int} SHA-256 pliku i wymiary w pikselach.
 * @throws \RuntimeException Przy braku pliku, przekroczonych limitach albo błędnym formacie i dekodowaniu JPEG.
 */
function validateSourceImage(string $path): array
{
    clearstatcache(true, $path);
    if (!is_readable($path) || filesize($path) <= 0 || filesize($path) > SOURCE_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Missing image or file exceeds 300000 bytes: ' . $path);
    }
    $size = @getimagesize($path);
    if (!$size || $size[2] !== IMAGETYPE_JPEG || max($size[0], $size[1]) < SOURCE_IMAGE_MIN_EDGE
        || max($size[0], $size[1]) > SOURCE_IMAGE_MAX_EDGE) {
        throw new RuntimeException('Expected processed JPEG, long edge 800-1600 px: ' . $path);
    }
    $decoded = @imagecreatefromjpeg($path);
    if ($decoded === false) {
        throw new RuntimeException('Cannot decode JPEG: ' . $path);
    }
    imagedestroy($decoded);
    return ['hash' => hash_file('sha256', $path), 'width' => $size[0], 'height' => $size[1]];
}

/**
 * Sprawdza obecność tabeli trwałego mapowania zdjęć w bieżącej bazie.
 *
 * @return bool Informacja o istnieniu tabeli mapowań w bieżącej bazie.
 */
function imageMapExists(): bool
{
    return (int) \Db::getInstance()->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
        . \pSQL(_DB_PREFIX_ . IMAGE_MAP_TABLE) . "'", false) === 1;
}

/**
 * Tworzy tabelę mapowania, jeśli jeszcze nie istnieje. Zachowuje dotychczasowe rekordy.
 *
 * Rekord mapowania może istnieć z id_image równym null, zanim powstanie obraz. Po wygenerowaniu plików
 * content_hash, thumbnail_signature i artifacts opisują ukończoną generację. Ograniczenia unikalności wiążą
 * źródło i docelowy obraz ze sklepem.
 *
 * @return void
 * @throws \RuntimeException Przy nieudanym utworzeniu tabeli trwałych mapowań.
 */
function ensureImageMap(): void
{
    // Klucz źródła pozostaje stabilny mimo lokalnych ID galerii. Nullable id_image
    // umożliwia rezerwację mapowania przed utworzeniem rekordu i plików obrazu.
    $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . IMAGE_MAP_TABLE . ' (
        id_shop INT UNSIGNED NOT NULL,
        source_product_id VARCHAR(64) NOT NULL,
        source_url_hash CHAR(64) NOT NULL,
        source_url TEXT NOT NULL,
        id_image INT UNSIGNED NULL,
        content_hash CHAR(64) NULL,
        thumbnail_signature CHAR(64) NULL,
        artifacts MEDIUMTEXT NULL,
        PRIMARY KEY (id_shop, source_product_id, source_url_hash),
        UNIQUE KEY shop_image (id_shop, id_image)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!\Db::getInstance()->execute($sql)) {
        throw new RuntimeException('Cannot prepare image mapping table.');
    }
}

/**
 * Odczytuje powiązanie produktu źródłowego i URL ze zdjęciem, wykrywając kolizje.
 *
 * @param string $sourceId ID produktu w sklepie źródłowym.
 * @param string $url Źródłowy adres zdjęcia HTTP lub HTTPS.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array|null Wiersz mapowania z opcjonalnym id_image i metadanymi ukończonych plików.
 * @throws \RuntimeException Przy nieudanym lub wieloznacznym odczycie oraz konflikcie hasha URL.
 */
function imageMapping(string $sourceId, string $url, \Context $context): ?array
{
    $query = new \DbQuery();
    $query->select('*')->from(IMAGE_MAP_TABLE);
    $query->where('id_shop = ' . (int) $context->shop->id);
    $query->where("source_product_id = '" . \pSQL($sourceId) . "'");
    $query->where("source_url_hash = '" . hash('sha256', $url) . "'");
    $rows = \Db::getInstance()->executeS($query, true, false);
    if ($rows === false || count($rows) > 1) {
        throw new RuntimeException('Cannot resolve source image mapping.');
    }
    if ($rows !== [] && $rows[0]['source_url'] !== $url) {
        throw new RuntimeException('Source image URL hash conflict.');
    }
    return $rows[0] ?? null;
}

/**
 * Pobiera i sprawdza definicje miniatur produktów wymagane przez sklep.
 *
 * @return list<array> Nazwy i wymiary aktywnych definicji miniatur produktów.
 * @throws \RuntimeException Przy braku typów miniatur lub ich niepoprawnych nazwach i wymiarach.
 */
function productImageTypes(): array
{
    $types = \ImageType::getImagesTypes('products');
    if (!$types) {
        throw new RuntimeException('No product thumbnail types configured.');
    }
    foreach ($types as $type) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $type['name']) || (int) $type['width'] <= 0 || (int) $type['height'] <= 0) {
            throw new RuntimeException('Invalid product thumbnail type.');
        }
    }
    return $types;
}

/**
 * Wylicza odcisk ustawień miniatur do wykrywania potrzeby regeneracji plików.
 *
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @return string SHA-256 parametrów generowania miniatur.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function thumbnailSignature(array $types): string
{
    return hash('sha256', json_encode([$types, \Configuration::get('PS_JPEG_QUALITY'), _PS_VERSION_], JSON_THROW_ON_ERROR));
}

/**
 * Łączy źródłowe galerie z lokalnymi JPEG i mapowaniami sklepu, odrzucając niejednoznaczności.
 *
 * Wymaga lokalnych JPEG i jednoznacznego cover_image, z wyjątkiem galerii pustej lub pojedynczej.
 * Kontroluje kolizje nazw, katalog rzeczywisty i przynależność mapowań. Zastane obrazy bez mapowania
 * przerywają planowanie przed nadpisaniem galerii.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param string $directory Lokalny katalog przetworzonych JPEG odpowiadających URL-om źródła.
 * @param bool $allowMissingProducts Dopuszcza product_id równe null podczas kontroli źródeł przed pierwszym importem.
 * @return list<array> source_id, product_id, name, cover_url i lista images z url, path, position, source oraz mapping.
 * @throws \RuntimeException Przy błędnym źródle, niepoprawnym cover_image, kolizji plików lub konflikcie galerii docelowej.
 */
function imagePlans(array $products, \Context $context, string $directory = SOURCE_IMAGE_DIR, bool $allowMissingProducts = false): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('PHP GD is required for product images.');
    }
    $root = realpath($directory);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Source image directory does not exist: ' . $directory);
    }
    $mapped = imageMapExists();
    $plans = [];
    $validated = [];
    $filenames = [];
    foreach ($products as $source) {
        $sourceId = (string) $source['product_id'];
        if (!\Validate::isGenericName(mb_substr($source['name'], 0, 128))) {
            throw new RuntimeException("Invalid image legend: product {$sourceId}");
        }
        $id = productId('pandomu-' . $sourceId, $context);
        if ($id === null && !$allowMissingProducts) {
            throw new RuntimeException("Product {$sourceId} is missing; run import-products.php first.");
        }
        if (!is_array($source['images'] ?? null) || !array_is_list($source['images'])) {
            throw new RuntimeException("Invalid image list: product {$sourceId}");
        }
        // Dla wielu zdjęć źródło musi jawnie wskazać okładkę. Pozycja w tablicy images
        // określa kolejność galerii i nie jest samodzielnym kryterium wyboru okładki.
        $coverUrl = $source['cover_image'] ?? (count($source['images']) === 1 ? $source['images'][0] : null);
        if (($source['images'] !== [] && !in_array($coverUrl, $source['images'], true))
            || ($source['images'] === [] && $coverUrl !== null)) {
            throw new RuntimeException("Missing or invalid source cover_image: product {$sourceId}. Run scraper/refresh_image_covers.py first.");
        }
        $images = [];
        $knownIds = [];
        foreach ($source['images'] as $index => $url) {
            if (!is_string($url) || isset($images[$url])) {
                throw new RuntimeException("Invalid or duplicate image URL: product {$sourceId}");
            }
            $filename = sourceImageFilename($url);
            if (isset($filenames[$filename]) && $filenames[$filename] !== $url) {
                throw new RuntimeException('Different source URLs resolve to the same filename: ' . $filename);
            }
            $filenames[$filename] = $url;
            // realpath i porównanie rodzica ograniczają odczyt do wskazanego katalogu.
            // Walidacja treści jest współdzielona dla zdjęć powtarzających się w planie.
            $path = realpath($root . '/' . $filename);
            if ($path === false || dirname($path) !== $root) {
                throw new RuntimeException('Source image is missing or outside the source directory: ' . $filename);
            }
            $validated[$path] ??= validateSourceImage($path);
            $mapping = $mapped && $id !== null ? imageMapping($sourceId, $url, $context) : null;
            if ($mapping !== null && $mapping['id_image'] !== null) {
                $image = new \Image((int) $mapping['id_image'], null, (int) $context->shop->id);
                if (\Validate::isLoadedObject($image)) {
                    if ((int) $image->id_product !== $id || !$image->isAssociatedToShop((int) $context->shop->id)) {
                        throw new RuntimeException("Mapped image belongs to another product or shop: {$sourceId}");
                    }
                    $knownIds[] = (int) $image->id;
                }
            }
            $images[$url] = [
                'url' => $url, 'path' => $path, 'position' => $index + 1,
                'source' => $validated[$path], 'mapping' => $mapping,
            ];
        }
        $rows = [];
        if ($id !== null) {
            $query = new \DbQuery();
            $query->select('i.id_image')->from('image', 'i');
            $query->innerJoin('image_shop', 's', 's.id_image = i.id_image');
            $query->where('i.id_product = ' . $id)->where('s.id_shop = ' . (int) $context->shop->id);
            $rows = \Db::getInstance()->executeS($query, true, false);
        }
        if ($rows === false) {
            throw new RuntimeException("Cannot read gallery: {$sourceId}");
        }
        $existing = array_map(fn (array $r): int => (int) $r['id_image'], $rows);
        // Zastana galeria musi odpowiadać znanym mapowaniom źródła. Dodatkowy lub
        // przestarzały obraz oznacza konflikt wymagający jawnego rozstrzygnięcia.
        if (array_diff($existing, $knownIds) !== []) {
            throw new RuntimeException("Product {$sourceId} has unmapped or obsolete gallery images. Review them before importing; no images were deleted.");
        }
        $plans[] = ['source_id' => $sourceId, 'product_id' => $id, 'name' => $source['name'], 'cover_url' => $coverUrl, 'images' => array_values($images)];
    }
    return $plans;
}

/**
 * Zwraca ścieżki oryginału i wszystkich miniatur przypisanych do zdjęcia.
 *
 * @param \Image $image Zapisany obiekt Image, którego ID wyznacza lokalizację plików galerii.
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @return array<string, string> Ścieżka original i ścieżki indeksowane nazwami typów miniatur.
 */
function imageArtifacts(\Image $image, array $types): array
{
    $base = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath();
    if (\Configuration::get('PS_LEGACY_IMAGES')) {
        $base = _PS_PRODUCT_IMG_DIR_ . $image->id_product . '-' . $image->id;
    }
    $files = ['original' => $base . '.jpg'];
    foreach ($types as $type) {
        $files[$type['name']] = $base . '-' . $type['name'] . '.jpg';
    }
    return $files;
}

/**
 * Porównuje hashe plików, źródła i konfiguracji miniatur z zapisanym mapowaniem.
 *
 * Wymaga zgodnej zawartości źródła, ustawień miniatur i kompletnego zestawu plików. Sprawdza ich bajtowe
 * hashe, dzięki czemu sam zapis ID obrazu lub istnienie pliku nie oznacza ukończonego importu.
 *
 * @param array $mapping Wiersz trwałego mapowania zdjęcia wraz z hashami ukończonych plików.
 * @param array $files Mapa nazw artefaktów na ich ścieżki docelowe.
 * @param string $sourceHash Oczekiwany SHA-256 lokalnego JPEG źródłowego.
 * @param string $signature Oczekiwany odcisk parametrów generowania miniatur.
 * @return bool Zgodność metadanych generacji i hashy wszystkich oczekiwanych plików.
 */
function artifactsCurrent(array $mapping, array $files, string $sourceHash, string $signature): bool
{
    if ($mapping['content_hash'] !== $sourceHash || $mapping['thumbnail_signature'] !== $signature) {
        return false;
    }
    // Manifest zawiera dokładny zestaw nazw i SHA-256 artefaktów. Metadane
    // generacji są uznawane za aktualne dopiero po porównaniu całego zestawu plików.
    $hashes = json_decode($mapping['artifacts'] ?? '', true);
    if (!is_array($hashes) || array_keys($hashes) !== array_keys($files)) {
        return false;
    }
    foreach ($files as $name => $file) {
        if (!is_readable($file) || hash_file('sha256', $file) !== $hashes[$name]) {
            return false;
        }
    }
    return true;
}

/**
 * Zapisuje poprawny obraz do pliku tymczasowego i atomowo zastępuje plik docelowy.
 *
 * Callback zapisuje do pliku tymczasowego w katalogu docelowym. Po sprawdzeniu obrazu i uprawnień rename
 * podmienia pojedynczy plik. Atomowość dotyczy pojedynczego artefaktu, a komplet galerii kontroluje trwałe
 * mapowanie.
 *
 * @param string $destination Ścieżka docelowego pliku obrazu w katalogu galerii.
 * @param callable $write Callback przyjmujący ścieżkę tymczasową i zwracający powodzenie zapisu obrazu.
 * @return void
 * @throws \RuntimeException Przy błędzie zapisu i dekodowania obrazu albo nieudanej podmianie docelowego pliku.
 */
function atomicImageFile(string $destination, callable $write): void
{
    $temporary = tempnam(dirname($destination), '.import-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary image file.');
    }
    try {
        if (!$write($temporary) || !is_file($temporary) || filesize($temporary) === 0 || @getimagesize($temporary) === false) {
            throw new RuntimeException('Cannot write image file: ' . basename($destination));
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $destination)) {
            throw new RuntimeException('Cannot replace image file: ' . basename($destination));
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * Buduje warunek mapowania z identyfikatorów planu i bezpiecznie cytowanego URL.
 *
 * @param array $plan Plan galerii ze źródłowym source_id produktu.
 * @param array $entry Pozycja zdjęcia z planu importu, zawierająca m.in. url i position.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return string Warunek SQL identyfikujący mapowanie w bieżącym sklepie.
 */
function imageMapWhere(array $plan, array $entry, \Context $context): string
{
    return 'id_shop = ' . (int) $context->shop->id
        . " AND source_product_id = '" . \pSQL($plan['source_id']) . "'"
        . " AND source_url_hash = '" . hash('sha256', $entry['url']) . "'";
}

/**
 * Zapisuje galerie i okładki oraz generuje brakujące pliki. Zwraca liczniki operacji.
 *
 * Rezerwuje mapowanie przed tworzeniem plików i wykorzystuje istniejące ID po przerwaniu. Pozycje oraz
 * podpisy zapisuje także dla plików cached. Regenerację kończy zapisem hashy, następnie ustawia
 * cover_image. Pusta galeria pozostaje jawnym wyjątkiem źródła.
 *
 * @param array $plans Plany imagePlans ze zweryfikowanymi źródłami i pozycjami galerii.
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array{created: int, regenerated: int, cached: int, without_images: array}
 * @throws \RuntimeException Przy błędzie mapowania, zapisu metadanych, generowania plików lub ustawienia okładki.
 */
function importProductImages(array $plans, array $types, \Context $context): array
{
    $db = \Db::getInstance();
    $signature = thumbnailSignature($types);
    $stats = ['created' => 0, 'regenerated' => 0, 'cached' => 0, 'without_images' => []];
    foreach ($plans as $productIndex => $plan) {
        if ($plan['images'] === []) {
            $stats['without_images'][] = $plan['source_id'];
            echo 'WARNING: source product ' . $plan['source_id'] . " has no source images; no placeholder assigned.\n";
            continue;
        }
        $productIds = [];
        foreach ($plan['images'] as $entry) {
            // Odczytujemy mapowanie ponownie, ponieważ wcześniejsza próba mogła zarezerwować ID.
            $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            if ($mapping === null) {
                if (!$db->insert(IMAGE_MAP_TABLE, [
                    'id_shop' => (int) $context->shop->id, 'source_product_id' => \pSQL($plan['source_id']),
                    'source_url_hash' => hash('sha256', $entry['url']), 'source_url' => \pSQL($entry['url']),
                ])) {
                    throw new RuntimeException('Cannot reserve source image mapping.');
                }
                $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            }
            $image = new \Image((int) ($mapping['id_image'] ?? 0), null, (int) $context->shop->id);
            $created = !\Validate::isLoadedObject($image);
            if ($created) {
                // Rekord obrazu łączymy z trwałym mapowaniem przed generowaniem plików,
                // co umożliwia wznowienie przerwanej operacji.
                $image = transaction(function () use ($db, $plan, $entry, $context): \Image {
                    $image = new \Image();
                    $image->id_product = $plan['product_id'];
                    $image->position = $entry['position'];
                    $image->cover = false;
                    if (!$image->add() || !$db->update(IMAGE_MAP_TABLE, ['id_image' => (int) $image->id], imageMapWhere($plan, $entry, $context))) {
                        throw new RuntimeException('Cannot create gallery image and mapping.');
                    }
                    return $image;
                });
            }
            $image->position = $entry['position'];
            foreach (\Language::getLanguages(false) as $language) {
                $image->legend[(int) $language['id_lang']] = mb_substr($plan['name'], 0, 128);
            }
            if (!$image->update()) {
                throw new RuntimeException('Cannot update image position or legend.');
            }
            $image->getPathForCreation();
            $files = imageArtifacts($image, $types);
            $current = !$created && artifactsCurrent($mapping, $files, $entry['source']['hash'], $signature);
            // Brak lub zmiana dowolnego artefaktu regeneruje zestaw danego obrazu.
            // Podmiany są atomowe per plik, a manifest jest zatwierdzany po całej generacji.
            if (!$current) {
                atomicImageFile($files['original'], fn (string $target): bool => copy($entry['path'], $target));
                if (hash_file('sha256', $files['original']) !== $entry['source']['hash']) {
                    throw new RuntimeException('Source photo changed during import; rerun after scraping finishes.');
                }
                foreach ($types as $type) {
                    atomicImageFile($files[$type['name']], fn (string $target): bool => \ImageManager::resize(
                        $entry['path'], $target, (int) $type['width'], (int) $type['height'], 'jpg', true
                    ));
                }
                $hashes = array_map(fn (string $path): string => hash_file('sha256', $path), $files);
                if (!$db->update(IMAGE_MAP_TABLE, [
                    'content_hash' => $entry['source']['hash'], 'thumbnail_signature' => $signature,
                    'artifacts' => \pSQL(json_encode($hashes, JSON_THROW_ON_ERROR)),
                ], imageMapWhere($plan, $entry, $context))) {
                    throw new RuntimeException('Cannot finish image mapping.');
                }
            }
            $status = $created ? 'CREATED' : ($current ? 'CACHED' : 'REGENERATED');
            $stats[$created ? 'created' : ($current ? 'cached' : 'regenerated')]++;
            $productIds[$entry['url']] = (int) $image->id;
            echo '[' . ($productIndex + 1) . '/' . count($plans) . '] ' . $status . ': product '
                . $plan['source_id'] . ', image ' . $entry['position'] . '/' . count($plan['images'])
                . ', ID ' . $image->id . PHP_EOL;
        }
        // Okładkę ustawiamy po zakończeniu całej galerii produktu, korzystając z ID
        // powiązanego z cover_url. Dzięki temu wybór odpowiada źródłu przy wielu zdjęciach.
        if (!\Image::deleteCover($plan['product_id'])) {
            throw new RuntimeException('Cannot reset product cover.');
        }
        $cover = new \Image($productIds[$plan['cover_url']], null, (int) $context->shop->id);
        $cover->cover = true;
        if (!$cover->update()) {
            throw new RuntimeException('Cannot set product cover.');
        }
    }
    return $stats;
}

/**
 * Sprawdza mapowania, metadane, okładki i wszystkie pliki galerii zgodnie ze źródłem.
 *
 * Weryfikuje zgodność kolejności, okładki i podpisów ze źródłem, bajtowe hashe oraz format i wymiary
 * miniatur. Zwraca również listę produktów bez źródłowych zdjęć.
 *
 * @param array $plans Plany źródłowe galerii wykorzystywane do ścisłej kontroli wyniku.
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array{images: int, thumbnails: int, without_images: array}
 * @throws \RuntimeException Przy rozbieżności mapowania, kolejności, okładki, podpisu lub plików galerii.
 */
function verifyProductImages(array $plans, array $types, \Context $context): array
{
    $signature = thumbnailSignature($types);
    $count = 0;
    $missing = [];
    foreach ($plans as $plan) {
        if ($plan['images'] === []) {
            $missing[] = $plan['source_id'];
            continue;
        }
        foreach ($plan['images'] as $entry) {
            $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            if ($mapping === null || !$mapping['id_image']) {
                throw new RuntimeException('Missing imported image: product ' . $plan['source_id'] . ', position ' . $entry['position']);
            }
            $image = new \Image((int) $mapping['id_image'], null, (int) $context->shop->id);
            if (!\Validate::isLoadedObject($image) || (int) $image->id_product !== $plan['product_id']
                || (int) $image->position !== $entry['position']
                || (bool) $image->cover !== ($entry['url'] === $plan['cover_url'])
                || !$image->isAssociatedToShop((int) $context->shop->id)) {
                throw new RuntimeException('Incorrect image metadata, order or cover: ' . $plan['source_id']);
            }
            foreach (\Language::getLanguages(false) as $language) {
                if (($image->legend[(int) $language['id_lang']] ?? null) !== mb_substr($plan['name'], 0, 128)) {
                    throw new RuntimeException('Incorrect image legend: ' . $plan['source_id']);
                }
            }
            $files = imageArtifacts($image, $types);
            if (!artifactsCurrent($mapping, $files, $entry['source']['hash'], $signature)
                || hash_file('sha256', $files['original']) !== $entry['source']['hash']) {
                throw new RuntimeException('Missing or changed original/thumbnail: image ' . $image->id);
            }
            foreach ($types as $type) {
                $size = @getimagesize($files[$type['name']]);
                if (!$size || $size[0] !== (int) $type['width'] || $size[1] !== (int) $type['height'] || $size[2] !== IMAGETYPE_JPEG) {
                    throw new RuntimeException('Invalid thumbnail dimensions/format: image ' . $image->id . ', ' . $type['name']);
                }
            }
            $count++;
        }
    }
    return ['images' => $count, 'thumbnails' => $count * count($types), 'without_images' => $missing];
}
