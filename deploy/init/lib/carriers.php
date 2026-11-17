<?php
declare(strict_types=1);

/**
 * Konfiguracja przewoźników, taryf i progów dostawy projektu.
 *
 * Używana przez configure-carriers.php przed configure-payments.php, po przygotowaniu Polski, PLN, kg i VAT
 * 23%.
 *
 * Zapisuje przewoźników, zakresy mas, opłaty, referencje w Configuration i uprawnienia modułów płatności.
 * Wykorzystuje products.php i payments.php.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/payments.php';

// PrestaShop stosuje górną granicę masy wyłącznie i próg darmowej ceny włącznie.
const COURIER_MAX_WEIGHT_KG = 50;
const COURIER_WEIGHT_UPPER_BOUND = '50.000001';
const FREE_SHIPPING_MIN_GROSS = '2000.01';

/**
 * Zwraca nazwy, opłaty brutto i komunikaty dwóch przewoźników projektu.
 *
 * @return array<string, array{name: string, gross: string, delay: string, grade: int}>
 */
function projectCarrierDefinitions(): array
{
    return [
        'STANDARD' => ['name' => 'InPost', 'gross' => '15.00', 'delay' => 'Dostawa w ciągu 2–3 dni roboczych', 'grade' => 5],
        'EXPRESS' => ['name' => 'DPD', 'gross' => '20.00', 'delay' => 'Dostawa w ciągu 1–2 dni roboczych', 'grade' => 9],
    ];
}

/**
 * Wyszukuje bieżącą wersję przewoźnika po trwałej referencji i sklepie.
 *
 * Wyszukuje nieusunięty rekord na podstawie id_reference zapisanego w konfiguracji. Referencja łączy wersje
 * przewoźnika tworzone przez panel. Wieloznaczny wynik jest błędem.
 *
 * @param string $key Klucz definicji lub ustawienia używany przez wywołujący obszar konfiguracji.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int|null ID bieżącego rekordu przewoźnika w sklepie albo null.
 * @throws \RuntimeException Przy wieloznacznym dopasowaniu lub błędzie odczytu identyfikatora.
 */
function projectCarrierId(string $key, \Context $context): ?int
{
    // Referencja pozostaje stała po utworzeniu nowej wersji przewoźnika w panelu.
    $reference = (int) \Configuration::get('SHOP_INIT_CARRIER_' . $key . '_REF');
    if (!$reference) return null;
    $query = new \DbQuery();
    $query->select('c.id_carrier AS id')->from('carrier', 'c');
    $query->innerJoin('carrier_shop', 's', 's.id_carrier=c.id_carrier');
    $query->where('c.id_reference=' . $reference)->where('c.deleted=0');
    $query->where('s.id_shop=' . (int) $context->shop->id);
    return uniqueId($query);
}

/**
 * Rozpoznaje metody instalatora i ich docelową dostępność.
 *
 * Pierwsze rozpoznanie dopuszcza nazwy oryginalne i polskie. Wymaga odrębnych rekordów sklepu, aby
 * późniejsza aktualizacja nie zmieniła przewoźnika współdzielonego z inną instalacją sklepową.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array<string, array> Rozpoznane ID metod instalatora oraz flagi enabled i pickup.
 * @throws \RuntimeException Przy brakującej, wieloznacznej lub współdzielonej metodzie instalatora.
 */
function installerCarrierPlans(\Context $context): array
{
    $definitions = [
        'PICKUP' => ['name' => 'Odbiór osobisty', 'legacy' => 'Click and collect', 'enabled' => true],
        'DEFAULT' => ['name' => 'Dostawa kurierska', 'legacy' => 'My carrier', 'enabled' => true],
        'ECONOMY' => ['name' => 'Dostawa ekonomiczna', 'legacy' => 'My cheap carrier', 'enabled' => false],
        'LIGHT' => ['name' => 'Dostawa lekkich przesyłek', 'legacy' => 'My light carrier', 'enabled' => false],
    ];
    foreach ($definitions as $key => &$definition) {
        $id = projectCarrierId('INSTALLER_' . $key, $context);
        if ($id === null) {
            $query = new \DbQuery();
            $query->select('c.id_carrier AS id')->from('carrier', 'c');
            $query->innerJoin('carrier_shop', 's', 's.id_carrier=c.id_carrier');
            $query->where('c.deleted=0')->where('s.id_shop=' . (int) $context->shop->id);
            $query->where("c.name IN ('" . pSQL($definition['name']) . "','" . pSQL($definition['legacy']) . "')");
            $id = uniqueId($query);
        }
        if ($id === null) throw new RuntimeException('Missing installer carrier to restore: ' . $definition['name']);
        $shops = \Db::getInstance()->executeS('SELECT id_shop FROM ' . _DB_PREFIX_ . 'carrier_shop WHERE id_carrier=' . $id);
        if ($shops === false || count($shops) !== 1) throw new RuntimeException('Installer carrier is shared between shops: ' . $definition['name']);
        $definition['id'] = $id;
        $definition['pickup'] = $key === 'PICKUP';
    }
    unset($definition);
    return $definitions;
}

