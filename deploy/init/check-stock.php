<?php
declare(strict_types=1);

/**
 * Porównanie magazynu i aktywacji z początkowym planem katalogu.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/check-stock.php [ID|--all]
 *
 * Domyślnie sprawdza wszystkie produkty po imporcie stanów i kombinacji.
 *
 * Kontroluje ilości 0–10, sumy i odsetek zer. Sprzedaż lub ręczna zmiana ilości może spowodować rozbieżność
 * z planem początkowym.
 */

require_once __DIR__ . '/lib/stock.php';
try {
    [$products, , $all] = ShopInit\selectProducts($argv, true);
    $context = ShopInit\boot();
    // To kontrola ilości początkowych. Weryfikacja po sprzedaży w trybie --resume
    // korzysta z verifyResumedStock i porównuje komplet wierszy oraz ich bieżącą sumę.
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
