<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/prestashop.php';
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function counts(): array
{
    $result = [];
    foreach (['product', 'product_shop', 'category', 'category_lang', 'category_shop',
        'category_product', 'category_group', 'attribute_group', 'attribute_group_lang',
        'attribute_group_shop', 'attribute', 'attribute_lang', 'attribute_shop',
        'product_attribute', 'product_attribute_shop', 'product_attribute_combination', 'stock_available'] as $table) {
        $result[$table] = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table, false);
    }
    return $result;
}
$started = false;
try {
    $context = ShopInit\boot();
    $db = Db::getInstance();
    // Faceted search runs TRUNCATE from hooks, which implicitly commits MySQL
    // transactions. Disable module hooks in this test process only.
    foreach (Module::getModulesInstalled() as $module) {
        Hook::disableHooksForModule((int) $module['id_module']);
    }
    $before = counts();
    foreach (array_keys($before) as $table) {
        $engine = $db->getValue("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . $table) . "'");
        verify(strtoupper((string) $engine) === 'INNODB', 'Rollback test requires InnoDB: ' . $table);
    }
    $catalog = ShopInit\readCatalog();
    $products = array_values(array_filter($catalog, fn (array $p): bool => in_array((int) $p['product_id'], [5, 119, 644, 463, 808, 826], true)));
    foreach ($products as $source) {
        verify(!$db->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . "product WHERE reference = 'pandomu-" . (int) $source['product_id'] . "'"), 'Test source product already exists; use a fresh test shop.');
    }
    verify($db->execute('START TRANSACTION'), 'Cannot start test transaction');
    $started = true;
    $plan = ShopInit\categoryPlan($catalog, true);
    $categories = ShopInit\importCategories($plan, $context);
    verify($categories === ShopInit\importCategories($plan, $context), 'Category IDs changed on repeat');
    $groups = ShopInit\attributePlan($catalog);
    $attributes = ShopInit\importAttributes($groups, $context);
    verify($attributes === ShopInit\importAttributes($groups, $context), 'Attribute IDs changed on repeat');
    foreach ($products as $source) {
        $price = ShopInit\pricePlan($source);
        $taxGroup = (int) $db->getValue('SELECT r.id_tax_rules_group FROM ' . _DB_PREFIX_ . 'tax_rule r'
            . ' JOIN ' . _DB_PREFIX_ . 'tax t ON t.id_tax = r.id_tax'
            . ' JOIN ' . _DB_PREFIX_ . 'tax_rules_group g ON g.id_tax_rules_group = r.id_tax_rules_group'
            . ' WHERE g.active = 1 AND g.deleted = 0 AND t.active = 1 AND t.deleted = 0 AND r.id_country = ' . (int) Country::getByIso('PL')
            . ' AND r.id_state = 0 AND r.zipcode_from = 0 AND r.zipcode_to = 0 AND r.behavior = 0 AND t.rate = ' . (float) $price['vat']
            . ' ORDER BY r.id_tax_rules_group');
        verify($taxGroup > 0, 'Missing test VAT rule');
        $product = new Product();
        $product->reference = 'pandomu-' . $source['product_id'];
        $product->price = $price['net'];
        $product->id_tax_rules_group = $taxGroup;
        $product->id_category_default = $categories[json_encode($source['category_paths'][0], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        $product->active = false;
        foreach (Language::getLanguages(false) as $language) {
            $id = (int) $language['id_lang'];
            $product->name[$id] = $source['name'];
            $product->link_rewrite[$id] = Tools::link_rewrite($source['name']);
        }
        verify($product->add(), 'Cannot create temporary draft');
    }
    $first = ShopInit\importCombinations(ShopInit\combinationPlan($products, $context), $context);
    $legacyId = reset($first)[0];
    verify($db->update('product_attribute', ['reference' => 'legacy-option-reference'], 'id_product_attribute = ' . $legacyId), 'Cannot prepare migration fixture');
    $second = ShopInit\importCombinations(ShopInit\combinationPlan($products, $context), $context);
    verify($first === $second, 'Combination IDs changed on repeat/migration');
    $expected = array_sum(array_map(fn (array $p): int => count($p['variants']), $products));
    verify(array_sum(array_map('count', $first)) === $expected, 'Combination count mismatch');
    verify(counts()['product_attribute'] === $before['product_attribute'] + $expected, 'Duplicate combinations were created');
    foreach ($products as $source) {
        foreach (ShopInit\pricePlan($source)['variants'] as $index => $variant) {
            $id = $first[$source['product_id']][$index];
            $query = new DbQuery();
            $query->select('COUNT(*)')->from('product_attribute_combination')->where('id_product_attribute = ' . $id);
            verify((int) $db->getValue($query, false) === count($variant['attributes']), 'Not all attribute groups saved');
        }
    }
    verify($db->execute('ROLLBACK'), 'Rollback failed');
    $started = false;
    $after = counts();
    verify($after === $before, 'Database counts differ after rollback: ' . json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR));
    echo "OK: all catalog categories and attributes imported twice; {$expected} combinations, actual PrestaShop prices and legacy references verified. Test writes rolled back.\n";
} catch (Throwable $error) {
    if ($started) {
        Db::getInstance()->execute('ROLLBACK');
    }
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
