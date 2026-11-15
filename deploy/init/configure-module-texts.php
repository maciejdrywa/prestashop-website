<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/module-texts.php';
try {
    $options = array_slice($argv,1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-module-texts.php [--dry-run]');
    ShopInit\configureModuleTexts(ShopInit\boot(),$options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR,'ERROR: module localization failed: '.$error->getMessage().' Fix the cause and rerun the command.'.PHP_EOL);
    exit(1);
}