/**
 * Zapisuje przewoźników, taryfy oraz progi ceny i masy. Tryb próbny tylko raportuje.
 *
 * Ustawia InPost 15 PLN i DPD 20 PLN brutto przy VAT 23%, zakresie do 50 kg i braku opłaty manipulacyjnej.
 * Odtwarza odbiór osobisty i podstawową dostawę, wyłącza metody economy/light, synchronizuje płatności oraz
 * sprawdza taryfy natywnym kalkulatorem.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $dryRun Przy true wykonywane są kontrole i raportowanie planu przed zapisami danego konfiguratora.
 * @return void
 * @throws \RuntimeException Przy błędnych zależnościach, zapisie taryf lub rozbieżności natywnych reguł dostawy.
 */
function configureProjectCarriers(\Context $context, bool $dryRun = false): void
{
    priceContext($context);
    if (strtolower(trim((string) \Configuration::get('PS_WEIGHT_UNIT'))) !== 'kg') throw new RuntimeException('Carrier weight limit requires PS_WEIGHT_UNIT=kg.');
    $inputs = paymentInputs($context);
    $country = new \Country($inputs['country']);
    $zone = new \Zone((int) $country->id_zone);
    if (!\Validate::isLoadedObject($zone) || !$zone->active) throw new RuntimeException('The Polish delivery zone must be active.');
    $taxGroup = taxGroup('23', $context);
    if ((int) $context->currency->precision !== 2) throw new RuntimeException('Shipping threshold requires PLN precision of two decimal places.');
    $shopId = (int) $context->shop->id;
    $db = \Db::getInstance();
    // Najpierw rozpoznajemy istniejące referencje i odrzucamy obiekty współdzielone
    // między sklepami. Docelowe opłaty netto wynikają z ustalonych taryf brutto i VAT 23%.
    $plans = [];
    foreach (projectCarrierDefinitions() as $key => $definition) {
        $id = projectCarrierId($key, $context);
        if ($id !== null) {
            $shops = $db->executeS('SELECT id_shop FROM ' . _DB_PREFIX_ . 'carrier_shop WHERE id_carrier=' . $id);
            if ($shops === false || count($shops) !== 1) throw new RuntimeException('Project carrier is shared between shops; review before changing it.');
        }
        $plans[$key] = $definition + ['id' => $id, 'net' => rounded(bcdiv($definition['gross'], '1.23', 12), 6)];
        echo ($dryRun ? 'PLAN: ' : 'CARRIER: ') . $definition['name'] . ', ' . $definition['gross'] . ' PLN gross, '
            . ($id === null ? 'new carrier' : 'ID ' . $id) . PHP_EOL;
    }
    $installerPlans = installerCarrierPlans($context);
    foreach ($installerPlans as $plan) {
        echo ($plan['enabled'] ? 'PLAN ENABLE: ' : 'PLAN DISABLE: ') . $plan['name'] . PHP_EOL;
    }
    echo "PLAN: InPost, DPD, standard delivery and pickup active; economy/light disabled; above 50 kg only free store pickup.\n";
    if ($dryRun) {
        echo "OK: dry run; no changes.\n";
        return;
    }
    echo "[1/3] Creating or updating two project carriers...\n";
    transaction(function () use ($context, $inputs, $zone, $taxGroup, $shopId, $db, $plans, $installerPlans): void {
        foreach ($plans as $key => $plan) {
            $carrier = new \Carrier($plan['id']);
            $carrier->id_shop_list = [$shopId];
            $carrier->name = $plan['name'];
            $carrier->active = true;
            $carrier->deleted = false;
            $carrier->is_free = false;
            $carrier->is_module = false;
            $carrier->external_module_name = '';
            $carrier->shipping_external = false;
            $carrier->shipping_handling = false;
            $carrier->shipping_method = \Carrier::SHIPPING_METHOD_WEIGHT;
            $carrier->range_behavior = true;
            $carrier->max_weight = COURIER_MAX_WEIGHT_KG;
            $carrier->max_width = $carrier->max_height = $carrier->max_depth = 0;
            $carrier->grade = $plan['grade'];
            foreach (\Language::getLanguages(false) as $language) $carrier->delay[(int) $language['id_lang']] = $plan['delay'];
            if (!($plan['id'] === null ? $carrier->add() : $carrier->update())) throw new RuntimeException('Cannot save carrier: ' . $plan['name']);
            $id = (int) $carrier->id;
            // Carrier::add zapisuje id_reference w SQL. Odczytujemy ją ponownie przed mapowaniem.
            $carrier = new \Carrier($id);
            saveShopSetting('SHOP_INIT_CARRIER_' . $key . '_REF', (int) $carrier->id_reference, $context);
            if (!$carrier->setTaxRulesGroup($taxGroup) || !$carrier->setGroups($inputs['groups'])) throw new RuntimeException('Cannot save carrier taxes/groups.');
            $zones = $db->executeS('SELECT id_zone FROM ' . _DB_PREFIX_ . 'carrier_zone WHERE id_carrier=' . $id);
            if ($zones === false) throw new RuntimeException('Cannot read carrier zones.');
            foreach ($zones as $row) {
                if ((int) $row['id_zone'] !== (int) $zone->id && !$carrier->deleteZone((int) $row['id_zone'])) throw new RuntimeException('Cannot remove obsolete carrier zone.');
            }
            if (!$carrier->getZone((int) $zone->id) && !$carrier->addZone((int) $zone->id)) throw new RuntimeException('Cannot assign Polish delivery zone.');
            $query = new \DbQuery();
            // Taryfa projektu używa pojedynczego zakresu masy. Wieloznaczny zastany
            // zakres zatrzyma uniqueId zamiast arbitralnie wybrać wiersz do aktualizacji.
            $query->select('id_range_weight AS id')->from('range_weight')->where('id_carrier=' . $id);
            $range = new \RangeWeight(uniqueId($query));
            $range->id_carrier = $id;
            $range->delimiter1 = 0;
            // Górna granica przedziału jest wyłączna. Masa jest zapisywana do sześciu miejsc.
            // W połączeniu z max_weight=50 pozwala to obsłużyć dokładnie 50 kg.
            $range->delimiter2 = COURIER_WEIGHT_UPPER_BOUND;
            if (!$range->save()) throw new RuntimeException('Cannot save carrier tariff range.');
            if (!$carrier->deleteDeliveryPrice('range_weight') || !$carrier->addDeliveryPrice([[
                'id_carrier' => $id, 'id_range_price' => null, 'id_range_weight' => (int) $range->id,
                'id_zone' => (int) $zone->id, 'price' => $plan['net'],
            ]])) throw new RuntimeException('Cannot save carrier delivery fee.');
            echo ($plan['id'] === null ? 'CREATED: ' : 'UPDATED: ') . $plan['name'] . ', ID ' . $id . ', net ' . $plan['net'] . " PLN, VAT 23%\n";
        }
        foreach ($installerPlans as $key => $plan) {
            $carrier = new \Carrier($plan['id']);
            $carrier->id_shop_list = [$shopId];
            $carrier->name = $plan['name'];
            $carrier->active = $plan['enabled'];
            $carrier->max_weight = $plan['pickup'] ? 0 : COURIER_MAX_WEIGHT_KG;
            // Zachowujemy taryfy, zakresy i sposób obsługi przekroczeń metod instalatora.
            // Natywna kontrola max_weight sprawdza sumę mas przed udostępnieniem dostawy.
            if ($plan['pickup']) {
                $carrier->is_free = true;
                $carrier->range_behavior = false;
                $carrier->shipping_handling = false;
                $carrier->max_width = $carrier->max_height = $carrier->max_depth = 0;
            }
            foreach (\Language::getLanguages(false) as $language) {
                if ($plan['pickup']) $carrier->delay[(int) $language['id_lang']] = 'Odbiór w sklepie';
            }
            if (!$carrier->update() || !$carrier->setGroups($inputs['groups'])) throw new RuntimeException('Cannot restore installer carrier: ' . $plan['name']);
            if (!$carrier->getZone((int) $zone->id) && !$carrier->addZone((int) $zone->id)) throw new RuntimeException('Cannot restore carrier zone.');
            saveShopSetting('SHOP_INIT_CARRIER_INSTALLER_' . $key . '_REF', (int) $carrier->id_reference, $context);
            echo ($plan['enabled'] ? 'ENABLED: ' : 'DISABLED: ') . $carrier->name . ', ID ' . $carrier->id . ', max weight: ' . $carrier->max_weight . PHP_EOL;
        }
        saveShopSetting('PS_CARRIER_DEFAULT', projectCarrierId('STANDARD', $context), $context);
        // Rdzeń porównuje >=. Dla PLN próg ponad 2000 zł zapisujemy jako >=2000.01.
        // Korzystamy z natywnej sumy koszyka brutto po rabatach, z pominięciem dostawy.
        saveShopSetting('PS_SHIPPING_FREE_PRICE', FREE_SHIPPING_MIN_GROSS, $context);
        // Darmowa wysyłka zależy od ceny, a próg darmowej dostawy według masy jest wyłączony.
        saveShopSetting('PS_SHIPPING_FREE_WEIGHT', 0, $context);
    });
    echo "[2/3] Synchronizing offline payment permissions for carrier references...\n";
    // Moduły płatności zapisują uprawnienia według referencji przewoźników.
    // Synchronizacja po utworzeniu metod udostępnia płatności również nowym referencjom.
    transaction(function () use ($context, $db, $shopId): void {
        $inputs = paymentInputs($context);
        foreach (allowedPaymentModules() as $name) {
            if (!\Module::isEnabled($name)) continue;
            $module = \Module::getInstanceByName($name);
            if (!$db->delete('module_carrier', 'id_module=' . (int) $module->id . ' AND id_shop=' . $shopId)) throw new RuntimeException('Cannot reset carrier payment permissions.');
            foreach ($inputs['carriers'] as $reference) {
                if (!$db->insert('module_carrier', ['id_module' => (int) $module->id, 'id_shop' => $shopId, 'id_reference' => $reference])) throw new RuntimeException('Cannot assign carrier payment permission.');
            }
        }
    });
    echo "[3/3] Checking Polish gross delivery tariffs...\n";
    // Kontrola korzysta z natywnych reguł wyboru taryfy i kalkulacji podatku,
    // aby sprawdzić dostępność granicy masy i brutto, które otrzyma koszyk.
    \Carrier::resetStaticCache();
    $address = new \Address();
    $address->id_country = (int) $country->id;
    foreach ($plans as $key => $plan) {
        $carrier = new \Carrier(projectCarrierId($key, $context));
        if ((float) $carrier->max_weight !== (float) COURIER_MAX_WEIGHT_KG || !$carrier->range_behavior
            || !\Carrier::checkDeliveryPriceByWeight((int) $carrier->id, 50, (int) $zone->id)
            || \Carrier::checkDeliveryPriceByWeight((int) $carrier->id, 50.000001, (int) $zone->id)) {
            throw new RuntimeException('Carrier weight limit/range mismatch: ' . $plan['name']);
        }
        $net = $carrier->getDeliveryPriceByWeight(1, (int) $zone->id);
        $gross = \Tools::ps_round((float) $net * (1 + $carrier->getTaxesRate($address) / 100), 2);
        if ($net === false || abs($gross - (float) $plan['gross']) > 0.001) throw new RuntimeException('Gross delivery tariff mismatch: ' . $plan['name']);
        echo 'PRICE OK: ' . $carrier->name . ', ' . number_format($gross, 2, '.', '') . " PLN gross\n";
    }
    \Tools::clearSmartyCache();
    $active = $db->executeS('SELECT c.id_carrier FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN '
        . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.deleted=0 AND c.active=1 AND s.id_shop=' . $shopId);
    if ($active === false) throw new RuntimeException('Cannot verify active delivery carriers.');
    $actualIds = array_map(fn ($row): int => (int) $row['id_carrier'], $active);
    $expectedIds = array_map(fn ($key): int => (int) projectCarrierId($key, $context), array_keys($plans));
    $expectedIds = array_merge($expectedIds, array_column(array_filter($installerPlans, fn ($plan): bool => $plan['enabled']), 'id'));
    sort($actualIds); sort($expectedIds);
    if ($actualIds !== $expectedIds) throw new RuntimeException('Active carriers differ from project and restored installer carriers; review unknown active carriers.');
    echo "OK: InPost, DPD, standard delivery and pickup active; economy/light disabled; above 50 kg only free store pickup.\n";
}
