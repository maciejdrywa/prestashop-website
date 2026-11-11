<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/products.php';
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function counts(): array
{
    $result = [];
    foreach (['product', 'product_shop', 'product_lang', 'category', 'category_lang', 'category_shop',
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
    // Private references allow the test to run after the real catalog import.
    foreach ($products as &$source) {
        $source['product_id'] = (string) (900000000 + (int) $source['product_id']);
    }
    unset($source);
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
    $bad = $products;
    $bad[1]['description'] = '<script>alert(1)</script>';
    $rejected = false;
    try {
        ShopInit\productPlans($bad, $context);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    verify($rejected, 'Unsafe description was accepted');
    verify(counts()['product'] === $before['product'], 'Preflight created products');
    $productPlans = ShopInit\productPlans($products, $context);
    $productIds = ShopInit\importProducts($productPlans, $categories, $context);
    verify($productIds === ShopInit\importProducts($productPlans, $categories, $context), 'Product IDs changed on repeat');
    $firstSource = (string) $products[0]['product_id'];
    $firstId = $productIds[$firstSource];
    $saved = new Product($firstId);
    $saved->active = true;
    verify((bool) $saved->update(), 'Cannot prepare activation preservation fixture');
    StockAvailable::setQuantity($firstId, 0, 7, (int) $context->shop->id);
    $changed = $products[0];
    $changed['name'] = 'Updated import fixture';
    $changed['description'] = '<p>Updated description with Polish text: test.</p>';
    $changed['weight'] = 2.5;
    $changed['category_paths'] = [$catalog[1]['category_paths'][0], $catalog[2]['category_paths'][0]];
    $changedPlans = ShopInit\productPlans([$changed], $context);
    verify(ShopInit\importProducts($changedPlans, $categories, $context)[$firstSource] === $firstId, 'Updated product was duplicated');
    $saved = new Product($firstId);
    verify((bool) $saved->active, 'Product activation was reset');
    verify((int) StockAvailable::getQuantityAvailableByProduct($firstId, 0, (int) $context->shop->id) === 7, 'Stock was overwritten');
    ShopInit\importProducts($productPlans, $categories, $context);
    $first = ShopInit\importCombinations(ShopInit\combinationPlan($products, $context), $context);
    verify($productIds === ShopInit\importProducts($productPlans, $categories, $context), 'Reimport after combinations duplicated products');
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
