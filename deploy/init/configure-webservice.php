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

function configureWebservice(): void
{
    echo "[CONFIG] Konfiguracja PrestaShop Webservice API...\n";

    //  Włączenie Webservice API w PS
    Configuration::updateValue('PS_WEBSERVICE', 1);
    Configuration::updateValue('PS_WEBSERVICE_CGI_HOST', 0);

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

    // Ograniczone uprawnienia
    // Uprawnienia nadajemy TYLKO do zasobów potrzebnych przy imporcie produktów i kategorii.
    // Nie dajemy dostępu do: customers, orders
    $importResources = [
        'categories',
        'products',
        'combinations',
        'product_options',
        'product_option_values',
        'product_features',
        'product_feature_values',
        'images',
        'stock_availables',
        'manufacturers',
        'taxes',
    ];

    $permissions = [];
    foreach ($importResources as $resource) {
        $permissions[$resource] = [
            'GET' => 1,    // Czy zasób istnieje
            'POST' => 1,   // Tworzenie nowego zasobu
            'PUT' => 1,    // Aktualizacja istniejącego zasobu
            'DELETE' => 1, // Usuwanie
            'HEAD' => 1,   // Szybkie sprawdzanie nagłówków
        ];
    }

    // Usunięcie starych uprawnień i zapisanie nowych
    WebserviceKey::setPermissionForAccount($wsKey->id, $permissions);

    // Powiązanie z aktywnym sklepem
    $shopId = (int) Configuration::get('PS_SHOP_DEFAULT');
    Db::getInstance()->execute(
        'REPLACE INTO ' . _DB_PREFIX_ . 'webservice_account_shop (id_webservice_account, id_shop) VALUES (' . (int)$wsKey->id . ', ' . (int)$shopId . ')'
    );

    echo "[CONFIG] Webservice API włączone. Klucz API skonfigurowany z uprawnieniami Least Privilege.\n";
}

// Jeśli skrypt jest uruchamiany bezpośrednio z CLI
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    ShopInit\boot();
    configureWebservice();
}
