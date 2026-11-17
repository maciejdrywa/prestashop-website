<?php
declare(strict_types=1);

/**
 * Planowanie, ustawianie i weryfikacja początkowego magazynu oraz aktywacji.
 *
 * Wywoływana po imporcie produktów i kombinacji przez import-stock.php lub init-shop.php.
 *
 * catalogStockPlan obejmuje cały katalog, stockPlans rozwiązuje docelowe ID, a importStock zapisuje
 * StockAvailable i Product.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';
const INITIAL_STOCK_MAX = 10;
const INITIAL_UNAVAILABLE_MIN = 0.05;
const INITIAL_UNAVAILABLE_MAX = 0.20;
const INITIAL_UNAVAILABLE_TARGET = 0.10;

/**
 * Wyznacza deterministyczne ilości 0–10 i około 10% produktów niedostępnych.
 *
 * Wyznacza dopuszczalną liczbę zer przez ceil i floor, a następnie zbliża ją do 10% katalogu. Ranking
 * SHA-256 oraz osobny hash ilości uniezależniają wynik od kolejności wejścia i liczby ponowień.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @return array<string, int> Całkowita początkowa ilość produktu indeksowana źródłowym ID.
 * @throws \RuntimeException Przy powielonych ID lub liczbie produktów uniemożliwiającej spełnienie odsetka zer.
 */
function catalogStockPlan(array $products): array
{
    $count = count($products);
    $minimum = (int) ceil($count * INITIAL_UNAVAILABLE_MIN);
    $maximum = (int) floor($count * INITIAL_UNAVAILABLE_MAX);
    if ($count === 0 || $minimum > $maximum) {
        throw new RuntimeException('Catalog is too small for 5-20% unavailable products.');
    }
    $zeroCount = max($minimum, min($maximum, (int) round($count * INITIAL_UNAVAILABLE_TARGET)));
    $ids = array_map(fn (array $p): string => (string) $p['product_id'], $products);
    if (count(array_unique($ids)) !== $count) {
        throw new RuntimeException('Duplicate source IDs in stock plan.');
    }
    // Ranking hashy wybiera produkty niedostępne niezależnie od kolejności JSON.
    // Osobny zakres hasha dla ilości zapobiega powiązaniu ilości z pozycją w rankingu.
    usort($ids, fn (string $a, string $b): int => strcmp(
        hash('sha256', 'stock-v1:unavailable:' . $a),
        hash('sha256', 'stock-v1:unavailable:' . $b)
    ) ?: strcmp($a, $b));
    $result = [];
    foreach ($ids as $index => $id) {
        $quantityHash = substr(hash('sha256', 'stock-v1:quantity:' . $id), 0, 8);
        $result[$id] = $index < $zeroCount ? 0 : 1 + (int) (hexdec($quantityHash) % INITIAL_STOCK_MAX);
    }
    return $result;
}

/**
 * Dzieli całkowity stan produktu pomiędzy warianty, zachowując sumę i kolejność źródłowych ID.
 *
 * Po sortowaniu ID każda kombinacja otrzymuje iloraz całkowity, a pierwsze kombinacje po jednej sztuce z
 * reszty. Przy liczbie wariantów większej od total część wariantów ma zerowy stan.
 *
 * @param int $total Całkowity stan produktu w zakresie 0–10.
 * @param array $sourceIds Niepusta lista unikalnych źródłowych ID kombinacji.
 * @return array<string, int> Ilości według źródłowych ID kombinacji, sumujące się do $total.
 * @throws \RuntimeException Przy ilości spoza zakresu albo pustej lub powielonej liście ID wariantów.
 */
function distributeStock(int $total, array $sourceIds): array
{
    if ($total < 0 || $total > INITIAL_STOCK_MAX || $sourceIds === [] || count(array_unique($sourceIds)) !== count($sourceIds)) {
        throw new RuntimeException('Invalid combination stock distribution.');
    }
    $sourceIds = array_map('strval', $sourceIds);
    usort($sourceIds, fn (string $a, string $b): int => (int) $a <=> (int) $b);
    $quantities = [];
    // Dzielenie całkowite daje bazę każdemu wariantowi, a reszta jest rozdzielana
    // po jednej sztuce. Dzięki temu suma wariantów pozostaje dokładnie równa total.
    foreach ($sourceIds as $index => $id) {
        $quantities[$id] = intdiv($total, count($sourceIds)) + ($index < $total % count($sourceIds) ? 1 : 0);
    }
    return $quantities;
}

