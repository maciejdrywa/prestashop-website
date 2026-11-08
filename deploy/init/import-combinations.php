<?php

declare(strict_types=1);

function lookupId(DbQuery $query): ?int
{
    $rows = Db::getInstance()->executeS($query);

    if ($rows === false) {
        throw new RuntimeException("Database lookup failed.");
    }

    if (count($rows) > 1) {
        throw new RuntimeException("Duplicate records found.");
    }

    return $rows === [] ? null : (int) $rows[0]["id"];
}

try {
    $sourceId = $argv[1] ?? "5";

    // Other products require complete, verified source combinations.
    if ($sourceId !== "5") {
        throw new RuntimeException(
            "This first importer supports only source product 5."
        );
    }

    $command = escapeshellarg(PHP_BINARY)
        . " " . escapeshellarg(__DIR__ . "/plan-variants.php")
        . " " . escapeshellarg($sourceId);

    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException("Variant plan failed.");
    }

    require "/var/www/html/config/config.inc.php";

    $context = Context::getContext();

    if (!Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException("Cannot determine the shop.");
    }

    $shopId = (int) $context->shop->id;
    $languageId = (int) Configuration::get("PS_LANG_DEFAULT");

    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);

    $context->currency = new Currency(
        (int) Configuration::get("PS_CURRENCY_DEFAULT")
    );

    // These objects provide calculation context without database writes.
    $context->cart = new Cart();
    $context->customer = new Customer();

    $polandId = (int) Country::getByIso("PL");

    if (!Validate::isLoadedObject($context->currency)
        || $context->currency->iso_code !== "PLN"
        || $polandId === 0
        || (int) Configuration::get("PS_COUNTRY_DEFAULT") !== $polandId
        || !Configuration::get("PS_TAX")) {
        throw new RuntimeException(
            "Expected PLN, Poland as the default country and enabled taxes."
        );
    }

    $json = file_get_contents("/opt/shop/scraper-results/products.json");

    if ($json === false) {
        throw new RuntimeException("Cannot read source products.");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($products) || !array_is_list($products)) {
        throw new RuntimeException("Expected a product list.");
    }

    $source = null;

    foreach ($products as $candidate) {
        if ((string) $candidate["product_id"] === $sourceId) {
            $source = $candidate;
            break;
        }
    }

    if ($source === null || empty($source["variants"])) {
        throw new RuntimeException("Source product or variants are missing.");
    }

    $sourceGross = $source["price_brutto"] ?? null;
    $vatRate = $source["vat_rate"] ?? null;

    if (!is_numeric($sourceGross)
        || !is_finite((float) $sourceGross)
        || (float) $sourceGross <= 0) {
        throw new RuntimeException("Invalid source gross price.");
    }

    if (!is_numeric($vatRate)
        || !is_finite((float) $vatRate)
        || (float) $vatRate < 0) {
        throw new RuntimeException("Invalid source VAT rate.");
    }

    $sourceGross = (float) $sourceGross;
    $taxMultiplier = 1 + (float) $vatRate / 100;

    $query = new DbQuery();
    $query->select("p.id_product AS id");
    $query->from("product", "p");
    $query->innerJoin(
        "product_shop",
        "ps",
        "ps.id_product = p.id_product"
    );
    $query->where("ps.id_shop = " . $shopId);
    $query->where("p.reference = 'pandomu-" . pSQL($sourceId) . "'");

    $productId = lookupId($query);

    if ($productId === null) {
        throw new RuntimeException("Import the product draft first.");
    }

    $product = new Product($productId, false, null, $shopId);

    if (!Validate::isLoadedObject($product)) {
        throw new RuntimeException("Cannot load the imported product.");
    }

    if ($product->active) {
        throw new RuntimeException(
            "This first import expects an inactive product draft."
        );
    }

    echo "[1/4] Checking the saved base price..." . PHP_EOL;

    $baseGross = Product::getPriceStatic(
        id_product: $productId,
        usetax: true,
        id_product_attribute: false,
        decimals: 2,
        usereduc: false,
        use_group_reduction: false,
        context: $context,
        use_customer_price: false
    );

    if ($baseGross === null
        || abs((float) $baseGross - $sourceGross) > 0.001) {
        throw new RuntimeException(
            "Saved base gross price differs from the source. "
            . "Correct the product base price before importing combinations."
        );
    }

    echo "Base gross price: {$baseGross} PLN" . PHP_EOL;
    echo "[2/4] Resolving attributes and price modifiers..." . PHP_EOL;

    // Validate and resolve the complete plan before combination writes.
    $plan = [];
    $seenReferences = [];

    foreach ($source["variants"] as $variant) {
        $groupName = array_key_first($variant["attributes"]);
        $value = $variant["attributes"][$groupName];
        $optionId = $variant["variant_id"] ?? null;

        if ((!is_string($optionId) && !is_int($optionId))
            || trim((string) $optionId) === "") {
            throw new RuntimeException(
                "Missing source option ID for variant: {$value}"
            );
        }

        $reference = "pandomu-" . $sourceId . "-option-" . $optionId;

        if (strlen($reference) > 64) {
            throw new RuntimeException(
                "Combination reference is too long: {$reference}"
            );
        }

        if (isset($seenReferences[$reference])) {
            throw new RuntimeException(
                "Duplicate source option reference: {$reference}"
            );
        }

        $seenReferences[$reference] = true;

        // Contract: price_modifier is a NET price difference.
        // Missing values must never silently become zero.
        $modifier = $variant["price_modifier"] ?? null;

        if (!is_numeric($modifier) || !is_finite((float) $modifier)) {
            throw new RuntimeException(
                "Missing or invalid price_modifier for variant: {$value}"
            );
        }

        $modifierNet = round((float) $modifier, 6);
        $variantNet = (float) $product->price + $modifierNet;

        if ($variantNet < 0) {
            throw new RuntimeException(
                "Modifier produces a negative price for variant: {$value}"
            );
        }

        $expectedGross = round(
            $sourceGross + $modifierNet * $taxMultiplier,
            2
        );

        $query = new DbQuery();
        $query->select("a.id_attribute AS id");
        $query->from("attribute", "a");
        $query->innerJoin(
            "attribute_lang",
            "al",
            "al.id_attribute = a.id_attribute"
        );
        $query->innerJoin(
            "attribute_group_lang",
            "gl",
            "gl.id_attribute_group = a.id_attribute_group"
        );
        $query->innerJoin(
            "attribute_shop",
            "s",
            "s.id_attribute = a.id_attribute"
        );
        $query->where("al.id_lang = " . $languageId);
        $query->where("gl.id_lang = " . $languageId);
        $query->where("s.id_shop = " . $shopId);
        $query->where("gl.name = '" . pSQL($groupName) . "'");
        $query->where("al.name = '" . pSQL($value) . "'");

        $attributeId = lookupId($query);

        if ($attributeId === null) {
            throw new RuntimeException(
                "Import attribute first: {$groupName} = {$value}"
            );
        }

        $plan[] = [
            "value" => $value,
            "attribute_id" => $attributeId,
            "reference" => $reference,
            "price_modifier_net" => $modifierNet,
            "expected_gross" => $expectedGross,
        ];

        echo "PLAN: {$value}"
            . ", modifier: {$modifierNet} PLN net"
            . ", expected price: {$expectedGross} PLN gross"
            . PHP_EOL;
    }

    if ($plan === []) {
        throw new RuntimeException("No combinations to import.");
    }

    echo "[3/4] Creating or updating combinations..." . PHP_EOL;

    $combinationIds = [];

    foreach ($plan as $entry) {
        $query = new DbQuery();
        $query->select("pa.id_product_attribute AS id");
        $query->from("product_attribute", "pa");
        $query->innerJoin(
            "product_attribute_shop",
            "pas",
            "pas.id_product_attribute = pa.id_product_attribute"
        );
        $query->where("pa.id_product = " . $productId);
        $query->where("pas.id_shop = " . $shopId);
        $query->where(
            "pa.reference = '" . pSQL($entry["reference"]) . "'"
        );

        $combinationId = lookupId($query);
        $exists = $combinationId !== null;

        $combination = $exists
            ? new Combination($combinationId, null, $shopId)
            : new Combination();

        $combination->id_product = $productId;
        $combination->reference = $entry["reference"];
        $combination->price = $entry["price_modifier_net"];
        $combination->weight = 0;
        $combination->minimal_quantity = 1;

        $saved = $exists
            ? $combination->update()
            : $combination->add();

        if (!$saved) {
            throw new RuntimeException(
                "Cannot save combination: " . $entry["value"]
            );
        }

        if (!$combination->setAttributes([$entry["attribute_id"]])) {
            throw new RuntimeException(
                "Cannot assign attribute: " . $entry["value"]
            );
        }

        $combinationIds[] = (int) $combination->id;

        echo ($exists ? "UPDATED" : "CREATED")
            . ": " . $entry["value"]
            . ", combination ID: " . $combination->id
            . ", modifier: " . $entry["price_modifier_net"] . " PLN net"
            . PHP_EOL;
    }

    // Initialization choice: first source option is the default.
    if (!$product->deleteDefaultAttributes()
        || !$product->setDefaultAttribute($combinationIds[0])) {
        throw new RuntimeException("Cannot set the default combination.");
    }

    echo "[4/4] Checking prices calculated by PrestaShop..." . PHP_EOL;

    foreach ($combinationIds as $index => $combinationId) {
        $gross = Product::getPriceStatic(
            id_product: $productId,
            usetax: true,
            id_product_attribute: $combinationId,
            decimals: 2,
            usereduc: false,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        $entry = $plan[$index];
        $expectedGross = $entry["expected_gross"];

        if ($gross === null
            || abs((float) $gross - $expectedGross) > 0.001) {
            throw new RuntimeException(
                "Unexpected price for combination {$combinationId}: "
                . "expected {$expectedGross}, received "
                . var_export($gross, true) . "."
            );
        }

        echo "PRICE OK: " . $entry["value"]
            . ", modifier: " . $entry["price_modifier_net"] . " PLN net"
            . ", gross: {$gross} PLN"
            . PHP_EOL;
    }

    echo "Default combination ID: " . $combinationIds[0] . PHP_EOL;
    echo "OK: " . count($combinationIds)
        . " combinations imported; product remains inactive."
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        "Combination import error: " . $error->getMessage() . PHP_EOL
    );

    exit(1);
}
