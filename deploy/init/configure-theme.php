<?php

declare(strict_types=1);

/**
 * Synchronizuje motyw PAN DOMU z aktualnym obrazem i aktywuje go w sklepie.
 * Uruchamiaj jako www-data po instalacji PrestaShop.
 */
require_once __DIR__ . '/lib/prestashop.php';
require_once __DIR__ . '/lib/theme.php';

try {
    if (count($argv) !== 1) {
        throw new RuntimeException('Usage: php configure-theme.php');
    }
    ShopInit\deployProjectTheme(ShopInit\boot());
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: theme deployment failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
