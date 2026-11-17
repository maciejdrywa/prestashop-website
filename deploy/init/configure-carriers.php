<?php
declare(strict_types=1);

/**
 * Przewoźnicy projektu, taryfy, próg darmowej dostawy i limit masy.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-carriers.php [--dry-run]
 *
 * Wymaga PL/PLN, kg, VAT 23% i metod instalatora. Poprzedza etap konfiguracji płatności.
 *
 * Deleguje do lib/carriers.php zapis InPost, DPD i metod bazowych oraz synchronizację uprawnień płatności.
 */

require_once __DIR__ . '/lib/carriers.php';

try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-carriers.php [--dry-run]');
    ShopInit\configureProjectCarriers(ShopInit\boot(), $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: carrier configuration failed: ' . $error->getMessage() . ' Correct the cause and rerun.' . PHP_EOL);
    exit(1);
}
