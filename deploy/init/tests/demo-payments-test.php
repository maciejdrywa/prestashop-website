<?php

declare(strict_types=1);

/** Sprawdza moduł i kontroler na tymczasowym koszyku, bez tworzenia zamówień i płatności. */
require_once __DIR__ . '/../lib/payments.php';
$context = ShopInit\boot();
require_once _PS_MODULE_DIR_ . 'shopdemopayments/shopdemopayments.php';
require_once _PS_MODULE_DIR_ . 'shopdemopayments/controllers/front/validation.php';

class DemoPaymentRejected extends RuntimeException {}

class DemoPaymentProbe extends ShopDemoPayments
{
    public $calls = [];
    public function validateOrder($id_cart, $id_order_state, $amount_paid, $payment_method = 'Unknown',
        $message = null, $extra_vars = [], $currency_special = null, $dont_touch_amount = false,
        $secure_key = false, Shop $shop = null, ?string $order_reference = null)
    {
        $this->calls[] = [$id_cart, $id_order_state, $amount_paid, $payment_method, $secure_key];
        $this->currentOrder = 999999;
        return true;
    }
}

class DemoPaymentControllerProbe extends ShopDemoPaymentsValidationModuleFrontController
{
    public $confirmed = null;
    public function __construct($context, $module)
    {
        $this->context = $context;
        $this->module = $module;
    }
    protected function rejectPayment() { throw new DemoPaymentRejected('Rejected'); }
    protected function redirectToConfirmation($orderId, $customer) { $this->confirmed = $orderId; }
}

function demoAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

$temporary = null;
$previousCart = $context->cart;
$previousCustomer = $context->customer;
$previousCurrency = $context->currency;
$previousCountry = $context->country;
$previousPost = $_POST;
$previousGet = $_GET;
$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
try {
    $module = Module::getInstanceByName('shopdemopayments');
    demoAssert($module instanceof ShopDemoPayments && $module->active, 'Demo payment module is not enabled.');
    $state = new OrderState((int) Configuration::get(ShopDemoPayments::WAITING_STATE));
    demoAssert(Validate::isLoadedObject($state) && !$state->paid && !$state->invoice && !$state->logable,
        'Demo payment must not mark an order as paid or generate an invoice.');

    $sampleId = (int) Db::getInstance()->getValue('SELECT c.id_cart FROM ' . _DB_PREFIX_ . 'cart c
        JOIN ' . _DB_PREFIX_ . 'customer cu ON cu.id_customer=c.id_customer AND cu.deleted=0
        JOIN ' . _DB_PREFIX_ . 'address a ON a.id_address=c.id_address_delivery AND a.deleted=0
        WHERE c.id_address_invoice>0 ORDER BY c.id_cart DESC');
    if (!$sampleId) throw new RuntimeException('Test requires one existing customer cart with addresses.');
    $sample = new Cart($sampleId);
    $temporary = new Cart();
    foreach (['id_shop', 'id_shop_group', 'id_lang', 'id_currency', 'id_customer', 'id_address_delivery', 'id_address_invoice', 'id_carrier', 'secure_key'] as $field) {
        $temporary->$field = $sample->$field;
    }
    demoAssert($temporary->add(), 'Cannot prepare temporary cart.');
    $context->cart = $temporary;
    $context->customer = new Customer((int) $temporary->id_customer);
    $context->currency = new Currency((int) $temporary->id_currency);
    $context->country = new Country((int) (new Address((int) $temporary->id_address_delivery))->id_country);
    $productId = (int) Db::getInstance()->getValue('SELECT ps.id_product FROM ' . _DB_PREFIX_ . 'product_shop ps
        JOIN ' . _DB_PREFIX_ . 'stock_available s ON s.id_product=ps.id_product AND s.id_product_attribute=0
        WHERE ps.active=1 AND ps.available_for_order=1 AND ps.cache_default_attribute=0 AND s.quantity>1 ORDER BY ps.id_product');
    demoAssert($productId && $temporary->updateQty(1, $productId), 'Cannot prepare temporary cart contents.');
    $probe = new DemoPaymentProbe();
    $options = $module->hookPaymentOptions(['cart' => $temporary]);
    demoAssert(count($options) === 2, 'Expected two separate demo payment options for PL/PLN.');
    demoAssert(array_map(fn ($option) => $option->getCallToActionText(), $options) === ['BLIK (demo)', 'PayPal (demo)'], 'Wrong option labels.');
    foreach ($options as $option) {
        demoAssert(str_contains($option->getAction(), '/module/shopdemopayments/validation'), 'Demo payment action must stay local.');
        demoAssert(str_contains($option->getAdditionalInformation(), 'Nie nastąpi pobranie pieniędzy'), 'Missing demo notice.');
    }
    $currencyId = $temporary->id_currency;
    $temporary->id_currency = 0;
    demoAssert($module->hookPaymentOptions(['cart' => $temporary]) === [], 'Invalid currency accepted.');
    $temporary->id_currency = $currencyId;
    $module->active = false;
    demoAssert($module->hookPaymentOptions(['cart' => $temporary]) === [], 'Disabled module still offers payments.');
    $module->active = true;
    $token = Tools::getToken(false);
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach (ShopDemoPayments::METHODS as $method => $label) {
        $_POST = ['demo_method' => $method, 'token' => $token, 'amount' => '0.01'];
        $controller = new DemoPaymentControllerProbe($context, $probe);
        $controller->postProcess();
        $call = end($probe->calls);
        demoAssert($controller->confirmed === 999999 && $call[3] === $label, 'Checkout did not select the requested demo method.');
        demoAssert(abs($call[2] - $temporary->getOrderTotal(true, Cart::BOTH)) < 0.001 && $call[2] > 0.01,
            'Checkout must use the server-side total, not a posted amount.');
    }
    $count = count($probe->calls);
    foreach ([['demo_method' => 'invalid', 'token' => $token], ['demo_method' => 'blik', 'token' => 'invalid'], ['demo_method' => 'paypal']] as $post) {
        $_POST = $post;
        try { (new DemoPaymentControllerProbe($context, $probe))->postProcess(); throw new RuntimeException('Invalid request accepted.'); }
        catch (DemoPaymentRejected $expected) {}
    }
    $_POST = ['demo_method' => 'blik', 'token' => $token];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try { (new DemoPaymentControllerProbe($context, $probe))->postProcess(); throw new RuntimeException('GET accepted.'); }
    catch (DemoPaymentRejected $expected) {}
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $context->customer = new Customer();
    try { (new DemoPaymentControllerProbe($context, $probe))->postProcess(); throw new RuntimeException('Wrong customer accepted.'); }
    catch (DemoPaymentRejected $expected) {}
    demoAssert(count($probe->calls) === $count, 'Rejected request created an order.');
    $context->customer = new Customer((int) $sample->id_customer);
    $context->cart = $sample;
    $existingOrder = (int) Order::getIdByCartId((int) $sample->id);
    if ($existingOrder) {
        $controller = new DemoPaymentControllerProbe($context, $probe);
        $controller->postProcess();
        demoAssert($controller->confirmed === $existingOrder && count($probe->calls) === $count, 'Repeated checkout must reuse the existing order.');
    }
    echo "OK: two local demo options, unpaid state, server-side amount, token/method/customer validation and repeated checkout; no orders created.\n";
} finally {
    if ($temporary && $temporary->id) $temporary->delete();
    $context->cart = $previousCart;
    $context->customer = $previousCustomer;
    $context->currency = $previousCurrency;
    $context->country = $previousCountry;
    $_POST = $previousPost;
    $_GET = $previousGet;
    if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previousMethod;
}
