<?php

declare(strict_types=1);

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/source.php';

function boot(string $root = '/var/www/html'): \Context
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Initialization scripts must run through PHP CLI.');
    }
    $config = $root . '/config/config.inc.php';
    if (!is_readable($config)) {
        throw new RuntimeException("PrestaShop is not installed or its configuration is unreadable: {$config}");
    }
    require_once $config;
    $context = \Context::getContext();
    if (!\Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException('Cannot determine the current shop.');
    }
    \Shop::setContext(\Shop::CONTEXT_SHOP, (int) $context->shop->id);
    if ((string) \Db::getInstance()->getValue('SELECT 1') !== '1') {
        throw new RuntimeException('Database connection failed.');
    }
    return $context;
}

function priceContext(\Context $context): void
{
    $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
    $poland = (int) \Country::getByIso('PL');
    if (!\Validate::isLoadedObject($context->currency) || $context->currency->iso_code !== 'PLN'
        || !$poland || (int) \Configuration::get('PS_COUNTRY_DEFAULT') !== $poland
        || !\Configuration::get('PS_TAX')) {
        throw new RuntimeException('Expected PLN, Poland as default country, and enabled taxes.');
    }
    $context->cart = new \Cart();
    $context->customer = new \Customer();
}

function uniqueId(\DbQuery $query): ?int
{
    $rows = \Db::getInstance()->executeS($query);
    if ($rows === false || count($rows) > 1) {
        throw new RuntimeException('Lookup failed or duplicate records exist.');
    }
    return $rows === [] ? null : (int) $rows[0]['id'];
}

function transaction(callable $callback)
{
    $db = \Db::getInstance();
    if (!$db->execute('START TRANSACTION')) {
        throw new RuntimeException('Cannot start initialization transaction.');
    }
    try {
        $result = $callback();
        if (!$db->execute('COMMIT')) {
            throw new RuntimeException('Cannot commit initialization transaction.');
        }
        return $result;
    } catch (\Throwable $error) {
        $db->execute('ROLLBACK');
        // Some module hooks execute TRUNCATE, which implicitly commits MySQL.
        throw new RuntimeException($error->getMessage() . ' Rollback requested; module hooks may have committed earlier writes. Correct the error and rerun.', 0, $error);
    }
}

function importCategories(array $plan, \Context $context, bool $create = true): array
{
    // Validate all names before the first write.
    foreach ($plan as $entry) {
        if (!\Validate::isCatalogName($entry['name']) || mb_strlen($entry['name']) > 128
            || !\Validate::isLinkRewrite(\Tools::link_rewrite($entry['name']))) {
            throw new RuntimeException('Invalid PrestaShop category name: ' . $entry['name']);
        }
    }
    $shopId = (int) $context->shop->id;
    $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
    $home = (int) $context->shop->id_category;
    if (!\Validate::isLoadedObject(new \Category($home, $languageId, $shopId))) {
        throw new RuntimeException('Shop home category does not exist.');
    }
    $ids = [];
    foreach ($plan as $key => $entry) {
        $parentId = $entry['parent'] === null ? $home : ($ids[$entry['parent']] ?? null);
        if ($parentId === null) {
            throw new RuntimeException('Category parent was not resolved.');
        }
        $query = new \DbQuery();
        $query->select('c.id_category AS id')->from('category', 'c');
        $query->innerJoin('category_lang', 'cl', 'cl.id_category = c.id_category');
        $query->innerJoin('category_shop', 'cs', 'cs.id_category = c.id_category');
        $query->where('c.id_parent = ' . $parentId);
        $query->where('cl.id_lang = ' . $languageId);
        $query->where('cl.id_shop = ' . $shopId)->where('cs.id_shop = ' . $shopId);
        $query->where("cl.name = '" . \pSQL($entry['name']) . "'");
        $id = uniqueId($query);
        $status = 'EXISTS';
        if ($id === null) {
            if (!$create) {
                throw new RuntimeException('Missing category: ' . implode(' > ', $entry['path']) . '; run import-category.php or import-products.php first.');
            }
            $category = new \Category();
            $category->id_parent = $parentId;
            $category->id_shop_default = $shopId;
            $category->active = true;
            $category->is_root_category = false;
            foreach (\Language::getLanguages(false) as $language) {
                $languageIdForValue = (int) $language['id_lang'];
                $category->name[$languageIdForValue] = $entry['name'];
                $category->link_rewrite[$languageIdForValue] = \Tools::link_rewrite($entry['name']);
            }
            if (!$category->add()) {
                throw new RuntimeException('Cannot create category: ' . $entry['name']);
            }
            $id = (int) $category->id;
            $status = 'CREATED';
        }
        $ids[$key] = $id;
        echo $status . ': ' . implode(' > ', $entry['path']) . ", ID: {$id}" . PHP_EOL;
    }
    return $ids;
}

