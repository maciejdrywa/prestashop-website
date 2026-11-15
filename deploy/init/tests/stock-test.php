<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/stock.php';

function stockTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function stockTestCounts(): array
{
    $result = [];
    foreach (['product', 'product_shop', 'product_lang', 'category_product', 'product_attribute',
        'product_attribute_shop', 'product_attribute_combination', 'stock_available', 'stock_mvt'] as $table) {
        $result[$table] = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table, false);
    }
    return $result;
}
$started = false;
try {
    $catalog = ShopInit\readCatalog();
    $plan = ShopInit\catalogStockPlan($catalog);
    $reordered = array_reverse($catalog);
    stockTest($plan === ShopInit\catalogStockPlan($reordered), 'Plan depends on source ordering');
    $zeroCount = count(array_filter($plan, fn (int $q): bool => $q === 0));
    stockTest($zeroCount / count($plan) >= 0.05 && $zeroCount / count($plan) <= 0.20, 'Catalog percentage is incorrect');
    foreach ($plan as $quantity) {
        stockTest($quantity >= 0 && $quantity <= 10, 'Catalog quantity outside limits');
    }
    $variantIds = array_map('strval', range(1, 12));
    foreach ([0, 1, 10] as $total) {
        $split = ShopInit\distributeStock($total, $variantIds);
        stockTest(array_sum($split) === $total, 'Combination sum differs from total');
        stockTest($split === ShopInit\distributeStock($total, array_reverse($variantIds)), 'Distribution depends on source order');
        stockTest(min($split) >= 0 && max($split) <= 10, 'Combination stock outside limits');
    }
    $context = ShopInit\boot();
    $db = Db::getInstance();
    // Core module hooks can commit transactions; disable them in this test only.
    foreach (Module::getModulesInstalled() as $module) {
        Hook::disableHooksForModule((int) $module['id_module']);
    }
    $before = stockTestCounts();
    foreach (array_keys($before) as $table) {
        $engine = $db->getValue("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . $table) . "'");
        stockTest(strtoupper((string) $engine) === 'INNODB', 'Rollback test requires InnoDB: ' . $table);
    }
    $products = [];
    foreach ([12, 119] as $sourceId) {
        $source = array_values(array_filter($catalog, fn (array $p): bool => (int) $p['product_id'] === $sourceId))[0];
        $source['product_id'] = (string) (900000000 + $sourceId);
        stockTest(ShopInit\productId('pandomu-' . $source['product_id'], $context) === null, 'Test product already exists');
        $products[] = $source;
    }
    stockTest((bool) $db->execute('START TRANSACTION'), 'Cannot start test transaction');
    $started = true;
    $categories = ShopInit\importCategories(ShopInit\categoryPlan($products), $context, false);
    $ids = ShopInit\importProducts(ShopInit\productPlans($products, $context), $categories, $context);
    $quantities = ['900000012' => 10, '900000119' => 1];
    $rejected = false;
    try {
        ShopInit\stockPlans($products, $quantities, $context);
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    stockTest($rejected, 'Missing combinations were accepted');
    stockTest(!(new Product($ids['900000012']))->active, 'Preflight activated a product');
    ShopInit\importAttributes(ShopInit\attributePlan($products), $context);
    $combinations = ShopInit\importCombinations(ShopInit\combinationPlan($products, $context), $context);
    $combinationIds = $combinations['900000119'];
    $oldDefault = end($combinationIds);
    $product = new Product($ids['900000119']);
    stockTest((bool) $product->deleteDefaultAttributes() && (bool) $product->setDefaultAttribute($oldDefault), 'Cannot prepare unavailable default fixture');
    $plans = ShopInit\stockPlans($products, $quantities, $context);
    ShopInit\importStock($plans, $context);
    stockTest((int) (new Product($ids['900000119']))->cache_default_attribute !== $oldDefault, 'Unavailable default combination was kept');
    $firstRows = array_map(fn (int $id): array => ShopInit\stockRows($id, $context), $ids);
    ShopInit\importStock(ShopInit\stockPlans($products, $quantities, $context), $context);
    stockTest($firstRows === array_map(fn (int $id): array => ShopInit\stockRows($id, $context), $ids), 'Repeated import changed quantities');
    foreach ([['900000012' => 0, '900000119' => 0], ['900000012' => 1, '900000119' => 10]] as $quantities) {
        $plans = ShopInit\stockPlans($products, $quantities, $context);
        ShopInit\importStock($plans, $context);
        foreach ($plans as $entry) {
            ShopInit\verifyProductStock($entry, $context);
        }
    }
    stockTest(stockTestCounts()['stock_mvt'] === $before['stock_mvt'], 'Initialization created stock movements');
    stockTest((bool) $db->execute('ROLLBACK'), 'Rollback failed');
    $started = false;
    stockTest(stockTestCounts() === $before, 'Test rows remained after rollback');
    echo "OK: deterministic catalog and distribution; simple/12-combination totals 0, 1 and 10; default selection, activation, backorder denial, repeat import and preflight verified. Test writes rolled back.\n";
} catch (Throwable $error) {
    if ($started) {
        Db::getInstance()->execute('ROLLBACK');
    }
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
