<?php
declare(strict_types=1);
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
