<?php

declare(strict_types=1);

try {
    $path = "/opt/shop/scraper-results/products.json";
    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("Cannot read file: {$path}");
    }

    $products = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($products) || !array_is_list($products) || $products === []) {
        throw new RuntimeException("Expected a non-empty product list.");
    }

    $categories = [];
    $missingCategories = [];

    foreach ($products as $product) {
        $breadcrumbs = $product["breadcrumbs"] ?? [];

        if (count($breadcrumbs) < 3) {
            $missingCategories[] = $product["product_id"];
            continue;
        }

        $categoryPath = array_slice($breadcrumbs, 1, -1);
        $parentKey = null;
        $currentPath = [];

        foreach ($categoryPath as $name) {
            $currentPath[] = $name;

            $key = json_encode(
                $currentPath,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            if (!isset($categories[$key])) {
                $categories[$key] = [
                    "name" => $name,
                    "path" => $currentPath,
                    "parent" => $parentKey,
                    "product_ids" => [],
                ];
            }

            $categories[$key]["product_ids"][
                "source:" . $product["product_id"]
            ] = true;

            $parentKey = $key;
        }
    }

    echo "CATEGORY PLAN — no database changes" . PHP_EOL;
    echo "Total categories: " . count($categories) . PHP_EOL . PHP_EOL;

    $qualifyingRoots = 0;

    foreach ($categories as $rootKey => $root) {
        if ($root["parent"] !== null) {
            continue;
        }

        echo $root["name"]
            . " — products in branch: "
            . count($root["product_ids"])
            . PHP_EOL;

        $nonEmptyChildren = 0;

        foreach ($categories as $child) {
            if ($child["parent"] !== $rootKey) {
                continue;
            }

            $count = count($child["product_ids"]);

            echo "  -> " . $child["name"]
                . " — products in branch: {$count}"
                . PHP_EOL;

            if ($count > 0) {
                $nonEmptyChildren++;
            }
        }

        echo "  Non-empty direct subcategories: {$nonEmptyChildren}"
            . PHP_EOL . PHP_EOL;

        if ($nonEmptyChildren >= 2) {
            $qualifyingRoots++;
        }
    }

    echo "Root categories with at least 2 non-empty subcategories: "
        . $qualifyingRoots . PHP_EOL;

    echo "Products without category assignments: "
        . count($missingCategories) . PHP_EOL;

    echo "Their source IDs: "
        . implode(", ", $missingCategories) . PHP_EOL;

    if ($qualifyingRoots < 4) {
        throw new RuntimeException(
            "The category plan does not meet the minimum requirement."
        );
    }

    echo "OK: the plan meets the minimum category structure requirement."
        . PHP_EOL;

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Category plan error: " . $error->getMessage() . PHP_EOL);
    exit(1);
}