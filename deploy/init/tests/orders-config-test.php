<?php
declare(strict_types=1);

/**
 * Test zapisanej konfiguracji statusów, faktur i szablonu potwierdzenia płatności.
 *
 * Wywołanie jako www-data w kontenerze z danymi wymaganymi przez scenariusz
 * php /opt/shop/init/tests/orders-config-test.php
 *
 * Wymaga skonfigurowanego sklepu. Odczytuje bazę i pliki, a --fresh dodatkowo wymaga braku importowanego
 * katalogu.
 * Kod zakończenia 0 oznacza spełnienie asercji, a 1 nieudany scenariusz.
 */

require_once __DIR__ . '/../lib/orders.php';
try {
    $options = array_slice($argv, 1);
    if ($options !== [] && $options !== ['--fresh']) throw new RuntimeException('Usage: php orders-config-test.php [--fresh]');
    $context = ShopInit\boot();
    $langId = (int) Configuration::get('PS_LANG_DEFAULT');
    // Porównujemy definicje statusów i ustawienia wystawcy z bieżącą bazą.
    // Ten scenariusz nie wykonuje przejścia statusu konkretnego zamówienia.
    foreach (ShopInit\orderStatusPlan() as $entry) {
        $state = new OrderState($entry['id'], $langId);
        foreach (['paid', 'invoice', 'pdf_invoice', 'logable'] as $flag) {
            if ((bool) $state->$flag !== $entry['paid']) throw new RuntimeException('Unexpected order-state flag: ' . $flag);
        }
        if ($state->name !== $entry['name'] || $state->template !== $entry['template']
            || (bool) $state->send_email !== $entry['email'] || $state->hidden) throw new RuntimeException('Order-state text/notification mismatch.');
    }
    foreach (ShopInit\demoInvoiceSettings($context) as $key => $expected) {
        if (is_array($expected)) {
            foreach ($expected as $id => $text) {
                if (Configuration::get($key, $id) !== $text) throw new RuntimeException('Invoice language setting mismatch: ' . $key);
            }
        } elseif ((string) Configuration::get($key) !== (string) $expected) throw new RuntimeException('Invoice setting mismatch: ' . $key);
    }
    $body = file_get_contents(_PS_MAIL_DIR_ . 'pl/payment.txt');
    if (!str_contains($body, 'została pomyślnie przetworzona')) throw new RuntimeException('Polish payment-accepted email missing.');
    if ($options === ['--fresh']) {
        // Instalator może utworzyć własne przykładowe zamówienia i faktury.
        // Świeża próba korzysta z odrębnych wolumenów przygotowanych przed instalacją.
        $imports = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . "product WHERE reference LIKE 'pandomu-%' OR reference LIKE 'invoice-qa-%'", false);
        if ($imports !== 0) throw new RuntimeException('Fresh test expected no imported catalog or invoice-test fixtures.');
    }
    echo 'OK: waiting/paid states, demo issuer, invoice settings and Polish payment email restored'
        . ($options ? ' on a fresh installation before catalog import (installer demo history preserved)' : '') . '. Read only.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
