<?php
declare(strict_types=1);
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
