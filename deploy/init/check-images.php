<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/images.php';
try {
    [$products] = ShopInit\selectProducts($argv, true);
    $context = ShopInit\boot();
    if (!ShopInit\imageMapExists()) {
        throw new RuntimeException('Image mapping is missing; run import-images.php first.');
    }
    echo "[1/2] Checking source files and complete gallery assignments...\n";
    $plans = ShopInit\imagePlans($products, $context);
    echo "[2/2] Checking originals, order, covers, legends and all thumbnails...\n";
    $result = ShopInit\verifyProductImages($plans, ShopInit\productImageTypes(), $context);
    echo 'OK: ' . json_encode($result, JSON_THROW_ON_ERROR)
        . ". No database changes. Missing source images still require an acceptance exception.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
