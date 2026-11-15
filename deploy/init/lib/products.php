<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';

function productId(string $reference, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('id_product AS id')->from('product');
    $query->where("reference = '" . \pSQL($reference) . "'");
    $id = uniqueId($query);
    if ($id !== null) {
        $query = new \DbQuery();
        $query->select('id_product AS id')->from('product_shop');
        $query->where('id_product = ' . $id)->where('id_shop = ' . (int) $context->shop->id);
        if (uniqueId($query) === null) {
            throw new RuntimeException("Reference {$reference} belongs to another shop.");
        }
    }
    return $id;
}

function taxGroup(string $vat, \Context $context): int
{
    $country = (int) \Country::getByIso('PL');
    $query = new \DbQuery();
    $query->select('g.id_tax_rules_group')->from('tax_rules_group', 'g');
    $query->innerJoin('tax_rule', 'r', 'r.id_tax_rules_group = g.id_tax_rules_group');
    $query->innerJoin('tax', 't', 't.id_tax = r.id_tax');
    $query->innerJoin('tax_rules_group_shop', 's', 's.id_tax_rules_group = g.id_tax_rules_group');
    $query->where('s.id_shop = ' . (int) $context->shop->id);
    $query->where('g.active = 1 AND g.deleted = 0 AND t.active = 1 AND t.deleted = 0');
    $query->where('r.id_country = ' . $country);
    $query->where('r.id_state = 0 AND r.zipcode_from = 0 AND r.zipcode_to = 0 AND r.behavior = 0');
    $query->where("t.rate = '" . \pSQL($vat) . "'");
    // Exclude groups with overlapping or compound Polish rules.
    $query->where('(SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tax_rule r2 WHERE r2.id_tax_rules_group = g.id_tax_rules_group AND r2.id_country = ' . $country . ') = 1');
    $query->orderBy('g.id_tax_rules_group');
    $rows = \Db::getInstance()->executeS($query);
    if (!$rows) {
        throw new RuntimeException("No unambiguous general Polish VAT rule for {$vat}% in this shop.");
    }
    return (int) $rows[0]['id_tax_rules_group'];
}

function productPlans(array $products, \Context $context): array
{
    priceContext($context);
    if (strtolower(trim((string) \Configuration::get('PS_WEIGHT_UNIT'))) !== 'kg') {
        throw new RuntimeException('Source weights are kilograms; configure PS_WEIGHT_UNIT=kg first.');
    }
    $plans = [];
    $taxes = [];
    foreach ($products as $source) {
        $sourceId = (string) $source['product_id'];
        $reference = 'pandomu-' . $sourceId;
        if (!\Validate::isCatalogName($source['name'])
            || mb_strlen($source['name']) > \Product::$definition['fields']['name']['size']) {
            throw new RuntimeException("Invalid or overlong product name: {$sourceId}");
        }
        $description = $source['description'] ?? null;
        $shortDescription = $source['description_short'] ?? null;
        if (!is_string($shortDescription) || !\Validate::isCleanHtml($shortDescription)
            || mb_strlen($shortDescription) > \Product::$definition['fields']['description_short']['size']) {
            throw new RuntimeException("Missing or invalid description_short: {$sourceId}; refresh source descriptions first.");
        }
        if (!is_string($description) || trim(strip_tags($description)) === ''
            || !\Validate::isCleanHtml($description)
            || mb_strlen($description) > \Product::$definition['fields']['description']['size']) {
            throw new RuntimeException("Missing or invalid HTML description: {$sourceId}");
        }
        if (\Configuration::get('PS_USE_HTMLPURIFIER')) {
            $description = \Tools::purifyHTML($description);
            $shortDescription = \Tools::purifyHTML($shortDescription);
        }
        $weight = ($source['weight'] ?? null) === null ? null
            : rounded(decimal($source['weight'], "product {$sourceId} weight"), 6);
        $price = pricePlan($source);
        $taxes[$price['vat']] ??= taxGroup($price['vat'], $context);
        $paths = $source['category_paths'];
        $primary = array_slice($source['breadcrumbs'] ?? [], 1, -1);
        if (!in_array($primary, $paths, true)) {
            $sorted = $paths;
            usort($sorted, fn (array $a, array $b): int => count($b) <=> count($a) ?: strcmp(implode(' > ', $a), implode(' > ', $b)));
            $primary = $sorted[0];
        }
        $slug = rtrim(mb_substr(\Tools::link_rewrite($source['name']), 0, 128), '-');
        if ($slug === '') {
            $slug = $reference;
        }
        $id = productId($reference, $context);
        if ($id !== null && $source['variants'] === []) {
            $query = new \DbQuery();
            $query->select('COUNT(*)')->from('product_attribute')->where('id_product = ' . $id);
            if ((int) \Db::getInstance()->getValue($query, false) > 0) {
                throw new RuntimeException("Product {$sourceId} now has no source variants but saved combinations exist; resolve before import.");
            }
        }
        $plans[] = [
            'source_id' => $sourceId, 'id' => $id, 'reference' => $reference,
            'name' => $source['name'], 'description' => $description,
            'description_short' => $shortDescription,
            'html_normalized' => $description !== $source['description'] || $shortDescription !== $source['description_short'],
            'weight' => $weight, 'price' => $price, 'tax_group' => $taxes[$price['vat']],
            'paths' => $paths, 'primary' => $primary, 'slug' => $slug,
            'type' => $source['variants'] === [] ? 'standard' : 'combinations',
        ];
    }
    return $plans;
}

