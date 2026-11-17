<?php

declare(strict_types=1);

/**
 * Kontrakt źródeł i dziesiętne plany cen, kategorii oraz atrybutów.
 *
 * Wspólna biblioteka importerów, diagnostyki i bootstrapu. Operuje na JSON i BCMath bez ładowania sklepu.
 *
 * Czyta products.json, opcjonalnie categories.json. Wynikami są plany w pamięci i wyjątki walidacji.
 */


namespace ShopInit;

use RuntimeException;

const SOURCE_FILE = '/opt/shop/scraper-results/products.json';

/**
 * Waliduje wartość dziesiętną i zwraca ją w postaci tekstowej dla obliczeń BCMath.
 *
 * Wartości bool, NaN, nieskończoność i niedozwolone wartości ujemne są odrzucane. Dla zapisu wykładniczego
 * normalizacja korzysta z float oraz sprintf z czternastoma miejscami, co określa dokładność tej ścieżki
 * wejścia.
 *
 * @param mixed $value Wartość przekazana do walidacji lub zapisu.
 * @param string $label Kontekst diagnostyczny dodawany do komunikatu błędu.
 * @param bool $negative Dopuszcza ujemną wartość, wymaganą m.in. dla obniżających cenę modyfikatorów.
 * @return string Zapis dziesiętny bez początkowego znaku plus, gotowy do obliczeń BCMath.
 * @throws \RuntimeException Przy nieliczbowej, nieskończonej lub niedozwolonej wartości ujemnej.
 */
function decimal($value, string $label, bool $negative = false): string
{
    if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
        throw new RuntimeException("Invalid number: {$label}");
    }
    $text = (string) $value;
    if (stripos($text, 'e') !== false) {
        $text = sprintf('%.14F', (float) $value);
    }
    if (!$negative && (float) $value < 0) {
        throw new RuntimeException("Negative value: {$label}");
    }
    return ltrim($text, '+');
}

/**
 * Zaokrągla liczbę dziesiętną do wskazanej skali bez pośrednictwa liczb binarnych.
 *
 * Dodaje połowę jednostki ostatniej zachowywanej pozycji ze znakiem liczby, następnie obcina wynik BCMath.
 * Daje zaokrąglenie połówek od zera i eliminuje zapis ujemnego zera.
 *
 * @param string $value Wartość przekazana do walidacji lub zapisu.
 * @param int $scale Liczba miejsc dziesiętnych wyniku.
 * @return string Liczba z dokładnie $scale miejscami dziesiętnymi, ze znormalizowanym zerem.
 * @throws \RuntimeException Przy niedostępnym rozszerzeniu BCMath.
 */
function rounded(string $value, int $scale): string
{
    if (!extension_loaded('bcmath')) {
        throw new RuntimeException('PHP bcmath is required for decimal price calculations.');
    }
    $half = '0.' . str_repeat('0', $scale) . '5';
    $result = bcadd($value, bccomp($value, '0', 14) < 0 ? '-' . $half : $half, $scale);
    return bccomp($result, '0', $scale) === 0 ? number_format(0, $scale, '.', '') : $result;
}

/**
 * Przelicza netto na brutto dla stawki VAT z zaokrągleniem do groszy.
 *
 * @param string $net Kwota netto w zapisie dziesiętnym PLN.
 * @param string $vat Stawka VAT w procentach, np. 23.
 * @return string Cena brutto w PLN z dwoma miejscami dziesiętnymi.
 */
function grossFromNet(string $net, string $vat): string
{
    return rounded(bcmul($net, bcadd('1', bcdiv($vat, '100', 12), 12), 12), 2);
}

/**
 * Oblicza sześciocyfrowe netto odpowiadające cenie brutto źródła.
 *
 * @param string $gross Kwota brutto w zapisie dziesiętnym PLN.
 * @param string $vat Stawka VAT w procentach, np. 23.
 * @return string Cena netto w PLN z sześcioma miejscami dziesiętnymi.
 */
function netFromGross(string $gross, string $vat): string
{
    return rounded(bcdiv($gross, bcadd('1', bcdiv($vat, '100', 12), 12), 12), 6);
}

