<?php

declare(strict_types=1);

function ensureCategory(
    string $name,
    int $parentId,
    int $shopId,
    int $languageId,
    array $languages,
    int &$created,
    int &$existing
): int {
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
        throw new RuntimeException("Cannot look up category: {$name}");
    }

    if (count($matches) > 1) {
        throw new RuntimeException(
            "Duplicate categories: {$name}, parent ID: {$parentId}"
        );
    }

    if (count($matches) === 1) {
        $id = (int) $matches[0]["id_category"];
        $existing++;

        echo "EXISTS: {$name}, ID: {$id}, parent ID: {$parentId}"
            . PHP_EOL;

        return $id;
    }

    $category = new Category();
    $category->id_parent = $parentId;
    $category->id_shop_default = $shopId;
    $category->active = true;
    $category->is_root_category = false;

    foreach ($languages as $language) {
        $id = (int) $language["id_lang"];

        $category->name[$id] = $name;
        $category->link_rewrite[$id] = Tools::link_rewrite($name);
    }

    if (!$category->add()) {
        throw new RuntimeException("Cannot save category: {$name}");
    }

    $created++;

    echo "CREATED: {$name}, ID: {$category->id}, parent ID: {$parentId}"
        . PHP_EOL;

    return (int) $category->id;
}

try {
    // Validate the complete source before making database changes.
    echo "[1/3] Validating source data..." . PHP_EOL;

    $command = escapeshellarg(PHP_BINARY)
        . " " . escapeshellarg(__DIR__ . "/validate-source.php");

    passthru($command, $validationExitCode);

    if ($validationExitCode !== 0) {
        throw new RuntimeException("Source validation failed.");
    }

    require "/var/www/html/config/config.inc.php";

    $context = Context::getContext();

    if (!Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException("Cannot determine the current shop.");
    }

    $shopId = (int) $context->shop->id;
    $languageId = (int) Configuration::get("PS_LANG_DEFAULT");
    $homeId = (int) $context->shop->id_category;

    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);

    $home = new Category($homeId, $languageId, $shopId);

    if (!Validate::isLoadedObject($home)) {
        throw new RuntimeException("Shop home category does not exist.");
    }

    $languages = Language::getLanguages(false);
    $path = "/opt/shop/scraper-results/products.json";
    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("Cannot read file: {$path}");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $categoryIds = [];
    $created = 0;
    $existing = 0;
    $skipped = 0;

    echo "[2/3] Importing category tree..." . PHP_EOL;

    foreach ($products as $product) {
        $breadcrumbs = $product["breadcrumbs"];

        if (count($breadcrumbs) < 3) {
            $skipped++;
            continue;
        }

        $categoryPath = array_slice($breadcrumbs, 1, -1);
        $currentPath = [];
        $parentId = $homeId;

        foreach ($categoryPath as $name) {
            $currentPath[] = $name;

            $key = json_encode(
                $currentPath,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            if (!isset($categoryIds[$key])) {
                $categoryIds[$key] = ensureCategory(
                    $name,
                    $parentId,
                    $shopId,
                    $languageId,
                    $languages,
                    $created,
                    $existing
                );
            }

            $parentId = $categoryIds[$key];
        }
    }

    echo "[3/3] Summary..." . PHP_EOL;
    echo "Unique source categories: " . count($categoryIds) . PHP_EOL;
    echo "Created: {$created}" . PHP_EOL;
    echo "Already existed: {$existing}" . PHP_EOL;
    echo "Products without category paths: {$skipped}" . PHP_EOL;

    echo "OK: category paths available in the source have been imported."
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        "Category import error: " . $error->getMessage() . PHP_EOL
    );

    exit(1);
}