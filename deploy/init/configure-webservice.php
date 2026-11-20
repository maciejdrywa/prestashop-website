<?php
declare(strict_types=1);

/**
 * Automatyczna konfiguracja PrestaShop Webservice API.
 *
 * - Włącza obsługę Webservice w konfiguracji sklepu.
 * - Tworzy lub aktualizuje klucz API (zgodny ze zmienną środowiskową PRESTASHOP_API_KEY).
 * - Nadaje uprawnienia wyłącznie do zasobów niezbędnych do importu katalogu (Least Privilege).
 */

require_once __DIR__ . '/lib/prestashop.php';
require_once __DIR__ . '/lib/webservice.php';

function configureWebservice(): void
{
    echo "[CONFIG] Konfiguracja PrestaShop Webservice API...\n";

    // Jezeli klucz API istnieje to go pobieramy jezeli nie to tworzymy nowy
    $apiKey = getenv('PRESTASHOP_API_KEY') ?: '';
    if (empty($apiKey)) {
        throw new RuntimeException("Błąd: Zmienna PRESTASHOP_API_KEY nie jest ustawiona. Skonfiguruj 32-znakowy klucz w pliku .env przed uruchomieniem inicjalizacji sklepu.");
    }

    if (strlen($apiKey) !== 32 || !ctype_alnum($apiKey)) {
        throw new RuntimeException("Klucz API PrestaShop musi mieć dokładnie 32 znaki alfanumeryczne (otrzymano: " . strlen($apiKey) . ").");
    }

    // Zapobiega PHP Warning przy wywołaniu $wsKey->save() w trybie CLI
    if (!Validate::isLoadedObject(Context::getContext()->employee)) {
        Context::getContext()->employee = new Employee(1);
    }

    // Sprawdzenie, czy klucz już istnieje w bazie
    $keyId = (int) Db::getInstance()->getValue(
        'SELECT id_webservice_account FROM ' . _DB_PREFIX_ . 'webservice_account WHERE `key` = \'' . pSQL($apiKey) . '\''
    );

    $wsKey = new WebserviceKey($keyId ?: null);
    $wsKey->key = $apiKey;
    $wsKey->description = 'Klucz automatycznego importu danych katalogowych';
    $wsKey->active = true;

    if (!$wsKey->save()) {
        throw new RuntimeException("Nie udało się zapisać klucza Webservice w bazie.");
    }

    if (!Configuration::updateValue('PS_WEBSERVICE', 1)
        || !Configuration::updateValue('PS_WEBSERVICE_CGI_HOST', 0)) {
        throw new RuntimeException('Nie można włączyć Webservice.');
    }
    if (!WebserviceKey::setPermissionForAccount($wsKey->id, ShopInit\webservicePermissions())) {
        throw new RuntimeException('Nie można zapisać uprawnień Webservice.');
    }

    // Powiązanie z aktywnym sklepem
    $shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
    if (!Db::getInstance()->delete('webservice_account_shop', 'id_webservice_account = ' . (int) $wsKey->id)
        || !Db::getInstance()->insert('webservice_account_shop', [
            'id_webservice_account' => (int) $wsKey->id, 'id_shop' => $shopId,
        ])) {
        throw new RuntimeException('Nie można przypisać klucza do sklepu.');
    }
    ShopInit\writeWebserviceIdentityHeader();
    if (!ShopInit\webserviceConfigurationPresent()) {
        throw new RuntimeException('Weryfikacja konfiguracji Webservice nie powiodła się.');
    }

    echo "[CONFIG] Webservice API włączone. Klucz API skonfigurowany z uprawnieniami Least Privilege.\n";
}

// Jeśli skrypt jest uruchamiany bezpośrednio z CLI
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    ShopInit\boot();
    configureWebservice();
}
