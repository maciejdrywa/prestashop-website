<?php
declare(strict_types=1);

/**
 * Import drzewa kategorii wybranego zakresu źródła.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/import-category.php [ID|--all] [--dry-run]
 *
 * Wybór domyślny to źródłowe ID 5. categoryPlan buduje rodziców, --all uwzględnia również categories.json.
 *
 * Tryb próbny sprawdza źródło. Zapis wykorzystuje importCategories w transakcji i klasy Category.
 */

require_once __DIR__ . '/lib/prestashop.php';
try {
    echo "[1/3] Validating source and planning category paths...\n";
    [$products, $dryRun, $all] = ShopInit\selectProducts($argv);
    $plan = ShopInit\categoryPlan($products, $all);
    echo 'Categories in plan: ' . count($plan) . PHP_EOL;
    if ($dryRun) {
        foreach ($plan as $entry) {
            echo implode(' > ', $entry['path']) . PHP_EOL;
        }
        echo "OK: dry run; no database changes.\n";
        exit(0);
    }
    echo "[2/3] Loading PrestaShop...\n";
    $context = ShopInit\boot();
    echo "[3/3] Finding or creating categories, parents first...\n";
    ShopInit\transaction(fn () => ShopInit\importCategories($plan, $context));
    echo "OK: category tree prepared; product assignments are handled by the future product importer.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
