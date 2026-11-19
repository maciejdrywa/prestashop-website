<?php
declare(strict_types=1);

/**
 * Planowanie, konfiguracja i weryfikacja promocji cenowych w sklepie.
 *
 * Używana przez configure-promotions.php oraz proces inicjalizacji init-shop.php.
 *
 * promotionsDefinitions zwraca deterministyczny zestaw 25 promocji (procentowych i kwotowych).
 * configurePromotions zapisuje rekordy SpecificPrice z podatkiem brutto (reduction_tax = 1).
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';
require_once __DIR__ . '/products.php';

/**
 * Zapewnia kontrolowany kontekst kalkulatora cen (PLN, brak klienta/koszyka, pracownik CLI).
 *
 * @param \Context $context
 * @return void
 */
function ensurePriceContext(\Context $context): void
{
    priceContext($context);
    if (!isset($context->employee) || !\Validate::isLoadedObject($context->employee)) {
        $context->employee = new \Employee(1);
    }
}

/**
 * Zwraca deterministyczną listę 25 produktów objętych promocją cenową.
 *
 * Zestaw obejmuje:
 * - 15 rabatów procentowych (od -10% do -30%), w tym wszystkie 10 produktów z wariantami
 * - 10 rabatów kwotowych brutto (od -5,00 zł do -35,00 zł) wyłącznie dla produktów prostych
 *
 * @return list<array{source_id: string, reduction_type: 'percentage'|'amount', reduction: float}>
 */
