<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/module-texts.php';

$customer=null;
$exitCode=0;
try {
    $context=ShopInit\boot();
    $languageId=(int)Configuration::get('PS_LANG_DEFAULT');
    $curl=curl_init();
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_COOKIEFILE=>'',
        CURLOPT_RESOLVE=>['localhost:443:'.gethostbyname('nginx'),'localhost:80:'.gethostbyname('nginx')]]);
    $request=function(string $url,?array $data=null) use($curl):string {
        if(parse_url($url,PHP_URL_HOST)!=='localhost') throw new RuntimeException('This test requires the local Compose shop.');
        curl_setopt_array($curl,[CURLOPT_URL=>$url,CURLOPT_POST=>$data!==null]);
        if($data!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($data));
        else curl_setopt($curl,CURLOPT_HTTPGET,true);
        $body=curl_exec($curl);
        if($body===false || curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200 || !mb_check_encoding($body,'UTF-8')) throw new RuntimeException('Module HTTP request failed.');
        return $body;
    };
    $parse=function(string $html):DOMXPath {
        $dom=new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new DOMXPath($dom);
    };
    $query=new DbQuery();
    $query->select('p.id_product AS id')->from('product','p');
    $query->innerJoin('product_shop','s','s.id_product=p.id_product');
    $query->where("p.reference='pandomu-5'")->where('s.active=1')->where('s.id_shop='.(int)$context->shop->id);
    $productId=ShopInit\uniqueId($query);
    if(!$productId) throw new RuntimeException('Import and activate source product 5 before this test.');
    $productUrl=$context->link->getProductLink($productId);
    $productHtml=$request($productUrl);
    $productXpath=$parse($productHtml);
    $cartHtml=$request($context->link->getPageLink('cart',true,null,['action'=>'show']));
    foreach(['product'=>$productHtml,'cart'=>$cartHtml] as $name=>$html) {
        foreach(ShopInit\reassuranceDefinitions() as $definition) {
            if(!str_contains($html,$definition['title']) || !str_contains($html,$definition['description'])) throw new RuntimeException('Polish information block missing from '.$name);
            $pageId=(int)Configuration::get('SHOP_INIT_CMS_'.strtoupper($definition['page']));
            $url=$context->link->getCMSLink($pageId,null,true,$languageId,(int)$context->shop->id);
            if(!str_contains($html,$url)) throw new RuntimeException('Policy block CMS destination is missing.');
            $request($url);
        }
        foreach(['Security policy','Delivery policy','Return policy','edit with the Customer Reassurance module','My wishlists','Create new list'] as $english) {
            if(str_contains($html,$english)) throw new RuntimeException('Untranslated module wording remains on '.$name.': '.$english);
        }
    }
    if(str_contains($productHtml,'shop-reassurance__card') || str_contains($cartHtml,'shop-reassurance__card')) throw new RuntimeException('Card layout was not removed.');
    foreach($productXpath->query('//link[@rel="stylesheet"]') as $link) {
        $url=$link->getAttribute('href');
        if(parse_url($url,PHP_URL_HOST)==='localhost' && str_contains($request($url),'.shop-reassurance__card')) throw new RuntimeException('Card styles remain loaded.');
    }
    $criteria=$productXpath->query('//*[@id="criterions_list"]//label');
    $qualityFound=false;
    foreach($criteria as $label) {
        if(trim($label->textContent)==='Jakość:') $qualityFound=true;
        if(str_contains($label->textContent,'Quality')) throw new RuntimeException('English review criterion remains.');
    }
    if(!$qualityFound) throw new RuntimeException('Translated review criterion is missing.');
    echo "OK: product/cart policy texts and CMS links, review form and guest wishlist dialogs.\n";
    $actionUrl=$context->link->getModuleLink('blockwishlist','action',[],true);
    $response=json_decode($request($actionUrl,['action'=>'createNewWishList','params'=>['name'=>'Próba']]),true,512,JSON_THROW_ON_ERROR);
    if(($response['success']??true)!==false || ($response['message']??'')!=='Nie jesteś zalogowany/a') throw new RuntimeException('Guest wishlist message is not Polish.');

    // Only this disposable account/list is created; no review or order is posted.
    $password=bin2hex(random_bytes(18));
    $customer=new Customer();
    $customer->firstname='Próba';
    $customer->lastname='Tłumaczeń';
    $customer->email='module-l10n-'.bin2hex(random_bytes(8)).'@example.test';
    $customer->passwd=Tools::hash($password);
    $customer->active=true;
    $customer->id_shop=(int)$context->shop->id;
    $customer->id_shop_group=(int)$context->shop->id_shop_group;
    $customer->id_default_group=(int)Configuration::get('PS_CUSTOMER_GROUP');
    if(!$customer->add()) throw new RuntimeException('Cannot create disposable module-test customer.');
    $loginUrl=$context->link->getPageLink('authentication',true);
    $loginXpath=$parse($request($loginUrl));
    $token=$loginXpath->evaluate('string(//form[@id="login-form"]//input[@name="token"]/@value)');
    $request($loginUrl,['email'=>$customer->email,'password'=>$password,'submitLogin'=>1,'token'=>$token]);
    $listsUrl=$context->link->getModuleLink('blockwishlist','lists',[],true);
    $listsHtml=$request($listsUrl);
    if(!str_contains($listsHtml,'Moje listy życzeń') || !str_contains($listsHtml,'Utwórz nową listę')) throw new RuntimeException('Logged-in wishlist page is not translated, or login failed.');
    $action=function(string $name,array $params,string $expected) use($request,$actionUrl):array {
        $response=json_decode($request($actionUrl,['action'=>$name,'params'=>$params]),true,512,JSON_THROW_ON_ERROR);
        if(($response['success']??false)!==true || ($response['message']??'')!==$expected) throw new RuntimeException('Unexpected wishlist response: '.$name);
        return $response;
    };
    $created=$action('createNewWishList',['name'=>'Próbna lista życzeń'],'Lista została utworzona prawidłowo');
    $listId=(int)$created['datas']['id_wishlist'];
    $listsHtml=$request($listsUrl);
    foreach(['Create new list','My wishlists'] as $english) {
        if(str_contains($listsHtml,$english)) throw new RuntimeException('Untranslated wishlist modal remains: '.$english);
    }
    $action('renameWishList',['idWishList'=>$listId,'name'=>'Zmieniona lista życzeń'],'Zmieniono nazwę listy');
    $action('deleteWishList',['idWishList'=>$listId],'Lista została usunięta');
    echo "OK: logged-in wishlist page and Polish create/rename/delete API messages verified.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'TEST FAILED: '.$error->getMessage().PHP_EOL);
    $exitCode=1;
} finally {
    if($customer instanceof Customer && $customer->id) {
        require_once _PS_MODULE_DIR_.'blockwishlist/classes/WishList.php';
        foreach(Db::getInstance()->executeS('SELECT id_wishlist FROM '._DB_PREFIX_.'wishlist WHERE id_customer='.(int)$customer->id) as $row) {
            if(!(new WishList((int)$row['id_wishlist']))->delete()) $exitCode=1;
        }
        foreach(Db::getInstance()->executeS('SELECT id_cart FROM '._DB_PREFIX_.'cart WHERE id_customer='.(int)$customer->id) as $row) {
            if(!(new Cart((int)$row['id_cart']))->delete()) $exitCode=1;
        }
        if(!$customer->delete()) { fwrite(STDERR,"Cannot remove disposable module-test customer.\n"); $exitCode=1; }
        echo "CLEANUP: disposable customer, lists and carts removed.\n";
    }
}
exit($exitCode);
