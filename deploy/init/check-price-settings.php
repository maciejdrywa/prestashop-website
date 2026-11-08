<?php

declare(strict_types=1);

try {
    require "/var/www/html/config/config.inc.php";

    $context = Context::getContext();

    if (!Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException("Cannot determine the current shop.");
    }

    $shopId = (int) $context->shop->id;
    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);

    echo "[1/3] Checking currency and tax configuration..." . PHP_EOL;

    $currencyId = (int) Configuration::get("PS_CURRENCY_DEFAULT");
    $currency = new Currency($currencyId);

    if (!Validate::isLoadedObject($currency)) {
        throw new RuntimeException("Default currency does not exist.");
    }

    echo "Default currency: " . $currency->iso_code . PHP_EOL;
    echo "Taxes enabled: "
        . (Configuration::get("PS_TAX") ? "YES" : "NO")
        . PHP_EOL;

    $polandId = (int) Country::getByIso("PL");

    if ($polandId === 0) {
        throw new RuntimeException("Country PL does not exist.");
    }

    echo "[2/3] Listing active tax rules for Poland..." . PHP_EOL;

    $query = new DbQuery();
    $query->select(
        "g.id_tax_rules_group, g.name, t.rate,"
        . " r.id_state, r.zipcode_from, r.zipcode_to, r.behavior"
    );
    $query->from("tax_rules_group", "g");
    $query->innerJoin(
        "tax_rule",
        "r",
        "r.id_tax_rules_group = g.id_tax_rules_group"
    );
    $query->innerJoin("tax", "t", "t.id_tax = r.id_tax");
    $query->innerJoin(
        "tax_rules_group_shop",
        "gs",
        "gs.id_tax_rules_group = g.id_tax_rules_group"
    );
    $query->where("g.active = 1");
    $query->where("g.deleted = 0");
    $query->where("gs.id_shop = " . $shopId);
    $query->where("r.id_country = " . $polandId);
    $query->where("t.active = 1");
    $query->where("t.deleted = 0");
    $query->orderBy("g.id_tax_rules_group");

    $rules = Db::getInstance()->executeS($query);

    if ($rules === false) {
        throw new RuntimeException("Cannot read tax rules.");
    }

    foreach ($rules as $rule) {
        echo json_encode(
            $rule,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }

    if ($rules === []) {
        echo "WARNING: no active tax rules found for Poland." . PHP_EOL;
    }

    echo "[3/3] Checking sample source price..." . PHP_EOL;

    $path = "/opt/shop/scraper-results/products.json";
    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("Cannot read source products.");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($products) || !isset($products[0])) {
        throw new RuntimeException("Source product list is empty or invalid.");
    }

    $product = $products[0];
    $gross = (float) $product["price_brutto"];
    $vat = (float) $product["vat_rate"];
    $net = round($gross / (1 + $vat / 100), 6);
    $recalculatedGross = round($net * (1 + $vat / 100), 2);

    echo "Source ID: " . $product["product_id"] . PHP_EOL;
    echo "Name: " . $product["name"] . PHP_EOL;
    echo "Source gross price: {$gross}" . PHP_EOL;
    echo "VAT: {$vat}%" . PHP_EOL;
    echo "Calculated net price: {$net}" . PHP_EOL;
    echo "Recalculated gross price: {$recalculatedGross}" . PHP_EOL;

    echo "OK: inspection finished; no settings were changed." . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Price settings error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}