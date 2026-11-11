<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/products.php';
try {
    echo "[1/4] Reading and validating source products...\n";
    [$products, $dryRun, $all] = ShopInit\selectProducts($argv);
    $context = ShopInit\boot();
    $plans = ShopInit\productPlans($products, $context);
    $categoryPlan = ShopInit\categoryPlan($products, $all);
    echo '[2/4] Ready: ' . count($plans) . ' products, ' . count($categoryPlan) . " category nodes.\n";
    $missingWeight = count(array_filter($plans, fn (array $p): bool => $p['weight'] === null));
    $normalizedHtml = count(array_filter($plans, fn (array $p): bool => $p['html_normalized']));
    echo "SOURCE NOTES: {$missingWeight} missing weights; {$normalizedHtml} descriptions normalized by PrestaShop purifier.\n";
    if ($dryRun) {
        foreach ($plans as $plan) {
            echo ($plan['id'] === null ? 'CREATE' : 'UPDATE') . ': ' . $plan['reference']
                . ', net: ' . $plan['price']['net'] . ', gross: ' . $plan['price']['gross']
                . ', VAT: ' . $plan['price']['vat'] . '%, default: ' . implode(' > ', $plan['primary']) . PHP_EOL;
        }
        echo "OK: dry run; no database changes.\n";
        exit(0);
    }
    echo "[3/4] Preparing category tree...\n";
    $categories = ShopInit\transaction(fn () => ShopInit\importCategories($categoryPlan, $context));
    echo "[4/4] Saving product data and checking PrestaShop prices...\n";
    $ids = ShopInit\transaction(fn () => ShopInit\importProducts($plans, $categories, $context));
    echo 'OK: ' . count($ids) . " products prepared. New products are inactive; images, stock and combinations are separate stages.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
