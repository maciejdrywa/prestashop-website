<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/content.php';

try {
    $context = ShopInit\boot();
    $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0,
        CURLOPT_RESOLVE=>['localhost:443:'.gethostbyname('nginx'), 'localhost:80:'.gethostbyname('nginx')]]);
    $fetch = function (string $url) use ($curl): array {
        if (parse_url($url, PHP_URL_HOST) !== 'localhost') throw new RuntimeException('Test only supports local Compose URLs.');
        curl_setopt($curl, CURLOPT_URL, $url);
        $html = curl_exec($curl);
        if ($html === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || !mb_check_encoding($html, 'UTF-8')) {
            throw new RuntimeException('Invalid page response: '.$url);
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return [$dom, new DOMXPath($dom)];
    };
    [$home,$homeXpath] = $fetch('https://localhost/');
    $footerNodes = $homeXpath->query('//*[@id="footer"]');
    if ($footerNodes->length !== 1) throw new RuntimeException('Footer missing from homepage.');
    $footerText = $footerNodes->item(0)->textContent;
    foreach (['Produkty','Informacje o sklepie'] as $heading) {
        if (!str_contains($footerText,$heading)) throw new RuntimeException('Missing Polish footer heading: '.$heading);
    }
    foreach (['Our company','Delivery','Legal Notice','Terms and conditions of use','About us','Secure payment'] as $oldText) {
        if (str_contains($footerText,$oldText)) throw new RuntimeException('Installer footer content remains: '.$oldText);
    }
    $pageIds = [];
    $internalLinks = [];
    foreach ($homeXpath->query('//*[@id="footer"]//a[starts-with(@id,"link-cms-page-") or starts-with(@id,"link-product-page-") or starts-with(@id,"link-static-page-")]') as $anchor) {
        $internalLinks[$anchor->getAttribute('href')] = true;
    }
    foreach (ShopInit\informationalPageDefinitions() as $key=>$definition) {
        $id = ShopInit\informationalPageId($key,$definition,$context);
        if (!$id || $id !== (int) Configuration::get('SHOP_INIT_CMS_'.strtoupper($key))) throw new RuntimeException('Missing persistent page mapping: '.$key);
        $pageIds[$key] = $id;
        $cms = new CMS($id,$languageId,(int)$context->shop->id);
        if (!$cms->active || $cms->meta_title !== $definition['title'] || $cms->link_rewrite !== $definition['slug']
            || str_contains($cms->content,'{{')) throw new RuntimeException('Invalid saved page: '.$key);
        $url = $context->link->getCMSLink($cms,$definition['slug'],true,$languageId,(int)$context->shop->id);
        $found = false;
        foreach ($homeXpath->query('//*[@id="footer"]//a') as $anchor) {
            if (trim($anchor->textContent) === $definition['title'] && $anchor->getAttribute('href') === $url) $found = true;
        }
        if (!$found) throw new RuntimeException('Required footer link is missing: '.$definition['title']);
        [$pageDom,$pageXpath] = $fetch($url);
        $heading = trim($pageXpath->evaluate('string(//*[@id="main"]//h1)'));
        if ($heading !== $definition['title']) throw new RuntimeException('Incorrect rendered page title: '.$key);
        $main = $pageXpath->query('//*[@id="main"]')->item(0);
        if (!$main || mb_strlen(trim($main->textContent)) < 200) throw new RuntimeException('Information page content is incomplete: '.$key);
        foreach (['Lorem ipsum','Here is the rule','Our company','Shipments and returns','With SSL'] as $oldText) {
            if (stripos($main->textContent,$oldText) !== false) throw new RuntimeException('Installer page text remains: '.$key);
        }
        foreach ($pageXpath->query('//*[@id="main"]//a[@href]') as $anchor) {
            $link = $anchor->getAttribute('href');
            if (parse_url($link,PHP_URL_HOST) === 'localhost') $internalLinks[$link] = true;
        }
        echo 'PAGE OK: '.$definition['title'].' — '.$url.PHP_EOL;
    }
    if (count(array_unique($pageIds)) !== 5 || (int) Configuration::get('PS_CONDITIONS_CMS_ID') !== $pageIds['terms']) {
        throw new RuntimeException('Page IDs overlap or checkout points to incorrect terms.');
    }
    if (!str_contains((string)Configuration::get('CUSTPRIV_MSG_AUTH',$languageId),'Polityka prywatności')) {
        throw new RuntimeException('Privacy form message is not linked to Polish content.');
    }
    foreach ($internalLinks as $url=>$unused) $fetch($url);
    [$registration,$registrationXpath] = $fetch($context->link->getPageLink('registration',true,$languageId));
    $registrationMain = $registrationXpath->query('//*[@id="main"]')->item(0);
    if (!$registrationMain || str_contains($registrationMain->textContent,'The personal data you provide')) {
        throw new RuntimeException('Installer privacy message remains in registration.');
    }
    $privacyLinked = false;
    foreach ($registrationXpath->query('//*[@id="main"]//a') as $anchor) {
        if (trim($anchor->textContent) === 'Polityka prywatności') $privacyLinked = true;
    }
    if (!$privacyLinked) throw new RuntimeException('Registration privacy link is missing.');
    echo 'OK: five mapped pages, Polish footer, terms/privacy references and '.count($internalLinks)." local cross-links verified through HTTP.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
