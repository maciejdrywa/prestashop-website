<?php
declare(strict_types=1);

/**
 * Przelew tradycyjny, pobranie i ich uprawnienia.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-payments.php [--dry-run]
 *
 * Wymaga modułów płatności, kraju PL, waluty PLN i referencji przewoźników. Korzysta z lib/payments.php.
 *
 * Zapisuje dostępność modułów i instrukcje testowe. Przy gotowych CMS odświeża stronę Formy płatności.
 */

require_once __DIR__.'/lib/payments.php';
try {
    $options=array_slice($argv,1);
    if($options!==[] && $options!==['--dry-run']) throw new RuntimeException('Usage: php configure-payments.php [--dry-run]');
    $context=ShopInit\boot();
    ShopInit\configurePayments($context,$options===['--dry-run']);
    if($options===[]) ShopInit\refreshPaymentInformationPage($context);
} catch(Throwable $error) {
    fwrite(STDERR,'ERROR: payment configuration failed: '.$error->getMessage().' Correct the cause and rerun.'.PHP_EOL);
    exit(1);
}
