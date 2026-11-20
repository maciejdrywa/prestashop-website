<?php
declare(strict_types=1);

/**
 * Walidacja wejścia i wspólna lista etapów pełnej inicjalizacji.
 *
 * Dołączana przez init-shop.php i obsługę wznowienia. Preflight wymaga działającego kontekstu sklepu, obu
 * JSON-ów i lokalnych zdjęć.
 *
 * Plany łączą kategorie, produkty, CMS, obrazy i magazyn. Etapy CLI są uruchamiane w podprocesach z
 * kontrolą kodu zakończenia.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/images.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/content.php';

/**
 * Sprawdza czytelność wymaganych JSON-ów i katalogu zdjęć, zwracając ich ścieżki.
 *
 * @param string $directory Katalog zawierający products.json, categories.json i images/.
 * @return array{products: string, categories: string, images: string}
 * @throws \RuntimeException Przy braku czytelnych JSON-ów lub katalogu images.
 */
function initializationInputs(string $directory): array
{
    $paths = [
        'products' => $directory . '/products.json',
        'categories' => $directory . '/categories.json',
        'images' => $directory . '/images',
    ];
    foreach (['products', 'categories'] as $name) {
        if (!is_file($paths[$name]) || !is_readable($paths[$name])) {
            throw new RuntimeException('Required readable source file is missing: ' . $paths[$name]);
        }
    }
    if (!is_dir($paths['images']) || !is_readable($paths['images'])) {
        throw new RuntimeException('Required source image directory is missing: ' . $paths['images']);
    }
    return $paths;
}

/**
 * Liczy główne gałęzie z dwiema niepustymi podkategoriami i wymaga przynajmniej czterech.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @return int Liczba głównych gałęzi spełniających minimum podkategorii.
 * @throws \RuntimeException Gdy mniej niż cztery główne gałęzie mają po dwie niepuste bezpośrednie podkategorie.
 */
function initializationCategoryCoverage(array $products): int
{
    $branches = [];
    foreach ($products as $product) {
        foreach ($product['category_paths'] as $path) {
            if (count($path) >= 2) {
                $branches[$path[0]][$path[1]] = true;
            }
        }
    }
    $count = count(array_filter($branches, fn (array $children): bool => count($children) >= 2));
    if ($count < 4) {
        throw new RuntimeException('Source requires at least four root categories with two non-empty direct subcategories each.');
    }
    return $count;
}

