<?php
declare(strict_types=1);

/**
 * Import lokalnych galerii z okładkami i miniaturami.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/import-images.php [ID|--all] [--dry-run]
 *
 * Wymaga produktów, kompletu lokalnych JPEG oraz GD. Domyślnie wybiera ID 5 i używa lib/images.php.
 *
 * Zapis chroni product-images.lock. Tworzy mapowanie w bazie oraz pliki img/p. Tryb próbny sprawdza plany
 * galerii bez generowania artefaktów.
 */

require_once __DIR__ . '/lib/images.php';
$lock = null;
try {
    echo "[1/3] Checking source images and target galleries...\n";
    [$products, $dryRun] = ShopInit\selectProducts($argv);
    $context = ShopInit\boot();
    if (!$dryRun) {
        $folder = _PS_ROOT_DIR_ . '/var/import';
        if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new RuntimeException('Cannot create import working directory.');
        }
        // Ta blokada obejmuje samodzielne import-images.php. Wznowienie wywołuje
        // bibliotekę bezpośrednio pod odrębną blokadą init-shop.lock.
        $lock = fopen($folder . '/product-images.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another image import is already running.');
        }
    }
    $types = ShopInit\productImageTypes();
    $plans = ShopInit\imagePlans($products, $context);
    $count = array_sum(array_map(fn (array $p): int => count($p['images']), $plans));
    echo 'PLAN: ' . count($plans) . " products, {$count} images, " . count($types) . " thumbnail types.\n";
    if ($dryRun) {
        foreach ($plans as $plan) {
            if ($plan['images'] === []) {
                echo 'WARNING: no source images for product ' . $plan['source_id'] . PHP_EOL;
            }
        }
        echo "OK: dry run; no images or database records changed.\n";
        exit(0);
    }
    echo "[2/3] Preparing durable source-image mapping...\n";
    // Tabela mapowań powstaje po walidacji planu. Import utrwala rezerwacje ID
    // i stan plików, dlatego ponowienie może dokończyć częściowo wygenerowaną galerię.
    ShopInit\ensureImageMap();
    echo "[3/3] Importing galleries, thumbnails and covers...\n";
    $stats = ShopInit\importProductImages($plans, $types, $context);
    ShopInit\verifyProductImages($plans, $types, $context);
    echo 'OK: ' . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . ". Product activation unchanged.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . ' Fix the cause and rerun; completed image mappings are retained.' . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
