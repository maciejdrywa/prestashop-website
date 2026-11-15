<?php
declare(strict_types=1);
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