/**
 * Wyznacza ceny bazowe i modyfikatory kombinacji z kontrolą zgodności brutto.
 *
 * Cena brutto źródła wyznacza bazowe netto przechowywane w PrestaShop. Każdy wariant musi mieć tę samą
 * listę grup i unikalny zestaw wartości. Wariant zawiera source_id, reference, attributes, source_modifier,
 * modifier i gross. Korekty dokładności są jawnie raportowane w adjustments.
 *
 * @param array $product Rekord źródłowy z product_id, VAT, netto/brutto i pełnymi cenami variants.
 * @return array{net: string, gross: string, vat: string, variants: list<array>, adjustments: list<array>}
 * @throws \RuntimeException Przy niespójnych cenach, grupach atrybutów lub kombinacjach.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function pricePlan(array $product): array
{
    $id = (string) ($product['product_id'] ?? '?');
    $vat = decimal($product['vat_rate'] ?? null, "product {$id} VAT");
    if ((float) $vat > 100) {
        throw new RuntimeException("Unsupported VAT for product {$id}");
    }
    $gross = rounded(decimal($product['price_brutto'] ?? null, "product {$id} gross"), 2);
    $sourceNet = decimal($product['price_netto'] ?? null, "product {$id} net");
    assertSourceGross($sourceNet, $gross, $vat, "product {$id}");
    $net = netFromGross($gross, $vat);
    $variants = $product['variants'] ?? null;
    if (!is_array($variants) || !array_is_list($variants)) {
        throw new RuntimeException("Invalid combination list for product {$id}");
    }
    $result = ['net' => $net, 'gross' => $gross, 'vat' => $vat, 'variants' => [], 'adjustments' => []];
    $seenIds = [];
    $seenAttributes = [];
    // W obrębie produktu każda rzeczywista kombinacja musi opisywać ten sam zbiór
    // grup. Sygnatura wartości wykrywa duplikaty niezależnie od kolejności kluczy.
    $groupSet = null;
    foreach ($variants as $variant) {
        $variantId = (string) ($variant['variant_id'] ?? '');
        if (!ctype_digit($variantId) || (int) $variantId <= 0 || isset($seenIds[$variantId])) {
            throw new RuntimeException("Invalid or duplicate combination ID for product {$id}");
        }
        $seenIds[$variantId] = true;
        $attributes = $variant['attributes'] ?? null;
        if (!is_array($attributes) || $attributes === [] || array_is_list($attributes)) {
            throw new RuntimeException("Invalid attributes for combination {$variantId}");
        }
        foreach ($attributes as $group => $value) {
            if (!is_string($group) || trim($group) === '' || !is_string($value) || trim($value) === '') {
                throw new RuntimeException("Empty attribute for combination {$variantId}");
            }
        }
        ksort($attributes, SORT_STRING);
        $groups = array_keys($attributes);
        if ($groupSet !== null && $groups !== $groupSet) {
            throw new RuntimeException("Inconsistent attribute groups for product {$id}");
        }
        $groupSet = $groups;
        $signature = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (isset($seenAttributes[$signature])) {
            throw new RuntimeException("Duplicate attribute combination for product {$id}");
        }
        $seenAttributes[$signature] = true;
        $raw = rounded(decimal($variant['price_modifier'] ?? null, "combination {$variantId} modifier", true), 6);
        $observedNet = decimal($variant['price_netto'] ?? null, "combination {$variantId} net");
        $target = rounded(decimal($variant['price_brutto'] ?? null, "combination {$variantId} gross"), 2);
        assertSourceGross($observedNet, $target, $vat, "combination {$variantId}");
        // Spójność modyfikatora sprawdzamy względem netto zapisanej ceny źródłowej.
        // Dopiero później uwzględniamy normalizację netto bazowego do skali PrestaShop.
        if (abs((float) bcsub(bcadd($sourceNet, $raw, 12), $observedNet, 12)) > 0.0000011) {
            throw new RuntimeException("Source net and price_modifier disagree for combination {$variantId}");
        }
        $modifier = $raw;
        if (grossFromNet(bcadd($net, $modifier, 6), $vat) !== $target) {
            // Zachowujemy źródłowy modyfikator. Korekta o milionową część złotego obsługuje
            // granicę połowy grosza po zapisie bazowej ceny netto do sześciu miejsc.
            $matched = false;
            for ($step = 1; $step <= 5 && !$matched; $step++) {
                foreach ([$step, -$step] as $offset) {
                    $candidate = bcadd($raw, bcdiv((string) $offset, '1000000', 6), 6);
                    if (grossFromNet(bcadd($net, $candidate, 6), $vat) === $target) {
                        $modifier = $candidate;
                        $matched = true;
                        break;
                    }
                }
            }
            // Po nieudanej korekcie kilku milionowych wyznaczamy wpływ od ceny docelowej
            // brutto. Końcowa kontrola nadal wymaga dokładnego odtworzenia groszy.
            if (!$matched) {
                $modifier = bcsub(netFromGross($target, $vat), $net, 6);
            }
        }
        if (bccomp(bcadd($net, $modifier, 6), '0', 6) < 0
            || grossFromNet(bcadd($net, $modifier, 6), $vat) !== $target) {
            throw new RuntimeException("Cannot reproduce gross price for combination {$variantId}");
        }
        if (bccomp($raw, $modifier, 6) !== 0) {
            $result['adjustments'][] = ['variant_id' => $variantId, 'source_modifier' => $raw, 'import_modifier' => $modifier];
        }
        $result['variants'][] = [
            'source_id' => $variantId, 'attributes' => $attributes,
            'source_modifier' => $raw, 'modifier' => $modifier, 'gross' => $target,
            'reference' => 'pandomu-' . $id . '-combination-' . $variantId,
        ];
    }
    return $result;
}

/**
 * Sprawdza zgodność źródłowego netto i brutto z tolerancją dokładności zapisu źródła.
 *
 * Tolerancja 0.005002 PLN obejmuje połowę grosza i niepewność sześciocyfrowego zapisu źródłowego. Jest to
 * kontrola spójności wejścia przed późniejszym dokładnym odtworzeniem brutto.
 *
 * @param string $net Kwota netto w zapisie dziesiętnym PLN.
 * @param string $gross Kwota brutto w zapisie dziesiętnym PLN.
 * @param string $vat Stawka VAT w procentach, np. 23.
 * @param string $label Kontekst diagnostyczny dodawany do komunikatu błędu.
 * @return void
 * @throws \RuntimeException Gdy zależność netto, VAT i brutto przekracza dopuszczalną tolerancję źródła.
 */
