<?php
declare(strict_types=1);

/**
 * Statusy oczekiwania i płatności oraz demonstracyjne faktury VAT.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-orders.php [--dry-run]
 *
 * Wywoływany po configure-payments.php, ponieważ korzysta z utworzonych przez moduły statusów.
 *
 * Zapisuje definicje OrderState i dane wystawcy przez lib/orders.php. Wystawienie dokumentu następuje przy
 * późniejszym przejściu statusu zamówienia.
 */

require_once __DIR__ . '/lib/orders.php';
try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-orders.php [--dry-run]');
    ShopInit\configureOrders(ShopInit\boot(), $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: order/invoice configuration failed: ' . $error->getMessage() . ' Correct the cause and rerun.' . PHP_EOL);
    exit(1);
}
