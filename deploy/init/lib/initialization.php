<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/images.php';
require_once __DIR__ . '/stock.php';

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

function initializationPreflight(\Context $context): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required to run initialization stages.');
    }
    $paths = initializationInputs(dirname(SOURCE_FILE));
    $hashes = [$paths['products'] => hash_file('sha256', $paths['products']),
        $paths['categories'] => hash_file('sha256', $paths['categories'])];
    $products = readCatalog($paths['products']);
    $categories = json_decode((string) file_get_contents($paths['categories']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($categories) || !array_is_list($categories) || $categories === []) {
        throw new RuntimeException('categories.json must contain a non-empty category list.');
    }
    $categoryPlan = categoryPlan($products, true);
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
    productPlans($products, $context);
    if (!\Configuration::get('PS_STOCK_MANAGEMENT')) {
        throw new RuntimeException('Stock management must be enabled before initialization.');
    }
    $stock = catalogStockPlan($products);
    productImageTypes();
    // On a fresh installation the source products do not exist yet. Validate
    // local photos now, while retaining strict target-gallery checks on reruns.
    $imagePlans = imagePlans($products, $context, $paths['images'], true);
    $missing = [];
    $images = 0;
    foreach ($imagePlans as $plan) {
        $images += count($plan['images']);
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
    ];
}

function initializationStages(): array
{
    return [
        ['name' => 'Installer demo storefront cleanup', 'script' => 'configure-storefront.php', 'args' => []],
        ['name' => 'Categories and products', 'script' => 'import-products.php', 'args' => ['--all']],
        ['name' => 'Attribute dictionary', 'script' => 'import-attributes.php', 'args' => ['--all']],
        ['name' => 'Product combinations and prices', 'script' => 'import-combinations.php', 'args' => ['--all']],
        ['name' => 'Galleries, covers and thumbnails', 'script' => 'import-images.php', 'args' => ['--all']],
        ['name' => 'Product and combination validation', 'script' => 'check-products.php', 'args' => ['--all', '--with-combinations']],
        ['name' => 'Gallery validation', 'script' => 'check-images.php', 'args' => ['--all']],
        ['name' => 'Stock and activation', 'script' => 'import-stock.php', 'args' => ['--all']],
        ['name' => 'Final stock and activation validation', 'script' => 'check-stock.php', 'args' => ['--all']],
    ];
}

function runInitializationScript(array $stage): int
{
    $script = dirname(__DIR__) . '/' . $stage['script'];
    if (!is_readable($script)) {
        throw new RuntimeException('Initialization stage script is missing: ' . $script);
    }
    // Array form bypasses the shell; children stream their progress and errors.
    $process = proc_open([PHP_BINARY, $script, ...$stage['args']], [
        0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR,
    ], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start initialization stage: ' . $stage['script']);
    }
    fclose($pipes[0]);
    return proc_close($process);
}

function runInitializationStages(array $stages, callable $runner, ?callable $guard = null): void
{
    foreach ($stages as $index => $stage) {
        if ($guard !== null) {
            $guard();
        }
        $started = microtime(true);
        echo PHP_EOL . '[INIT ' . ($index + 1) . '/' . count($stages) . '] ' . $stage['name'] . PHP_EOL;
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

function unchangedInitializationSources(array $hashes): void
{
    foreach ($hashes as $path => $expected) {
        if (!is_readable($path) || hash_file('sha256', $path) !== $expected) {
            throw new RuntimeException('Source JSON changed during initialization: ' . $path . '. Finish scraping and rerun initialization.');
        }
    }
}
