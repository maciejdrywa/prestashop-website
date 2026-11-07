<?php

declare(strict_types=1);

try {
    $path = "/opt/shop/scraper-results/products.json";

    echo "[1/3] Checking if file exists..." . PHP_EOL;

    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("File not found: $path");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($products) || !array_is_list($products) || $products === []) {
        throw new RuntimeException("Invalid JSON structure in file: $path");
    }

    echo "[2/3] Validating product data..." . PHP_EOL;

    $seenIds = [];
    $errors = [];
    $missingCategories = [];
    $withVariants = 0;
    $imageUrls = [];

    foreach ($products as $index => $product) {
        $label = "Record " . ($index + 1);

        if (!is_array($product)) {
            $errors[] = "{$label}: wait for product object";
            continue;
        }

        $id = $product["product_id"] ?? null;

        if ((!is_string($id) && !is_int($id)) || trim((string) $id) === "") {
            $errors[] = "{$label}: invalid product ID";
        } else {
            $label = "Product {$id}";
            $key = "source:" . $id;
            
            if (isset($seenIds[$key])) {
                $errors[] = "{$label}: duplicate product ID";
            } else {
                $seenIds[$key] = true;
            }
        }

        foreach (["name", "description", "product_url"] as $field) {
            if (!is_string($product[$field] ?? null)
                || trim($product[$field]) === "") {
                $errors[] = "{$label}: field doesn't exist {$field}.";
            }
        }

        foreach (["price_brutto", "price_netto", "vat_rate"] as $field) {
            $value = $product[$field] ?? null;

            if (!is_numeric($value) || !is_finite((float) $value)
                || ($field === "vat_rate" ? $value < 0 : $value <= 0)) {
                $errors[] = "{$label}: invalid field {$field}.";
            }
        }

        $images = $product["images"] ?? null;

        if (!is_array($images) || !array_is_list($images) || $images === []) {
            $errors[] = "{$label}: empty image list.";
        } else {
            foreach ($images as $url) {
                if (!is_string($url)
                    || !filter_var($url, FILTER_VALIDATE_URL)
                    || !in_array(parse_url($url, PHP_URL_SCHEME), ["http", "https"], true)) {
                    $errors[] = "{$label}: invalid image URL.";
                } else {
                    $imageUrls[$url] = true;
                }
            }
        }


        $breadcrumbs = $product["breadcrumbs"] ?? null;

        if (!is_array($breadcrumbs) || !array_is_list($breadcrumbs)) {
            $errors[] = "{$label}: invalid breadcrumbs.";
        } else {
            foreach ($breadcrumbs as $name) {
                if (!is_string($name) || trim($name) === "") {
                    $errors[] = "{$label}: empty or invalid breadcrumb element.";
                    break;
                }
            }

            if (count($breadcrumbs) < 3) {
                $missingCategories[] = (string) ($id ?? $index);
            }
        }

        $variants = $product["variants"] ?? [];

        if (!is_array($variants) || !array_is_list($variants)) {
            $errors[] = "{$label}: invalid variants list.";
        } elseif ($variants !== []) {
            $withVariants++;
        }
    }

    echo "[3/3] Ending..." . PHP_EOL;
    echo "Product records: " . count($products) . PHP_EOL;
    echo "Unique ID: " . count($seenIds) . PHP_EOL;
    echo "Unique image URLs: " . count($imageUrls) . PHP_EOL;
    echo "Products with variants: {$withVariants}" . PHP_EOL;
    echo "Products without categories: " . count($missingCategories) . PHP_EOL;

    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}" . PHP_EOL);
    }

    if ($errors !== []) {
        throw new RuntimeException(
            "Validation detected " . count($errors) . " errors."
        );
    }

    if ($missingCategories !== []) {
        echo "WARNING: before full import, you need to complete category assignments."
            . PHP_EOL;
    }

    echo "OK: basic data structure is correct." . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Validation error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}