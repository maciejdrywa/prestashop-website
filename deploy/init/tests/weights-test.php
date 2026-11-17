<?php
declare(strict_types=1);

/**
 * Test masy źródłowej, reguł demonstracyjnych i pierwszeństwa kategorii.
 *
 * Wywołanie jako www-data w kontenerze z danymi wymaganymi przez scenariusz
 * php /opt/shop/init/tests/weights-test.php
 *
 * Czyta katalog i operuje na planach masy w pamięci.
 * Kod zakończenia 0 oznacza spełnienie asercji, a 1 nieudany scenariusz.
 */

require_once __DIR__ . '/../lib/weights.php';

/**
 * Wymaga spełnienia warunku testu, a przy błędzie zgłasza podany komunikat.
 *
 * @param bool $condition Warunek asercji wymagany przez bieżący scenariusz.
 * @param string $message Komunikat zgłaszany po niespełnieniu asercji.
 * @return void
 * @throws \RuntimeException Gdy warunek asercji jest fałszywy.
 */
function weightCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    // Fixture wymusza rozpoznanie szczegółowej kategorii. Dalsze przypadki
    // sprawdzają dodatnią wagę źródłową, brak lub zero oraz odrzucanie błędnych wartości.
    $fixture = ['product_id' => 'test', 'category_paths' => [['Akcesoria meblowe', 'UCHWYTY MEBLOWE']]];
    weightCheck(ShopInit\productWeightPlan($fixture)['weight'] === '0.100000', 'Specific category rule was not used.');
    weightCheck(ShopInit\productWeightPlan($fixture + ['weight' => 2.5])['weight'] === '2.500000', 'Source weight was overwritten.');
    foreach ([null, 0, '0.000000', 0.00000001] as $value) {
        $plan = ShopInit\productWeightPlan($fixture + ['weight' => $value]);
        weightCheck($plan['weight_origin'] === 'demo' && $plan['weight'] === '0.100000', 'Missing/zero weight was not replaced.');
    }
    $multiple = ['category_paths' => [['Bramy i ogrodzenia'], ['Akcesoria meblowe', 'UCHWYTY MEBLOWE']]];
    $reversed = $multiple; $reversed['category_paths'] = array_reverse($multiple['category_paths']);
    weightCheck(ShopInit\productWeightPlan($multiple) === ShopInit\productWeightPlan($reversed), 'Weight depends on category order.');
    weightCheck(ShopInit\productWeightPlan(['category_paths' => [['Unknown']]])['weight'] === '1.000000', 'Default weight missing.');
    foreach ([-1, 'invalid', false] as $value) {
        $rejected = false;
        try { ShopInit\productWeightPlan($fixture + ['weight' => $value]); } catch (RuntimeException $error) { $rejected = true; }
        weightCheck($rejected, 'Malformed source weight accepted.');
    }
    $source = $demo = 0;
    foreach (ShopInit\readCatalog() as $product) {
        $plan = ShopInit\productWeightPlan($product);
        weightCheck(bccomp($plan['weight'], '0', 6) > 0, 'Catalog contains a nonpositive planned weight.');
        $plan['weight_origin'] === 'source' ? $source++ : $demo++;
    }
    echo "OK: positive weights for all products; {$source} source / {$demo} demonstration. Category precedence, determinism, missing/zero fallback and invalid input verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
