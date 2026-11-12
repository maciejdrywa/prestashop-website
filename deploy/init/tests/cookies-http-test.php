<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/content.php';

try {
    $context=ShopInit\boot();
    $module=Module::getInstanceByName('shopcookieinfo');
    if(!$module || !Module::isEnabled('shopcookieinfo')) throw new RuntimeException('Cookie information module is not enabled.');
    if((int)Db::getInstance()->getValue('SELECT COUNT(*) FROM '._DB_PREFIX_."module WHERE name='shopcookieinfo'")!==1) throw new RuntimeException('Cookie module installation is duplicated.');
    $cookieName=$module->noticeCookieName();
    $curl=curl_init();
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,
        CURLOPT_RESOLVE=>['localhost:443:'.gethostbyname('nginx'),'localhost:80:'.gethostbyname('nginx')]]);
    $fetch=function(string $url,string $cookie='') use($curl):string {
        if(parse_url($url,PHP_URL_HOST)!=='localhost') throw new RuntimeException('This test requires the local Compose shop.');
        curl_setopt_array($curl,[CURLOPT_URL=>$url,CURLOPT_COOKIE=>$cookie]);
        $body=curl_exec($curl);
        if($body===false || curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) throw new RuntimeException('Cookie notice HTTP request failed.');
        return $body;
    };
    $parse=function(string $html):DOMXPath {
        $dom=new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new DOMXPath($dom);
    };
    $privacyId=(int)Configuration::get('SHOP_COOKIE_INFO_CMS_ID');
    $privacyUrl=$context->link->getCMSLink($privacyId,null,true);
    foreach(['home'=>'https://localhost/', 'registration'=>$context->link->getPageLink('registration',true),
        'cart'=>$context->link->getPageLink('cart',true,null,['action'=>'show']), 'privacy'=>$privacyUrl] as $name=>$url) {
        $html=$fetch($url);
        $xpath=$parse($html);
        $notices=$xpath->query('//*[@id="shop-cookie-info"]');
        if($notices->length!==1) throw new RuntimeException('Expected one visible cookie notice on '.$name);
        $notice=$notices->item(0);
        if($notice->hasAttribute('hidden') || $notice->getAttribute('data-cookie-max-age')!=='15552000'
            || !str_contains($notice->textContent,'Pliki cookies.')) throw new RuntimeException('Cookie notice content/lifetime is incorrect.');
        $button=$xpath->query('.//button[@data-cookie-dismiss]',$notice)->item(0);
        if(!$button || trim($button->textContent)!=='Rozumiem' || $button->getAttribute('type')!=='button') throw new RuntimeException('Cookie dismiss button is missing or submits a form.');
        if($xpath->evaluate('string(.//a/@href)',$notice)!==$privacyUrl) throw new RuntimeException('Incorrect privacy link in cookie notice.');
        $dismissed=$parse($fetch($url,$cookieName.'=1'));
        if($dismissed->query('//*[@id="shop-cookie-info"]')->length!==0) throw new RuntimeException('Cookie acknowledgement is not respected on '.$name);
        echo 'OK: '.$name.' shows notice initially and hides it after acknowledgement.'.PHP_EOL;
    }
    $home=$parse($fetch('https://localhost/'));
    $cssFound=$jsFound=false;
    foreach($home->query('//link[@rel="stylesheet"]/@href') as $attribute) {
        $url=$attribute->nodeValue;
        if(parse_url($url,PHP_URL_HOST)==='localhost' && str_contains($fetch($url),'.shop-cookie-info')) $cssFound=true;
    }
    foreach($home->query('//script[@src]/@src') as $attribute) {
        $url=$attribute->nodeValue;
        if(parse_url($url,PHP_URL_HOST)==='localhost' && str_contains($fetch($url),'data-cookie-dismiss')) $jsFound=true;
    }
    if(!$cssFound || !$jsFound) throw new RuntimeException('Cookie module CSS or JavaScript is not loaded.');
    if(!str_contains($fetch($privacyUrl),'180 dni')) throw new RuntimeException('Privacy page does not describe acknowledgement storage.');
    echo "OK: working privacy link, module styles/script and acknowledgement persistence verified via HTTP. No customer/order changes.\n";
} catch(Throwable $error) {
    fwrite(STDERR,'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
