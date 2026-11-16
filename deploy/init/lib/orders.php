<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/content.php';

/** Native payment controllers read these settings; never hard-code installer IDs. */
function orderStatusPlan(): array
{
    $plan = [
        'PS_OS_BANKWIRE' => ['name' => 'Oczekiwanie na płatność przelewem', 'paid' => false, 'template' => 'bankwire', 'email' => true],
        'PS_OS_COD_VALIDATION' => ['name' => 'Oczekiwanie na płatność przy odbiorze', 'paid' => false, 'template' => '', 'email' => false],
        'PS_OS_PAYMENT' => ['name' => 'Płatność zaakceptowana', 'paid' => true, 'template' => 'payment', 'email' => true],
    ];
    $ids = [];
    foreach ($plan as $setting => &$entry) {
        // COD reads the global setting in its native controller, rather than a shop override.
        $id = (int) ($setting === 'PS_OS_COD_VALIDATION' ? \Configuration::getGlobalValue($setting) : \Configuration::get($setting));
        $state = new \OrderState($id);
        if (!$id || !\Validate::isLoadedObject($state) || $state->deleted || in_array($id, $ids, true)) {
            throw new RuntimeException('Missing, deleted or conflicting order state: ' . $setting . '. Run configure-payments.php first.');
        }
        $entry['id'] = $id;
        $ids[] = $id;
    }
    unset($entry);
    return $plan;
}

function demoInvoiceSettings(\Context $context): array
{
    $prefix = $notice = [];
    foreach (\Language::getLanguages(false) as $language) {
        $prefix[(int) $language['id_lang']] = '#FV/DEMO/';
        $notice[(int) $language['id_lang']] = 'Dokument demonstracyjny projektu edukacyjnego. Dane wystawcy są fikcyjne. Nie służy do rozliczeń.';
    }
    return [
        'PS_INVOICE' => 1,
        'PS_INVOICE_MODEL' => 'invoice',
        'PS_INVOICE_PREFIX' => $prefix,
        'PS_INVOICE_LEGAL_FREE_TEXT' => $notice,
        'PS_SHOP_ADDR1' => 'Adres demonstracyjny 1',
        'PS_SHOP_ADDR2' => 'Sklep demonstracyjny',
        'PS_SHOP_CODE' => '00-000',
        'PS_SHOP_CITY' => 'Miasto testowe',
        'PS_SHOP_COUNTRY_ID' => (int) \Country::getByIso('PL'),
        'PS_SHOP_STATE_ID' => 0,
        'PS_SHOP_DETAILS' => 'Projekt edukacyjny - fikcyjne dane wystawcy, brak rzeczywistej sprzedaży.',
    ];
}

function configureOrders(\Context $context, bool $dryRun = false): void
{
    $plan = orderStatusPlan();
    $settings = demoInvoiceSettings($context);
    if (!$settings['PS_SHOP_COUNTRY_ID']) throw new RuntimeException('Poland is required for invoice issuer configuration.');
    if ($dryRun) {
        foreach ($plan as $entry) echo 'PLAN: ' . $entry['name'] . ', ID ' . $entry['id'] . ', paid/invoice=' . (int) $entry['paid'] . PHP_EOL;
        echo "PLAN: native invoice PDF, demonstrational issuer and prefix; preserve invoice counters and existing order histories.\nOK: dry run; no changes.\n";
        return;
    }
    echo "[1/3] Configuring waiting and payment-accepted statuses...\n";
    transaction(function () use ($context, $plan, $settings): void {
        foreach ($plan as $entry) {
            $state = new \OrderState($entry['id']);
            // An offline selection is not proof of payment. Both methods start unpaid.
            // Only the explicit administrator transition to the paid state generates
            // the invoice and lets native OrderHistory record the received payment.
            $state->paid = $entry['paid'];
            $state->invoice = $entry['paid'];
            $state->pdf_invoice = $entry['paid'];
            $state->logable = $entry['paid'];
            $state->send_email = $entry['email'];
            $state->hidden = false;
            $state->shipped = $state->delivery = $state->pdf_delivery = false;
            foreach (\Language::getLanguages(false) as $language) {
                $state->name[(int) $language['id_lang']] = $entry['name'];
                $state->template[(int) $language['id_lang']] = $entry['template'];
            }
            if (!$state->update()) throw new RuntimeException('Cannot save order state: ' . $entry['name']);
            echo 'SAVED STATE: ' . $entry['name'] . ', ID ' . $state->id . PHP_EOL;
        }
        echo "[2/3] Enabling native invoices and demonstrational issuer...\n";
        foreach ($settings as $key => $value) contentSetting($key, $value, $context);
        // Never reset PS_INVOICE_START_NUMBER or renumber existing invoices on reruns.
        // Configuration changes do not transition existing orders or create invoices.
    });
    \Cache::clean('OrderState::getOrderStates_*');
    \Tools::clearSmartyCache();
    echo "[3/3] Verifying invoice/status configuration...\n";
    foreach ($plan as $entry) {
        $state = new \OrderState($entry['id']);
        foreach (['paid', 'invoice', 'pdf_invoice', 'logable'] as $flag) {
            if ((bool) $state->$flag !== $entry['paid']) throw new RuntimeException('Order status flag mismatch: ' . $flag);
        }
    }
    if (!(bool) \Configuration::get('PS_INVOICE')) throw new RuntimeException('Invoice generation is disabled.');
    echo "OK: bank transfer/COD start unpaid without invoice; administrator confirms payment to generate a downloadable demo invoice.\n";
}
