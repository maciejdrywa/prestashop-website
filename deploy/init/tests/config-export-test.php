<?php
declare(strict_types=1);

/**
 * Test eksportu i odtwarzania konfiguracji.
 *
 * Uruchamiać tylko na odrębnej instancji: zmienia ustawienia, przewoźników, płatności, CMS, podatki, kraje
 * i moduł, dodaje język, a następnie odtwarza konfigurację z eksportu wykonanego na początku testu. Blok
 * finally odtwarza konfigurację z tego eksportu i usuwa dodany język. Usunięta w teście strona CMS wraca pod
 * nowym ID (odnośniki stopki i menu są przenoszone), a przewoźnik zachowuje referencję.
 */

require_once __DIR__ . '/../lib/initialization-state.php';

function expectConfig(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectRejected(callable $callback, string $fragment, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        expectConfig(str_contains($error->getMessage(), $fragment), $message . ' Komunikat: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException($message);
}

$context = ShopInit\boot();
Db::getInstance()->disableCache();
$baseline = ShopInit\exportShopConfiguration($context);
$file = tempnam(sys_get_temp_dir(), 'config-export-test-');
file_put_contents($file, ShopInit\encodeConfigExport($baseline));
$export = ShopInit\readConfigExport($file);
$db = Db::getInstance();

try {
    echo "[1/7] Eksport jest deterministyczny i nie zawiera sekretów ani danych osobowych...\n";
    expectConfig(ShopInit\encodeConfigExport(ShopInit\exportShopConfiguration($context)) === file_get_contents($file), 'Eksport nie jest deterministyczny.');
    expectConfig(ShopInit\configExportFindings($baseline, ShopInit\knownSecretValues()) === [], 'Eksport zawiera sekret lub dane osobowe.');
    $json = (string) file_get_contents($file);
    foreach ($db->executeS('SELECT email, lastname FROM ' . _DB_PREFIX_ . 'customer UNION SELECT email, lastname FROM ' . _DB_PREFIX_ . 'employee') ?: [] as $person) {
        expectConfig(!str_contains($json, (string) $person['email']), 'Eksport zawiera adres e-mail klienta lub pracownika.');
    }
    foreach ($db->executeS('SELECT address1, phone, phone_mobile FROM ' . _DB_PREFIX_ . 'address WHERE deleted=0') ?: [] as $address) {
        foreach ($address as $value) {
            expectConfig(strlen((string) $value) < 6 || !str_contains($json, (string) $value), 'Eksport zawiera dane adresowe klienta.');
        }
    }
    expectConfig(!str_contains($json, _COOKIE_KEY_) && !str_contains($json, (string) getenv('PRESTASHOP_API_KEY') ?: "\0"), 'Eksport zawiera klucz instalacji.');

    echo "[2/7] Walidacja odrzuca sekrety, dane osobowe, nieznane klucze i UTF-16...\n";
    $leak = $baseline;
    $leak['configuration']['PS_SHOP_DETAILS'] = 'Kontakt: jan.kowalski@example.com';
    expectRejected(fn () => ShopInit\assertConfigExportClean($leak), 'adres e-mail', 'Adres e-mail nie został wykryty.');
    $leak = $baseline;
    $leak['configuration']['BANK_WIRE_DETAILS'] = 'PL61 1090 1014 0000 0712 1981 2874';
    expectRejected(fn () => ShopInit\assertConfigExportClean($leak), 'IBAN', 'Numer rachunku nie został wykryty.');
    $leak = $baseline;
    $leak['configuration']['PS_SHOP_DETAILS'] = _COOKIE_KEY_;
    expectRejected(fn () => ShopInit\assertConfigExportClean($leak), 'sekretu', 'Klucz instalacji nie został wykryty.');
    $leak = $baseline;
    $leak['configuration']['PS_MAIL_PASSWD'] = 'x';
    expectConfig(ShopInit\configExportFindings($leak) !== [], 'Nazwa pola hasła nie została wykryta.');
    $invalid = tempnam(sys_get_temp_dir(), 'config-export-invalid-');
    file_put_contents($invalid, ShopInit\encodeConfigExport($leak));
    expectRejected(fn () => ShopInit\readConfigExport($invalid), 'allowlisty', 'Klucz spoza allowlisty nie został odrzucony.');
    file_put_contents($invalid, "\xFF\xFE" . mb_convert_encoding(ShopInit\encodeConfigExport($baseline), 'UTF-16LE', 'UTF-8'));
    expectRejected(fn () => ShopInit\readConfigExport($invalid), 'UTF-8', 'Plik UTF-16 nie został odrzucony.');
    unlink($invalid);
    $standardReference = ShopInit\exportReferenceMaps($context)['carrier_reference']['STANDARD'];
    ShopInit\saveShopSetting('SHOP_INIT_CARRIER_DUPLIKAT_REF', $standardReference, $context);
    expectRejected(fn () => ShopInit\exportShopConfiguration($context), 'kilka kluczy', 'Kolizja kluczy przewoźnika nie została wykryta.');
    Configuration::deleteByName('SHOP_INIT_CARRIER_DUPLIKAT_REF');

    echo "[3/7] Plik sprzeczny z etapami inicjalizacji jest wycofywany...\n";
    $guard = fn () => ShopInit\assertInitializationConfigurationReady($context);
    $conflicting = $baseline;
    $conflicting['modules']['ps_imageslider'] = ['enabled' => true];
    expectRejected(fn () => ShopInit\applyConfigExport($conflicting, 'test', $context, false, $guard), 'configure-storefront.php',
        'Plik włączający moduł demonstracyjny nie został odrzucony.');
    Module::resetStaticCache();
    Cache::clean('*');
    expectConfig(!Module::isEnabled('ps_imageslider'), 'Odrzucony plik pozostawił włączony moduł demonstracyjny.');

    echo "[4/7] Zmiana ustawień, przewoźnika, płatności, CMS, podatku, kraju i modułu w sklepie...\n";
    $maps = ShopInit\exportReferenceMaps($context);
    $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
    Configuration::deleteByName(ShopInit\CONFIG_EXPORT_APPLIED_SETTING);
    // Język dodany po eksporcie nie może blokować odtwarzania pliku z jednym językiem.
    $extra = new Language();
    $extra->name = 'Test';
    $extra->iso_code = 'zz';
    $extra->language_code = 'zz-zz';
    $extra->locale = 'zz-ZZ';
    $extra->active = true;
    expectConfig($extra->add(), 'Nie można dodać języka testowego.');
    ShopInit\saveShopSetting('PS_SHIPPING_FREE_PRICE', '1', $context);
    ShopInit\saveShopSetting('PS_INVOICE_PREFIX', [$languageId => '#ZMIANA'], $context);
    ShopInit\saveShopSetting('PS_CONDITIONS_CMS_ID', 0, $context);
    $standard = new Carrier(ShopInit\carrierIdByReference($maps['carrier_reference']['STANDARD'], $context));
    $standard->active = false;
    $standard->update();
    $db->update('delivery', ['price' => 99], 'id_carrier=' . (int) $standard->id);
    $standard->deleteZone((int) $maps['zone_id']['Europe']);
    $express = new Carrier(ShopInit\carrierIdByReference($maps['carrier_reference']['EXPRESS'], $context));
    $express->deleted = true;
    $express->update();
    $wire = (int) Module::getModuleIdByName('ps_wirepayment');
    $db->delete('module_country', 'id_module=' . $wire);
    $db->delete('module_carrier', 'id_module=' . $wire);
    $terms = new CMS($maps['cms_id']['regulamin']);
    $terms->content[$languageId] = '<p>Zmieniona treść</p>';
    $terms->update();
    $payments = new CMS($maps['cms_id']['formy-platnosci']);
    $payments->delete();
    $taxGroup = array_key_first($baseline['taxes']);
    $db->delete('tax_rule', 'id_tax_rules_group=' . (int) $maps['tax_group_id'][$taxGroup] . ' AND id_country=' . (int) $maps['country_id']['PL']);
    $db->update('country', ['active' => 1], 'iso_code="DE"');
    $module = Module::getInstanceByName('ps_featuredproducts');
    $module->disable();
    Configuration::resetStaticCache();
    Cache::clean('*');

    echo "[5/7] Plan i odtworzenie konfiguracji z pliku...\n";
    $plan = ShopInit\applyConfigExport($export['data'], $export['sha256'], $context, true);
    foreach (['configuration/PS_SHIPPING_FREE_PRICE', 'configuration/PS_INVOICE_PREFIX', 'configuration/PS_CONDITIONS_CMS_ID',
        'carriers/STANDARD', 'carriers/EXPRESS', 'payments/ps_wirepayment', 'cms/regulamin', 'cms/formy-platnosci', 'taxes/' . $taxGroup,
        'countries', 'modules/ps_featuredproducts'] as $expected) {
        expectConfig(in_array($expected, $plan, true), 'Plan nie zawiera ' . $expected . ': ' . implode(', ', $plan));
    }
    expectConfig(!ShopInit\configExportApplied($context, $file), 'Odcisk pliku zapisany przed odtworzeniem.');
    ShopInit\applyConfigExport($export['data'], $export['sha256'], $context, false, $guard);
    Configuration::resetStaticCache();
    $after = ShopInit\exportShopConfiguration($context);
    expectConfig(ShopInit\configExportDifferences($after, $export['data']) === [], 'Odtworzona konfiguracja różni się od pliku.');
    expectConfig(ShopInit\configExportApplied($context, $file), 'Brak odcisku zastosowanego pliku.');
    expectConfig(in_array('languages/zz (tylko w sklepie)', ShopInit\configExportDifferences($after, $export['data'], true), true),
        'Kontrola --check nie zgłasza języka dodanego w sklepie.');

    echo "[6/7] Kontrola skutków w modelu PrestaShop...\n";
    $maps = ShopInit\exportReferenceMaps($context);
    $restored = new Carrier(ShopInit\carrierIdByReference($maps['carrier_reference']['EXPRESS'], $context));
    expectConfig(Validate::isLoadedObject($restored) && !$restored->deleted && $restored->active && (int) $restored->id === (int) $express->id,
        'Usunięty przewoźnik nie został przywrócony z tą samą referencją.');
    $zone = (int) $maps['zone_id']['Europe'];
    foreach (['STANDARD', 'EXPRESS'] as $key) {
        $carrier = new Carrier(ShopInit\carrierIdByReference($maps['carrier_reference'][$key], $context));
        expectConfig(Carrier::checkDeliveryPriceByWeight((int) $carrier->id, 50, $zone)
            && !Carrier::checkDeliveryPriceByWeight((int) $carrier->id, 50.000001, $zone), 'Zakres masy przewoźnika ' . $key . ' jest niepoprawny.');
        $expected = $baseline['carriers'][$key]['weight_ranges'][0]['prices']['Europe'];
        expectConfig(abs((float) $carrier->getDeliveryPriceByWeight(1, $zone) - (float) $expected) < 0.000001, 'Cena przewoźnika ' . $key . ' nie została odtworzona.');
    }
    expectConfig((int) Configuration::get('PS_CARRIER_DEFAULT') === ShopInit\carrierIdByReference($maps['carrier_reference'][$baseline['configuration']['PS_CARRIER_DEFAULT']], $context), 'Domyślny przewoźnik nie wskazuje bieżącej wersji.');
    $page = new CMS((int) Configuration::get('SHOP_INIT_CMS_PAYMENTS'));
    expectConfig(Validate::isLoadedObject($page) && $page->id === $maps['cms_id']['formy-platnosci'], 'Usunięta strona CMS nie została odtworzona.');
    foreach ($db->executeS('SELECT content FROM ' . _DB_PREFIX_ . 'link_block') ?: [] as $row) {
        foreach (json_decode((string) $row['content'], true)['cms'] ?? [] as $linked) {
            expectConfig(Validate::isLoadedObject(new CMS((int) $linked)), 'Blok linków wskazuje nieistniejącą stronę ' . $linked . '.');
        }
    }
    expectConfig(trim((string) $restored->delay[(int) $extra->id]) !== '', 'Przewoźnik nie ma opisu dostawy dla dodanego języka.');
    foreach ($db->executeS('SELECT content FROM ' . _DB_PREFIX_ . 'cms_lang') ?: [] as $row) {
        preg_match_all('#/content/(\\d+)-#', (string) $row['content'], $links);
        foreach ($links[1] as $linked) {
            expectConfig(Validate::isLoadedObject(new CMS((int) $linked)), 'Treść CMS zawiera odnośnik do nieistniejącej strony ' . $linked . '.');
        }
        expectConfig(!str_contains((string) $row['content'], '{{'), 'Treść CMS zawiera nierozwiązany znacznik.');
    }
    expectConfig(Module::isEnabled('ps_featuredproducts'), 'Moduł nie został ponownie włączony.');
    expectConfig(Configuration::get('PS_INVOICE_PREFIX', $languageId) === $baseline['configuration']['PS_INVOICE_PREFIX'][Language::getIsoById($languageId)], 'Prefiks faktur nie został odtworzony.');

    echo "[7/7] Ponowne odtworzenie bez zmian...\n";
    expectConfig(ShopInit\applyConfigExport($export['data'], $export['sha256'], $context, false, $guard) === [], 'Drugie odtworzenie wprowadziło zmiany.');
    echo "OK: eksport bez sekretów, walidacja pliku, odtworzenie zmienionych obszarów i idempotencja.\n";
} finally {
    // Przywrócenie stanu wyjściowego niezależnie od wyniku testu.
    $restore = ShopInit\readConfigExport($file);
    ShopInit\applyConfigExport($restore['data'], $restore['sha256'], $context);
    if (isset($extra) && Validate::isLoadedObject($extra)) {
        $extra->delete();
    }
    unlink($file);
}
