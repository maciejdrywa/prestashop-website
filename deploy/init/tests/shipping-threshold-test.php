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
    $projectIds = $expectedIds;
    $installerPlans = ShopInit\installerCarrierPlans($context);
    $pickupId = $installerPlans['PICKUP']['id'];
    $expectedIds = array_merge($expectedIds, array_column(array_filter($installerPlans, fn ($plan): bool => $plan['enabled']), 'id'));
    $active = $db->executeS('SELECT c.id_carrier FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN '
        . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.active=1 AND c.deleted=0 AND s.id_shop=' . $shopId);
    if ($active === false) throw new RuntimeException('Cannot read active carriers.');
    $activeIds = array_map(fn ($row): int => (int) $row['id_carrier'], $active);
    sort($activeIds); sort($expectedIds);
    if ($activeIds !== $expectedIds || (int) Configuration::get('PS_CARRIER_DEFAULT') !== ShopInit\projectCarrierId('STANDARD', $context)) {
        throw new RuntimeException('InPost/DPD, standard delivery and pickup must be active; economy/light disabled; default InPost.');
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
    foreach (['49.999', '50.000', '50.001', '50.000001'] as $weight) {
        foreach (['100.00', '2000.01'] as $gross) {
            $cases[] = ['gross' => $gross, 'discount' => '0.00', 'total' => $gross,
                'free' => $gross === '2000.01', 'items' => [['weight' => $weight, 'quantity' => 1]],
                'weight' => $weight, 'blocked' => (float) $weight > 50];
        }
    }
    $cases[] = ['gross' => '10.00', 'discount' => '0.00', 'total' => '20.00', 'free' => false,
        'items' => [['weight' => '25', 'quantity' => 2]], 'weight' => '50', 'blocked' => false];
    $cases[] = ['gross' => '10.00', 'discount' => '0.00', 'total' => '30.00', 'free' => false,
        'items' => [['weight' => '16.667', 'quantity' => 3]], 'weight' => '50.001', 'blocked' => true];
    $cases[] = ['gross' => '10.00', 'discount' => '0.00', 'total' => '20.00', 'free' => false,
        'items' => [['weight' => '25', 'quantity' => 1], ['weight' => '25.001', 'quantity' => 1]],
        'weight' => '50.001', 'blocked' => true];
    $cases[] = ['gross' => '2000.01', 'discount' => '0.00', 'total' => '4000.02', 'free' => true,
        'items' => [['weight' => '25', 'quantity' => 1], ['weight' => '25.001', 'quantity' => 1]],
        'weight' => '50.001', 'blocked' => true];
    foreach ($cases as $index => $case) {
        $cartItems = [];
        foreach ($case['items'] ?? [['weight' => '1', 'quantity' => 1]] as $item) {
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
            $product->available_for_order = true; $product->weight = (float) $item['weight'];
            if (!$product->add() || !$product->addToCategories([$product->id_category_default])) throw new RuntimeException('Cannot create isolated test product.');
            StockAvailable::setQuantity((int) $product->id, 0, $item['quantity'], $shopId);
            $cartItems[] = ['id' => (int) $product->id, 'quantity' => $item['quantity']];
        }
        $cart = new Cart(); $carts[] = $cart;
        $cart->id_shop = $shopId; $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = $cart->id_address_invoice = (int) $address->id;
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) $context->currency->id;
        $cart->secure_key = $customer->secure_key;
        if (!$cart->add()) throw new RuntimeException('Cannot create isolated test cart.');
        $context->cart = $cart;
        foreach ($cartItems as $item) {
            if ($cart->updateQty($item['quantity'], $item['id']) !== true) throw new RuntimeException('Cannot add test product to cart.');
        }
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
        $weight = $cart->getTotalWeight();
        if (abs($weight - (float) ($case['weight'] ?? 1)) > 0.0000001) throw new RuntimeException('Unexpected test cart weight: ' . $weight);
        $deliveryOptions = $cart->getDeliveryOptionList(null, true);
        if ($case['blocked'] ?? false) {
            $offeredIds = [];
            foreach ($deliveryOptions as $options) foreach ($options as $option) {
                foreach ($option['carrier_list'] as $id => $offered) {
                    $offeredIds[(int) $id] = true;
                    if ((float) $offered['price_with_tax'] !== 0.0) throw new RuntimeException('Store pickup must be free.');
                }
            }
            if (array_keys($offeredIds) !== [$pickupId]) throw new RuntimeException('Overweight cart must offer only store pickup: ' . $weight . ' kg, ' . $total . ' PLN.');
            echo 'PICKUP ONLY OK: ' . $weight . ' kg, ' . $total . " PLN; no courier offered.\n";
            continue;
        }
        $offeredIds = [];
        foreach ($deliveryOptions as $options) foreach ($options as $option) {
            foreach ($option['carrier_list'] as $id => $offered) {
                $offeredIds[(int) $id] = true;
                $expected = $case['free'] ? 0.0 : ((int) $id === ShopInit\projectCarrierId('STANDARD', $context) ? 15.0 : 20.0);
                if (in_array((int) $id, $projectIds, true) && abs((float) $offered['price_with_tax'] - $expected) > 0.001) throw new RuntimeException('Wrong fee displayed in delivery option.');
                if ((int) $id === $pickupId && (float) $offered['price_with_tax'] !== 0.0) throw new RuntimeException('Store pickup must be free.');
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
        echo 'OK: weight ' . $weight . ' kg, gross ' . $case['gross'] . ' - discount ' . $case['discount'] . ' = ' . $case['total']
            . ' PLN; InPost/DPD ' . ($case['free'] ? '0/0' : '15/20') . " PLN.\n";
    }
    echo "OK: installer methods restored; courier price/weight boundaries, discounts, quantities and mixed carts verified; above 50 kg only free pickup. No order submitted.\n";
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
