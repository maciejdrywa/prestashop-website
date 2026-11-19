<?php
declare(strict_types=1);

/**
 * Test poprawności konfiguracji promocji cenowych i naliczania rabatów w koszyku.
 *
 * Wywołanie jako www-data w kontenerze shop:
 * php /opt/shop/init/tests/promotions-test.php
 *
 * Sprawdza:
 * 1. Obecność min. 20 aktywnych promocji w bazie.
 * 2. Obecność rabatów procentowych i kwotowych.
 * 3. Poprawność podatku reduction_tax = 1 (brutto).
 * 4. Prawidłowość wyliczenia ceny pierwotnej i promocyjnej przez PrestaShop.
 * 5. Naliczanie rabatu w koszyku zakupowym.
 */

require_once __DIR__ . '/../lib/promotions.php';

function promoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $context = ShopInit\boot();
    ShopInit\priceContext($context);
    ShopInit\ensurePriceContext($context);
    $catalog = ShopInit\readCatalog();

    echo "[1/4] Sprawdzanie definicji i planu promocji...\n";
    $definitions = ShopInit\promotionsDefinitions();
    promoAssert(count($definitions) >= 20, 'Plan musi zawierać co najmniej 20 produktów w promocji.');

    $percentageCount = count(array_filter($definitions, fn (array $d) => $d['reduction_type'] === 'percentage'));
    $amountCount = count(array_filter($definitions, fn (array $d) => $d['reduction_type'] === 'amount'));
    promoAssert($percentageCount >= 5, 'Wymagane co najmniej 5 rabatów procentowych.');
    promoAssert($amountCount >= 5, 'Wymagane co najmniej 5 rabatów kwotowych.');

    $plan = ShopInit\promotionsPlan($catalog, $context);
    promoAssert(count($plan) >= 20, 'Plan po rozwiązaniu z bazą musi obejmować min. 20 produktów.');

    echo "[2/4] Weryfikacja zapisu SpecificPrice i reduction_tax w bazie...\n";
    promoAssert(ShopInit\promotionsConfigured($context), 'Funkcja promotionsConfigured() zwróciła false — brak wymaganych promocji w bazie.');

    $db = Db::getInstance();
    foreach ($plan as $item) {
        $row = $db->getRow(
            'SELECT id_specific_price, reduction, reduction_type, reduction_tax, price, id_group, id_currency, id_customer, id_country, from_quantity, `from`, `to` FROM ' . _DB_PREFIX_ . 'specific_price '
            . 'WHERE id_product = ' . (int) $item['product_id']
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
        promoAssert($row !== false && $row !== null, "Brak rekordu SpecificPrice dla produktu {$item['source_id']} (ID {$item['product_id']}).");
        promoAssert((int) $row['reduction_tax'] === 1, "Rabat dla produktu {$item['source_id']} musi mieć reduction_tax = 1 (brutto).");
        promoAssert((string) $row['reduction_type'] === $item['reduction_type'], "Niezgodny typ redukcji dla produktu {$item['source_id']}.");
        promoAssert(abs((float) $row['reduction'] - $item['reduction']) < 0.0001, "Niezgodna wartość redukcji dla produktu {$item['source_id']}.");
        promoAssert(abs((float) $row['price'] - (-1.0)) < 0.0001, "Wartość price w SpecificPrice musi wynosić -1.0 dla produktu {$item['source_id']}.");
    }

    echo "[3/4] Weryfikacja cen pierwotnych i promocyjnych przez kalkulator PrestaShop...\n";
    foreach ($plan as $item) {
        $regularGross = (float) Product::getPriceStatic(
            id_product: $item['product_id'],
            usetax: true,
            id_product_attribute: null,
            decimals: 2,
            usereduc: false,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );
        promoAssert(abs($regularGross - $item['regular_gross']) < 0.05, "Cena bazowa produktu {$item['source_id']} różni się od oczekiwanej: {$regularGross} vs {$item['regular_gross']}.");

        $promoGross = (float) Product::getPriceStatic(
            id_product: $item['product_id'],
            usetax: true,
            id_product_attribute: null,
            decimals: 2,
            usereduc: true,
            use_group_reduction: false,
            context: $context,
            use_customer_price: false
        );
        promoAssert(abs($promoGross - $item['expected_promo_gross']) < 0.05, "Cena promocyjna produktu {$item['source_id']} różni się od oczekiwanej: {$promoGross} vs {$item['expected_promo_gross']}.");
        promoAssert($promoGross < $regularGross, "Cena promocyjna musi być niższa od bazowej dla produktu {$item['source_id']}.");

        // Weryfikacja kombinacji dla produktów z wariantami
        $attrIds = Product::getProductAttributesIds($item['product_id']);
        foreach ($attrIds as $attrRow) {
            $attrId = (int) $attrRow['id_product_attribute'];
            $varRegGross = (float) Product::getPriceStatic(
                id_product: $item['product_id'],
                usetax: true,
                id_product_attribute: $attrId,
                decimals: 2,
                usereduc: false,
                use_group_reduction: false,
                context: $context,
                use_customer_price: false
            );
            $varPromoGross = (float) Product::getPriceStatic(
                id_product: $item['product_id'],
                usetax: true,
                id_product_attribute: $attrId,
                decimals: 2,
                usereduc: true,
                use_group_reduction: false,
                context: $context,
                use_customer_price: false
            );
            promoAssert($varPromoGross < $varRegGross, "Cena promocyjna wariantu {$attrId} musi być niższa od bazowej dla produktu {$item['source_id']}.");
            promoAssert($varPromoGross >= 10.00, "Cena promocyjna wariantu {$attrId} nie może być niższa niż 10 PLN dla produktu {$item['source_id']}.");
        }
    }

    echo "[4/4] Test naliczania rabatu w koszyku zakupowym...\n";
    $testItem = $plan[0]; // ID 12 (-10%)
    $cart = new Cart();
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $cart->id_shop = (int) $context->shop->id;
    $cart->add();
    $context->cart = $cart;

    try {
        $qty = 2;
        $cart->updateQty($qty, $testItem['product_id']);
        $productsInCart = $cart->getProducts();
        promoAssert(count($productsInCart) === 1, 'Koszyk powinien zawierać dokładnie 1 produkt.');

        $cartLine = $productsInCart[0];
        $expectedLineTotal = round($testItem['expected_promo_gross'] * $qty, 2);
        $actualLineTotal = (float) $cartLine['total_wt'];

        promoAssert(abs($actualLineTotal - $expectedLineTotal) < 0.10, "Błędna wartość pozycji koszyka po rabacie: oczekiwano {$expectedLineTotal} zł, otrzymano {$actualLineTotal} zł.");

        $cartTotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        promoAssert(abs($cartTotal - $expectedLineTotal) < 0.10, "Błędna suma produktów w koszyku po rabacie: oczekiwano {$expectedLineTotal} zł, otrzymano {$cartTotal} zł.");
    } finally {
        $cart->delete();
    }

    echo sprintf(
        "OK: Zweryfikowano %d aktywnych promocji (%d procentowych, %d kwotowych). Ceny pierwotne, promocyjne i naliczanie w koszyku poprawne.\n",
        count($plan),
        $percentageCount,
        $amountCount
    );
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST PROMOTION FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
