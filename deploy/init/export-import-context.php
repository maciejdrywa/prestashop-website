<?php
declare(strict_types=1);

/** Tylko odczyt: świeży kontekst dla importera API, bez dostępu do configurations. */
require_once __DIR__ . '/lib/prestashop.php';
require_once __DIR__ . '/lib/webservice.php';
try {
    $context = ShopInit\boot();
    $shopId = (int) $context->shop->id;
    $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
    $groups = Db::getInstance()->executeS('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rules_group_shop WHERE id_shop=' . $shopId);
    if ($groups === false) throw new RuntimeException('Cannot read tax groups.');
    echo json_encode([
        'identity' => ShopInit\webserviceInstanceIdentity(),
        'exported_at' => time(),
        'shop_id' => $shopId,
        'home_category_id' => (int) $context->shop->id_category,
        'default_language_id' => (int) Configuration::get('PS_LANG_DEFAULT'),
        'currency' => $currency->iso_code,
        'country' => $country->iso_code,
        'weight_unit' => Configuration::get('PS_WEIGHT_UNIT'),
        'tax_enabled' => (bool) Configuration::get('PS_TAX'),
        'stock_enabled' => (bool) Configuration::get('PS_STOCK_MANAGEMENT'),
        'tax_group_ids' => array_map(fn ($row): int => (int) $row['id_tax_rules_group'], $groups),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