function promotionsDefinitions(): array
{
    return [
        // 15 rabatów procentowych (percentage) - wszystkie produkty z wariantami + produkty proste
        ['source_id' => '12',  'reduction_type' => 'percentage', 'reduction' => 0.10], // prosty
        ['source_id' => '901', 'reduction_type' => 'percentage', 'reduction' => 0.10], // prosty
        ['source_id' => '393', 'reduction_type' => 'percentage', 'reduction' => 0.10], // warianty (4)
        ['source_id' => '137', 'reduction_type' => 'percentage', 'reduction' => 0.15], // prosty
        ['source_id' => '501', 'reduction_type' => 'percentage', 'reduction' => 0.15], // prosty
        ['source_id' => '343', 'reduction_type' => 'percentage', 'reduction' => 0.15], // warianty (2)
        ['source_id' => '909', 'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (9)
        ['source_id' => '5',   'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (2)
        ['source_id' => '107', 'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (13)
        ['source_id' => '712', 'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (13)
        ['source_id' => '466', 'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (4)
        ['source_id' => '504', 'reduction_type' => 'percentage', 'reduction' => 0.20], // warianty (2)
        ['source_id' => '815', 'reduction_type' => 'percentage', 'reduction' => 0.25], // warianty (2)
        ['source_id' => '576', 'reduction_type' => 'percentage', 'reduction' => 0.25], // prosty
        ['source_id' => '944', 'reduction_type' => 'percentage', 'reduction' => 0.30], // warianty (2)

        // 10 rabatów kwotowych brutto (amount) - wyłącznie produkty proste bez wariantów
        ['source_id' => '913', 'reduction_type' => 'amount', 'reduction' => 5.00],
        ['source_id' => '507', 'reduction_type' => 'amount', 'reduction' => 5.00],
        ['source_id' => '151', 'reduction_type' => 'amount', 'reduction' => 8.00],
        ['source_id' => '896', 'reduction_type' => 'amount', 'reduction' => 10.00],
        ['source_id' => '4',   'reduction_type' => 'amount', 'reduction' => 10.00],
        ['source_id' => '444', 'reduction_type' => 'amount', 'reduction' => 15.00],
        ['source_id' => '139', 'reduction_type' => 'amount', 'reduction' => 15.00],
        ['source_id' => '541', 'reduction_type' => 'amount', 'reduction' => 20.00],
        ['source_id' => '738', 'reduction_type' => 'amount', 'reduction' => 25.00],
        ['source_id' => '685', 'reduction_type' => 'amount', 'reduction' => 35.00],
    ];
}

/**
 * Buduje zweryfikowany plan promocji łącząc definicje z produktami w bazie.
 *
 * @param array $catalog Lista rekordów źródłowych products.json.
 * @param \Context $context Kontekst PrestaShop.
 * @return list<array{source_id: string, product_id: int, name: string, regular_gross: float, expected_promo_gross: float, reduction_type: string, reduction: float}>
 * @throws \RuntimeException Gdy produkt nie istnieje, cena bazowa jest zbyt niska lub rabat jest niepoprawny.
 */
function promotionsPlan(array $catalog, \Context $context): array
{
    ensurePriceContext($context);
    $definitions = promotionsDefinitions();
    $bySourceId = [];
    foreach ($catalog as $item) {
        $bySourceId[(string) $item['product_id']] = $item;
    }

    $plan = [];
    foreach ($definitions as $def) {
        $sourceId = $def['source_id'];
        if (!isset($bySourceId[$sourceId])) {
            throw new RuntimeException("Promotional product {$sourceId} is absent from source catalog.");
        }
        $source = $bySourceId[$sourceId];
        $reference = 'pandomu-' . $sourceId;
        $productId = productId($reference, $context);
        if ($productId === null) {
            throw new RuntimeException("Product {$reference} does not exist in PrestaShop.");
        }

        // Sprawdzamy czy produkt posiada warianty/kombinacje
        $attrIds = \Product::getProductAttributesIds($productId);
        if ($attrIds !== []) {
            if ($def['reduction_type'] === 'amount') {
                throw new RuntimeException("Product {$sourceId} has combinations; only percentage discounts are allowed for variant products.");
            }
            // Weryfikujemy każdą kombinację (w tym najtańszą)
            foreach ($attrIds as $attrRow) {
                $attrId = (int) $attrRow['id_product_attribute'];
                $varRegularGross = (float) \Product::getPriceStatic(
                    id_product: $productId,
                    usetax: true,
                    id_product_attribute: $attrId,
                    decimals: 2,
                    usereduc: false,
                    use_group_reduction: false,
                    context: $context,
                    use_customer_price: false
                );
                if ($varRegularGross < 20.00) {
                    throw new RuntimeException("Product {$sourceId} combination {$attrId} regular price {$varRegularGross} is too low for safe promotion.");
                }
                $varPromoGross = round($varRegularGross * (1.0 - $def['reduction']), 2);
                if ($varPromoGross < 10.00) {
                    throw new RuntimeException("Product {$sourceId} combination {$attrId} promo price {$varPromoGross} is below minimum 10 PLN.");
                }
            }
        }

        // Odczytujemy rzeczywistą cenę brutto domyślnego wariantu/produktu bezpośrednio z silnika PrestaShop
        $regularGross = (float) \Product::getPriceStatic(
            id_product: $productId,
            usetax: true,
            id_product_attribute: null,
            decimals: 2,
            usereduc: false,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        if ($regularGross < 20.00) {
            throw new RuntimeException("Product {$sourceId} regular price {$regularGross} is too low for safe promotion.");
        }

        if ($def['reduction_type'] === 'percentage') {
            if ($def['reduction'] <= 0 || $def['reduction'] >= 0.70) {
                throw new RuntimeException("Invalid percentage reduction {$def['reduction']} for product {$sourceId}.");
            }
            $expectedPromoGross = round($regularGross * (1.0 - $def['reduction']), 2);
        } else {
            if ($def['reduction'] <= 0 || $def['reduction'] >= ($regularGross * 0.50)) {
                throw new RuntimeException("Amount reduction {$def['reduction']} exceeds 50% of regular price {$regularGross} for product {$sourceId}.");
            }
            $expectedPromoGross = round($regularGross - $def['reduction'], 2);
        }

        if ($expectedPromoGross < 10.00) {
            throw new RuntimeException("Promotional price {$expectedPromoGross} is below minimum 10 PLN for product {$sourceId}.");
        }

        $plan[] = [
            'source_id' => $sourceId,
            'product_id' => $productId,
            'name' => (string) $source['name'],
            'regular_gross' => $regularGross,
            'expected_promo_gross' => $expectedPromoGross,
            'reduction_type' => $def['reduction_type'],
            'reduction' => $def['reduction'],
        ];
    }

    return $plan;
}

/**
 * Zapisuje lub aktualizuje reguły SpecificPrice dla zaplanowanych promocji.
 *
 * Wszystkie rabaty kwotowe zapisywane są z reduction_tax = 1 (brutto).
 * Po zapisie czyści pamięć podręczną cen i Smarty.
 *
 * @param array $plan Plan z promotionsPlan().
 * @param \Context $context Kontekst PrestaShop.
 * @return array{created: int, updated: int, unchanged: int}
 * @throws \RuntimeException Przy błędzie zapisu bazy danych lub niezgodności ceny po rabacie.
 */
function configurePromotions(array $plan, \Context $context): array
{
    ensurePriceContext($context);
    $created = 0;
    $updated = 0;
    $unchanged = 0;

    $db = \Db::getInstance();

    foreach ($plan as $entry) {
        $productId = $entry['product_id'];
        $reductionType = $entry['reduction_type'];
        $reduction = (float) $entry['reduction'];

        // Sprawdzamy istniejące specyficzne ceny dla tego produktu
        $existing = $db->executeS(
            'SELECT id_specific_price, reduction, reduction_type, reduction_tax, price, '
            . 'id_group, id_currency, id_customer, id_country, from_quantity, `from`, `to` FROM ' . _DB_PREFIX_ . 'specific_price '
            . 'WHERE id_product = ' . (int) $productId . ' AND id_product_attribute = 0 AND id_cart = 0'
        );

        if ($existing === false) {
            throw new RuntimeException("Cannot query specific prices for product ID {$productId}.");
        }

        $matched = false;
        foreach ($existing as $row) {
            $rowReduction = (float) $row['reduction'];
            $rowType = (string) $row['reduction_type'];
            $rowTax = (int) $row['reduction_tax'];
            $rowPrice = (float) $row['price'];
            $rowGroup = (int) $row['id_group'];
            $rowCurrency = (int) $row['id_currency'];
            $rowCustomer = (int) $row['id_customer'];
            $rowCountry = (int) $row['id_country'];
            $rowFromQty = (int) $row['from_quantity'];
            $rowFrom = (string) $row['from'];
            $rowTo = (string) $row['to'];

            if ($rowType === $reductionType
                && abs($rowReduction - $reduction) < 0.0001
                && $rowTax === 1
                && abs($rowPrice - (-1.0)) < 0.0001
                && $rowGroup === 0
                && $rowCurrency === 0
                && $rowCustomer === 0
                && $rowCountry === 0
                && $rowFromQty === 1
                && $rowFrom === '0000-00-00 00:00:00'
                && $rowTo === '0000-00-00 00:00:00') {
                $matched = true;
                $unchanged++;
                break;
            }
        }

        if (!$matched) {
            // Usuwamy ewentualne stare/niepasujące reguły specyficzne dla całego produktu
            if ($existing !== []) {
                $db->delete('specific_price', 'id_product = ' . (int) $productId . ' AND id_product_attribute = 0 AND id_cart = 0');
                $updated++;
            } else {
                $created++;
            }

            $sp = new \SpecificPrice();
            $sp->id_product = (int) $productId;
            $sp->id_product_attribute = 0;
            $sp->id_shop = 0;
            $sp->id_shop_group = 0;
            $sp->id_currency = 0;
            $sp->id_country = 0;
            $sp->id_group = 0;
            $sp->id_customer = 0;
            $sp->id_specific_price_rule = 0;
            $sp->id_cart = 0;
            $sp->from_quantity = 1;
            $sp->price = -1.000000;
            $sp->reduction_type = $reductionType;
            $sp->reduction = $reduction;
            $sp->reduction_tax = 1; // Zawsze z podatkiem (brutto)
            $sp->from = '0000-00-00 00:00:00';
            $sp->to = '0000-00-00 00:00:00';

            if (!$sp->add()) {
                throw new RuntimeException("Cannot create SpecificPrice for product ID {$productId}.");
            }
        }
    }

    // Czyszczenie pamięci podręcznej PrestaShop
    \Product::flushPriceCache();
    \Tools::clearSmartyCache();
    \Media::clearCache();

    // Weryfikacja kalkulacji ceny promocyjnej
    foreach ($plan as $entry) {
        $actualPromoGross = (float) \Product::getPriceStatic(
            id_product: $entry['product_id'],
            usetax: true,
            id_product_attribute: null,
            decimals: 2,
            usereduc: true,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );

        if (abs($actualPromoGross - $entry['expected_promo_gross']) > 0.05) {
            throw new RuntimeException(
                "Promotional price mismatch for product ID {$entry['product_id']} ({$entry['name']}): "
                . "expected {$entry['expected_promo_gross']} PLN, got {$actualPromoGross} PLN."
            );
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'unchanged' => $unchanged,
    ];
}

/**
 * Sprawdza obecność co najmniej 20 skonfigurowanych i poprawnych promocji.
 *
 * Używane przez configurationPresent() podczas wznowienia (--resume).
 *
 * @param \Context $context Kontekst PrestaShop.
 * @return bool True jeśli w sklepie aktywnych jest co najmniej 20 zaplanowanych promocji.
 */
function promotionsConfigured(\Context $context): bool
{
    ensurePriceContext($context);
    $definitions = promotionsDefinitions();
    $bySource = [];
    foreach ($definitions as $def) {
        $bySource[$def['source_id']] = $def;
    }

    $validCount = 0;
    foreach ($bySource as $sourceId => $def) {
        $productId = productId('pandomu-' . $sourceId, $context);
        if ($productId === null) {
            continue;
        }

        $row = \Db::getInstance()->getRow(
            'SELECT id_specific_price, reduction, reduction_type, reduction_tax, price FROM ' . _DB_PREFIX_ . 'specific_price '
            . 'WHERE id_product = ' . (int) $productId
            . ' AND id_product_attribute = 0'
            . ' AND id_cart = 0'
            . ' AND id_group = 0'
            . ' AND id_currency = 0'
            . ' AND id_customer = 0'
            . ' AND id_country = 0'
            . ' AND from_quantity = 1'
            . ' AND `from` = \'0000-00-00 00:00:00\''
            . ' AND `to` = \'0000-00-00 00:00:00\''
        );

        if ($row && (string) $row['reduction_type'] === $def['reduction_type']
            && abs((float) $row['reduction'] - $def['reduction']) < 0.0001
            && (int) $row['reduction_tax'] === 1
            && abs((float) $row['price'] - (-1.0)) < 0.0001) {
            $validCount++;
        }
    }

    return $validCount >= 20;
}