/**
 * Odczytuje stany produktu i wariantów w sklepie oraz odrzuca powielone wiersze.
 *
 * Używa natywnego ograniczenia sklepu i współdzielenia magazynu. Klucz 0 oznacza ilość całego produktu, a
 * pozostałe klucze ilości kombinacji. Wiersze zawierają quantity, out_of_stock i depends_on_stock.
 *
 * @param int $productId Docelowe ID produktu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array<int, array> Wiersze magazynu indeksowane ID kombinacji, z kluczem 0 dla sumy produktu.
 * @throws \RuntimeException Przy błędzie odczytu magazynu lub powieleniu wiersza dla tej samej kombinacji.
 */
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

/**
 * Łączy plan ilości z zapisanymi produktami i wariantami bez zmiany magazynu.
 *
 * Sprawdza komplet referencji kombinacji, odrzuca magazyn zaawansowany i obce wiersze stock_available.
 * Wybiera istniejący dostępny wariant domyślny albo pierwszy wariant z dodatnią ilością, zachowując sumę
 * produktu.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @param array $catalogPlan Plan początkowych ilości dla całego katalogu według źródłowych ID.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return list<array{source_id: string, product_id: int, total: int, combinations: array<int, int>, default: int}>
 * @throws \RuntimeException Przy niekompletnym katalogu, konflikcie referencji, magazynie zaawansowanym lub niepoprawnej ilości.
 */
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
        // Referencje łączą wariant źródła z ID PrestaShop. Zgodna liczba wierszy
        // i unikalne referencje są wymagane przed planowaniem ilości dla kombinacji.
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
        if (!is_int($total) || $total < 0 || $total > INITIAL_STOCK_MAX) {
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
        // Produkt z dodatnim stanem powinien otwierać się na dostępnym wariancie.
        // Przy zerze wszystkich wariantów zachowujemy poprawny domyślny lub wybieramy pierwszy.
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

/**
 * Sprawdza początkowe ilości, sumy wariantów, aktywację i dostępność domyślnej kombinacji.
 *
 * @param array $plan Plan produktu z total, combinations i docelowym wariantem default.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy rozbieżności ilości, polityki braków, aktywacji lub domyślnego wariantu.
 */
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
            || (int) $row['depends_on_stock'] !== 0 || $quantity < 0 || $quantity > INITIAL_STOCK_MAX) {
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

/**
 * Ustawia początkowy magazyn, aktywację i dostępny wariant domyślny.
 *
 * Nadpisuje ilości początkowe, wyłącza zamawianie przy braku stanu i aktywuje produkt. Aktualizuje wariant
 * domyślny oraz sprawdza zapis. We wznowieniu decyzję o dopuszczeniu tego zapisu podejmuje
 * resumeCatalogRecord.
 *
 * @param array $plans Plany stockPlans z docelowymi ID, ilościami i wariantem domyślnym.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array{products: int, unavailable: int, unavailable_percent: string}
 * @throws \RuntimeException Przy błędzie aktywacji, ustawienia wariantu domyślnego lub końcowej kontroli magazynu.
 */
function importStock(array $plans, \Context $context): array
{
    $zero = 0;
    foreach ($plans as $index => $plan) {
        $id = $plan['product_id'];
        $shopId = (int) $context->shop->id;
        foreach ($plan['combinations'] as $combinationId => $quantity) {
            \StockAvailable::setQuantity($id, $combinationId, $quantity, $shopId, false);
        }
        // PrestaShop sumuje warianty. Wiersz główny dodatkowo ustawiamy i sprawdzamy.
        \StockAvailable::setQuantity($id, 0, $plan['total'], $shopId, false);
        \StockAvailable::setProductOutOfStock($id, 0, $shopId);
        $product = new \Product($id, false, null, $shopId);
        // Aktywacja następuje po zapisie ilości. Wznowienie wykorzystuje aktywność
        // jako sygnał, że produkt mógł już uczestniczyć w sprzedaży.
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
