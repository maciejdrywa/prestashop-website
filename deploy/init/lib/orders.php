<?php
declare(strict_types=1);

/**
 * Definicje statusów płatności i ustawienia demonstracyjnych faktur VAT.
 *
 * Wywoływana przez configure-orders.php po przygotowaniu natywnych modułów płatności i ich statusów.
 *
 * Aktualizuje OrderState i Configuration. Zdarzenia konkretnych zamówień, płatności i wystawienia faktury
 * obsługuje natywny proces zamówienia.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/content.php';

/**
 * Odczytuje identyfikatory statusów płatności i sprawdza ich dostępność oraz rozłączność.
 *
 * Statusy pochodzą z konfiguracji utworzonej przez natywne moduły płatności. Pobranie korzysta z wartości
 * globalnej. Każdy status musi istnieć, być nieusunięty i mieć inne ID.
 *
 * @return array<string, array> ID statusów i docelowe nazwy, flagi paid, email oraz szablony wiadomości.
 * @throws \RuntimeException Przy brakującym, usuniętym lub powielonym ID statusu płatności.
 */
function orderStatusPlan(): array
{
    $plan = [
        'PS_OS_BANKWIRE' => ['name' => 'Oczekiwanie na płatność przelewem', 'paid' => false, 'template' => 'bankwire', 'email' => true],
        'PS_OS_COD_VALIDATION' => ['name' => 'Oczekiwanie na płatność przy odbiorze', 'paid' => false, 'template' => '', 'email' => false],
        'PS_OS_PAYMENT' => ['name' => 'Płatność zaakceptowana', 'paid' => true, 'template' => 'payment', 'email' => true],
    ];
    $ids = [];
    foreach ($plan as $setting => &$entry) {
        // Natywny kontroler pobrania odczytuje globalną wartość konfiguracji statusu.
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

/**
 * Zwraca demonstracyjne dane wystawcy i ustawienia faktury dla języków sklepu.
 *
 * Zwraca demonstracyjny prefiks i dane wystawcy dla zapisu w Configuration. Parametr context pozostaje
 * częścią wspólnego interfejsu konfiguratorów, a bieżąca implementacja pobiera języki globalnie.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array<string, mixed> Ustawienia faktury i fikcyjnego wystawcy, z wartościami wielojęzycznymi.
 */
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

/**
 * Konfiguruje statusy i faktury bez przechodzenia statusów istniejących zamówień.
 *
 * Ustawia definicje statusów oczekiwania i potwierdzonej płatności oraz parametry wystawcy. Faktyczne
 * przejście statusu, zapis płatności i utworzenie faktury wykonuje później natywny OrderHistory dla
 * konkretnego zamówienia.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $dryRun Przy true wykonywane są kontrole i raportowanie planu przed zapisami danego konfiguratora.
 * @return void
 * @throws \RuntimeException Przy niepoprawnym planie statusów, błędzie zapisu lub rozbieżności flag faktury i płatności.
 */
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
            // Obie płatności offline rozpoczynają się od statusu oczekiwania na pieniądze.
            // Administrator potwierdza płatność zmianą statusu, która generuje fakturę
            // i pozwala natywnemu OrderHistory zapisać otrzymaną kwotę.
            // Zgodne flagi paid, invoice, pdf_invoice i logable wiążą potwierdzenie
            // płatności z natywnym scenariuszem rejestracji kwoty i udostępnienia faktury.
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
        foreach ($settings as $key => $value) saveShopSetting($key, $value, $context);
        // Zachowujemy PS_INVOICE_START_NUMBER i numery wystawionych faktur.
        // Konfiguracja pozostawia bieżące statusy zamówień i istniejące dokumenty.
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
