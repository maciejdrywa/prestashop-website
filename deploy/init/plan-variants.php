<?php

declare(strict_types=1);

try {
    $sourceId = $argv[1] ?? "5";
    $json = file_get_contents("/opt/shop/scraper-results/products.json");

    if ($json === false) {
        throw new RuntimeException("Cannot read source products.");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($products) || !array_is_list($products)) {
        throw new RuntimeException("Expected a product list.");
    }

    $source = null;

    foreach ($products as $product) {
        if ((string) $product["product_id"] === $sourceId) {
            $source = $product;
            break;
        }
    }

    if ($source === null) {
        throw new RuntimeException("Source product {$sourceId} not found.");
    }

    $variants = $source["variants"] ?? [];

    if (!is_array($variants) || !array_is_list($variants)) {
        throw new RuntimeException("Invalid variant list.");
    }

    echo "Product: " . $source["name"] . PHP_EOL;
    echo "Source ID: {$sourceId}" . PHP_EOL;
    echo "Source variant entries: " . count($variants) . PHP_EOL;

    $groups = [];

    foreach ($variants as $variant) {
        $attributes = $variant["attributes"] ?? null;

        if (!is_array($attributes) || count($attributes) !== 1) {
            throw new RuntimeException(
                "This first importer supports one attribute per entry."
            );
        }

        $groupName = array_key_first($attributes);
        $value = $attributes[$groupName];

        if (!is_string($groupName) || trim($groupName) === ""
            || !is_string($value) || trim($value) === "") {
            throw new RuntimeException("Invalid attribute name or value.");
        }

        // Prefix keys so numeric-looking names stay unambiguous.
        $groupKey = "group:" . $groupName;
        $valueKey = "value:" . $value;

        if (isset($groups[$groupKey]["values"][$valueKey])) {
            throw new RuntimeException(
                "Duplicate option: {$groupName} = {$value}"
            );
        }

        $groups[$groupKey]["name"] = $groupName;
        $groups[$groupKey]["values"][$valueKey] = $value;

        echo "OPTION: {$groupName} = {$value}"
            . ", source option ID: " . ($variant["variant_id"] ?? "?")
            . PHP_EOL;
    }

    if ($variants === []) {
        echo "OK: simple product, no combinations needed." . PHP_EOL;
        exit(0);
    }

    if (count($groups) !== 1) {
        throw new RuntimeException(
            "Multiple attribute groups detected. "
            . "Actual source combinations must be collected first."
        );
    }

    echo "OK: single-group structure can be used to build combinations."
        . PHP_EOL;

    echo "NOTE: variant prices still require source verification."
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Variant plan error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}