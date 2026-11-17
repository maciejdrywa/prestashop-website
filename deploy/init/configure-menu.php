<?php
declare(strict_types=1);

/**
 * Menu górne z głównymi kategoriami i pełnym zagnieżdżeniem.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-menu.php [--dry-run]
 *
 * Wymaga categories.json, kontekstu sklepu i ps_mainmenu. Następuje po imporcie produktów.
 *
 * Wykorzystuje lib/menu.php do rozpoznania drzewa, aktywacji węzłów, zapisania kolejności i odświeżenia
 * cache menu.
 */

require_once __DIR__ . '/lib/menu.php';

try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) {
        throw new RuntimeException('Usage: php configure-menu.php [--dry-run]');
    }
    $plan = ShopInit\readMainMenuPlan();
    ShopInit\configureMainMenu(ShopInit\boot(), $plan, $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: menu configuration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
