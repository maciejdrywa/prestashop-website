<?php

declare(strict_types=1);

function findSingleId(DbQuery $query): ?int
{
    $rows = Db::getInstance()->executeS($query);

    if ($rows === false) {
        throw new RuntimeException("Cannot look up attribute data.");
    }

    if (count($rows) > 1) {
        throw new RuntimeException("Duplicate attribute data found.");
    }

    return $rows === [] ? null : (int) $rows[0]["id"];
}

try {
    $sourceId = $argv[1] ?? "5";

    // Validate the supported variant structure before database writes.
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

    $json = file_get_contents("/opt/shop/scraper-results/products.json");

    if ($json === false) {
        throw new RuntimeException("Cannot read source products.");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $source = null;

    foreach ($products as $product) {
        if ((string) $product["product_id"] === $sourceId) {
            $source = $product;
            break;
        }
    }

    if ($source === null) {
        throw new RuntimeException("Source product not found.");
    }

    if ($source["variants"] === []) {
        echo "OK: no attributes required." . PHP_EOL;
        exit(0);
    }

    $languages = Language::getLanguages(false);
    $groupName = array_key_first($source["variants"][0]["attributes"]);

    echo "[1/2] Finding or creating attribute group..." . PHP_EOL;

    $query = new DbQuery();
    $query->select("g.id_attribute_group AS id");
    $query->from("attribute_group", "g");
    $query->innerJoin(
        "attribute_group_lang",
        "gl",
        "gl.id_attribute_group = g.id_attribute_group"
    );
    $query->innerJoin(
        "attribute_group_shop",
        "gs",
        "gs.id_attribute_group = g.id_attribute_group"
    );
    $query->where("gl.id_lang = " . $languageId);
    $query->where("gs.id_shop = " . $shopId);
    $query->where("gl.name = '" . pSQL($groupName) . "'");

    $groupId = findSingleId($query);

    if ($groupId === null) {
        $group = new AttributeGroup();
        $group->group_type = "select";
        $group->is_color_group = false;

        foreach ($languages as $language) {
            $id = (int) $language["id_lang"];
            $group->name[$id] = $groupName;
            $group->public_name[$id] = $groupName;
        }

        if (!$group->add()) {
            throw new RuntimeException("Cannot create attribute group.");
        }

        $groupId = (int) $group->id;

        echo "CREATED GROUP: {$groupName}, ID: {$groupId}" . PHP_EOL;
    } else {
        echo "EXISTS GROUP: {$groupName}, ID: {$groupId}" . PHP_EOL;
    }

    echo "[2/2] Finding or creating attribute values..." . PHP_EOL;

    foreach ($source["variants"] as $variant) {
        $value = $variant["attributes"][$groupName];

        $query = new DbQuery();
        $query->select("a.id_attribute AS id");
        $query->from("attribute", "a");
        $query->innerJoin(
            "attribute_lang",
            "al",
            "al.id_attribute = a.id_attribute"
        );
        $query->innerJoin(
            "attribute_shop",
            "s",
            "s.id_attribute = a.id_attribute"
        );
        $query->where("a.id_attribute_group = " . $groupId);
        $query->where("al.id_lang = " . $languageId);
        $query->where("s.id_shop = " . $shopId);
        $query->where("al.name = '" . pSQL($value) . "'");

        $attributeId = findSingleId($query);

        if ($attributeId !== null) {
            echo "EXISTS VALUE: {$value}, ID: {$attributeId}" . PHP_EOL;
            continue;
        }

        $query = new DbQuery();
        $query->select("MAX(position)");
        $query->from("attribute");
        $query->where("id_attribute_group = " . $groupId);

        $position = (int) Db::getInstance()->getValue($query) + 1;

        $attribute = new ProductAttribute();
        $attribute->id_attribute_group = $groupId;
        $attribute->position = $position;

        foreach ($languages as $language) {
            $attribute->name[(int) $language["id_lang"]] = $value;
        }

        if (!$attribute->add()) {
            throw new RuntimeException("Cannot create attribute: {$value}");
        }

        echo "CREATED VALUE: {$value}, ID: {$attribute->id}" . PHP_EOL;
    }

    echo "OK: attribute dictionary prepared; no product combinations created."
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Attribute import error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
