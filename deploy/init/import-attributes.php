<?php
declare(strict_types=1);

/**
 * Przygotowanie wspólnego słownika grup i wartości atrybutów.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/import-attributes.php [ID|--all] [--dry-run]
 *
 * Wczytuje variants z products.json. Domyślnie wybiera ID 5, a tryb próbny działa na samym źródle.
 *
 * Po boot() zapisuje brakujące AttributeGroup i ProductAttribute w transakcji. Kombinacje powstają w
 * następnym importerze.
 */

require_once __DIR__ . '/lib/prestashop.php';
try {
    echo "[1/3] Validating source and collecting all attribute groups...\n";
    [$products, $dryRun] = ShopInit\selectProducts($argv);
    $groups = ShopInit\attributePlan($products);
    echo 'Groups: ' . count($groups) . ', values: ' . array_sum(array_map('count', $groups)) . PHP_EOL;
    if ($dryRun) {
        foreach ($groups as $name => $values) {
            echo $name . ': ' . implode(', ', $values) . PHP_EOL;
        }
        echo "OK: dry run; no database changes.\n";
        exit(0);
    }
    echo "[2/3] Loading PrestaShop...\n";
    $context = ShopInit\boot();
    echo "[3/3] Finding or creating groups and values...\n";
    ShopInit\transaction(fn () => ShopInit\importAttributes($groups, $context));
    echo "OK: attribute dictionary prepared; no product combinations created.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
