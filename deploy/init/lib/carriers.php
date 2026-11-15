<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/payments.php';

function projectCarrierDefinitions(): array
{
    return [
        'STANDARD' => ['name' => 'InPost', 'gross' => '15.00', 'delay' => 'Dostawa w ciągu 2–3 dni roboczych', 'grade' => 5],
        'EXPRESS' => ['name' => 'DPD', 'gross' => '20.00', 'delay' => 'Dostawa w ciągu 1–2 dni roboczych', 'grade' => 9],
    ];
}

function projectCarrierId(string $key, \Context $context): ?int
{
    // The reference survives the new carrier versions created by the back office.
    $reference = (int) \Configuration::get('SHOP_INIT_CARRIER_' . $key . '_REF');
    if (!$reference) return null;
    $query = new \DbQuery();
    $query->select('c.id_carrier AS id')->from('carrier', 'c');
    $query->innerJoin('carrier_shop', 's', 's.id_carrier=c.id_carrier');
    $query->where('c.id_reference=' . $reference)->where('c.deleted=0');
    $query->where('s.id_shop=' . (int) $context->shop->id);
    return uniqueId($query);
}

function configureProjectCarriers(\Context $context, bool $dryRun = false): void
{
    priceContext($context);
    $inputs = paymentInputs($context);
    $country = new \Country($inputs['country']);
    $zone = new \Zone((int) $country->id_zone);
    if (!\Validate::isLoadedObject($zone) || !$zone->active) throw new RuntimeException('The Polish delivery zone must be active.');
    $taxGroup = taxGroup('23', $context);
    if ((int) $context->currency->precision !== 2) throw new RuntimeException('Shipping threshold requires PLN precision of two decimal places.');
    $shopId = (int) $context->shop->id;
    $db = \Db::getInstance();
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
    $projectIds = array_values(array_filter(array_column($plans, 'id'), fn ($id): bool => $id !== null));
    $otherCarriers = $db->executeS('SELECT c.id_carrier,c.name FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN '
        . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.deleted=0 AND c.active=1 AND s.id_shop=' . $shopId
        . ($projectIds === [] ? '' : ' AND c.id_carrier NOT IN (' . implode(',', $projectIds) . ')'));
    if ($otherCarriers === false) throw new RuntimeException('Cannot list carriers to disable.');
    foreach ($otherCarriers as $row) {
        $shops = $db->executeS('SELECT id_shop FROM ' . _DB_PREFIX_ . 'carrier_shop WHERE id_carrier=' . (int) $row['id_carrier']);
        if ($shops === false || count($shops) !== 1) throw new RuntimeException('Cannot disable a carrier shared between shops: ' . $row['name']);
        echo 'PLAN DISABLE: ' . $row['name'] . ', ID ' . $row['id_carrier'] . PHP_EOL;
    }
    echo "PLAN: only InPost/DPD active, default InPost; free shipping from 2000.01 PLN gross after discounts, excluding shipping.\n";
    if ($dryRun) {
        echo "OK: dry run; no changes.\n";
        return;
    }
    echo "[1/3] Creating or updating two project carriers...\n";
    transaction(function () use ($context, $inputs, $zone, $taxGroup, $shopId, $db, $plans, $otherCarriers): void {
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
            // Only the base tariff is implemented here. The 50 kg rule is a later stage.
            $carrier->range_behavior = false;
            $carrier->max_weight = 0;
            $carrier->max_width = $carrier->max_height = $carrier->max_depth = 0;
            $carrier->grade = $plan['grade'];
            foreach (\Language::getLanguages(false) as $language) $carrier->delay[(int) $language['id_lang']] = $plan['delay'];
            if (!($plan['id'] === null ? $carrier->add() : $carrier->update())) throw new RuntimeException('Cannot save carrier: ' . $plan['name']);
            $id = (int) $carrier->id;
            // Carrier::add writes id_reference in SQL; reload before saving the mapping.
            $carrier = new \Carrier($id);
            contentSetting('SHOP_INIT_CARRIER_' . $key . '_REF', (int) $carrier->id_reference, $context);
            if (!$carrier->setTaxRulesGroup($taxGroup) || !$carrier->setGroups($inputs['groups'])) throw new RuntimeException('Cannot save carrier taxes/groups.');
            $zones = $db->executeS('SELECT id_zone FROM ' . _DB_PREFIX_ . 'carrier_zone WHERE id_carrier=' . $id);
            if ($zones === false) throw new RuntimeException('Cannot read carrier zones.');
            foreach ($zones as $row) {
                if ((int) $row['id_zone'] !== (int) $zone->id && !$carrier->deleteZone((int) $row['id_zone'])) throw new RuntimeException('Cannot remove obsolete carrier zone.');
            }
            if (!$carrier->getZone((int) $zone->id) && !$carrier->addZone((int) $zone->id)) throw new RuntimeException('Cannot assign Polish delivery zone.');
            $query = new \DbQuery();
            $query->select('id_range_weight AS id')->from('range_weight')->where('id_carrier=' . $id);
            $range = new \RangeWeight(uniqueId($query));
            $range->id_carrier = $id;
            $range->delimiter1 = 0;
            $range->delimiter2 = 1000000;
            if (!$range->save()) throw new RuntimeException('Cannot save carrier tariff range.');
            if (!$carrier->deleteDeliveryPrice('range_weight') || !$carrier->addDeliveryPrice([[
                'id_carrier' => $id, 'id_range_price' => null, 'id_range_weight' => (int) $range->id,
                'id_zone' => (int) $zone->id, 'price' => $plan['net'],
            ]])) throw new RuntimeException('Cannot save carrier delivery fee.');
            echo ($plan['id'] === null ? 'CREATED: ' : 'UPDATED: ') . $plan['name'] . ', ID ' . $id . ', net ' . $plan['net'] . " PLN, VAT 23%\n";
        }
        foreach ($otherCarriers as $row) {
            $carrier = new \Carrier((int) $row['id_carrier']);
            $carrier->id_shop_list = [$shopId];
            $carrier->active = false;
            if (!$carrier->update()) throw new RuntimeException('Cannot disable carrier: ' . $row['name']);
            echo 'DISABLED: ' . $carrier->name . ', ID ' . $carrier->id . PHP_EOL;
        }
        contentSetting('PS_CARRIER_DEFAULT', projectCarrierId('STANDARD', $context), $context);
        // The core compares >=. PLN has two decimals, so >2000 means >=2000.01.
        // Keep the native cart calculation (gross after discounts, without shipping).
        contentSetting('PS_SHIPPING_FREE_PRICE', '2000.01', $context);
        // Weight must not grant free shipping independently of the price threshold.
        contentSetting('PS_SHIPPING_FREE_WEIGHT', 0, $context);
    });
    echo "[2/3] Synchronizing offline payment permissions for carrier references...\n";
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
    \Carrier::resetStaticCache();
    $address = new \Address();
    $address->id_country = (int) $country->id;
    foreach ($plans as $key => $plan) {
        $carrier = new \Carrier(projectCarrierId($key, $context));
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
    sort($actualIds); sort($expectedIds);
    if ($actualIds !== $expectedIds) throw new RuntimeException('Active carriers differ from InPost/DPD.');
    echo "OK: only InPost and DPD active; free shipping above 2000.00 PLN gross (native threshold 2000.01).\n";
}
