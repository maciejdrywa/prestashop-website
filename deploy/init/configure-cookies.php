<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/content.php';

try {
    if (count($argv) !== 1) throw new RuntimeException('Usage: php configure-cookies.php');
    $context = ShopInit\boot();
    $definition = ShopInit\informationalPageDefinitions()['privacy'];
    $privacyId = ShopInit\informationalPageId('privacy',$definition,$context);
    if (!$privacyId || !(new CMS($privacyId))->active) throw new RuntimeException('Prepare the privacy page with configure-content.php first.');
    $files = ['shopcookieinfo.php','views/css/notice.css','views/js/notice.js'];
    $source = '/opt/shop-modules/shopcookieinfo/';
    foreach ($files as $file) {
        if (!is_readable($source.$file)) throw new RuntimeException('Cookie module source missing: '.$file.'. Rebuild the shop image.');
    }
    echo "[1/3] Installing/updating cookie notice module files...\n";
    $changed = false;
    foreach ($files as $file) {
        $target = _PS_MODULE_DIR_.'shopcookieinfo/'.$file;
        if (is_file($target) && hash_file('sha256',$target) === hash_file('sha256',$source.$file)) continue;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target),0775,true) && !is_dir(dirname($target))) throw new RuntimeException('Cannot create cookie module directory.');
        if (!copy($source.$file,$target)) throw new RuntimeException('Cannot install cookie module file: '.$file);
        $changed = true;
    }
    echo "[2/3] Enabling notice and linking Polish privacy page...\n";
    $module = Module::getInstanceByName('shopcookieinfo');
    if (!$module || (!Module::isInstalled('shopcookieinfo') && !$module->install()) || !$module->enable()) throw new RuntimeException('Cannot install/enable cookie notice module.');
    foreach (['displayAfterBodyOpeningTag','actionFrontControllerSetMedia'] as $hook) {
        if (!$module->registerHook($hook)) throw new RuntimeException('Cannot register cookie module hook: '.$hook);
    }
    ShopInit\contentSetting('SHOP_COOKIE_INFO_CMS_ID',$privacyId,$context);
    Cache::clean('Module::isEnabledshopcookieinfo');
    if (!Module::isEnabled('shopcookieinfo')) throw new RuntimeException('Cookie notice module is not enabled.');
    echo "[3/3] Clearing notice caches...\n";
    if ($changed) Media::clearCache();
    Tools::clearSmartyCache();
    echo "OK: Polish cookie notice enabled; privacy page ID ".$privacyId."; dismissal remembered for 180 days.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'ERROR: cookie notice configuration failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