function productCategoryIds(array $plan, array $categories): array
{
    $ids = [];
    // Assign every source leaf and its ancestors, so browsing a parent includes
    // its branch without relying on theme-specific descendant aggregation.
    foreach ($plan['paths'] as $path) {
        for ($length = 1; $length <= count($path); $length++) {
            $key = json_encode(array_slice($path, 0, $length), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!isset($categories[$key])) {
                throw new RuntimeException('Unresolved category path: ' . $key);
            }
            $ids[] = $categories[$key];
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function verifyProduct(array $plan, int $id, array $categories, \Context $context): void
{
    $saved = new \Product($id, false, null, (int) $context->shop->id);
    if (!\Validate::isLoadedObject($saved) || $saved->reference !== $plan['reference']
        || rounded(decimal($saved->price, 'saved net'), 6) !== $plan['price']['net']
        || ($plan['weight'] !== null && rounded(decimal($saved->weight, 'saved weight'), 6) !== $plan['weight'])
        || (int) $saved->id_tax_rules_group !== $plan['tax_group']
        || $saved->product_type !== $plan['type']
        || (int) $saved->state !== \Product::STATE_SAVED) {
        throw new RuntimeException('Saved product data mismatch: ' . $plan['source_id']);
    }
    foreach (\Language::getLanguages(false) as $language) {
        $languageId = (int) $language['id_lang'];
        if (($saved->name[$languageId] ?? null) !== $plan['name']
            || ($saved->description[$languageId] ?? null) !== $plan['description']
            || ($saved->description_short[$languageId] ?? null) !== $plan['description_short']
            || ($saved->link_rewrite[$languageId] ?? null) !== $plan['slug']) {
            throw new RuntimeException('Saved product translation mismatch: ' . $plan['source_id']);
        }
    }
    $query = new \DbQuery();
    $query->select('id_category')->from('category_product')->where('id_product = ' . $id)->orderBy('id_category');
    $rows = \Db::getInstance()->executeS($query, true, false);
    $actual = $rows === false ? [] : array_map(fn (array $r): int => (int) $r['id_category'], $rows);
    $default = $categories[json_encode($plan['primary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    if ($actual !== productCategoryIds($plan, $categories) || (int) $saved->id_category_default !== $default) {
        throw new RuntimeException('Saved category assignments mismatch: ' . $plan['source_id']);
    }
    if (productGross($id, false, $context) !== $plan['price']['gross']) {
        throw new RuntimeException('PrestaShop base gross price mismatch: ' . $plan['source_id']);
    }
}

function importProducts(array $plans, array $categories, \Context $context): array
{
    $ids = [];
    $created = $updated = 0;
    foreach ($plans as $index => $plan) {
        // Resolve again to allow reuse of a plan in the same process.
        $id = productId($plan['reference'], $context);
        $product = $id === null ? new \Product() : new \Product($id, false, null, (int) $context->shop->id);
        if ($id === null) {
            $product->active = false;
            $product->id_shop_default = (int) $context->shop->id;
            $product->available_for_order = true;
            $product->show_price = true;
            $product->visibility = 'both';
            $product->minimal_quantity = 1;
            $product->condition = 'new';
        }
        $product->reference = $plan['reference'];
        $product->state = \Product::STATE_SAVED;
        $product->product_type = $plan['type'];
        $product->price = $plan['price']['net'];
        if ($plan['weight'] !== null) {
            $product->weight = $plan['weight'];
        }
        $product->id_tax_rules_group = $plan['tax_group'];
        $product->id_category_default = $categories[json_encode($plan['primary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        foreach (\Language::getLanguages(false) as $language) {
            $languageId = (int) $language['id_lang'];
            $product->name[$languageId] = $plan['name'];
            $product->description[$languageId] = $plan['description'];
            $product->description_short[$languageId] = $plan['description_short'];
            $product->link_rewrite[$languageId] = $plan['slug'];
        }
        if (!($id === null ? $product->add() : $product->update())
            || !$product->updateCategories(productCategoryIds($plan, $categories))) {
            throw new RuntimeException('Cannot save product: ' . $plan['source_id']);
        }
        $id === null ? $created++ : $updated++;
        $ids[$plan['source_id']] = (int) $product->id;
        verifyProduct($plan, (int) $product->id, $categories, $context);
        echo '[' . ($index + 1) . '/' . count($plans) . '] ' . ($id === null ? 'CREATED' : 'UPDATED')
            . ': ' . $plan['reference'] . ', ID: ' . $product->id
            . ', gross: ' . $plan['price']['gross'] . ' PLN, categories: '
            . count(productCategoryIds($plan, $categories)) . PHP_EOL;
        if ($plan['html_normalized']) {
            echo 'HTML NORMALIZED: ' . $plan['reference'] . ' (PrestaShop purifier)' . PHP_EOL;
        }
        if ($plan['weight'] === null) {
            echo 'WARNING: ' . $plan['reference'] . ' has no source weight; preserving existing/default weight.' . PHP_EOL;
        }
    }
    echo "SUMMARY: {$created} created, {$updated} updated; all base prices and fields verified.\n";
    return $ids;
}
