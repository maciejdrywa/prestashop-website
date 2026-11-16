<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/orders.php';
try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-orders.php [--dry-run]');
    ShopInit\configureOrders(ShopInit\boot(), $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: order/invoice configuration failed: ' . $error->getMessage() . ' Correct the cause and rerun.' . PHP_EOL);
    exit(1);
}
