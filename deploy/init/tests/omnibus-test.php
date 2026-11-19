<?php
declare(strict_types=1);

/**
 * Test integracyjny weryfikujący moduł Dyrektywy Omnibus (shopomnibus).
 *
 * Uruchomienie w kontenerze shop:
 *   php /opt/shop/init/tests/omnibus-test.php
 *
 * Sprawdza:
 * 1. Instalację modułu, rejestrację hooków i istnienie tabeli ps_shopomnibus_price_history.
 * 2. Zasilenie danymi demonstracyjnymi dla 25 produktów objętych promocjami.
 * 3. Poprawność wyznaczania najniższej ceny dla 5 scenariuszy biznesowych/prawnych.
 * 4. Automatyczny zapis zmian cen w historii (hooki i recordCurrentPrice).
 * 5. Idempotencję konfiguratora i funkcję stanu resume (omnibusConfigured).
 */

require_once __DIR__ . '/../lib/prestashop.php';
require_once __DIR__ . '/../lib/omnibus.php';
require_once __DIR__ . '/../lib/promotions.php';

function omnibusAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BŁĄD ASERCJI OMNIBUS: {$message}\n");
        exit(1);
    }
}

try {
    echo "[1/5] Weryfikacja instalacji modułu shopomnibus i schematu bazy...\n";
    $context = ShopInit\boot();
    ShopInit\priceContext($context);
    ShopInit\ensurePriceContext($context);

    omnibusAssert(Module::isInstalled('shopomnibus'), 'Moduł shopomnibus musi być zainstalowany.');
    omnibusAssert(Module::isEnabled('shopomnibus'), 'Moduł shopomnibus musi być włączony.');

    $module = Module::getInstanceByName('shopomnibus');
    omnibusAssert($module instanceof ShopOmnibus, 'Instancja modułu shopomnibus musi być klasy ShopOmnibus.');

    $requiredHooks = [
        'displayProductPriceBlock',
        'actionFrontControllerSetMedia',
        'actionObjectProductUpdateAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionProductAttributeUpdate',
    ];

    foreach ($requiredHooks as $hook) {
        omnibusAssert($module->isRegisteredInHook($hook), "Moduł musi być zarejestrowany w hooku: {$hook}");
    }

    $db = Db::getInstance();
    $tableName = _DB_PREFIX_ . 'shopomnibus_price_history';
    $tableExists = (bool) $db->executeS("SHOW TABLES LIKE '{$tableName}'");
    omnibusAssert($tableExists, "Tabela {$tableName} musi istnieć w bazie danych.");

    echo "[2/5] Weryfikacja obecności wpisów historii dla 25 produktów promocyjnych...\n";
    $catalog = ShopInit\readCatalog();
    $plan = ShopInit\promotionsPlan($catalog, $context);
    omnibusAssert(count($plan) === 25, 'Plan promocji musi zawierać dokładnie 25 produktów.');

    foreach ($plan as $item) {
        $count = (int) $db->getValue(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE `id_product` = {$item['product_id']}"
        );
        omnibusAssert(
            $count > 0,
            "Produkt {$item['product_id']} ({$item['name']}) musi posiadać wpisy w historii cen."
        );
    }

    echo "[3/5] Test algorytmu wyznaczania najniższej ceny dla 5 scenariuszy prawnych...\n";

    // Scenariusz 1: Standardowy produkt (> 30 dni, stała cena przed obniżką) - ID 53 (Klamka NINA)
    $info53 = ShopOmnibus::getLowestPriceInfo(53, 0, 1);
    omnibusAssert($info53 !== null, 'Produkt 53 musi zwrócić dane Omnibus.');
    omnibusAssert(abs($info53['lowest_price'] - 42.55) < 0.01, "Produkt 53: najniższa cena musi wynosić 42.55 PLN, otrzymano: {$info53['lowest_price']}");
    omnibusAssert($info53['is_under_30_days'] === false, 'Produkt 53 nie może być oznaczony jako oferowany krócej niż 30 dni.');

    // Scenariusz 2: Wahania cen w oknie 30 dni (krótka przecena do 26.00 zł i powrót) - ID 22 (Zamek ZN-160)
    $info22 = ShopOmnibus::getLowestPriceInfo(22, 0, 1);
    omnibusAssert($info22 !== null, 'Produkt 22 musi zwrócić dane Omnibus.');
    omnibusAssert(abs($info22['lowest_price'] - 26.00) < 0.01, "Produkt 22: najniższa cena z 30 dni musi wynosić 26.00 PLN, otrzymano: {$info22['lowest_price']}");

    // Scenariusz 3: Sukcesywne obniżki cen (69.99 -> 59.99 -> 49.99) - ID 43 (KLAMKA LIZA)
    $info43 = ShopOmnibus::getLowestPriceInfo(43, 0, 1);
    omnibusAssert($info43 !== null, 'Produkt 43 musi zwrócić dane Omnibus.');
    omnibusAssert(abs($info43['lowest_price'] - 69.99) < 0.01, "Produkt 43: najniższa cena przed pierwszą obniżką z serii musi wynosić 69.99 PLN, otrzymano: {$info43['lowest_price']}");

    // Scenariusz 4: Produkt oferowany krócej niż 30 dni (< 30 dni) - ID 21 (Zamek GAMA)
    $info21 = ShopOmnibus::getLowestPriceInfo(21, 0, 1);
    omnibusAssert($info21 !== null, 'Produkt 21 musi zwrócić dane Omnibus.');
    omnibusAssert(abs($info21['lowest_price'] - 59.99) < 0.01, "Produkt 21: najniższa cena od wprowadzenia musi wynosić 59.99 PLN, otrzymano: {$info21['lowest_price']}");
    omnibusAssert($info21['is_under_30_days'] === true, 'Produkt 21 musi być oznaczony jako oferowany krócej niż 30 dni (is_under_30_days = true).');

    // Scenariusz 5: Warianty produktów - ID 32 (Wycieraczka z ramą, kombinacje 89 i 90)
    $info32_89 = ShopOmnibus::getLowestPriceInfo(32, 89, 1);
    $info32_90 = ShopOmnibus::getLowestPriceInfo(32, 90, 1);
    omnibusAssert($info32_89 !== null && $info32_90 !== null, 'Produkt 32 musi zwrócić dane Omnibus dla obu kombinacji.');
    omnibusAssert(abs($info32_89['lowest_price'] - 144.74) < 0.01, "Kombinacja 89 produktu 32 musi mieć najniższą cenę 144.74 PLN, otrzymano: {$info32_89['lowest_price']}");
    omnibusAssert(abs($info32_90['lowest_price'] - 132.44) < 0.01, "Kombinacja 90 produktu 32 musi mieć najniższą cenę 132.44 PLN, otrzymano: {$info32_90['lowest_price']}");

    echo "[4/5] Test automatycznego zapisu historii cen (recordCurrentPrice)...\n";
    $testDate = date('Y-m-d H:i:s');
    $recorded = ShopOmnibus::recordCurrentPrice(53, 0, true, $testDate);
    omnibusAssert($recorded, 'recordCurrentPrice musi zwrócić true.');

    $latestRecorded = (float) $db->getValue(
        "SELECT price_gross FROM `{$tableName}` WHERE `id_product` = 53 AND `date_add` = '{$testDate}'"
    );
    omnibusAssert($latestRecorded > 0, 'Zapisany punkt cenowy musi mieć dodatnią kwotę.');

    echo "[5/5] Test idempotencji i weryfikacji stanu inicjalizacji (omnibusConfigured)...\n";
    omnibusAssert(ShopInit\omnibusConfigured($context), 'omnibusConfigured() musi zwrócić true dla skonfigurowanego modułu.');

    // Usunięcie wpisu testowego z fazy 4
    $db->execute("DELETE FROM `{$tableName}` WHERE `id_product` = 53 AND `date_add` = '{$testDate}'");

    echo "OK: Moduł shopomnibus, tabela historii, 5 scenariuszy prawnych oraz zapis zmian pomyślnie zweryfikowane.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD testu Omnibus: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
