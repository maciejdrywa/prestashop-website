<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/content.php';
try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-content.php [--dry-run]');
    $templates = ShopInit\informationalTemplates();
    ShopInit\configureInformationPages(ShopInit\boot(), $templates, $options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: information pages/footer configuration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