function assertSourceGross(string $net, string $gross, string $vat, string $label): void
{
    $difference = bcsub(bcmul($net, bcadd('1', bcdiv($vat, '100', 12), 12), 12), $gross, 12);
    // Uwzględniamy dokładność sześciu miejsc źródła na granicy połowy grosza.
    if (bccomp($difference, '0.005002', 12) > 0 || bccomp($difference, '-0.005002', 12) < 0) {
        throw new RuntimeException("Source net, VAT and gross disagree for {$label}");
    }
}

/**
 * Wczytuje i waliduje aktualny format produktów JSON w UTF-8.
 *
 * Wymaga niepustej listy, dodatnich i unikalnych ID, nazw oraz pełnych ścieżek kategorii. Wywołuje
 * pricePlan dla każdego produktu. Pełne wymagania pól opisów, masy i galerii sprawdzają odpowiednie plany
 * domenowe.
 *
 * @param string $path Ścieżka pliku wejściowego.
 * @return list<array> Rekordy źródłowe po walidacji identyfikatorów, kategorii i cen.
 * @throws \RuntimeException Przy błędzie odczytu lub niespójnym kontrakcie katalogu.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function readCatalog(string $path = SOURCE_FILE): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Cannot read source catalog: {$path}");
    }
    $products = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($products) || !array_is_list($products) || $products === []) {
        throw new RuntimeException('Expected a non-empty product list.');
    }
    $ids = [];
    foreach ($products as $product) {
        $id = (string) ($product['product_id'] ?? '');
        if (!ctype_digit($id) || (int) $id <= 0 || isset($ids[$id])) {
            throw new RuntimeException('Invalid or duplicate product ID.');
        }
        $ids[$id] = true;
        if (!is_string($product['name'] ?? null) || trim($product['name']) === '') {
            throw new RuntimeException("Missing product name: {$id}");
        }
        $paths = $product['category_paths'] ?? null;
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            throw new RuntimeException("Missing category_paths for product {$id}");
        }
        foreach ($paths as $categoryPath) {
            if (!is_array($categoryPath) || !array_is_list($categoryPath) || $categoryPath === []) {
                throw new RuntimeException("Invalid category path for product {$id}");
            }
            foreach ($categoryPath as $name) {
                if (!is_string($name) || trim($name) === '') {
                    throw new RuntimeException("Invalid category name for product {$id}");
                }
            }
        }
        pricePlan($product);
    }
    return $products;
}

/**
 * Obsługuje wybór ID, całego katalogu i trybu próbnego z argumentów CLI.
 *
 * @param array $argv Argumenty CLI łącznie z nazwą uruchomionego skryptu.
 * @param bool $defaultAll Wybiera cały katalog przy pominięciu selektora, w przeciwnym razie domyślnym ID jest 5.
 * @return array{0: list<array>, 1: bool, 2: bool} Wybrane produkty, tryb próbny i wybór całego katalogu.
 * @throws \RuntimeException Przy niepoprawnych argumentach CLI, błędnym katalogu albo braku wybranego produktu.
 */
