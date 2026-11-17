<?php
declare(strict_types=1);

/**
 * Nazwa projektu i przygotowanie strony głównej do właściwego katalogu.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-storefront.php [--dry-run]
 *
 * Pierwszy etap inicjalizacji, oparty na lib/storefront.php i istniejącym sklepie.
 *
 * Zapisuje nazwę, polskie etykiety przewoźników, usuwa rozpoznane produkty demo i wyłącza trzy moduły
 * demonstracyjne.
 */

require_once __DIR__ . '/lib/storefront.php';

try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) {
        throw new RuntimeException('Usage: php configure-storefront.php [--dry-run]');
    }
    $context = ShopInit\boot();
    ShopInit\configureShopName($context, $options === ['--dry-run']);
    ShopInit\configureDeliveryLanguage($context, $options === ['--dry-run']);
    ShopInit\cleanDemoStorefront($context, $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: storefront configuration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
