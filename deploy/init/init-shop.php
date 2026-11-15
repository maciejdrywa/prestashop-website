<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/initialization.php';

function initializeShopMain(array $arguments): int
{
    $lock = null;
    $started = microtime(true);
    try {
        $options = array_slice($arguments, 1);
        if ($options === ['--help']) {
            echo "Usage: php init-shop.php [--dry-run]\nRequired: scraper-results/products.json, categories.json and images/. PrestaShop must already be running.\n";
            return 0;
        }
        if ($options !== [] && $options !== ['--dry-run']) {
            throw new RuntimeException('Usage: php init-shop.php [--dry-run]');
        }
        $dryRun = $options === ['--dry-run'];
        echo "[INIT] Loading PrestaShop...\n";
        $context = ShopInit\boot();
        if (!$dryRun) {
            $folder = _PS_ROOT_DIR_ . '/var/import';
            if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new RuntimeException('Cannot create initialization working directory.');
            }
            $lock = fopen($folder . '/init-shop.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another shop initialization is already running.');
            }
        }
        echo "[INIT] Validating all source files before any import...\n";
        $summary = ShopInit\initializationPreflight($context);
        echo '[INIT] Source: ' . $summary['products'] . ' products, ' . $summary['categories']
            . ' category nodes, ' . $summary['images'] . ' images; ' . $summary['unavailable_products'] . " products planned unavailable.\n";
        if ($summary['without_images'] !== []) {
            echo '[INIT] WARNING: no source gallery for products ' . implode(', ', $summary['without_images'])
                . ". These missing photos still require an acceptance exception.\n";
        }
        $stages = ShopInit\initializationStages();
        if ($dryRun) {
            foreach ($stages as $index => $stage) {
                echo '[PLAN ' . ($index + 1) . '/' . count($stages) . '] ' . $stage['name']
                    . ': ' . $stage['script'] . ' ' . implode(' ', $stage['args']) . PHP_EOL;
            }
            echo "OK: dry run; no catalog, stock, activation or gallery changes.\n";
            return 0;
        }
        ShopInit\runInitializationStages($stages, 'ShopInit\\runInitializationScript',
            fn () => ShopInit\unchangedInitializationSources($summary['source_hashes']));
        echo PHP_EOL . '[INIT] COMPLETE' . ($summary['without_images'] !== [] ? ' WITH SOURCE WARNINGS' : '')
            . ': ' . $summary['products'] . ' active products, ' . $summary['images'] . ' images, '
            . $summary['unavailable_products'] . ' unavailable products; elapsed '
            . number_format(microtime(true) - $started, 1) . " s.\n";
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, '[INIT] FAILED: ' . $error->getMessage()
            . ' Completed stages may have persisted changes. Fix the cause and rerun the same command.' . PHP_EOL);
        return 1;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

exit(initializeShopMain($argv));
