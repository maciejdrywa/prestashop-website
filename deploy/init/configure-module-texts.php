<?php
declare(strict_types=1);

/**
 * Polskie bloki informacyjne, kryterium opinii i etykiety listy życzeń.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-module-texts.php [--dry-run]
 *
 * Wymaga gotowych CMS, aktywnych modułów i HTTPS. Deleguje do lib/module-texts.php.
 *
 * Zapisuje treści i powiązania bloków oraz ustawienia modułów, następnie czyści cache Smarty.
 */

require_once __DIR__ . '/lib/module-texts.php';
try {
    $options = array_slice($argv,1);
    if ($options !== [] && $options !== ['--dry-run']) throw new RuntimeException('Usage: php configure-module-texts.php [--dry-run]');
    ShopInit\configureModuleTexts(ShopInit\boot(),$options === ['--dry-run']);
} catch (Throwable $error) {
    fwrite(STDERR,'ERROR: module localization failed: '.$error->getMessage().' Fix the cause and rerun the command.'.PHP_EOL);
    exit(1);
}
