<?php
declare(strict_types=1);

/** Uruchamiać tylko na odrębnej instancji: zmienia i odtwarza uprawnienia klucza. */
require_once __DIR__ . '/../configure-webservice.php';
ShopInit\boot();

function expectWebservice(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    configureWebservice();
    expectWebservice(ShopInit\webserviceConfigurationPresent(), 'Konfiguracja nie została potwierdzona.');
    $before = file_get_contents(_PS_ROOT_DIR_ . '/.htaccess');
    configureWebservice();
    expectWebservice(file_get_contents(_PS_ROOT_DIR_ . '/.htaccess') === $before, 'Nagłówek nie jest idempotentny.');
    expectWebservice(!str_contains($before, _COOKIE_KEY_), 'Nagłówek ujawnia sekret.');
    $id = (int) WebserviceKey::getIdFromKey(getenv('PRESTASHOP_API_KEY'));
    $permissions = ShopInit\webservicePermissions();
    $permissions['categories']['DELETE'] = 1;
    WebserviceKey::setPermissionForAccount($id, $permissions);
    expectWebservice(!ShopInit\webserviceConfigurationPresent(), 'Nadmiarowe uprawnienie nie zostało wykryte.');
    configureWebservice();
    $permissions = ShopInit\webservicePermissions();
    unset($permissions['languages']);
    WebserviceKey::setPermissionForAccount($id, $permissions);
    expectWebservice(!ShopInit\webserviceConfigurationPresent(), 'Brak uprawnienia nie został wykryty.');
    configureWebservice();
    Db::getInstance()->delete('webservice_account_shop', 'id_webservice_account=' . $id);
    expectWebservice(!ShopInit\webserviceConfigurationPresent(), 'Brak sklepu nie został wykryty.');
    configureWebservice();
    file_put_contents(_PS_ROOT_DIR_ . '/.htaccess', str_replace(ShopInit\webserviceIdentityBlock(), '', $before));
    expectWebservice(!ShopInit\webserviceConfigurationPresent(), 'Brak nagłówka nie został wykryty.');
    configureWebservice();
    echo "OK: macierz, przypisanie sklepu, dryf uprawnień i idempotencja konfiguracji.\n";
} finally {
    configureWebservice();
}
