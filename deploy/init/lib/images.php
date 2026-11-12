<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/products.php';

const SOURCE_IMAGE_DIR = '/opt/shop/scraper-results/images';
const IMAGE_MAP_TABLE = 'import_image_map';

function sourceImageFilename(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Invalid source image URL.');
    }
    $path = rtrim($parts['path'] ?? '', '/');
    $segments = explode('/', $path);
    if (count($segments) < 3 || str_contains($path, '\\')) {
        throw new RuntimeException('Invalid source image path: ' . $path);
    }
    $filename = $segments[count($segments) - 2] . '_' . $segments[count($segments) - 1];
    return pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
}

function validateSourceImage(string $path): array
{
    clearstatcache(true, $path);
    if (!is_readable($path) || filesize($path) <= 0 || filesize($path) > 300000) {
        throw new RuntimeException('Missing image or file exceeds 300000 bytes: ' . $path);
    }
    $size = @getimagesize($path);
    if (!$size || $size[2] !== IMAGETYPE_JPEG || max($size[0], $size[1]) < 800 || max($size[0], $size[1]) > 1600) {
        throw new RuntimeException('Expected processed JPEG, long edge 800-1600 px: ' . $path);
    }
    $decoded = @imagecreatefromjpeg($path);
    if ($decoded === false) {
        throw new RuntimeException('Cannot decode JPEG: ' . $path);
    }
    imagedestroy($decoded);
    return ['hash' => hash_file('sha256', $path), 'width' => $size[0], 'height' => $size[1]];
}

function imageMapExists(): bool
{
    return (int) \Db::getInstance()->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
        . \pSQL(_DB_PREFIX_ . IMAGE_MAP_TABLE) . "'", false) === 1;
}

function ensureImageMap(): void
{
    $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . IMAGE_MAP_TABLE . ' (
        id_shop INT UNSIGNED NOT NULL,
        source_product_id VARCHAR(64) NOT NULL,
        source_url_hash CHAR(64) NOT NULL,
        source_url TEXT NOT NULL,
        id_image INT UNSIGNED NULL,
        content_hash CHAR(64) NULL,
        thumbnail_signature CHAR(64) NULL,
        artifacts MEDIUMTEXT NULL,
        PRIMARY KEY (id_shop, source_product_id, source_url_hash),
        UNIQUE KEY shop_image (id_shop, id_image)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    if (!\Db::getInstance()->execute($sql)) {
        throw new RuntimeException('Cannot prepare image mapping table.');
    }
}

function imageMapping(string $sourceId, string $url, \Context $context): ?array
{
    $query = new \DbQuery();
    $query->select('*')->from(IMAGE_MAP_TABLE);
    $query->where('id_shop = ' . (int) $context->shop->id);
    $query->where("source_product_id = '" . \pSQL($sourceId) . "'");
    $query->where("source_url_hash = '" . hash('sha256', $url) . "'");
    $rows = \Db::getInstance()->executeS($query, true, false);
    if ($rows === false || count($rows) > 1) {
        throw new RuntimeException('Cannot resolve source image mapping.');
    }
    if ($rows !== [] && $rows[0]['source_url'] !== $url) {
        throw new RuntimeException('Source image URL hash conflict.');
    }
    return $rows[0] ?? null;
}

function productImageTypes(): array
{
    $types = \ImageType::getImagesTypes('products');
    if (!$types) {
        throw new RuntimeException('No product thumbnail types configured.');
    }
    foreach ($types as $type) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $type['name']) || (int) $type['width'] <= 0 || (int) $type['height'] <= 0) {
            throw new RuntimeException('Invalid product thumbnail type.');
        }
    }
    return $types;
}

function thumbnailSignature(array $types): string
{
    return hash('sha256', json_encode([$types, \Configuration::get('PS_JPEG_QUALITY'), _PS_VERSION_], JSON_THROW_ON_ERROR));
}

