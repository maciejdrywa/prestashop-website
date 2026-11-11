<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/source.php';
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException('Malformed data accepted');
}
try {
    $products = ShopInit\readCatalog();
    $combinations = $adjustments = 0;
    foreach ($products as $product) {
        $plan = ShopInit\pricePlan($product);
        check(ShopInit\grossFromNet($plan['net'], $plan['vat']) === $plan['gross'], 'Base gross mismatch');
        foreach ($plan['variants'] as $variant) {
            check(ShopInit\grossFromNet(bcadd($plan['net'], $variant['modifier'], 6), $plan['vat']) === $variant['gross'], 'Variant gross mismatch');
            $combinations++;
        }
        $adjustments += count($plan['adjustments']);
    }
    $fixture = [
        'product_id' => 1, 'vat_rate' => 23, 'price_brutto' => '12.30', 'price_netto' => '10',
        'variants' => [
            ['variant_id' => 10, 'attributes' => ['Size' => 'S', 'Color' => 'Gold'], 'price_modifier' => '-2', 'price_netto' => '8', 'price_brutto' => '9.84'],
            ['variant_id' => 11, 'attributes' => ['Size' => 'L', 'Color' => 'Gold'], 'price_modifier' => '3', 'price_netto' => '13', 'price_brutto' => '15.99'],
            ['variant_id' => 12, 'attributes' => ['Size' => 'M', 'Color' => 'Gold'], 'price_modifier' => '0', 'price_netto' => '10', 'price_brutto' => '12.30'],
        ],
    ];
    $plan = ShopInit\pricePlan($fixture);
    check($plan['variants'][0]['modifier'] === '-2.000000', 'Negative modifier lost');
    check(count(ShopInit\attributePlan([$fixture])['Color']) === 1, 'Repeated values were not deduplicated');
    $bad = $fixture;
    unset($bad['variants'][0]['price_modifier']);
    rejects(fn () => ShopInit\pricePlan($bad));
    $bad = $fixture;
    $bad['variants'][1]['attributes'] = $bad['variants'][0]['attributes'];
    rejects(fn () => ShopInit\pricePlan($bad));
    $bad = $fixture;
    unset($bad['variants'][1]['attributes']['Size']);
    rejects(fn () => ShopInit\pricePlan($bad));
    $bad = $fixture;
    $bad['variants'][1]['price_modifier'] = 100;
    rejects(fn () => ShopInit\pricePlan($bad));
    $bad = $fixture;
    $bad['variants'][1]['price_brutto'] = 100;
    rejects(fn () => ShopInit\pricePlan($bad));
    check(ShopInit\rounded('-1.005', 2) === '-1.01', 'Negative rounding failed');
    $categories = ShopInit\categoryPlan($products, true);
    $seen = [];
    foreach ($categories as $key => $entry) {
        check($entry['parent'] === null || isset($seen[$entry['parent']]), 'Child before parent');
        $seen[$key] = true;
    }
    echo 'OK: ' . count($products) . " source products, {$combinations} combinations, {$adjustments} rounding adjustments, "
        . count($categories) . " category nodes; malformed-data and signed-modifier fixtures passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
