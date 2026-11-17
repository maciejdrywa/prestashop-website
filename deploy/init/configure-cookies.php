<?php
declare(strict_types=1);

/**
 * Instalacja informacji o cookies i powiązanie z polityką prywatności.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-cookies.php
 *
 * Wymaga aktywnej strony privacy i źródeł modułu w obrazie. Uruchamia kernel Symfony potrzebny przy
 * pierwszej instalacji modułu.
 *
 * Synchronizuje pliki modules/shopcookieinfo, instaluje i włącza moduł, rejestruje hooki oraz zapisuje ID
 * strony CMS.
 */

require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/cookies.php';

try {
    if (count($argv) !== 1) throw new RuntimeException('Usage: php configure-cookies.php');
    $context = ShopInit\boot();
    // Pierwsza instalacja modułu tłumaczy tabele przez kontener Symfony.
    global $kernel;
    require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
    $kernel = new AppKernel('prod', false);
    $kernel->boot();
    $definition = ShopInit\informationalPageDefinitions()['privacy'];
    $privacyId = ShopInit\informationalPageId('privacy',$definition,$context);
    if (!$privacyId || !(new CMS($privacyId))->active) throw new RuntimeException('Prepare the privacy page with configure-content.php first.');
    echo "[1/3] Aktualizacja plików modułu cookies...\n";
    $changed = ShopInit\installCookieModuleFiles();
    echo "[2/3] Enabling notice and linking Polish privacy page...\n";
    $module = Module::getInstanceByName('shopcookieinfo');
    if (!$module || (!Module::isInstalled('shopcookieinfo') && !$module->install()) || !$module->enable()) throw new RuntimeException('Cannot install/enable cookie notice module.');
    // Oba hooki są potrzebne niezależnie od samej instalacji modułu. Pierwszy
    // renderuje komunikat, drugi dołącza jego zasoby przez front controller.
    foreach (['displayAfterBodyOpeningTag','actionFrontControllerSetMedia'] as $hook) {
        if (!$module->registerHook($hook)) throw new RuntimeException('Cannot register cookie module hook: '.$hook);
    }
    ShopInit\saveShopSetting('SHOP_COOKIE_INFO_CMS_ID',$privacyId,$context);
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
