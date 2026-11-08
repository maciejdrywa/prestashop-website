<?php

declare(strict_types=1);

try {
    require "/var/www/html/config/config.inc.php";

    $context = Context::getContext();

    if (!Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException("Cannot determine the current shop.");
    }

    $shopId = (int) $context->shop->id;
    $languageId = (int) Configuration::get("PS_LANG_DEFAULT");
    $parentId = (int) $context->shop->id_category;

    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);

    $name = "Okucia drzwiowe";

    echo "[1/3] Checking parent category..." . PHP_EOL;

    $parent = new Category($parentId, $languageId, $shopId);

    if (!Validate::isLoadedObject($parent)) {
        throw new RuntimeException("Shop home category does not exist.");
    }

    echo "[2/3] Looking for an existing category..." . PHP_EOL;

    $query = new DbQuery();
    $query->select("c.id_category");
    $query->from("category", "c");
    $query->innerJoin(
        "category_lang",
        "cl",
        "cl.id_category = c.id_category"
    );
    $query->innerJoin(
        "category_shop",
        "cs",
        "cs.id_category = c.id_category"
    );
    $query->where("c.id_parent = " . $parentId);
    $query->where("cl.id_lang = " . $languageId);
    $query->where("cl.id_shop = " . $shopId);
    $query->where("cs.id_shop = " . $shopId);
    $query->where("cl.name = '" . pSQL($name) . "'");

    $matches = Db::getInstance()->executeS($query);

    if ($matches === false) {
        throw new RuntimeException("Cannot check existing categories.");
    }

    if (count($matches) > 1) {
        throw new RuntimeException(
            "Multiple matching categories exist. Resolve duplicates first."
        );
    }

    if (count($matches) === 1) {
        echo "EXISTS: " . $name
            . ", ID: " . $matches[0]["id_category"]
            . PHP_EOL;

        exit(0);
    }

    echo "[3/3] Creating category..." . PHP_EOL;

    $category = new Category();
    $category->id_parent = $parentId;
    $category->id_shop_default = $shopId;
    $category->active = true;
    $category->is_root_category = false;

    foreach (Language::getLanguages(false) as $language) {
        $id = (int) $language["id_lang"];

        $category->name[$id] = $name;
        $category->link_rewrite[$id] = Tools::link_rewrite($name);
    }

    if (!$category->add()) {
        throw new RuntimeException("Cannot save category: {$name}");
    }

    echo "CREATED: " . $name
        . ", ID: " . $category->id
        . ", parent ID: " . $parentId
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Category import error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}