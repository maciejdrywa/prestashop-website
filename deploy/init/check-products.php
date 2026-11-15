<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/products.php';
try {
    $withCombinations = in_array('--with-combinations', $argv, true);
    $arguments = array_values(array_filter($argv, fn (string $arg): bool => $arg !== '--with-combinations'));
    [$products, , $all] = ShopInit\selectProducts($arguments, true);
    $context = ShopInit\boot();
    $plans = ShopInit\productPlans($products, $context);
    echo "[1/3] Resolving existing categories (read only)...\n";
    $categories = ShopInit\importCategories(ShopInit\categoryPlan($products, $all), $context, false);
    echo "[2/3] Checking saved fields, translations, categories and base gross prices...\n";
    $inactive = $missingWeight = 0;
    foreach ($plans as $plan) {
        if ($plan['id'] === null) {
            throw new RuntimeException('Missing product: ' . $plan['reference']);
        }
        ShopInit\verifyProduct($plan, $plan['id'], $categories, $context);
        $saved = new Product($plan['id'], false, null, (int) $context->shop->id);
        $inactive += !$saved->active ? 1 : 0;
        $missingWeight += $plan['weight'] === null ? 1 : 0;
    }
    if ($all) {
        $query = new DbQuery();
        $query->select('COUNT(*)')->from('product', 'p');
        $query->innerJoin('product_shop', 's', 's.id_product = p.id_product');
        $query->where('s.id_shop = ' . (int) $context->shop->id);
        $query->where("p.reference LIKE 'pandomu-%'");
        if ((int) Db::getInstance()->getValue($query, false) !== count($plans)) {
            throw new RuntimeException('Imported catalog count differs from source; check stale or duplicate references.');
        }
        $branches = [];
        foreach ($products as $source) {
            foreach ($source['category_paths'] as $path) {
                if (count($path) >= 2) {
                    $branches[$path[0]][$path[1]] = true;
                }
            }
        }
        $qualifying = count(array_filter($branches, fn (array $children): bool => count($children) >= 2));
        if ($qualifying < 4) {
            throw new RuntimeException('Fewer than four root categories have two non-empty direct subcategories.');
        }
        echo "CATEGORY STRUCTURE OK: {$qualifying} root categories with at least two non-empty direct subcategories.\n";
    }
    echo "[3/3] Checking optional combinations...\n";
    $count = 0;
    if ($withCombinations) {
        foreach (ShopInit\combinationPlan($products, $context) as $entry) {
            $productId = (int) $entry['product']->id;
            $query = new DbQuery();
            $query->select('pa.id_product_attribute, pa.reference, s.price, s.default_on')->from('product_attribute', 'pa');
            $query->innerJoin('product_attribute_shop', 's', 's.id_product_attribute = pa.id_product_attribute');
            $query->where('pa.id_product = ' . $productId)->where('s.id_shop = ' . (int) $context->shop->id);
            $rows = Db::getInstance()->executeS($query, true, false);
            if ($rows === false || count($rows) !== count($entry['prices']['variants'])) {
                throw new RuntimeException('Combination count mismatch: ' . $entry['source_id']);
            }
            $byReference = [];
            $defaults = [];
            foreach ($rows as $row) {
                if (isset($byReference[$row['reference']])) {
                    throw new RuntimeException('Duplicate combination reference: ' . $row['reference']);
                }
                $byReference[$row['reference']] = $row;
                if ((int) $row['default_on'] === 1) {
                    $defaults[] = (int) $row['id_product_attribute'];
                }
            }
            if ($defaults !== [(int) $entry['product']->cache_default_attribute]) {
                throw new RuntimeException('Invalid default combination: ' . $entry['source_id']);
            }
            foreach ($entry['prices']['variants'] as $variant) {
                $row = $byReference[$variant['reference']] ?? null;
                if ($row === null || ShopInit\rounded((string) $row['price'], 6) !== $variant['modifier']) {
                    throw new RuntimeException('Missing combination or incorrect modifier: ' . $variant['reference']);
                }
                $id = (int) $row['id_product_attribute'];
                $query = new DbQuery();
                $query->select('id_attribute')->from('product_attribute_combination')->where('id_product_attribute = ' . $id)->orderBy('id_attribute');
                $attributes = Db::getInstance()->executeS($query, true, false);
                $ids = $attributes === false ? [] : array_map(fn (array $a): int => (int) $a['id_attribute'], $attributes);
                if ($ids !== $variant['attribute_ids'] || ShopInit\productGross($productId, $id, $context) !== $variant['gross']) {
                    throw new RuntimeException('Incorrect attributes or gross price: ' . $variant['reference']);
                }
                $count++;
            }
        }
    }
    echo 'OK: ' . count($plans) . " products, {$inactive} inactive, {$missingWeight} without source weight; "
        . ($withCombinations ? "{$count} combinations verified" : 'combinations not checked')
        . ". No database changes. Images and stock are not checked at this stage.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