function imagePlans(array $products, \Context $context, string $directory = SOURCE_IMAGE_DIR, bool $allowMissingProducts = false): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('PHP GD is required for product images.');
    }
    $root = realpath($directory);
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('Source image directory does not exist: ' . $directory);
    }
    $mapped = imageMapExists();
    $plans = [];
    $validated = [];
    $filenames = [];
    foreach ($products as $source) {
        $sourceId = (string) $source['product_id'];
        if (!\Validate::isGenericName(mb_substr($source['name'], 0, 128))) {
            throw new RuntimeException("Invalid image legend: product {$sourceId}");
        }
        $id = productId('pandomu-' . $sourceId, $context);
        if ($id === null && !$allowMissingProducts) {
            throw new RuntimeException("Product {$sourceId} is missing; run import-products.php first.");
        }
        if (!is_array($source['images'] ?? null) || !array_is_list($source['images'])) {
            throw new RuntimeException("Invalid image list: product {$sourceId}");
        }
        $coverUrl = $source['cover_image'] ?? (count($source['images']) === 1 ? $source['images'][0] : null);
        if (($source['images'] !== [] && !in_array($coverUrl, $source['images'], true))
            || ($source['images'] === [] && $coverUrl !== null)) {
            throw new RuntimeException("Missing or invalid source cover_image: product {$sourceId}. Run scraper/refresh_image_covers.py first.");
        }
        $images = [];
        $knownIds = [];
        foreach ($source['images'] as $index => $url) {
            if (!is_string($url) || isset($images[$url])) {
                throw new RuntimeException("Invalid or duplicate image URL: product {$sourceId}");
            }
            $filename = sourceImageFilename($url);
            if (isset($filenames[$filename]) && $filenames[$filename] !== $url) {
                throw new RuntimeException('Different source URLs resolve to the same filename: ' . $filename);
            }
            $filenames[$filename] = $url;
            $path = realpath($root . '/' . $filename);
            if ($path === false || dirname($path) !== $root) {
                throw new RuntimeException('Source image is missing or outside the source directory: ' . $filename);
            }
            $validated[$path] ??= validateSourceImage($path);
            $mapping = $mapped && $id !== null ? imageMapping($sourceId, $url, $context) : null;
            if ($mapping !== null && $mapping['id_image'] !== null) {
                $image = new \Image((int) $mapping['id_image'], null, (int) $context->shop->id);
                if (\Validate::isLoadedObject($image)) {
                    if ((int) $image->id_product !== $id || !$image->isAssociatedToShop((int) $context->shop->id)) {
                        throw new RuntimeException("Mapped image belongs to another product or shop: {$sourceId}");
                    }
                    $knownIds[] = (int) $image->id;
                }
            }
            $images[$url] = [
                'url' => $url, 'path' => $path, 'position' => $index + 1,
                'source' => $validated[$path], 'mapping' => $mapping,
            ];
        }
        $rows = [];
        if ($id !== null) {
            $query = new \DbQuery();
            $query->select('i.id_image')->from('image', 'i');
            $query->innerJoin('image_shop', 's', 's.id_image = i.id_image');
            $query->where('i.id_product = ' . $id)->where('s.id_shop = ' . (int) $context->shop->id);
            $rows = \Db::getInstance()->executeS($query, true, false);
        }
        if ($rows === false) {
            throw new RuntimeException("Cannot read gallery: {$sourceId}");
        }
        $existing = array_map(fn (array $r): int => (int) $r['id_image'], $rows);
        if (array_diff($existing, $knownIds) !== []) {
            throw new RuntimeException("Product {$sourceId} has unmapped or obsolete gallery images. Review them before importing; no images were deleted.");
        }
        $plans[] = ['source_id' => $sourceId, 'product_id' => $id, 'name' => $source['name'], 'cover_url' => $coverUrl, 'images' => array_values($images)];
    }
    return $plans;
}

function imageArtifacts(\Image $image, array $types): array
{
    $base = _PS_PRODUCT_IMG_DIR_ . $image->getImgPath();
    if (\Configuration::get('PS_LEGACY_IMAGES')) {
        $base = _PS_PRODUCT_IMG_DIR_ . $image->id_product . '-' . $image->id;
    }
    $files = ['original' => $base . '.jpg'];
    foreach ($types as $type) {
        $files[$type['name']] = $base . '-' . $type['name'] . '.jpg';
    }
    return $files;
}

