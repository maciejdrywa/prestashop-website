<?php

declare(strict_types=1);

namespace ShopInit;

use RuntimeException;

const SOURCE_FILE = '/opt/shop/scraper-results/products.json';

function decimal($value, string $label, bool $negative = false): string
{
    if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
        throw new RuntimeException("Invalid number: {$label}");
    }
    $text = (string) $value;
    if (stripos($text, 'e') !== false) {
        $text = sprintf('%.14F', (float) $value);
    }
    if (!$negative && (float) $value < 0) {
        throw new RuntimeException("Negative value: {$label}");
    }
    return ltrim($text, '+');
}

function rounded(string $value, int $scale): string
{
    if (!extension_loaded('bcmath')) {
        throw new RuntimeException('PHP bcmath is required for decimal price calculations.');
    }
    $half = '0.' . str_repeat('0', $scale) . '5';
    $result = bcadd($value, bccomp($value, '0', 14) < 0 ? '-' . $half : $half, $scale);
    return bccomp($result, '0', $scale) === 0 ? number_format(0, $scale, '.', '') : $result;
}

function grossFromNet(string $net, string $vat): string
{
    return rounded(bcmul($net, bcadd('1', bcdiv($vat, '100', 12), 12), 12), 2);
}

function netFromGross(string $gross, string $vat): string
{
    return rounded(bcdiv($gross, bcadd('1', bcdiv($vat, '100', 12), 12), 12), 6);
}

function pricePlan(array $product): array
{
    $id = (string) ($product['product_id'] ?? '?');
    $vat = decimal($product['vat_rate'] ?? null, "product {$id} VAT");
    if ((float) $vat > 100) {
        throw new RuntimeException("Unsupported VAT for product {$id}");
    }
    $gross = rounded(decimal($product['price_brutto'] ?? null, "product {$id} gross"), 2);
    $sourceNet = decimal($product['price_netto'] ?? null, "product {$id} net");
    assertSourceGross($sourceNet, $gross, $vat, "product {$id}");
    $net = netFromGross($gross, $vat);
    $variants = $product['variants'] ?? null;
    if (!is_array($variants) || !array_is_list($variants)) {
        throw new RuntimeException("Invalid combination list for product {$id}");
    }
    $result = ['net' => $net, 'gross' => $gross, 'vat' => $vat, 'variants' => [], 'adjustments' => []];
    $seenIds = [];
    $seenAttributes = [];
    $groupSet = null;
    foreach ($variants as $variant) {
        $variantId = (string) ($variant['variant_id'] ?? '');
        if (!ctype_digit($variantId) || (int) $variantId <= 0 || isset($seenIds[$variantId])) {
            throw new RuntimeException("Invalid or duplicate combination ID for product {$id}");
        }
        $seenIds[$variantId] = true;
        $attributes = $variant['attributes'] ?? null;
        if (!is_array($attributes) || $attributes === [] || array_is_list($attributes)) {
            throw new RuntimeException("Invalid attributes for combination {$variantId}");
        }
        foreach ($attributes as $group => $value) {
            if (!is_string($group) || trim($group) === '' || !is_string($value) || trim($value) === '') {
                throw new RuntimeException("Empty attribute for combination {$variantId}");
            }
        }
        ksort($attributes, SORT_STRING);
        $groups = array_keys($attributes);
        if ($groupSet !== null && $groups !== $groupSet) {
            throw new RuntimeException("Inconsistent attribute groups for product {$id}");
        }
        $groupSet = $groups;
        $signature = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (isset($seenAttributes[$signature])) {
            throw new RuntimeException("Duplicate attribute combination for product {$id}");
        }
        $seenAttributes[$signature] = true;
        $raw = rounded(decimal($variant['price_modifier'] ?? null, "combination {$variantId} modifier", true), 6);
        $observedNet = decimal($variant['price_netto'] ?? null, "combination {$variantId} net");
        $target = rounded(decimal($variant['price_brutto'] ?? null, "combination {$variantId} gross"), 2);
        assertSourceGross($observedNet, $target, $vat, "combination {$variantId}");
        if (abs((float) bcsub(bcadd($sourceNet, $raw, 12), $observedNet, 12)) > 0.0000011) {
            throw new RuntimeException("Source net and price_modifier disagree for combination {$variantId}");
        }
        $modifier = $raw;
        if (grossFromNet(bcadd($net, $modifier, 6), $vat) !== $target) {
            // Preserve the source modifier where possible. One micro-unit fixes
            // source half-cent boundaries lost when the base net is stored at 6 dp.
            $matched = false;
            for ($step = 1; $step <= 5 && !$matched; $step++) {
                foreach ([$step, -$step] as $offset) {
                    $candidate = bcadd($raw, bcdiv((string) $offset, '1000000', 6), 6);
                    if (grossFromNet(bcadd($net, $candidate, 6), $vat) === $target) {
                        $modifier = $candidate;
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                $modifier = bcsub(netFromGross($target, $vat), $net, 6);
            }
        }
        if (bccomp(bcadd($net, $modifier, 6), '0', 6) < 0
            || grossFromNet(bcadd($net, $modifier, 6), $vat) !== $target) {
            throw new RuntimeException("Cannot reproduce gross price for combination {$variantId}");
        }
        if (bccomp($raw, $modifier, 6) !== 0) {
            $result['adjustments'][] = ['variant_id' => $variantId, 'source_modifier' => $raw, 'import_modifier' => $modifier];
        }
        $result['variants'][] = [
            'source_id' => $variantId, 'attributes' => $attributes,
            'source_modifier' => $raw, 'modifier' => $modifier, 'gross' => $target,
            'reference' => 'pandomu-' . $id . '-combination-' . $variantId,
        ];
    }
    return $result;
}

function assertSourceGross(string $net, string $gross, string $vat, string $label): void
{
    $difference = bcsub(bcmul($net, bcadd('1', bcdiv($vat, '100', 12), 12), 12), $gross, 12);
    // Allow source six-decimal uncertainty at a half-cent boundary.
    if (bccomp($difference, '0.005002', 12) > 0 || bccomp($difference, '-0.005002', 12) < 0) {
        throw new RuntimeException("Source net, VAT and gross disagree for {$label}");
    }
}

function readCatalog(string $path = SOURCE_FILE): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Cannot read source catalog: {$path}");
    }
    $products = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($products) || !array_is_list($products) || $products === []) {
        throw new RuntimeException('Expected a non-empty product list.');
    }
    $ids = [];
    foreach ($products as $product) {
        $id = (string) ($product['product_id'] ?? '');
        if (!ctype_digit($id) || (int) $id <= 0 || isset($ids[$id])) {
            throw new RuntimeException('Invalid or duplicate product ID.');
        }
        $ids[$id] = true;
        if (!is_string($product['name'] ?? null) || trim($product['name']) === '') {
            throw new RuntimeException("Missing product name: {$id}");
        }
        $paths = $product['category_paths'] ?? null;
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            throw new RuntimeException("Missing category_paths for product {$id}");
        }
        foreach ($paths as $categoryPath) {
            if (!is_array($categoryPath) || !array_is_list($categoryPath) || $categoryPath === []) {
                throw new RuntimeException("Invalid category path for product {$id}");
            }
            foreach ($categoryPath as $name) {
                if (!is_string($name) || trim($name) === '') {
                    throw new RuntimeException("Invalid category name for product {$id}");
                }
            }
        }
        pricePlan($product);
    }
    return $products;
}

