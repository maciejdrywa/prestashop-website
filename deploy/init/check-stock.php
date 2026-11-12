<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/stock.php';
try {
    [$products, , $all] = ShopInit\selectProducts($argv, true);
    $context = ShopInit\boot();
    $plans = ShopInit\stockPlans($products, ShopInit\catalogStockPlan(ShopInit\readCatalog()), $context);
    $zeros = 0;
    foreach ($plans as $plan) {
        ShopInit\verifyProductStock($plan, $context);
        $zeros += $plan['total'] === 0 ? 1 : 0;
    }
    $percent = round(100 * $zeros / max(1, count($plans)), 2);
    if ($all && ($percent < 5 || $percent > 20)) {
        throw new RuntimeException('Unavailable product percentage is outside 5-20%.');
    }
    echo 'OK: ' . count($plans) . " active products; totals 0-10, {$zeros} unavailable ({$percent}%). Combinations and backorder policy verified. No database changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
