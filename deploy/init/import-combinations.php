<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/prestashop.php';
try {
    echo "[1/3] Validating source combinations and prices...\n";
    [$products, $dryRun] = ShopInit\selectProducts($argv);
    if ($dryRun) {
        foreach ($products as $product) {
            $plan = ShopInit\pricePlan($product);
            echo 'Product ' . $product['product_id'] . ': ' . count($plan['variants']) . " combinations\n";
        }
        echo "OK: source-only dry run; no database changes. Run without --dry-run to check target products and attributes.\n";
        exit(0);
    }
    echo "[2/3] Checking target products, base prices and attributes...\n";
    $context = ShopInit\boot();
    $plans = ShopInit\combinationPlan($products, $context);
    echo "[3/3] Saving combinations and checking PrestaShop gross prices...\n";
    $ids = ShopInit\transaction(fn () => ShopInit\importCombinations($plans, $context));
    echo 'OK: ' . array_sum(array_map('count', $ids)) . " combinations imported; product activation unchanged.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