function selectProducts(array $argv, bool $defaultAll = false): array
{
    $selection = $defaultAll ? '--all' : '5';
    $specified = false;
    $dryRun = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $dryRun = true;
        } elseif (!$specified && ($argument === '--all' || ctype_digit($argument))) {
            $selection = $argument;
            $specified = true;
        } else {
            throw new RuntimeException('Usage: script.php [source_product_id|--all] [--dry-run]');
        }
    }
    $products = readCatalog();
    if ($selection !== '--all') {
        $products = array_values(array_filter($products, fn (array $p): bool => (string) $p['product_id'] === $selection));
        if ($products === []) {
            throw new RuntimeException("Source product {$selection} does not exist.");
        }
    }
    return [$products, $dryRun, $selection === '--all'];
}

function categoryPlan(array $products, bool $includeEmpty = false): array
{
    $paths = [];
    foreach ($products as $product) {
        array_push($paths, ...$product['category_paths']);
    }
    if ($includeEmpty) {
        $path = dirname(SOURCE_FILE) . '/categories.json';
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read category tree: {$path}");
        }
        $categories = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($categories) || !array_is_list($categories)) {
            throw new RuntimeException('Invalid category tree.');
        }
        foreach ($categories as $category) {
            $paths[] = $category['category_path'] ?? [];
        }
    }
    $result = [];
    foreach ($paths as $path) {
        if (!is_array($path) || !array_is_list($path) || $path === []) {
            throw new RuntimeException('Invalid category path.');
        }
        $prefix = [];
        foreach ($path as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new RuntimeException('Invalid category name.');
            }
            $parent = $prefix === [] ? null : json_encode($prefix, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $prefix[] = $name;
            $key = json_encode($prefix, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $result[$key] = ['name' => $name, 'path' => $prefix, 'parent' => $parent];
        }
    }
    uasort($result, fn (array $a, array $b): int => count($a['path']) <=> count($b['path']) ?: strcmp(implode(' > ', $a['path']), implode(' > ', $b['path'])));
    return $result;
}

function attributePlan(array $products): array
{
    $groups = [];
    foreach ($products as $product) {
        foreach ($product['variants'] as $variant) {
            foreach ($variant['attributes'] as $group => $value) {
                $groups[$group]['value:' . $value] = $value;
            }
        }
    }
    ksort($groups, SORT_STRING);
    return $groups;
}
