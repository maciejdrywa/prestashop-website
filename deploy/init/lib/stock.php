<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';

function catalogStockPlan(array $products): array
{
    $count = count($products);
    $minimum = (int) ceil($count * 0.05);
    $maximum = (int) floor($count * 0.20);
    if ($count === 0 || $minimum > $maximum) {
        throw new RuntimeException('Catalog is too small for 5-20% unavailable products.');
    }
    $zeroCount = max($minimum, min($maximum, (int) round($count * 0.10)));
    $ids = array_map(fn (array $p): string => (string) $p['product_id'], $products);
    if (count(array_unique($ids)) !== $count) {
        throw new RuntimeException('Duplicate source IDs in stock plan.');
    }
    usort($ids, fn (string $a, string $b): int => strcmp(
        hash('sha256', 'stock-v1:unavailable:' . $a),
        hash('sha256', 'stock-v1:unavailable:' . $b)
    ) ?: strcmp($a, $b));
    $result = [];
    foreach ($ids as $index => $id) {
        $result[$id] = $index < $zeroCount ? 0 : 1 + (int) (hexdec(substr(hash('sha256', 'stock-v1:quantity:' . $id), 0, 8)) % 10);
    }
    return $result;
}

function distributeStock(int $total, array $sourceIds): array
{
    if ($total < 0 || $total > 10 || $sourceIds === [] || count(array_unique($sourceIds)) !== count($sourceIds)) {
        throw new RuntimeException('Invalid combination stock distribution.');
    }
    $sourceIds = array_map('strval', $sourceIds);
    usort($sourceIds, fn (string $a, string $b): int => (int) $a <=> (int) $b);
    $quantities = [];
    foreach ($sourceIds as $index => $id) {
        $quantities[$id] = intdiv($total, count($sourceIds)) + ($index < $total % count($sourceIds) ? 1 : 0);
    }
    return $quantities;
}

function stockRows(int $productId, \Context $context): array
{
    $rows = \Db::getInstance()->executeS(
        'SELECT id_product_attribute, quantity, out_of_stock, depends_on_stock FROM ' . _DB_PREFIX_ . 'stock_available'
        . ' WHERE id_product = ' . $productId . \StockAvailable::addSqlShopRestriction(null, (int) $context->shop->id), true, false
    );
    if ($rows === false) {
        throw new RuntimeException("Cannot read stock: {$productId}");
    }
    $result = [];
    foreach ($rows as $row) {
        $id = (int) $row['id_product_attribute'];
        if (isset($result[$id])) {
            throw new RuntimeException("Duplicate stock rows: {$productId}");
        }
        $result[$id] = $row;
    }
    return $result;
}

function stockPlans(array $products, array $catalogPlan, \Context $context): array
{
    if (!\Configuration::get('PS_STOCK_MANAGEMENT')) {
        throw new RuntimeException('Stock management must be enabled in PrestaShop.');
    }
    $plans = [];
    foreach ($products as $source) {
        $sourceId = (string) $source['product_id'];
        if (!array_key_exists($sourceId, $catalogPlan)) {
            throw new RuntimeException('Product missing from full catalog stock plan: ' . $sourceId);
        }
        $id = productId('pandomu-' . $sourceId, $context);
        if ($id === null) {
            throw new RuntimeException("Missing product {$sourceId}; run import-products.php first.");
        }
        $product = new \Product($id, false, null, (int) $context->shop->id);
        if ((int) $product->state !== \Product::STATE_SAVED) {
            throw new RuntimeException("Product {$sourceId} is not a saved catalog product.");
        }
        if (\StockAvailable::dependsOnStock($id, (int) $context->shop->id)) {
            throw new RuntimeException("Product {$sourceId} uses advanced warehouse stock; manual quantity seeding is not supported.");
        }
        $query = new \DbQuery();
        $query->select('pa.id_product_attribute, pa.reference')->from('product_attribute', 'pa');
        $query->innerJoin('product_attribute_shop', 's', 's.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = ' . $id)->where('s.id_shop = ' . (int) $context->shop->id);
        $rows = \Db::getInstance()->executeS($query, true, false);
        if ($rows === false || count($rows) !== count($source['variants'])) {
            throw new RuntimeException("Combination count mismatch: {$sourceId}; import combinations first.");
        }
        $byReference = [];
        foreach ($rows as $row) {
            if (isset($byReference[$row['reference']])) {
                throw new RuntimeException('Duplicate combination reference: ' . $row['reference']);
            }
            $byReference[$row['reference']] = (int) $row['id_product_attribute'];
        }
        $total = $catalogPlan[$sourceId];
        if (!is_int($total) || $total < 0 || $total > 10) {
            throw new RuntimeException('Planned product stock is outside 0-10: ' . $sourceId);
        }
        $quantities = [];
        if ($source['variants'] !== []) {
            $distribution = distributeStock($total, array_column($source['variants'], 'variant_id'));
            foreach ($distribution as $variantId => $quantity) {
                $reference = 'pandomu-' . $sourceId . '-combination-' . $variantId;
                if (!isset($byReference[$reference])) {
                    throw new RuntimeException("Missing combination {$reference}; import combinations first.");
                }
                $quantities[$byReference[$reference]] = $quantity;
            }
        }
        foreach (stockRows($id, $context) as $attributeId => $row) {
            if (($attributeId !== 0 && !array_key_exists($attributeId, $quantities)) || (int) $row['depends_on_stock'] !== 0) {
                throw new RuntimeException("Stale or warehouse-managed stock row for product {$sourceId}.");
            }
        }
        $default = (int) $product->cache_default_attribute;
        if ($quantities !== []) {
            $candidates = $total > 0 ? array_filter($quantities, fn (int $q): bool => $q > 0) : $quantities;
            if (!array_key_exists($default, $candidates)) {
                $default = (int) array_key_first($candidates);
            }
        }
        $plans[] = ['source_id' => $sourceId, 'product_id' => $id, 'total' => $total,
            'combinations' => $quantities, 'default' => $default];
    }
    return $plans;
}

