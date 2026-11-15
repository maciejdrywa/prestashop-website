<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/carriers.php';

$customer = $address = null;
$carts = $products = $rules = [];
$exitCode = 0;
try {
    $context = ShopInit\boot();
    $shopId = (int) $context->shop->id;
    $db = Db::getInstance();
    $expectedIds = [];
    foreach (ShopInit\projectCarrierDefinitions() as $key => $definition) {
        $id = ShopInit\projectCarrierId($key, $context);
        if ($id === null) throw new RuntimeException('Run configure-carriers.php before this test.');
        $expectedIds[] = $id;
    }
    $active = $db->executeS('SELECT c.id_carrier FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN '
        . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.active=1 AND c.deleted=0 AND s.id_shop=' . $shopId);
    if ($active === false) throw new RuntimeException('Cannot read active carriers.');
    $activeIds = array_map(fn ($row): int => (int) $row['id_carrier'], $active);
    sort($activeIds); sort($expectedIds);
    if ($activeIds !== $expectedIds || (int) Configuration::get('PS_CARRIER_DEFAULT') !== ShopInit\projectCarrierId('STANDARD', $context)) {
        throw new RuntimeException('Only InPost and DPD must be active, with InPost as default.');
    }
    $customer = new Customer();
    $customer->firstname = 'Test'; $customer->lastname = 'Progu';
    $customer->email = 'shipping-' . bin2hex(random_bytes(8)) . '@example.test';
    $customer->passwd = Tools::hash(bin2hex(random_bytes(18)));
    $customer->active = true;
    $customer->id_shop = $shopId; $customer->id_shop_group = (int) $context->shop->id_shop_group;
    $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    if (!$customer->add()) throw new RuntimeException('Cannot create disposable test customer.');
    $address = new Address();
    $address->id_customer = (int) $customer->id; $address->id_country = (int) Country::getByIso('PL');
    $address->firstname = $customer->firstname; $address->lastname = $customer->lastname;
    $address->alias = 'Test'; $address->address1 = 'Testowa 1';
    $address->city = 'Gdańsk'; $address->postcode = '80-001'; $address->phone = '500000000';
    if (!$address->add()) throw new RuntimeException('Cannot create test address.');
    $context->customer = $customer;
    $context->country = new Country($address->id_country);
    $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    // Separate carts/products avoid stale price and shipping caches between cases.
    $cases = [
        ['gross' => '1999.99', 'discount' => '0.00', 'total' => '1999.99', 'free' => false],
        ['gross' => '2000.00', 'discount' => '0.00', 'total' => '2000.00', 'free' => false],
        ['gross' => '2000.01', 'discount' => '0.00', 'total' => '2000.01', 'free' => true],
        ['gross' => '2100.00', 'discount' => '100.00', 'total' => '2000.00', 'free' => false],
        ['gross' => '2100.00', 'discount' => '99.99', 'total' => '2000.01', 'free' => true],
    ];
    foreach ($cases as $index => $case) {
        $product = new Product(); $products[] = $product;
        $product->id_shop_list = [$shopId];
        $product->reference = 'shipping-test-' . bin2hex(random_bytes(6));
        foreach (Language::getLanguages(false) as $language) {
            $product->name[(int) $language['id_lang']] = 'Test progu dostawy';
            $product->link_rewrite[(int) $language['id_lang']] = 'test-progu-dostawy';
        }
        $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
        $product->price = (float) ShopInit\rounded(bcdiv($case['gross'], '1.23', 12), 6);
        $product->id_tax_rules_group = ShopInit\taxGroup('23', $context);
        $product->active = true; $product->visibility = 'none';
        $product->available_for_order = true; $product->weight = 1;
        if (!$product->add() || !$product->addToCategories([$product->id_category_default])) throw new RuntimeException('Cannot create isolated test product.');
        StockAvailable::setQuantity((int) $product->id, 0, 1, $shopId);
        $cart = new Cart(); $carts[] = $cart;
        $cart->id_shop = $shopId; $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = $cart->id_address_invoice = (int) $address->id;
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) $context->currency->id;
        $cart->secure_key = $customer->secure_key;
        if (!$cart->add()) throw new RuntimeException('Cannot create isolated test cart.');
        $context->cart = $cart;
        if ($cart->updateQty(1, (int) $product->id) !== true) throw new RuntimeException('Cannot add test product to cart.');
        if ($case['discount'] !== '0.00') {
            $rule = new CartRule(); $rules[] = $rule;
            $rule->id_shop_list = [$shopId]; $rule->id_customer = (int) $customer->id;
            foreach (Language::getLanguages(false) as $language) $rule->name[(int) $language['id_lang']] = 'Test rabatu progu';
            $rule->date_from = date('Y-m-d H:i:s', time() - 3600);
            $rule->date_to = date('Y-m-d H:i:s', time() + 3600);
            $rule->quantity = $rule->quantity_per_user = 1;
            $rule->code = 'SHIPPINGTEST' . bin2hex(random_bytes(6));
            $rule->active = true; $rule->partial_use = false;
            $rule->reduction_amount = (float) $case['discount'];
            $rule->reduction_tax = true; $rule->reduction_currency = (int) $context->currency->id;
            if (!$rule->add() || !$cart->addCartRule((int) $rule->id)) throw new RuntimeException('Cannot apply isolated test discount.');
        }
        $total = $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, null, null, false);
        if (abs($total - (float) $case['total']) > 0.001) throw new RuntimeException('Unexpected test cart gross total: ' . $total);
        $offeredIds = [];
        foreach ($cart->getDeliveryOptionList(null, true) as $options) foreach ($options as $option) {
            foreach ($option['carrier_list'] as $id => $offered) {
                $offeredIds[(int) $id] = true;
                $expected = $case['free'] ? 0.0 : ((int) $id === ShopInit\projectCarrierId('STANDARD', $context) ? 15.0 : 20.0);
                if (abs((float) $offered['price_with_tax'] - $expected) > 0.001) throw new RuntimeException('Wrong fee displayed in delivery option.');
            }
        }
        $offeredIds = array_keys($offeredIds); sort($offeredIds);
        if ($offeredIds !== $expectedIds) throw new RuntimeException('Cart offers a missing or unexpected carrier.');
        foreach (ShopInit\projectCarrierDefinitions() as $key => $definition) {
            $id = ShopInit\projectCarrierId($key, $context);
            $expected = $case['free'] ? 0.0 : (float) $definition['gross'];
            $fee = $cart->getPackageShippingCost($id, true);
            if ($fee === false || abs((float) $fee - $expected) > 0.001) throw new RuntimeException('Wrong shipping fee for ' . $definition['name'] . ' at ' . $case['total']);
            $fullTotal = $cart->getOrderTotal(true, Cart::BOTH, null, $id, false);
            if (abs($fullTotal - ($total + $expected)) > 0.001) throw new RuntimeException('Wrong full cart total.');
        }
        echo 'OK: gross ' . $case['gross'] . ' - discount ' . $case['discount'] . ' = ' . $case['total']
            . ' PLN; InPost/DPD ' . ($case['free'] ? '0/0' : '15/20') . " PLN.\n";
    }
    echo "OK: only InPost/DPD offered; exact threshold and discounts verified through native cart totals and delivery options. No order submitted.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach (array_merge($carts, $rules, $products, [$address, $customer]) as $object) {
        if ($object !== null && $object->id && !$object->delete()) {
            fwrite(STDERR, 'CLEANUP FAILED: ' . get_class($object) . ' ' . $object->id . PHP_EOL);
            $exitCode = 1;
        }
    }
    echo "CLEANUP: disposable carts, discounts, products, address and customer removed.\n";
}
exit($exitCode);
