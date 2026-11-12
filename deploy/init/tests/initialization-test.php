<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/initialization.php';

function initTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function initRejects(callable $callback): void
{
    $failed = false;
    try {
        $callback();
    } catch (RuntimeException $error) {
        $failed = true;
    }
    initTest($failed, 'Expected initialization failure was not reported');
}
$temporary = null;
$exitCode = 0;
try {
    $temporary = tempnam(sys_get_temp_dir(), 'init-qa-');
    initTest(is_string($temporary), 'Cannot create temporary test directory');
    unlink($temporary);
    mkdir($temporary);
    initRejects(fn () => ShopInit\initializationInputs($temporary));
    file_put_contents($temporary . '/products.json', '[]');
    initRejects(fn () => ShopInit\initializationInputs($temporary));
    file_put_contents($temporary . '/categories.json', '[]');
    initRejects(fn () => ShopInit\initializationInputs($temporary));
    mkdir($temporary . '/images');
    initTest(count(ShopInit\initializationInputs($temporary)) === 3, 'Input paths are incorrect');
    $hashes = [$temporary . '/products.json' => hash_file('sha256', $temporary . '/products.json')];
    ShopInit\unchangedInitializationSources($hashes);
    file_put_contents($temporary . '/products.json', '[{}]');
    initRejects(fn () => ShopInit\unchangedInitializationSources($hashes));
    $fixture = [];
    foreach (range(1, 4) as $root) {
        foreach (range(1, 2) as $child) {
            $fixture[] = ['category_paths' => [["Root {$root}", "Child {$child}"]]];
        }
    }
    initTest(ShopInit\initializationCategoryCoverage($fixture) === 4, 'Valid category structure rejected');
    array_pop($fixture);
    initRejects(fn () => ShopInit\initializationCategoryCoverage($fixture));
    $stages = ShopInit\initializationStages();
    $calls = [];
    $runner = function (array $stage) use (&$calls): int {
        $calls[] = $stage['script'];
        return count($calls) === 2 ? 7 : 0;
    };
    initRejects(fn () => ShopInit\runInitializationStages($stages, $runner));
    initTest(count($calls) === 2, 'A later stage ran after failure');
    initTest(!in_array('import-stock.php', $calls, true), 'Activation ran after import failure');
    $calls = [];
    ShopInit\runInitializationStages($stages, function (array $stage) use (&$calls): int {
        $calls[] = $stage['script'];
        return 0;
    });
    initTest(count($calls) === count($stages), 'Successful run skipped stages');
    initTest(array_search('check-images.php', $calls, true) < array_search('import-stock.php', $calls, true), 'Activation precedes gallery validation');
    initTest(array_search('check-products.php', $calls, true) < array_search('import-stock.php', $calls, true), 'Activation precedes product validation');
    initTest(array_search('import-products.php', $calls, true) < array_search('configure-menu.php', $calls, true), 'Menu runs before category import');
    $calls = [];
    $guardRuns = 0;
    $guard = function () use (&$guardRuns): void {
        $guardRuns++;
        if ($guardRuns === 2) {
            throw new RuntimeException('Simulated source change');
        }
    };
    $guardedRunner = function (array $stage) use (&$calls): int {
        $calls[] = $stage['script'];
        return 0;
    };
    initRejects(fn () => ShopInit\runInitializationStages($stages, $guardedRunner, $guard));
    initTest(count($calls) === 1, 'Stages continued after source modification');
    echo "[TEST] Expected usage error from a child process follows:\n";
    initTest(ShopInit\runInitializationScript(['script' => 'init-shop.php', 'args' => ['--invalid']]) === 1, 'Child exit status was lost');
    $context = ShopInit\boot();
    $source = array_values(array_filter(ShopInit\readCatalog(), fn (array $p): bool => (string) $p['product_id'] === '5'))[0];
    $source['product_id'] = '900000005';
    initTest(ShopInit\productId('pandomu-900000005', $context) === null, 'Private test reference already exists');
    initRejects(fn () => ShopInit\imagePlans([$source], $context));
    $sourcePlan = ShopInit\imagePlans([$source], $context, ShopInit\SOURCE_IMAGE_DIR, true);
    initTest($sourcePlan[0]['product_id'] === null && count($sourcePlan[0]['images']) === count($source['images']), 'Fresh-product image preflight failed');
    initTest(ShopInit\productId('pandomu-900000005', $context) === null, 'Image preflight created a product');
    echo "OK: missing inputs, category minimum, changed source, fail-fast stage ordering, activation gate, child exit status and source-only image validation before product creation verified. No catalog changes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_string($temporary) && is_dir($temporary)) {
        foreach (['products.json', 'categories.json'] as $filename) {
            if (is_file($temporary . '/' . $filename)) {
                unlink($temporary . '/' . $filename);
            }
        }
        if (is_dir($temporary . '/images')) {
            rmdir($temporary . '/images');
        }
        rmdir($temporary);
    }
}
exit($exitCode);