function verifyProductStock(array $plan, \Context $context): void
{
    $rows = stockRows($plan['product_id'], $context);
    $expected = [0 => $plan['total']] + $plan['combinations'];
    if (count($rows) !== count($expected)) {
        throw new RuntimeException('Stock row count mismatch: ' . $plan['source_id']);
    }
    foreach ($expected as $id => $quantity) {
        $row = $rows[$id] ?? null;
        if ($row === null || (int) $row['quantity'] !== $quantity || (int) $row['out_of_stock'] !== 0
            || (int) $row['depends_on_stock'] !== 0 || $quantity < 0 || $quantity > 10) {
            throw new RuntimeException('Stock or out-of-stock policy mismatch: ' . $plan['source_id']);
        }
    }
    if ($plan['combinations'] !== [] && array_sum($plan['combinations']) !== $plan['total']) {
        throw new RuntimeException('Product stock is not the sum of its combinations.');
    }
    $product = new \Product($plan['product_id'], false, null, (int) $context->shop->id);
    if (!$product->active || !$product->available_for_order || $product->visibility !== 'both') {
        throw new RuntimeException('Product is not active and available in the catalog: ' . $plan['source_id']);
    }
    if ($plan['combinations'] !== []) {
        if ((int) $product->cache_default_attribute !== $plan['default']
            || ($plan['total'] > 0 && ($rows[$plan['default']]['quantity'] ?? 0) <= 0)) {
            throw new RuntimeException('Default combination is unavailable despite positive product stock.');
        }
        $query = new \DbQuery();
        $query->select('pa.id_product_attribute')->from('product_attribute', 'pa');
        $query->innerJoin('product_attribute_shop', 's', 's.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = ' . $plan['product_id'])->where('s.id_shop = ' . (int) $context->shop->id);
        $query->where('s.default_on = 1');
        $defaults = \Db::getInstance()->executeS($query, true, false);
        if ($defaults === false || count($defaults) !== 1 || (int) $defaults[0]['id_product_attribute'] !== $plan['default']) {
            throw new RuntimeException('Invalid default combination marker.');
        }
    }
}

function importStock(array $plans, \Context $context): array
{
    $zero = 0;
    foreach ($plans as $index => $plan) {
        $id = $plan['product_id'];
        $shopId = (int) $context->shop->id;
        foreach ($plan['combinations'] as $combinationId => $quantity) {
            \StockAvailable::setQuantity($id, $combinationId, $quantity, $shopId, false);
        }
        // The core aggregates combinations too; explicitly set/check the parent row.
        \StockAvailable::setQuantity($id, 0, $plan['total'], $shopId, false);
        \StockAvailable::setProductOutOfStock($id, 0, $shopId);
        $product = new \Product($id, false, null, $shopId);
        $product->active = true;
        $product->available_for_order = true;
        $product->visibility = 'both';
        if (!$product->update()) {
            throw new RuntimeException('Cannot activate product: ' . $plan['source_id']);
        }
        if ($plan['combinations'] !== []
            && (!$product->deleteDefaultAttributes() || !$product->setDefaultAttribute($plan['default']))) {
            throw new RuntimeException('Cannot set available default combination.');
        }
        verifyProductStock($plan, $context);
        $zero += $plan['total'] === 0 ? 1 : 0;
        echo '[' . ($index + 1) . '/' . count($plans) . '] STOCK: pandomu-' . $plan['source_id']
            . ', quantity: ' . $plan['total'] . ', combinations: ' . count($plan['combinations'])
            . ", active: YES, backorders: NO\n";
    }
    return ['products' => count($plans), 'unavailable' => $zero,
        'unavailable_percent' => number_format(100 * $zero / max(1, count($plans)), 2, '.', '')];
}