/**
 * Weryfikuje dane, VAT, kategorie i zdjęcia przed importem. Zwraca liczności oraz odciski źródeł.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array Liczności katalogu, without_images oraz mapy source_hashes i image_hashes indeksowane ścieżką pliku.
 * @throws \RuntimeException Przy niespójności wejścia, braku zależności środowiska lub błędzie planowania danych.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function initializationPreflight(\Context $context): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required to run initialization stages.');
    }
    $paths = initializationInputs(dirname(SOURCE_FILE));
    readMainMenuPlan($paths['categories']);
    informationalTemplates();
    $hashes = [$paths['products'] => hash_file('sha256', $paths['products']),
        $paths['categories'] => hash_file('sha256', $paths['categories'])];
    $products = readCatalog($paths['products']);
    $categories = json_decode((string) file_get_contents($paths['categories']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($categories) || !array_is_list($categories) || $categories === []) {
        throw new RuntimeException('categories.json must contain a non-empty category list.');
    }
    $categoryPlan = categoryPlan($products, true);
    // Każda ścieżka przypisana do produktu musi występować w pełnym eksporcie
    // kategorii. Chroni to wspólne założenia importu katalogu i menu.
    $sourcePaths = [];
    foreach ($categories as $category) {
        $path = $category['category_path'] ?? null;
        if (!is_array($path) || $path === []) {
            throw new RuntimeException('Invalid category_path in categories.json.');
        }
        $sourcePaths[json_encode($path, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] = true;
    }
    foreach ($products as $product) {
        foreach ($product['category_paths'] as $path) {
            if (!isset($sourcePaths[json_encode($path, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])) {
                throw new RuntimeException('Product category path is absent from categories.json: ' . implode(' > ', $path));
            }
        }
    }
    $rootCategories = initializationCategoryCoverage($products);
    foreach ($categoryPlan as $category) {
        if (!\Validate::isCatalogName($category['name']) || mb_strlen($category['name']) > 128
            || !\Validate::isLinkRewrite(\Tools::link_rewrite($category['name']))) {
            throw new RuntimeException('Invalid category name: ' . $category['name']);
        }
    }
    // Planowanie sprawdza docelowe ograniczenia pól, masę, walutę i VAT przed
    // rozpoczęciem etapów zapisu. Lokalny plan zdjęć dopuszcza jeszcze brak produktów.
    productPlans($products, $context);
    if (!\Configuration::get('PS_STOCK_MANAGEMENT')) {
        throw new RuntimeException('Stock management must be enabled before initialization.');
    }
    $stock = catalogStockPlan($products);
    productImageTypes();
    // Na świeżej instalacji produkty dopiero powstaną. Walidacja obejmuje
    // lokalne zdjęcia, a kolejne przebiegi sprawdzają również galerie docelowe.
    $imagePlans = imagePlans($products, $context, $paths['images'], true);
    $missing = [];
    $images = 0;
    $imageHashes = [];
    foreach ($imagePlans as $plan) {
        $images += count($plan['images']);
        foreach ($plan['images'] as $entry) {
            $imageHashes[$entry['path']] = $entry['source']['hash'];
        }
        if ($plan['images'] === []) {
            $missing[] = $plan['source_id'];
        }
    }
    unchangedInitializationSources($hashes);
    return [
        'products' => count($products), 'categories' => count($categoryPlan),
        'qualifying_root_categories' => $rootCategories, 'images' => $images,
        'unavailable_products' => count(array_filter($stock, fn (int $quantity): bool => $quantity === 0)),
        'without_images' => $missing,
        'source_hashes' => $hashes,
        'image_hashes' => $imageHashes,
    ];
}

/**
 * Zwraca uporządkowaną listę etapów, ich skrypty i argumenty CLI.
 *
 * @return list<array{name: string, script: string, args: list<string>}>
 */
function initializationStages(): array
{
    // Kolejność jest zależnością wykonania. Płatności poprzedzają statusy,
    // CMS poprzedza cookies i treści modułów, a kontrola katalogu i galerii poprzedza aktywację.
    // Motyw wdrażamy po wyłączeniu modułów demonstracyjnych i przed generowaniem miniatur.
    return [
        ['name' => 'Nazwa sklepu, polskie dostawy i usunięcie demonstracji', 'script' => 'configure-storefront.php', 'args' => []],
        ['name' => 'Motyw potomny PAN DOMU', 'script' => 'configure-theme.php', 'args' => []],
        ['name' => 'Polskie wiadomości i lokalny SMTP', 'script' => 'configure-mail.php', 'args' => []],
        ['name' => 'Przewoźnicy projektu i taryfy dostaw', 'script' => 'configure-carriers.php', 'args' => []],
        ['name' => 'Przelew tradycyjny i pobranie', 'script' => 'configure-payments.php', 'args' => []],
        ['name' => 'Statusy zamówień i demonstracyjne faktury VAT', 'script' => 'configure-orders.php', 'args' => []],
        ['name' => 'Kategorie i produkty', 'script' => 'import-products.php', 'args' => ['--all']],
        ['name' => 'Menu kategorii źródłowych', 'script' => 'configure-menu.php', 'args' => []],
        ['name' => 'Polskie strony informacyjne i stopka', 'script' => 'configure-content.php', 'args' => []],
        ['name' => 'Informacja o cookies', 'script' => 'configure-cookies.php', 'args' => []],
        ['name' => 'Polskie treści modułów', 'script' => 'configure-module-texts.php', 'args' => []],
        ['name' => 'Webservice API i uprawnienia klucza', 'script' => 'configure-webservice.php', 'args' => []],
        ['name' => 'Słownik atrybutów', 'script' => 'import-attributes.php', 'args' => ['--all']],
        ['name' => 'Kombinacje produktów i ceny', 'script' => 'import-combinations.php', 'args' => ['--all']],
        ['name' => 'Galerie, okładki i miniatury', 'script' => 'import-images.php', 'args' => ['--all']],
        ['name' => 'Kontrola produktów i kombinacji', 'script' => 'check-products.php', 'args' => ['--all', '--with-combinations']],
        ['name' => 'Kontrola galerii', 'script' => 'check-images.php', 'args' => ['--all']],
        ['name' => 'Magazyn i aktywacja', 'script' => 'import-stock.php', 'args' => ['--all']],
        ['name' => 'Końcowa kontrola magazynu i aktywacji', 'script' => 'check-stock.php', 'args' => ['--all']],
        ['name' => 'Promocje cenowe produktów', 'script' => 'configure-promotions.php', 'args' => []],
        ['name' => 'Dyrektywa Omnibus (historia cen i najniższa cena)', 'script' => 'configure-omnibus.php', 'args' => []],
        ['name' => 'Treści motywu i filtry katalogu', 'script' => 'configure-theme-content.php', 'args' => []],
    ];
}

