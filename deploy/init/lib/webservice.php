<?php
declare(strict_types=1);

namespace ShopInit;

/** Macierz metod używanych przez import. Klucz nigdy nie usuwa rekordów. */
function webservicePermissions(): array
{
    $permissions = [];
    foreach (['categories', 'products', 'combinations', 'product_options', 'product_option_values',
        'product_features', 'product_feature_values', 'images'] as $resource) {
        $permissions[$resource] = ['GET' => 1, 'POST' => 1, 'PUT' => 1];
    }
    $permissions['stock_availables'] = ['GET' => 1, 'PUT' => 1];
    foreach (['languages', 'shops', 'currencies', 'countries', 'taxes', 'tax_rules', 'tax_rule_groups'] as $resource) {
        $permissions[$resource] = ['GET' => 1];
    }
    return $permissions;
}

/** Publiczny odcisk nie ujawnia sekretu, a zmienia się po ponownej instalacji. */
function webserviceInstanceIdentity(): string
{
    return hash_hmac('sha256', _DB_NAME_ . '|' . _DB_PREFIX_ . '|' . (int) \Configuration::get('PS_SHOP_DEFAULT'), _COOKIE_KEY_);
}

function webserviceIdentityBlock(): string
{
    return "# BEGIN catalog-import-identity\n<IfModule mod_headers.c>\n"
        . 'Header always set X-PrestaShop-Import-Instance "' . webserviceInstanceIdentity() . "\"\n"
        . "</IfModule>\n# END catalog-import-identity\n";
}

/** Zachowuje reguły PrestaShop, dodając blok poza sekcją generowaną przez sklep. */
function writeWebserviceIdentityHeader(): void
{
    $path = _PS_ROOT_DIR_ . '/.htaccess';
    $previous = is_file($path) ? file_get_contents($path) : '';
    if ($previous === false) throw new \RuntimeException('Nie można odczytać .htaccess.');
    $content = preg_replace('/# BEGIN catalog-import-identity\n.*?# END catalog-import-identity\n?/s', '', $previous);
    if ($content === null) throw new \RuntimeException('Nie można przygotować nagłówka tożsamości API.');
    $temporary = tempnam(dirname($path), '.webservice-identity-');
    if ($temporary === false) throw new \RuntimeException('Nie można przygotować pliku .htaccess.');
    try {
        $expected = rtrim($content) . "\n" . webserviceIdentityBlock();
        $mode = is_file($path) ? (fileperms($path) & 0777) : 0644;
        if (file_put_contents($temporary, $expected) !== strlen($expected)
            || !chmod($temporary, $mode) || !rename($temporary, $path)
            || file_get_contents($path) !== $expected) {
            throw new \RuntimeException('Nie można atomowo zapisać nagłówka tożsamości API.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

/** Kontrola aktywności, całej macierzy, przypisania sklepu i publicznego odcisku. */
function webserviceConfigurationPresent(): bool
{
    $key = getenv('PRESTASHOP_API_KEY') ?: '';
    if (!preg_match('/^[a-zA-Z0-9]{32}$/D', $key) || (int) \Configuration::get('PS_WEBSERVICE') !== 1
        || (int) \Configuration::get('PS_WEBSERVICE_CGI_HOST') !== 0) return false;
    $db = \Db::getInstance();
    $id = (int) $db->getValue('SELECT id_webservice_account FROM ' . _DB_PREFIX_
        . "webservice_account WHERE `key`='" . \pSQL($key) . "' AND active=1");
    if (!$id) return false;
    $rows = $db->executeS('SELECT resource, method FROM ' . _DB_PREFIX_ . 'webservice_permission WHERE id_webservice_account=' . $id);
    if ($rows === false) return false;
    $actual = [];
    foreach ($rows as $row) $actual[] = $row['resource'] . ':' . $row['method'];
    $expected = [];
    foreach (webservicePermissions() as $resource => $methods) {
        foreach ($methods as $method => $enabled) if ($enabled) $expected[] = $resource . ':' . $method;
    }
    sort($actual); sort($expected);
    $shops = $db->executeS('SELECT id_shop FROM ' . _DB_PREFIX_ . 'webservice_account_shop WHERE id_webservice_account=' . $id);
    $path = _PS_ROOT_DIR_ . '/.htaccess';
    return $actual === $expected && is_array($shops) && count($shops) === 1
        && (int) $shops[0]['id_shop'] === (int) \Configuration::get('PS_SHOP_DEFAULT')
        && is_readable($path) && str_contains((string) file_get_contents($path), webserviceIdentityBlock());
}
