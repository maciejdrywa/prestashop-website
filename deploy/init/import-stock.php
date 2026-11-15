<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/stock.php';
try {
    echo "[1/3] Preparing deterministic full-catalog stock plan...\n";
    [$products, $dryRun, $all] = ShopInit\selectProducts($argv);
    $catalog = ShopInit\catalogStockPlan(ShopInit\readCatalog());
    $zeros = count(array_filter($catalog, fn (int $q): bool => $q === 0));
    echo 'CATALOG PLAN: ' . $zeros . '/' . count($catalog) . ' unavailable ('
        . round(100 * $zeros / count($catalog), 2) . "%); product totals 0-10.\n";
    echo "[2/3] Checking products and imported combinations...\n";
    $context = ShopInit\boot();
    $plans = ShopInit\stockPlans($products, $catalog, $context);
    if ($dryRun) {
        foreach ($plans as $plan) {
            echo 'PLAN: pandomu-' . $plan['source_id'] . ', total stock: ' . $plan['total'] . ', activate: YES' . PHP_EOL;
        }
        echo "OK: dry run; no stock or activation changes.\n";
        exit(0);
    }
    echo "[3/3] Setting stock, denying backorders and activating products...\n";
    $stats = ShopInit\transaction(fn () => ShopInit\importStock($plans, $context));
    if ($all && ($stats['unavailable_percent'] < 5 || $stats['unavailable_percent'] > 20)) {
        throw new RuntimeException('Unavailable product percentage is outside 5-20%.');
    }
    echo 'OK: ' . json_encode($stats, JSON_THROW_ON_ERROR) . PHP_EOL;
    if (!$all) {
        echo "NOTE: only selected products changed; catalog availability percentage remains a plan.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
