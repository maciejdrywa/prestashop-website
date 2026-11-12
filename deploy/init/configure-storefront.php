<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/storefront.php';

try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) {
        throw new RuntimeException('Usage: php configure-storefront.php [--dry-run]');
    }
    ShopInit\cleanDemoStorefront(ShopInit\boot(), $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: storefront cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
