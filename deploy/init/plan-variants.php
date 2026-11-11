<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/source.php';
try {
    [$products] = ShopInit\selectProducts($argv);
    $count = 0;
    foreach ($products as $product) {
        $plan = ShopInit\pricePlan($product);
        echo 'Product ' . $product['product_id'] . ': ' . $product['name'] . PHP_EOL;
        echo 'Base: ' . $plan['net'] . ' PLN net, ' . $plan['gross'] . " PLN gross\n";
        foreach ($plan['variants'] as $variant) {
            $attributes = [];
            foreach ($variant['attributes'] as $group => $value) {
                $attributes[] = $group . ' = ' . $value;
            }
            echo 'COMBINATION ' . $variant['source_id'] . ': ' . implode('; ', $attributes)
                . ', modifier: ' . $variant['modifier'] . ' PLN net, gross: ' . $variant['gross'] . PHP_EOL;
            $count++;
        }
        foreach ($plan['adjustments'] as $change) {
            echo 'ROUNDING: ' . json_encode($change, JSON_THROW_ON_ERROR) . PHP_EOL;
        }
    }
    echo "OK: {$count} actual combinations planned; no database changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