function artifactsCurrent(array $mapping, array $files, string $sourceHash, string $signature): bool
{
    if ($mapping['content_hash'] !== $sourceHash || $mapping['thumbnail_signature'] !== $signature) {
        return false;
    }
    $hashes = json_decode($mapping['artifacts'] ?? '', true);
    if (!is_array($hashes) || array_keys($hashes) !== array_keys($files)) {
        return false;
    }
    foreach ($files as $name => $file) {
        if (!is_readable($file) || hash_file('sha256', $file) !== $hashes[$name]) {
            return false;
        }
    }
    return true;
}

function atomicImageFile(string $destination, callable $write): void
{
    $temporary = tempnam(dirname($destination), '.import-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create temporary image file.');
    }
    try {
        if (!$write($temporary) || !is_file($temporary) || filesize($temporary) === 0 || @getimagesize($temporary) === false) {
            throw new RuntimeException('Cannot write image file: ' . basename($destination));
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $destination)) {
            throw new RuntimeException('Cannot replace image file: ' . basename($destination));
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function imageMapWhere(array $plan, array $entry, \Context $context): string
{
    return 'id_shop = ' . (int) $context->shop->id
        . " AND source_product_id = '" . \pSQL($plan['source_id']) . "'"
        . " AND source_url_hash = '" . hash('sha256', $entry['url']) . "'";
}

function importProductImages(array $plans, array $types, \Context $context): array
{
    $db = \Db::getInstance();
    $signature = thumbnailSignature($types);
    $stats = ['created' => 0, 'regenerated' => 0, 'cached' => 0, 'without_images' => []];
    foreach ($plans as $productIndex => $plan) {
        if ($plan['images'] === []) {
            $stats['without_images'][] = $plan['source_id'];
            echo 'WARNING: source product ' . $plan['source_id'] . " has no source images; no placeholder assigned.\n";
            continue;
        }
        $productIds = [];
        foreach ($plan['images'] as $entry) {
            // Re-read mapping: a previous attempt may already have reserved an ID.
            $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            if ($mapping === null) {
                if (!$db->insert(IMAGE_MAP_TABLE, [
                    'id_shop' => (int) $context->shop->id, 'source_product_id' => \pSQL($plan['source_id']),
                    'source_url_hash' => hash('sha256', $entry['url']), 'source_url' => \pSQL($entry['url']),
                ])) {
                    throw new RuntimeException('Cannot reserve source image mapping.');
                }
                $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            }
            $image = new \Image((int) ($mapping['id_image'] ?? 0), null, (int) $context->shop->id);
            $created = !\Validate::isLoadedObject($image);
            if ($created) {
                // Pair the new image row with its durable mapping before files
                // are generated; interrupted generation can be safely resumed.
                $image = transaction(function () use ($db, $plan, $entry, $context): \Image {
                    $image = new \Image();
                    $image->id_product = $plan['product_id'];
                    $image->position = $entry['position'];
                    $image->cover = false;
                    if (!$image->add() || !$db->update(IMAGE_MAP_TABLE, ['id_image' => (int) $image->id], imageMapWhere($plan, $entry, $context))) {
                        throw new RuntimeException('Cannot create gallery image and mapping.');
                    }
                    return $image;
                });
            }
            $image->position = $entry['position'];
            foreach (\Language::getLanguages(false) as $language) {
                $image->legend[(int) $language['id_lang']] = mb_substr($plan['name'], 0, 128);
            }
            if (!$image->update()) {
                throw new RuntimeException('Cannot update image position or legend.');
            }
            $image->getPathForCreation();
            $files = imageArtifacts($image, $types);
            $current = !$created && artifactsCurrent($mapping, $files, $entry['source']['hash'], $signature);
            if (!$current) {
                atomicImageFile($files['original'], fn (string $target): bool => copy($entry['path'], $target));
                if (hash_file('sha256', $files['original']) !== $entry['source']['hash']) {
                    throw new RuntimeException('Source photo changed during import; rerun after scraping finishes.');
                }
                foreach ($types as $type) {
                    atomicImageFile($files[$type['name']], fn (string $target): bool => \ImageManager::resize(
                        $entry['path'], $target, (int) $type['width'], (int) $type['height'], 'jpg', true
                    ));
                }
                $hashes = array_map(fn (string $path): string => hash_file('sha256', $path), $files);
                if (!$db->update(IMAGE_MAP_TABLE, [
                    'content_hash' => $entry['source']['hash'], 'thumbnail_signature' => $signature,
                    'artifacts' => \pSQL(json_encode($hashes, JSON_THROW_ON_ERROR)),
                ], imageMapWhere($plan, $entry, $context))) {
                    throw new RuntimeException('Cannot finish image mapping.');
                }
            }
            $status = $created ? 'CREATED' : ($current ? 'CACHED' : 'REGENERATED');
            $stats[$created ? 'created' : ($current ? 'cached' : 'regenerated')]++;
            $productIds[$entry['url']] = (int) $image->id;
            echo '[' . ($productIndex + 1) . '/' . count($plans) . '] ' . $status . ': product '
                . $plan['source_id'] . ', image ' . $entry['position'] . '/' . count($plan['images'])
                . ', ID ' . $image->id . PHP_EOL;
        }
        if (!\Image::deleteCover($plan['product_id'])) {
            throw new RuntimeException('Cannot reset product cover.');
        }
        $cover = new \Image($productIds[$plan['cover_url']], null, (int) $context->shop->id);
        $cover->cover = true;
        if (!$cover->update()) {
            throw new RuntimeException('Cannot set product cover.');
        }
    }
    return $stats;
}

function verifyProductImages(array $plans, array $types, \Context $context): array
{
    $signature = thumbnailSignature($types);
    $count = 0;
    $missing = [];
    foreach ($plans as $plan) {
        if ($plan['images'] === []) {
            $missing[] = $plan['source_id'];
            continue;
        }
        foreach ($plan['images'] as $entry) {
            $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            if ($mapping === null || !$mapping['id_image']) {
                throw new RuntimeException('Missing imported image: product ' . $plan['source_id'] . ', position ' . $entry['position']);
            }
            $image = new \Image((int) $mapping['id_image'], null, (int) $context->shop->id);
            if (!\Validate::isLoadedObject($image) || (int) $image->id_product !== $plan['product_id']
                || (int) $image->position !== $entry['position']
                || (bool) $image->cover !== ($entry['url'] === $plan['cover_url'])
                || !$image->isAssociatedToShop((int) $context->shop->id)) {
                throw new RuntimeException('Incorrect image metadata, order or cover: ' . $plan['source_id']);
            }
            foreach (\Language::getLanguages(false) as $language) {
                if (($image->legend[(int) $language['id_lang']] ?? null) !== mb_substr($plan['name'], 0, 128)) {
                    throw new RuntimeException('Incorrect image legend: ' . $plan['source_id']);
                }
            }
            $files = imageArtifacts($image, $types);
            if (!artifactsCurrent($mapping, $files, $entry['source']['hash'], $signature)
                || hash_file('sha256', $files['original']) !== $entry['source']['hash']) {
                throw new RuntimeException('Missing or changed original/thumbnail: image ' . $image->id);
            }
            foreach ($types as $type) {
                $size = @getimagesize($files[$type['name']]);
                if (!$size || $size[0] !== (int) $type['width'] || $size[1] !== (int) $type['height'] || $size[2] !== IMAGETYPE_JPEG) {
                    throw new RuntimeException('Invalid thumbnail dimensions/format: image ' . $image->id . ', ' . $type['name']);
                }
            }
            $count++;
        }
    }
    return ['images' => $count, 'thumbnails' => $count * count($types), 'without_images' => $missing];
}