/**
 * Uruchamia etap bez pośrednictwa powłoki i zwraca jego kod zakończenia.
 *
 * @param array $stage Definicja etapu z nazwą, skryptem i argumentami procesu PHP.
 * @return int Kod zakończenia podprocesu PHP.
 * @throws \RuntimeException Przy brakującym skrypcie lub nieudanym uruchomieniu procesu PHP.
 */
function runInitializationScript(array $stage): int
{
    $script = dirname(__DIR__) . '/' . $stage['script'];
    if (!is_readable($script)) {
        throw new RuntimeException('Initialization stage script is missing: ' . $script);
    }
    // Tablica argumentów omija powłokę. Podprocesy przekazują postęp i błędy na bieżąco.
    $process = proc_open([PHP_BINARY, $script, ...$stage['args']], [
        0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR,
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start initialization stage: ' . $stage['script']);
    }
    fclose($pipes[0]);
    return proc_close($process);
}

/**
 * Wykonuje etapy po kolei z kontrolą źródeł i zatrzymuje przebieg przy pierwszym błędzie.
 *
 * @param array $stages Uporządkowane definicje etapów do wykonania.
 * @param callable $runner Callback wykonujący etap i zwracający jego kod zakończenia.
 * @param callable|null $guard Opcjonalna kontrola wywoływana przed i po etapie, zgłaszająca naruszenie warunków.
 * @return void
 * @throws \RuntimeException Gdy runner zwróci kod różny od zera. Wyjątki runnera i guarda propagują się do wywołującego.
 */
function runInitializationStages(array $stages, callable $runner, ?callable $guard = null): void
{
    foreach ($stages as $index => $stage) {
        if ($guard !== null) {
            $guard();
        }
        $started = microtime(true);
        echo PHP_EOL . '[INIT ' . ($index + 1) . '/' . count($stages) . '] ' . $stage['name'] . PHP_EOL;
        // Każdy etap jest granicą błędu. Udany wcześniejszy etap może mieć trwałe
        // zapisy, dlatego dalsze etapy ruszają wyłącznie po kodzie 0 i kontroli źródeł.
        $code = $runner($stage);
        if ($code !== 0) {
            throw new RuntimeException('Stage "' . $stage['name'] . '" (' . $stage['script'] . ') failed with exit code ' . $code . '. Later stages were not run.');
        }
        if ($guard !== null) {
            $guard();
        }
        echo '[INIT] Completed: ' . $stage['name'] . ' (' . number_format(microtime(true) - $started, 1) . ' s)' . PHP_EOL;
    }
}

/**
 * Porównuje aktualne skróty źródeł z planem i odrzuca zmianę w trakcie pracy.
 *
 * @param array $hashes Mapa ścieżek na oczekiwane SHA-256 plików źródłowych.
 * @return void
 * @throws \RuntimeException Gdy plik źródłowy zniknął, stał się nieczytelny lub ma inny SHA-256.
 */
function unchangedInitializationSources(array $hashes): void
{
    foreach ($hashes as $path => $expected) {
        if (!is_readable($path) || hash_file('sha256', $path) !== $expected) {
            throw new RuntimeException('Source JSON changed during initialization: ' . $path . '. Finish scraping and rerun initialization.');
        }
    }
}
