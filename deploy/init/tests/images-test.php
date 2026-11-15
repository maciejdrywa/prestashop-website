<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/images.php';

function imageTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function imageRejects(callable $callback): void
{
    $rejected = false;
    try {
        $callback();
    } catch (RuntimeException $error) {
        $rejected = true;
    }
    imageTest($rejected, 'Expected failure was not reported.');
}
function imageTestCounts(): array
{
    $counts = [];
    foreach (['product', 'image', 'image_shop', 'image_lang', ShopInit\IMAGE_MAP_TABLE] as $table) {
        $counts[$table] = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . $table, false);
    }
    return $counts;
}
$testProductId = null;
$temporary = null;
$failed = null;
try {
    $context = ShopInit\boot();
    ShopInit\ensureImageMap();
    $before = imageTestCounts();
    $fixtureId = '900000005';
    imageTest(ShopInit\productId('pandomu-' . $fixtureId, $context) === null, 'Test fixture product already exists.');
    $fixture = array_values(array_filter(ShopInit\readCatalog(), fn (array $p): bool => (string) $p['product_id'] === '5'))[0];
    $fixture['product_id'] = $fixtureId;
    $fixture['name'] = 'Image import test fixture';
    $fixture['cover_image'] = $fixture['images'][3];
    imageTest(ShopInit\sourceImageFilename('https://example.test/12/photo.webp?x=1') === '12_photo.jpg', 'Filename mismatch');
    imageRejects(fn () => ShopInit\sourceImageFilename('file:///tmp/private.jpg'));
    $temporary = tempnam(sys_get_temp_dir(), 'image-qa-');
    imageTest($temporary !== false, 'Cannot create temporary source fixture');
    file_put_contents($temporary, str_repeat('x', 300001));
    imageRejects(fn () => ShopInit\validateSourceImage($temporary));
    $small = imagecreatetruecolor(400, 300);
    imagejpeg($small, $temporary);
    imagedestroy($small);
    imageRejects(fn () => ShopInit\validateSourceImage($temporary));
    $categories = ShopInit\importCategories(ShopInit\categoryPlan([$fixture]), $context, false);
    $productPlans = ShopInit\productPlans([$fixture], $context);
    $ids = ShopInit\importProducts($productPlans, $categories, $context);
    $testProductId = $ids[$fixtureId];
    $ambiguous = $fixture;
    unset($ambiguous['cover_image']);
    imageRejects(fn () => ShopInit\imagePlans([$ambiguous], $context));
    $types = ShopInit\productImageTypes();
    $plans = ShopInit\imagePlans([$fixture], $context);
    $first = ShopInit\importProductImages($plans, $types, $context);
    imageTest($first['created'] === 4, 'Expected four new images');
    ShopInit\verifyProductImages($plans, $types, $context);
    $second = ShopInit\importProductImages($plans, $types, $context);
    imageTest($second['created'] === 0 && $second['cached'] === 4, 'Repeat created duplicates or rewrote valid files');
    $fixture['images'] = array_reverse($fixture['images']);
    $plans = ShopInit\imagePlans([$fixture], $context);
    ShopInit\importProductImages($plans, $types, $context);
    ShopInit\verifyProductImages($plans, $types, $context);
    $entry = $plans[0]['images'][0];
    $mapping = ShopInit\imageMapping($fixtureId, $entry['url'], $context);
    $imageId = (int) $mapping['id_image'];
    $image = new Image($imageId, null, (int) $context->shop->id);
    $files = ShopInit\imageArtifacts($image, $types);
    file_put_contents($files[$types[0]['name']], 'broken thumbnail');
    imageRejects(fn () => ShopInit\verifyProductImages($plans, $types, $context));
    $repaired = ShopInit\importProductImages($plans, $types, $context);
    imageTest($repaired['regenerated'] === 1 && $repaired['created'] === 0, 'Thumbnail was not repaired in place');
    unlink($files['original']);
    $repaired = ShopInit\importProductImages($plans, $types, $context);
    imageTest($repaired['regenerated'] === 1 && $repaired['created'] === 0, 'Missing original was not repaired in place');
    // Simulate interruption after the image ID was reserved, before finishing files.
    imageTest((bool) Db::getInstance()->update(ShopInit\IMAGE_MAP_TABLE, ['content_hash' => ''], ShopInit\imageMapWhere($plans[0], $entry, $context)), 'Cannot prepare interrupted-write fixture');
    $resumed = ShopInit\importProductImages($plans, $types, $context);
    imageTest($resumed['regenerated'] === 1 && $resumed['created'] === 0, 'Resume duplicated an image');
    imageTest((int) ShopInit\imageMapping($fixtureId, $entry['url'], $context)['id_image'] === $imageId, 'Image ID changed during repair');
    ShopInit\verifyProductImages($plans, $types, $context);
    $extra = new Image();
    $extra->id_product = $testProductId;
    $extra->cover = false;
    imageTest((bool) $extra->add(), 'Cannot prepare unmapped image fixture');
    imageTest(copy($entry['path'], $extra->getPathForCreation() . '.jpg'), 'Cannot prepare unmapped image file');
    try {
        imageRejects(fn () => ShopInit\imagePlans([$fixture], $context));
    } finally {
        imageTest((bool) $extra->delete(), 'Cannot delete unmapped test fixture');
    }
    imageTest(!(new Product($testProductId))->active, 'Image import activated the product');
} catch (Throwable $error) {
    $failed = $error;
} finally {
    if ($testProductId !== null) {
        if (!(new Product($testProductId))->delete()) {
            $failed = new RuntimeException('Cannot delete image test product.');
        }
        if (!Db::getInstance()->delete(ShopInit\IMAGE_MAP_TABLE, "source_product_id = '900000005' AND id_shop = " . (int) $context->shop->id)) {
            $failed = new RuntimeException('Cannot delete image test mappings.');
        }
    }
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
}
if ($failed !== null) {
    fwrite(STDERR, 'TEST FAILED: ' . $failed->getMessage() . PHP_EOL);
    exit(1);
}
try {
    imageTest(imageTestCounts() === $before, 'Test records remained after cleanup.');
    echo "OK: JPEG constraints, repeat import, gallery order, cover, corrupt thumbnails, missing original, interrupted write and unmapped images verified. Test product/files removed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
