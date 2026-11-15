<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/payments.php';

$customer=null;
$address=null;
$exitCode=0;
try {
    $context=ShopInit\boot();
    $inputs=ShopInit\paymentInputs($context);
    $expected=ShopInit\allowedPaymentModules();
    $actual=ShopInit\enabledPaymentModules($context);
    sort($expected); sort($actual);
    if($actual!==$expected) throw new RuntimeException('Checkout payment allowlist is not configured.');
    foreach($expected as $name) {
        $module=Module::getInstanceByName($name);
        foreach(['module_country'=>['id_country',[$inputs['country']]],'module_currency'=>['id_currency',[$inputs['currency']]],
            'module_group'=>['id_group',$inputs['groups']],'module_carrier'=>['id_reference',$inputs['carriers']]] as $table=>$set) {
            $rows=Db::getInstance()->executeS('SELECT '.$set[0].' AS value FROM '._DB_PREFIX_.$table.' WHERE id_module='.(int)$module->id.' AND id_shop='.(int)$context->shop->id);
            $values=array_map(fn($row)=>(int)$row['value'],$rows);
            $wanted=$set[1]; sort($wanted); sort($values);
            if($values!==$wanted) throw new RuntimeException('Missing/duplicate payment permissions: '.$name.' '.$table);
        }
    }
    $candidate=Db::getInstance()->getRow('SELECT p.id_product,s.id_product_attribute FROM '._DB_PREFIX_.'product p INNER JOIN '
        ._DB_PREFIX_.'product_shop ps ON ps.id_product=p.id_product INNER JOIN '._DB_PREFIX_.'stock_available s ON s.id_product=p.id_product'
        ." WHERE p.reference LIKE 'pandomu-%' AND ps.active=1 AND ps.id_shop=".(int)$context->shop->id.' AND s.id_shop=ps.id_shop'
        .' AND s.quantity>0 AND p.is_virtual=0 ORDER BY p.id_product,s.id_product_attribute DESC');
    if(!$candidate) throw new RuntimeException('Import an available physical product before the checkout test.');
    $password=bin2hex(random_bytes(18));
    $customer=new Customer();
    $customer->firstname='Próba'; $customer->lastname='Płatności';
    $customer->email='payments-'.bin2hex(random_bytes(8)).'@example.test';
    $customer->passwd=Tools::hash($password);
    $customer->active=true;
    $customer->id_shop=(int)$context->shop->id; $customer->id_shop_group=(int)$context->shop->id_shop_group;
    $customer->id_default_group=(int)Configuration::get('PS_CUSTOMER_GROUP');
    if(!$customer->add()) throw new RuntimeException('Cannot create disposable payment-test customer.');
    $address=new Address();
    $address->id_customer=(int)$customer->id; $address->id_country=$inputs['country'];
    $address->firstname=$customer->firstname; $address->lastname=$customer->lastname;
    $address->alias='Adres testowy'; $address->address1='Ulica Testowa 1';
    $address->city='Gdańsk'; $address->postcode='80-001'; $address->phone='500000000';
    if(!$address->add()) throw new RuntimeException('Cannot create disposable test address.');

    $curl=curl_init();
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25,
        CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_COOKIEFILE=>'',
        CURLOPT_RESOLVE=>['localhost:443:'.gethostbyname('nginx'),'localhost:80:'.gethostbyname('nginx')]]);
    $request=function(string $url,?array $data=null) use($curl):string {
        if(parse_url($url,PHP_URL_HOST)!=='localhost') throw new RuntimeException('Test requires local Compose URLs.');
        curl_setopt_array($curl,[CURLOPT_URL=>$url,CURLOPT_POST=>$data!==null]);
        if($data!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data));
        else curl_setopt($curl,CURLOPT_HTTPGET,true);
        $body=curl_exec($curl);
        if($body===false || curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) throw new RuntimeException('Payment checkout HTTP failed.');
        return $body;
    };
    $parse=function(string $html):DOMXPath {
        $dom=new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">'.$html); return new DOMXPath($dom);
    };
    $login=$context->link->getPageLink('authentication',true);
    $loginXpath=$parse($request($login));
    $token=$loginXpath->evaluate('string(//form[@id="login-form"]//input[@name="token"]/@value)');
    $request($login,['email'=>$customer->email,'password'=>$password,'submitLogin'=>1,'token'=>$token]);
    $product=$parse($request($context->link->getProductLink((int)$candidate['id_product'])));
    $token=$product->evaluate('string(//form[@id="add-to-cart-or-refresh"]//input[@name="token"]/@value)');
    $cartResponse=json_decode($request($context->link->getPageLink('cart',true),['token'=>$token,
        'id_product'=>$candidate['id_product'],'id_product_attribute'=>$candidate['id_product_attribute'],'qty'=>1,'add'=>1,'ajax'=>1,'action'=>'update']),true,512,JSON_THROW_ON_ERROR);
    if(!empty($cartResponse['hasError']) || !empty($cartResponse['errors'])) throw new RuntimeException('Cannot add the test product to cart.');
    $orderUrl=$context->link->getPageLink('order',true);
    $request($orderUrl);
    $shipping=$parse($request($orderUrl,['confirm-addresses'=>1,'id_address_delivery'=>$address->id,
        'id_address_invoice'=>$address->id,'use_same_address'=>1]));
    $radio=$shipping->query('//input[starts-with(@name,"delivery_option[")]')->item(0);
    if(!$radio) throw new RuntimeException('No delivery option for the test address.');
    $paymentHtml=$request($orderUrl,[$radio->getAttribute('name')=>$radio->getAttribute('value'),'confirmDeliveryOption'=>1]);
    $payment=$parse($paymentHtml);
    $names=[];
    foreach($payment->query('//input[@name="payment-option"]') as $input) $names[]=$input->getAttribute('data-module-name');
    sort($names);
    if($names!==$expected) throw new RuntimeException('Rendered checkout does not show exactly bank transfer and cash on delivery: '.implode(', ',$names));
    if(!str_contains(html_entity_decode(strip_tags($paymentHtml),ENT_QUOTES|ENT_HTML5,'UTF-8'),'nie wykonuj rzeczywistego przelewu')) {
        throw new RuntimeException('Test-only bank transfer instructions are missing.');
    }
    foreach($payment->query('//input[@name="payment-option"]') as $input) {
        $form=$payment->query('//*[@id="pay-with-'.$input->getAttribute('id').'-form"]//form')->item(0);
        if($form && parse_url($form->getAttribute('action'),PHP_URL_HOST)!=='localhost') throw new RuntimeException('Payment form points outside the test shop.');
    }
    $pageId=(int)Configuration::get('SHOP_INIT_CMS_PAYMENTS');
    $cms=new CMS($pageId,(int)Configuration::get('PS_LANG_DEFAULT'),(int)$context->shop->id);
    if(str_contains(strip_tags($cms->content),'Czek (testowy)')) throw new RuntimeException('Removed cheque option remains on the information page.');
    echo "OK: PL/PLN and customer-group/carrier permissions verified; checkout renders only bank transfer and cash on delivery with test instructions. No order submitted.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'TEST FAILED: '.$error->getMessage().PHP_EOL);
    $exitCode=1;
} finally {
    if($customer instanceof Customer && $customer->id) {
        foreach(Db::getInstance()->executeS('SELECT id_cart FROM '._DB_PREFIX_.'cart WHERE id_customer='.(int)$customer->id) as $row) {
            if(!(new Cart((int)$row['id_cart']))->delete()) $exitCode=1;
        }
        if($address instanceof Address && $address->id && !$address->delete()) $exitCode=1;
        if(!$customer->delete()) $exitCode=1;
        echo "CLEANUP: disposable test account, address and carts removed.\n";
    }
}
exit($exitCode);
