<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/carriers.php';

$customer = $address = $cart = null;
$exitCode = 0;
try {
    $context = ShopInit\boot();
    $db = Db::getInstance();
    $shopId = (int) $context->shop->id;
    $snapshot = function () use ($db, $shopId): array {
        $ids = [];
        foreach (ShopInit\projectCarrierDefinitions() as $key => $definition) {
            $id = ShopInit\projectCarrierId($key, Context::getContext());
            if ($id === null) throw new RuntimeException('Run configure-carriers.php before the test.');
            $ids[] = $id;
        }
        $data = ['ids' => $ids];
        foreach (['carrier', 'range_weight', 'delivery', 'carrier_zone', 'carrier_group', 'carrier_tax_rules_group_shop'] as $table) {
            $data[$table] = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . $table . ' WHERE id_carrier IN (' . implode(',', $ids) . ')');
            if ($data[$table] === false) throw new RuntimeException('Cannot snapshot carrier configuration.');
            // Delivery row primary keys may change when restoring tariffs.
            foreach ($data[$table] as &$row) unset($row['id_delivery']);
            unset($row);
        }
        $data['other_carriers'] = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'carrier WHERE id_carrier NOT IN (' . implode(',', $ids) . ') ORDER BY id_carrier');
        foreach (['PS_SHIPPING_HANDLING', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT', 'PS_CARRIER_DEFAULT'] as $key) $data[$key] = Configuration::get($key);
        return $data;
    };
    $before = $snapshot();
    ShopInit\configureProjectCarriers($context, true);
    if ($before !== $snapshot()) throw new RuntimeException('Dry run changed carrier configuration.');
    ShopInit\configureProjectCarriers($context);
    if ($before !== $snapshot()) throw new RuntimeException('Rerun duplicated/changed carrier configuration or modified existing carriers/settings.');
    echo "OK: dry run and idempotence, existing carriers and global settings verified.\n";

    $customer = new Customer();
    $customer->firstname = 'Test'; $customer->lastname = 'Dostawy';
    $customer->email = 'carriers-' . bin2hex(random_bytes(8)) . '@example.test';
    $customer->passwd = Tools::hash(bin2hex(random_bytes(18)));
    $customer->id_shop = $shopId; $customer->id_shop_group = (int) $context->shop->id_shop_group;
    $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    $customer->active = true;
    if (!$customer->add()) throw new RuntimeException('Cannot create disposable test customer.');
    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->id_country = (int) Country::getByIso('PL');
    $address->firstname = $customer->firstname; $address->lastname = $customer->lastname;
    $address->alias = 'Test'; $address->address1 = 'Testowa 1';
    $address->city = 'Gdańsk'; $address->postcode = '80-001'; $address->phone = '500000000';
    if (!$address->add()) throw new RuntimeException('Cannot create test address.');
    $cart = new Cart();
    $cart->id_shop = $shopId; $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = $cart->id_address_invoice = (int) $address->id;
    $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->secure_key = $customer->secure_key;
    if (!$cart->add()) throw new RuntimeException('Cannot create test cart.');
    $context->customer = $customer; $context->cart = $cart;
    $context->country = new Country($address->id_country);
    $context->currency = new Currency($cart->id_currency);
    $candidate = $db->getRow('SELECT p.id_product,s.id_product_attribute FROM ' . _DB_PREFIX_ . 'product p INNER JOIN '
        . _DB_PREFIX_ . 'product_shop ps ON ps.id_product=p.id_product INNER JOIN ' . _DB_PREFIX_ . 'stock_available s ON s.id_product=p.id_product'
        . " WHERE p.reference LIKE 'pandomu-%' AND ps.active=1 AND ps.id_shop=" . $shopId . ' AND s.id_shop=ps.id_shop'
        . ' AND s.quantity>0 AND p.is_virtual=0 ORDER BY p.id_product,s.id_product_attribute DESC');
    if (!$candidate || $cart->updateQty(1, (int) $candidate['id_product'], (int) $candidate['id_product_attribute']) !== true) throw new RuntimeException('Cannot add available source product to test cart.');
    $options = $cart->getDeliveryOptionList(null, true);
    foreach (ShopInit\projectCarrierDefinitions() as $key => $definition) {
        $id = ShopInit\projectCarrierId($key, $context);
        $offered = false;
        foreach ($options as $addressOptions) foreach ($addressOptions as $option) {
            if (isset($option['carrier_list'][$id])) $offered = true;
        }
        if (!$offered) throw new RuntimeException('Carrier missing from real cart delivery options: ' . $definition['name']);
        $gross = $cart->getPackageShippingCost($id, true);
        if ($gross === false || abs((float) $gross - (float) $definition['gross']) > 0.001) throw new RuntimeException('Incorrect real cart gross fee: ' . $definition['name'] . ', got ' . var_export($gross, true));
        foreach (ShopInit\allowedPaymentModules() as $name) {
            $module = Module::getInstanceByName($name);
            $carrier = new Carrier($id);
            $count = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module_carrier WHERE id_module=' . (int) $module->id . ' AND id_shop=' . $shopId . ' AND id_reference=' . (int) $carrier->id_reference);
            if ($count !== 1) throw new RuntimeException('Missing/duplicate payment permission for new carrier.');
        }
        echo 'CART OK: ' . $definition['name'] . ', ' . number_format((float) $gross, 2, '.', '') . " PLN gross; bank transfer and COD permitted.\n";
    }
    echo "OK: both new carriers available with distinct gross fees in the PrestaShop cart. No order submitted.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ([$cart, $address, $customer] as $object) {
        if ($object !== null && $object->id && !$object->delete()) $exitCode = 1;
    }
    echo "CLEANUP: disposable cart, address and customer removed.\n";
}
exit($exitCode);
