<?php
declare(strict_types=1);

/** Tylko odczyt: potwierdzone mapowania galerii PHP dla importera API D2.4. */
require_once __DIR__ . '/lib/images.php';
require_once __DIR__ . '/lib/webservice.php';
try {
    $context = ShopInit\boot();
    $shopId = (int) $context->shop->id;
    $images = [];
    if (ShopInit\imageMapExists()) {
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . ShopInit\IMAGE_MAP_TABLE . ' WHERE id_shop=' . $shopId);
        if ($rows === false) throw new RuntimeException('Cannot read image mappings.');
        foreach ($rows as $row) {
            if ($row['id_image'] === null) throw new RuntimeException('Incomplete PHP image mapping.');
            $image = new Image((int) $row['id_image'], null, $shopId);
            $product = new Product((int) $image->id_product, false, null, $shopId);
            if (!Validate::isLoadedObject($image) || !$image->isAssociatedToShop($shopId)
                || !Validate::isLoadedObject($product) || $product->reference !== 'pandomu-' . $row['source_product_id']) {
                throw new RuntimeException('Conflicting PHP image mapping.');
            }
            $file = _PS_PROD_IMG_DIR_ . $image->getExistingImgPath() . '.jpg';
            if (!is_readable($file) || !preg_match('/^[a-f0-9]{64}$/', (string) $row['content_hash'])) {
                throw new RuntimeException('Incomplete image files or source hash.');
            }
            $images[] = [
                'source_id' => (string) $row['source_product_id'], 'source_url' => $row['source_url'],
                'product_id' => (int) $image->id_product, 'image_id' => (int) $image->id,
                'content_hash' => $row['content_hash'], 'server_hash' => hash_file('sha256', $file),
            ];
        }
    }
    echo json_encode(['identity' => ShopInit\webserviceInstanceIdentity(), 'shop_id' => $shopId,
        'exported_at' => time(), 'images' => $images], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
