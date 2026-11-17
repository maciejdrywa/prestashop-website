<?php
declare(strict_types=1);

/**
 * Import rzeczywistych kombinacji i modyfikatorów ceny netto.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/import-combinations.php [ID|--all] [--dry-run]
 *
 * Wymaga zapisanych produktów z poprawnymi cenami bazowymi i słownika atrybutów. Domyślnie wybiera źródłowe
 * ID 5.
 *
 * Tryb próbny sprawdza ceny źródłowe. Zapis aktualizuje Combination, powiązania atrybutów i wariant
 * domyślny, po czym sprawdza brutto.
 */

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
    // Ta faza wymaga już poprawnej ceny bazowej i zapisanych wartości atrybutów.
    // Źródłowy tryb próbny powyżej sprawdza ceny przed rozwiązaniem docelowych rekordów.
    $plans = ShopInit\combinationPlan($products, $context);
    echo "[3/3] Saving combinations and checking PrestaShop gross prices...\n";
    $ids = ShopInit\transaction(fn () => ShopInit\importCombinations($plans, $context));
    echo 'OK: ' . array_sum(array_map('count', $ids)) . " combinations imported; product activation unchanged.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
