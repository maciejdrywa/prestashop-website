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

    $pid139 = (int) (ShopInit\productId('pandomu-139', $context) ?: 53); // Klamka NINA
    $pid12  = (int) (ShopInit\productId('pandomu-12', $context) ?: 22);  // Zamek ZN-160
    $pid541 = (int) (ShopInit\productId('pandomu-541', $context) ?: 43); // KLAMKA LIZA
    $pid5   = (int) (ShopInit\productId('pandomu-5', $context) ?: 21);   // Zamek GAMA
    $pid944 = (int) (ShopInit\productId('pandomu-944', $context) ?: 32); // Wycieraczka z ramą

    // Scenariusz 1: Standardowy produkt (> 30 dni, stała cena przed obniżką) - Klamka NINA (source 139)
    $info53 = ShopOmnibus::getLowestPriceInfo($pid139, 0, 1);
    omnibusAssert($info53 !== null, "Produkt {$pid139} (NINA) musi zwrócić dane Omnibus.");
    omnibusAssert(abs($info53['lowest_price'] - 42.55) < 0.01, "Produkt {$pid139}: najniższa cena musi wynosić 42.55 PLN, otrzymano: {$info53['lowest_price']}");
    omnibusAssert($info53['is_under_30_days'] === false, "Produkt {$pid139} nie może być oznaczony jako oferowany krócej niż 30 dni.");

    // Scenariusz 2: Wahania cen w oknie 30 dni (krótka przecena do 26.00 zł i powrót) - Zamek ZN-160 (source 12)
    $info22 = ShopOmnibus::getLowestPriceInfo($pid12, 0, 1);
    omnibusAssert($info22 !== null, "Produkt {$pid12} (ZN-160) musi zwrócić dane Omnibus.");
    omnibusAssert(abs($info22['lowest_price'] - 26.00) < 0.01, "Produkt {$pid12}: najniższa cena z 30 dni musi wynosić 26.00 PLN, otrzymano: {$info22['lowest_price']}");

    // Scenariusz 3: Sukcesywne obniżki cen (69.99 -> 59.99 -> 49.99) - KLAMKA LIZA (source 541)
    $info43 = ShopOmnibus::getLowestPriceInfo($pid541, 0, 1);
    omnibusAssert($info43 !== null, "Produkt {$pid541} (LIZA) musi zwrócić dane Omnibus.");
    omnibusAssert(abs($info43['lowest_price'] - 69.99) < 0.01, "Produkt {$pid541}: najniższa cena przed pierwszą obniżką z serii musi wynosić 69.99 PLN, otrzymano: {$info43['lowest_price']}");

    // Scenariusz 4: Produkt oferowany krócej niż 30 dni (< 30 dni) - Zamek GAMA (source 5)
    $info21 = ShopOmnibus::getLowestPriceInfo($pid5, 0, 1);
    omnibusAssert($info21 !== null, "Produkt {$pid5} (GAMA) musi zwrócić dane Omnibus.");
    omnibusAssert(abs($info21['lowest_price'] - 59.99) < 0.01, "Produkt {$pid5}: najniższa cena od wprowadzenia musi wynosić 59.99 PLN, otrzymano: {$info21['lowest_price']}");
    omnibusAssert($info21['is_under_30_days'] === true, "Produkt {$pid5} musi być oznaczony jako oferowany krócej niż 30 dni (is_under_30_days = true).");

    // Scenariusz 5: Warianty produktów - Wycieraczka z ramą (source 944)
    $combos32 = Product::getProductAttributesIds($pid944);
    $attrIds = array_column($combos32, 'id_product_attribute');
    sort($attrIds);
    $infoComboA = ShopOmnibus::getLowestPriceInfo($pid944, (int) $attrIds[0], 1);
    $infoComboB = ShopOmnibus::getLowestPriceInfo($pid944, (int) $attrIds[1], 1);
    omnibusAssert($infoComboA !== null && $infoComboB !== null, "Produkt {$pid944} musi zwrócić dane Omnibus dla obu kombinacji.");
    $comboPrices = [$infoComboA['lowest_price'], $infoComboB['lowest_price']];
    sort($comboPrices);
    omnibusAssert(abs($comboPrices[0] - 132.44) < 0.01 && abs($comboPrices[1] - 144.74) < 0.01, "Kombinacje produktu {$pid944} muszą mieć najniższe ceny 132.44 i 144.74 PLN, otrzymano: {$comboPrices[0]} i {$comboPrices[1]}");

    echo "[4/5] Test automatycznego zapisu historii cen, dirty-checkingu i pełnego cyklu życia hooków...\n";

    // 4a: Test deduplikacji (dirty-check)
    $testDate = '2026-11-19 12:00:00';
    $countBefore = (int) $db->getValue("SELECT COUNT(*) FROM `{$tableName}` WHERE `id_product` = {$pid139} AND `id_product_attribute` = 0");
    ShopOmnibus::recordCurrentPrice($pid139, 0, true, $testDate);
    ShopOmnibus::recordCurrentPrice($pid139, 0, true, $testDate);
    $countAfter = (int) $db->getValue("SELECT COUNT(*) FROM `{$tableName}` WHERE `id_product` = {$pid139} AND `id_product_attribute` = 0");
    omnibusAssert($countAfter <= $countBefore + 1, 'Dirty-checking: powtórne wywołanie z identyczną ceną nie może tworzyć duplikatów.');

    // 4b: Pełny cykl życia (Product update -> SpecificPrice add -> successive promo -> SpecificPrice delete)
    $testProd = new Product();
    $testProd->name = [1 => 'Omnibus Lifecycle Test Product'];
    $testProd->link_rewrite = [1 => 'omnibus-lifecycle-test'];
    $testProd->id_category_default = 2;
    $testProd->id_tax_rules_group = 1;
    $testProd->price = round(140.00 / 1.23, 6);
    $testProd->active = 1;
    $testProd->add();
    $testPid = (int) $testProd->id;
    omnibusAssert($testPid > 0, 'Utworzenie produktu testowego musi się powieść.');

    try {
        // Krok 1: Wstępna wyższa cena regularna w historii (140 zł) 20 dni temu
        ShopOmnibus::recordCurrentPrice($testPid, 0, false, date('Y-m-d H:i:s', strtotime('-20 days')));

        // Krok 2: Zmiana ceny regularnej przez Product->update() na 120 zł brutto (10 dni temu)
        $testProd->price = round(120.00 / 1.23, 6);
        $testProd->update();
        // Zaktualizuj znacznik daty edycji na 10 dni temu dla spójności osi czasu testu
        $db->execute("UPDATE `{$tableName}` SET `date_add` = '" . date('Y-m-d H:i:s', strtotime('-10 days')) . "' WHERE `id_product` = {$testPid} AND `price_gross` >= 119.9");

        $latestPrice = (float) $db->getValue(
            "SELECT price_gross FROM `{$tableName}` WHERE `id_product` = {$testPid} ORDER BY `id_price_history` DESC"
        );
        omnibusAssert(abs($latestPrice - 120.00) < 0.1, "Po update produktu w historii musi pojawić się 120 zł, otrzymano: {$latestPrice}");

        // Krok 3: Dodanie promocji (-20 zł -> cena 100 zł) przez SpecificPrice->add()
        $sp1 = new SpecificPrice();
        $sp1->id_product = $testPid;
        $sp1->id_product_attribute = 0;
        $sp1->id_shop = 1;
        $sp1->id_currency = 0;
        $sp1->id_country = 0;
        $sp1->id_group = 0;
        $sp1->id_customer = 0;
        $sp1->price = -1;
        $sp1->from_quantity = 1;
        $sp1->reduction = round(20.00 / 1.23, 6);
        $sp1->reduction_type = 'amount';
        $sp1->reduction_tax = 0;
        $sp1->from = date('Y-m-d H:i:s', strtotime('-3 days'));
        $sp1->to = '0000-00-00 00:00:00';
        $sp1->add();

        // getLowestPriceInfo musi teraz zwrócić 120 zł jako najniższą cenę przed tą obniżką
        $infoPromo1 = ShopOmnibus::getLowestPriceInfo($testPid, 0, 1);
        omnibusAssert($infoPromo1 !== null, 'getLowestPriceInfo musi zwrócić dane dla promocji 1.');
        omnibusAssert(abs($infoPromo1['lowest_price'] - 120.00) < 0.1, "Najniższa cena przed obniżką 1 musi wynosić 120 zł, otrzymano: {$infoPromo1['lowest_price']}");

        // Krok 4: Sukcesywne pogłębienie promocji (-40 zł -> cena 80 zł) przez SpecificPrice->update()
        $sp1->reduction = round(40.00 / 1.23, 6);
        $sp1->from = date('Y-m-d H:i:s', strtotime('-1 day'));
        $sp1->update();

        $infoPromo2 = ShopOmnibus::getLowestPriceInfo($testPid, 0, 1);
        omnibusAssert($infoPromo2 !== null, 'getLowestPriceInfo musi zwrócić dane dla pogłębionej promocji.');
        omnibusAssert(abs($infoPromo2['lowest_price'] - 120.00) < 0.1, "Przy sukcesywnej obniżce najniższa cena nadal musi wynosić 120 zł (sprzed serii), otrzymano: {$infoPromo2['lowest_price']}");

        // Krok 5: Zakończenie promocji przez delete()
        $sp1->delete();
        $lastRow = $db->getRow(
            "SELECT is_promotional, price_gross FROM `{$tableName}` WHERE `id_product` = {$testPid} ORDER BY `date_add` DESC, `id_price_history` DESC"
        );
        omnibusAssert((int) $lastRow['is_promotional'] === 0, 'Po usunięciu SpecificPrice ostatni wpis musi mieć is_promotional = 0.');
    } finally {
        // Sprzątanie po teście
        $testProd->delete();
        $db->execute("DELETE FROM `{$tableName}` WHERE `id_product` = {$testPid}");
        $db->execute("DELETE FROM `{$tableName}` WHERE `id_product` = {$pid139} AND `date_add` = '{$testDate}'");
    }

    echo "[5/5] Test pokrycia baseline dla katalogu i stanu inicjalizacji (omnibusConfigured)...\n";
    $activeCount = (int) $db->getValue("SELECT COUNT(*) FROM `" . _DB_PREFIX_ . "product` WHERE `active` = 1");
    $coveredCount = (int) $db->getValue("SELECT COUNT(DISTINCT id_product) FROM `{$tableName}`");
    omnibusAssert($coveredCount >= min(25, $activeCount), "Tabela historii musi pokrywać produkty. Aktywne: {$activeCount}, Pokryte: {$coveredCount}");
    omnibusAssert(ShopInit\omnibusConfigured($context), 'omnibusConfigured() musi zwrócić true dla skonfigurowanego modułu.');

    echo "OK: Moduł shopomnibus, tabela historii, 5 scenariuszy prawnych oraz zapis zmian pomyślnie zweryfikowane.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD testu Omnibus: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
