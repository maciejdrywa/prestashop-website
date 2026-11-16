<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/orders.php';
require_once __DIR__ . '/../lib/carriers.php';

function invoiceTest(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function invoiceHtml(string $body): DOMXPath
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $body);
    return new DOMXPath($dom);
}

$customer = $address = $product = null;
$orderIds = [];
$exitCode = 0;
try {
    $context = ShopInit\boot();
    // Back-office OrderHistory uses Symfony stock/payment services. Bootstrap the
    // same container in CLI before exercising that native administrator operation.
    require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
    global $kernel;
    $kernel = new AppKernel('prod', false);
    $kernel->boot();
    $shopId = (int) $context->shop->id;
    $langId = (int) Configuration::get('PS_LANG_DEFAULT');
    $db = Db::getInstance();
    $employeeId = (int) $db->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE active=1 ORDER BY id_employee');
    invoiceTest($employeeId > 0, 'An active administrator is required for the native status-change test.');
    $context->employee = new Employee($employeeId);
    // This integration test submits real local orders. Its emails must stay in Mailpit.
    invoiceTest((int) Configuration::get('PS_MAIL_METHOD') === 2 && Configuration::get('PS_MAIL_SERVER') === 'mailpit'
        && (int) Configuration::get('PS_MAIL_SMTP_PORT') === 1025, 'Test requires local Mailpit SMTP.');
    invoiceTest($context->shop->domain === 'localhost', 'Test may only submit orders to the local demo shop.');
    $plan = ShopInit\orderStatusPlan();
    $snapshot = function () use ($db, $plan, $context): array {
        $ids = implode(',', array_column($plan, 'id'));
        $data = ['states' => $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'order_state WHERE id_order_state IN (' . $ids . ') ORDER BY id_order_state'),
            'names' => $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'order_state_lang WHERE id_order_state IN (' . $ids . ') ORDER BY id_order_state,id_lang')];
        foreach (ShopInit\demoInvoiceSettings($context) as $key => $value) {
            if (is_array($value)) {
                foreach (array_keys($value) as $langId) $data[$key][$langId] = (string) Configuration::get($key, $langId);
            } else $data[$key] = (string) Configuration::get($key);
        }
        foreach (['PS_INVOICE_START_NUMBER', 'PS_INVOICE_LAST_NUMBER'] as $key) $data[$key] = (string) Configuration::get($key);
        foreach (['orders', 'order_invoice', 'order_history'] as $table) $data[$table] = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table, false);
        return $data;
    };
    $before = $snapshot();
    ShopInit\configureOrders($context, true);
    invoiceTest($before === $snapshot(), 'Dry run modified configuration/history.');
    ShopInit\configureOrders($context);
    invoiceTest($before === $snapshot(), 'Configuration rerun changed IDs/counters/order histories.');

    $password = bin2hex(random_bytes(18));
    $customer = new Customer();
    $customer->firstname = 'Test'; $customer->lastname = 'Faktury';
    $customer->email = 'invoice-qa-' . bin2hex(random_bytes(8)) . '@example.test';
    $customer->passwd = Tools::hash($password); $customer->active = true;
    $customer->id_shop = $shopId; $customer->id_shop_group = (int) $context->shop->id_shop_group;
    $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    invoiceTest((bool) $customer->add(), 'Cannot create test customer.');
    $address = new Address();
    $address->id_customer = (int) $customer->id; $address->id_country = (int) Country::getByIso('PL');
    $address->firstname = $customer->firstname; $address->lastname = $customer->lastname;
    $address->alias = 'Test faktury'; $address->address1 = 'Testowa 1';
    $address->city = 'Gdańsk'; $address->postcode = '80-001'; $address->phone = '500000000';
    invoiceTest((bool) $address->add(), 'Cannot create test address.');
    $product = new Product();
    $product->id_shop_list = [$shopId];
    $product->reference = 'invoice-qa-' . bin2hex(random_bytes(6));
    foreach (Language::getLanguages(false) as $language) {
        $product->name[(int) $language['id_lang']] = 'Test faktury VAT - produkt demonstracyjny';
        $product->link_rewrite[(int) $language['id_lang']] = 'test-faktury-vat';
    }
    $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $product->id_tax_rules_group = ShopInit\taxGroup('23', $context);
    $product->price = '81.300813'; // 100.00 PLN gross at 23% VAT, using native rounding.
    $product->weight = 1; $product->active = true; $product->available_for_order = true; $product->visibility = 'none';
    invoiceTest((bool) $product->add() && (bool) $product->addToCategories([$product->id_category_default]), 'Cannot create test product.');
    StockAvailable::setQuantity((int) $product->id, 0, 4, $shopId, false);

    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_COOKIEFILE => '',
        CURLOPT_RESOLVE => ['localhost:443:' . gethostbyname('nginx'), 'localhost:80:' . gethostbyname('nginx')]]);
    $request = function (string $url, ?array $data = null, $handle = null) use ($curl): array {
        invoiceTest(parse_url($url, PHP_URL_HOST) === 'localhost', 'External URL blocked by test.');
        $handle ??= $curl;
        curl_setopt_array($handle, [CURLOPT_URL => $url, CURLOPT_POST => $data !== null]);
        if ($data !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        else curl_setopt($handle, CURLOPT_HTTPGET, true);
        $body = curl_exec($handle);
        invoiceTest($body !== false, 'HTTP request failed: ' . curl_error($handle));
        return ['body' => $body, 'status' => curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'url' => curl_getinfo($handle, CURLINFO_EFFECTIVE_URL)];
    };
    $login = $context->link->getPageLink('authentication', true);
    $token = invoiceHtml($request($login)['body'])->evaluate('string(//form[@id="login-form"]//input[@name="token"]/@value)');
    $request($login, ['email' => $customer->email, 'password' => $password, 'submitLogin' => 1, 'token' => $token]);
    $artifactDir = _PS_ROOT_DIR_ . '/var/import/invoice-qa';
    if (!is_dir($artifactDir)) invoiceTest(mkdir($artifactDir, 0775, true), 'Cannot create PDF test directory.');

    foreach (['ps_wirepayment' => 'PS_OS_BANKWIRE', 'ps_cashondelivery' => 'PS_OS_COD_VALIDATION'] as $moduleName => $setting) {
        $page = invoiceHtml($request($context->link->getProductLink((int) $product->id))['body']);
        $token = $page->evaluate('string(//form[@id="add-to-cart-or-refresh"]//input[@name="token"]/@value)');
        $added = json_decode($request($context->link->getPageLink('cart', true), ['token' => $token, 'id_product' => $product->id,
            'qty' => 1, 'add' => 1, 'ajax' => 1, 'action' => 'update'])['body'], true, 512, JSON_THROW_ON_ERROR);
        invoiceTest(empty($added['hasError']) && empty($added['errors']), 'Cannot add invoice test product.');
        $orderUrl = $context->link->getPageLink('order', true);
        $request($orderUrl);
        $shipping = invoiceHtml($request($orderUrl, ['confirm-addresses' => 1, 'id_address_delivery' => $address->id,
            'id_address_invoice' => $address->id, 'use_same_address' => 1])['body']);
        $radio = null;
        foreach ($shipping->query('//input[starts-with(@name,"delivery_option[")]') as $option) {
            if ($option->getAttribute('value') === ShopInit\projectCarrierId('STANDARD', $context) . ',') $radio = $option;
        }
        invoiceTest($radio !== null, 'InPost missing from checkout.');
        $payment = invoiceHtml($request($orderUrl, [$radio->getAttribute('name') => $radio->getAttribute('value'), 'confirmDeliveryOption' => 1])['body']);
        $option = $payment->query('//input[@name="payment-option" and @data-module-name="' . $moduleName . '"]')->item(0);
        invoiceTest($option !== null, 'Payment option missing: ' . $moduleName);
        $form = $payment->query('//*[@id="pay-with-' . $option->getAttribute('id') . '-form"]//form')->item(0);
        invoiceTest($form !== null, 'Native payment form missing.');
        $data = [];
        foreach ($payment->query('.//input[@name]', $form) as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
        foreach ($payment->query('//input[starts-with(@name,"conditions_to_approve")]') as $input) $data[$input->getAttribute('name')] = '1';
        $confirmation = $request(html_entity_decode($form->getAttribute('action'), ENT_QUOTES, 'UTF-8'), $data);
        $row = $db->getRow('SELECT id_order,id_cart FROM ' . _DB_PREFIX_ . 'orders WHERE id_customer=' . (int) $customer->id
            . " AND module='" . pSQL($moduleName) . "' ORDER BY id_order DESC");
        invoiceTest((bool) $row, 'Native checkout did not create order: ' . $moduleName);
        $orderId = (int) $row['id_order']; $orderIds[] = $orderId;
        $order = new Order($orderId);
        invoiceTest($confirmation['status'] === 200 && str_contains(strip_tags($confirmation['body']), $order->reference), 'Order confirmation missing reference.');
        invoiceTest((int) $order->current_state === $plan[$setting]['id'] && !(float) $order->total_paid_real, 'Order must start waiting and unpaid.');
        invoiceTest(abs((float) $order->total_products_wt - 100) < 0.001 && abs((float) $order->total_shipping_tax_incl - 15) < 0.001
            && abs((float) $order->total_paid_tax_incl - 115) < 0.001, 'Unexpected invoice test prices/VAT/shipping.');
        $detailUrl = $context->link->getPageLink('order-detail', true, $langId, ['id_order' => $orderId]);
        invoiceTest(str_contains(html_entity_decode(strip_tags($request($detailUrl)['body']), ENT_QUOTES, 'UTF-8'), $plan[$setting]['name']), 'Waiting status missing from customer order page.');
        $pdfUrl = $context->link->getPageLink('pdf-invoice', true, $langId, ['id_order' => $orderId]);
        invoiceTest(!(int) $order->invoice_number && count($order->getInvoicesCollection()) === 0
            && !str_starts_with($request($pdfUrl)['body'], '%PDF-'), 'Invoice available before payment acceptance.');

        // Exactly the same native operation as an administrator selecting a status.
        // Only the two orders created above can be transitioned by this test.
        $context->customer = $customer; $context->cart = new Cart((int) $row['id_cart']);
        $context->currency = new Currency($order->id_currency); $context->language = new Language($langId);
        $history = new OrderHistory(); $history->id_order = $orderId;
        $history->changeIdOrderState($plan['PS_OS_PAYMENT']['id'], $order, true);
        invoiceTest((bool) $history->addWithemail(true, false, $context), 'Cannot record payment acceptance and send local notification.');
        $order = new Order($orderId);
        invoiceTest((int) $order->current_state === $plan['PS_OS_PAYMENT']['id'] && (int) $order->invoice_number > 0
            && count($order->getInvoicesCollection()) === 1 && abs((float) $order->total_paid_real - 115) < 0.001, 'Paid state/invoice/payment not recorded.');
        $detail = $request($detailUrl);
        invoiceTest(str_contains(html_entity_decode(strip_tags($detail['body']), ENT_QUOTES, 'UTF-8'), 'Płatność zaakceptowana'), 'Paid status missing from customer order page.');
        $historyPage = invoiceHtml($request($context->link->getPageLink('history', true))['body']);
        $downloadFound = false;
        foreach ($historyPage->query('//a[@href]') as $link) {
            $url = html_entity_decode($link->getAttribute('href'), ENT_QUOTES, 'UTF-8');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, 'pdf-invoice') && (int) ($query['id_order'] ?? 0) === $orderId) $downloadFound = true;
        }
        invoiceTest($downloadFound, 'Invoice download link missing from customer history.');
        $pdf = $request($pdfUrl);
        invoiceTest($pdf['status'] === 200 && str_contains($pdf['type'], 'application/pdf') && str_starts_with($pdf['body'], '%PDF-')
            && str_contains(substr($pdf['body'], -100), '%%EOF'), 'Invoice download is not a complete PDF.');
        $path = $artifactDir . '/' . $moduleName . '-' . $orderId . '.pdf';
        invoiceTest(file_put_contents($path, $pdf['body']) !== false, 'Cannot save downloaded invoice.');
        $anonymous = curl_init();
        curl_setopt_array($anonymous, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RESOLVE => ['localhost:443:' . gethostbyname('nginx'), 'localhost:80:' . gethostbyname('nginx')]]);
        invoiceTest(!str_starts_with($request($pdfUrl, null, $anonymous)['body'], '%PDF-'), 'Anonymous browser downloaded a private invoice.');
        curl_close($anonymous);
        echo 'ORDER/PDF OK: ' . $moduleName . ', order ID ' . $orderId . ', pending -> payment accepted, 115.00 PLN gross; ' . $path . PHP_EOL;
    }
    echo "OK: both native checkout methods, waiting/paid states, invoice absence before payment, customer history links, PDF download and anonymous denial verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    // Keep demo orders/invoices for review, rather than deleting financial history.
    // Disable only this run's fixture account and product; source catalog stock is untouched.
    if ($product instanceof Product && $product->id) { $product->active = false; if (!$product->update()) $exitCode = 1; }
    if ($customer instanceof Customer && $customer->id) { $customer->active = false; if (!$customer->update()) $exitCode = 1; }
    if ($orderIds !== []) echo 'RETAINED QA ORDERS: ' . implode(', ', $orderIds) . ' (customer Test Faktury; fixture account/product disabled).' . PHP_EOL;
}
exit($exitCode);