function findGroup(string $name, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('g.id_attribute_group AS id')->from('attribute_group', 'g');
    $query->innerJoin('attribute_group_lang', 'gl', 'gl.id_attribute_group = g.id_attribute_group');
    $query->innerJoin('attribute_group_shop', 'gs', 'gs.id_attribute_group = g.id_attribute_group');
    $query->where('gl.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where('gs.id_shop = ' . (int) $context->shop->id);
    $query->where("gl.name = '" . \pSQL($name) . "'");
    return uniqueId($query);
}

function findAttribute(int $groupId, string $value, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('a.id_attribute AS id')->from('attribute', 'a');
    $query->innerJoin('attribute_lang', 'al', 'al.id_attribute = a.id_attribute');
    $query->innerJoin('attribute_shop', 's', 's.id_attribute = a.id_attribute');
    $query->where('a.id_attribute_group = ' . $groupId);
    $query->where('al.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where('s.id_shop = ' . (int) $context->shop->id);
    $query->where("al.name = '" . \pSQL($value) . "'");
    return uniqueId($query);
}

function importAttributes(array $groups, \Context $context): array
{
    foreach ($groups as $name => $values) {
        if (!\Validate::isGenericName($name) || mb_strlen($name) > 64) {
            throw new RuntimeException("Invalid attribute group name: {$name}");
        }
        foreach ($values as $value) {
            if (!\Validate::isGenericName($value) || mb_strlen($value) > 128) {
                throw new RuntimeException("Invalid attribute value: {$value}");
            }
        }
    }
    $result = [];
    foreach ($groups as $name => $values) {
        $groupId = findGroup($name, $context);
        if ($groupId === null) {
            $group = new \AttributeGroup();
            $group->group_type = 'select';
            $group->is_color_group = false;
            foreach (\Language::getLanguages(false) as $language) {
                $id = (int) $language['id_lang'];
                $group->name[$id] = $name;
                $group->public_name[$id] = $name;
            }
            if (!$group->add()) {
                throw new RuntimeException("Cannot create attribute group: {$name}");
            }
            $groupId = (int) $group->id;
            echo "CREATED GROUP: {$name}, ID: {$groupId}" . PHP_EOL;
        } else {
            echo "EXISTS GROUP: {$name}, ID: {$groupId}" . PHP_EOL;
        }
        foreach ($values as $key => $value) {
            $id = findAttribute($groupId, $value, $context);
            $status = 'EXISTS';
            if ($id === null) {
                $query = new \DbQuery();
                $query->select('MAX(position)')->from('attribute')->where('id_attribute_group = ' . $groupId);
                $attribute = new \ProductAttribute();
                $attribute->id_attribute_group = $groupId;
                $attribute->position = (int) \Db::getInstance()->getValue($query) + 1;
                foreach (\Language::getLanguages(false) as $language) {
                    $attribute->name[(int) $language['id_lang']] = $value;
                }
                if (!$attribute->add()) {
                    throw new RuntimeException("Cannot create attribute: {$value}");
                }
                $id = (int) $attribute->id;
                $status = 'CREATED';
            }
            $result[$name][$key] = $id;
            echo "{$status} VALUE: {$name} = {$value}, ID: {$id}" . PHP_EOL;
        }
    }
    return $result;
}

function productGross(int $productId, $combinationId, \Context $context): string
{
    \Product::flushPriceCache();
    $gross = \Product::getPriceStatic(
        id_product: $productId, usetax: true, id_product_attribute: $combinationId,
        decimals: 2, usereduc: false, use_group_reduction: false,
        context: $context, use_customer_price: false
    );
    if ($gross === null) {
        throw new RuntimeException("Cannot calculate price for product {$productId}");
    }
    return rounded(decimal($gross, 'PrestaShop gross'), 2);
}

function combinationPlan(array $products, \Context $context): array
{
    priceContext($context);
    $result = [];
    $missing = [];
    foreach ($products as $source) {
        if ($source['variants'] === []) {
            continue;
        }
        $sourceId = (string) $source['product_id'];
        $query = new \DbQuery();
        $query->select('p.id_product AS id')->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product');
        $query->where('ps.id_shop = ' . (int) $context->shop->id);
        $query->where("p.reference = 'pandomu-" . \pSQL($sourceId) . "'");
        $id = uniqueId($query);
        if ($id === null) {
            $missing[] = $sourceId;
            continue;
        }
        $plan = pricePlan($source);
        $product = new \Product($id, false, null, (int) $context->shop->id);
        if (rounded(decimal($product->price, 'saved product net'), 6) !== $plan['net']
            || productGross($id, false, $context) !== $plan['gross']) {
            throw new RuntimeException("Product {$sourceId}: import the normalized base price and correct VAT first.");
        }
        foreach ($plan['variants'] as &$variant) {
            $ids = [];
            foreach ($variant['attributes'] as $group => $value) {
                $groupId = findGroup($group, $context);
                $attributeId = $groupId === null ? null : findAttribute($groupId, $value, $context);
                if ($attributeId === null) {
                    throw new RuntimeException("Product {$sourceId}: import attributes first ({$group} = {$value}).");
                }
                $ids[] = $attributeId;
            }
            sort($ids, SORT_NUMERIC);
            $variant['attribute_ids'] = $ids;
        }
        unset($variant);
        $result[] = ['product' => $product, 'source_id' => $sourceId, 'prices' => $plan];
    }
    if ($missing !== []) {
        throw new RuntimeException('Product drafts are missing; run import-products.php first. Source IDs: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' ...' : ''));
    }
    return $result;
}

function importCombinations(array $plans, \Context $context): array
{
    $result = [];
    foreach ($plans as $entry) {
        $product = $entry['product'];
        $query = new \DbQuery();
        $query->select('pa.id_product_attribute, pa.reference')->from('product_attribute', 'pa');
        $query->innerJoin('product_attribute_shop', 'pas', 'pas.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = ' . (int) $product->id)->where('pas.id_shop = ' . (int) $context->shop->id);
        $existing = \Db::getInstance()->executeS($query);
        if ($existing === false) {
            throw new RuntimeException('Cannot read existing combinations.');
        }
        $byReference = [];
        $byAttributes = [];
        foreach ($existing as $row) {
            $id = (int) $row['id_product_attribute'];
            $query = new \DbQuery();
            $query->select('id_attribute')->from('product_attribute_combination')->where('id_product_attribute = ' . $id)->orderBy('id_attribute');
            $attributes = \Db::getInstance()->executeS($query);
            if ($attributes === false) {
                throw new RuntimeException('Cannot read combination attributes.');
            }
            $signature = implode(',', array_map(fn (array $a): int => (int) $a['id_attribute'], $attributes));
            if (($row['reference'] !== '' && isset($byReference[$row['reference']])) || ($signature !== '' && isset($byAttributes[$signature]))) {
                throw new RuntimeException('Duplicate existing combination references or attribute sets.');
            }
            if ($row['reference'] !== '') {
                $byReference[$row['reference']] = $id;
            }
            if ($signature !== '') {
                $byAttributes[$signature] = $id;
            }
        }
        $ids = [];
        foreach ($entry['prices']['variants'] as $variant) {
            $signature = implode(',', $variant['attribute_ids']);
            $id = $byReference[$variant['reference']] ?? $byAttributes[$signature] ?? null;
            if (isset($byReference[$variant['reference']], $byAttributes[$signature])
                && $byReference[$variant['reference']] !== $byAttributes[$signature]) {
                throw new RuntimeException('Combination reference conflicts with its attribute set.');
            }
            $combination = $id === null ? new \Combination() : new \Combination($id, null, (int) $context->shop->id);
            $combination->id_product = (int) $product->id;
            $combination->reference = $variant['reference'];
            $combination->price = $variant['modifier'];
            $combination->minimal_quantity = 1;
            if (!($id === null ? $combination->add() : $combination->update())
                || !$combination->setAttributes($variant['attribute_ids'])) {
                throw new RuntimeException('Cannot save combination ' . $variant['source_id']);
            }
            $ids[] = (int) $combination->id;
            echo ($id === null ? 'CREATED' : 'UPDATED') . ': ' . $variant['reference'] . ', ID: ' . $combination->id . PHP_EOL;
        }
        // Preserve a valid existing default, otherwise select the first source combination.
        $default = in_array((int) $product->cache_default_attribute, $ids, true)
            ? (int) $product->cache_default_attribute : $ids[0];
        if (!$product->deleteDefaultAttributes() || !$product->setDefaultAttribute($default)) {
            throw new RuntimeException('Cannot set the default combination.');
        }
        foreach ($entry['prices']['variants'] as $index => $variant) {
            $gross = productGross((int) $product->id, $ids[$index], $context);
            if ($gross !== $variant['gross']) {
                throw new RuntimeException('Gross price mismatch for combination ' . $variant['source_id'] . ": {$gross} instead of " . $variant['gross']);
            }
            echo 'PRICE OK: ' . $variant['source_id'] . ", {$gross} PLN gross" . PHP_EOL;
        }
        $result[$entry['source_id']] = $ids;
    }
    return $result;
}