function selectProducts(array $argv, bool $defaultAll = false): array
{
    // Selektor dotyczy dalszej operacji. Cały JSON jest walidowany przed filtrowaniem,
    // więc błędny rekord poza wybranym ID również zatrzyma polecenie.
    $selection = $defaultAll ? '--all' : '5';
    $specified = false;
    $dryRun = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif (!$specified && ($argument === '--all' || ctype_digit($argument))) {
            $selection = $argument;
            $specified = true;
        } else {
            throw new RuntimeException('Usage: script.php [source_product_id|--all] [--dry-run]');
        }
    }
    $products = readCatalog();
    if ($selection !== '--all') {
        $products = array_values(array_filter($products, fn (array $p): bool => (string) $p['product_id'] === $selection));
        if ($products === []) {
            throw new RuntimeException("Source product {$selection} does not exist.");
        }
    }
    return [$products, $dryRun, $selection === '--all'];
}

/**
 * Buduje plan ścieżek kategorii z kolejnością rodziców przed dziećmi.
 *
 * Kluczem węzła jest JSON całej ścieżki, dlatego identyczne nazwy pod różnymi rodzicami pozostają odrębnymi
 * kategoriami. parent wskazuje klucz rodzica, a sortowanie zapewnia rozwiązanie rodzica przed dzieckiem.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @param bool $includeEmpty Dołącza ścieżki z categories.json, także bez przypisanych produktów.
 * @return array<string, array{name: string, path: array, parent: string|null}> Węzły indeksowane pełną ścieżką JSON.
 * @throws \RuntimeException Przy niepoprawnej ścieżce lub błędzie odczytu pełnego drzewa kategorii.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function categoryPlan(array $products, bool $includeEmpty = false): array
{
    $paths = [];
    foreach ($products as $product) {
        array_push($paths, ...$product['category_paths']);
    }
    if ($includeEmpty) {
        $path = dirname(SOURCE_FILE) . '/categories.json';
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read category tree: {$path}");
        }
        $categories = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($categories) || !array_is_list($categories)) {
            throw new RuntimeException('Invalid category tree.');
        }
        foreach ($categories as $category) {
            $paths[] = $category['category_path'] ?? [];
        }
    }
    $result = [];
    foreach ($paths as $path) {
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            throw new RuntimeException('Invalid category path.');
        }
        $prefix = [];
        foreach ($path as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException('Invalid category name.');
            }
            $parent = $prefix === [] ? null : json_encode($prefix, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $prefix[] = $name;
            $key = json_encode($prefix, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            // Pełna ścieżka jest tożsamością węzła. Wspólny prefiks wielu produktów
            // scala się tutaj w pojedynczą kategorię z jednoznacznym rodzicem.
            $result[$key] = ['name' => $name, 'path' => $prefix, 'parent' => $parent];
        }
    }
    uasort($result, fn (array $a, array $b): int => count($a['path']) <=> count($b['path']) ?: strcmp(implode(' > ', $a['path']), implode(' > ', $b['path'])));
    return $result;
}

/**
 * Zbiera unikalne grupy i wartości atrybutów wszystkich źródłowych kombinacji.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @return array<string, array<string, string>> Grupy atrybutów i wartości pod kluczami value:NAZWA.
 */
function attributePlan(array $products): array
{
    $groups = [];
    foreach ($products as $product) {
        foreach ($product['variants'] as $variant) {
            foreach ($variant['attributes'] as $group => $value) {
                // Prefiks klucza chroni wartości wyglądające jak liczby przed konwersją
                // klucza tablicy PHP i pozwala zachować odrębność etykiet słownika.
                $groups[$group]['value:' . $value] = $value;
            }
        }
    }
    ksort($groups, SORT_STRING);
    return $groups;
}
