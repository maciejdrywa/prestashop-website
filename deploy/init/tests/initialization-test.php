<?php
declare(strict_types=1);

/**
 * Test dziennika, walidacji wejścia, kolejności etapów i zatrzymania po błędzie.
 *
 * Wywołanie jako www-data w kontenerze z danymi wymaganymi przez scenariusz
 * php /opt/shop/init/tests/initialization-test.php
 *
 * Tworzy pliki tymczasowe i uruchamia kontrolowany podproces z błędnym argumentem. Kontrola świeżego
 * produktu odczytuje sklep, a finally sprząta fixture plikowe.
 * Kod zakończenia 0 oznacza spełnienie asercji, a 1 nieudany scenariusz.
 */

require_once __DIR__ . '/../lib/initialization-state.php';

/**
 * Wymaga spełnienia warunku testu, a przy błędzie zgłasza podany komunikat.
 *
 * @param bool $condition Warunek asercji wymagany przez bieżący scenariusz.
 * @param string $message Komunikat zgłaszany po niespełnieniu asercji.
 * @return void
 * @throws \RuntimeException Gdy warunek asercji jest fałszywy.
 */
function initTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
/**
 * Sprawdza, że przekazana operacja zgłasza oczekiwany błąd walidacji.
 *
 * @param callable $callback Operacja, która powinna zgłosić RuntimeException.
 * @return void
 * @throws \RuntimeException Gdy oczekiwany błąd walidacji nie wystąpi.
 */
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
    // Dziennik jest badany na odrębnym pliku tymczasowym. Próby z inną tożsamością
    // lub hashem muszą zostać odrzucone, a kolejny zapis zachować wcześniejsze checkpointy.
    $journal = ShopInit\readInitializationState($temporary . '/state.json', 'test-installation', ['products' => 'test-hash']);
    $journal['records']['5'] = ['id' => 20, 'done' => ['import-products.php']];
    ShopInit\saveInitializationState($temporary . '/state.json', $journal);
    initTest(json_decode((string) file_get_contents($temporary . '/state.json'), true, 512, JSON_THROW_ON_ERROR) === $journal,
        'Journal contents changed during atomic write');
    initTest(ShopInit\readInitializationState($temporary . '/state.json', 'test-installation', ['products' => 'test-hash']) === $journal,
        'Matching journal was rejected');
    initRejects(fn () => ShopInit\readInitializationState($temporary . '/state.json', 'other-installation', ['products' => 'test-hash']));
    initRejects(fn () => ShopInit\readInitializationState($temporary . '/state.json', 'test-installation', ['products' => 'changed-hash']));
    $journal['records']['5']['done'][] = 'import-images.php';
    ShopInit\saveInitializationState($temporary . '/state.json', $journal);
    initTest(count(glob($temporary . '/.initialization-*')) === 0, 'Temporary journal files were left behind');
    initTest(json_decode((string) file_get_contents($temporary . '/state.json'), true, 512, JSON_THROW_ON_ERROR) === $journal,
        'Journal replacement lost completed records');
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
    // Runner jest kontrolowany przez test. Pozwala sprawdzić kolejność, granicę
    // błędu i opóźnienie aktywacji bez uruchamiania rzeczywistych konfiguratorów.
    $stages = ShopInit\initializationStages();
    $scripts = array_column($stages, 'script');
    initTest(in_array('configure-orders.php', $scripts, true), 'Order/invoice configuration is missing');
    initTest(array_search('configure-payments.php', $scripts, true) < array_search('configure-orders.php', $scripts, true), 'Order statuses must follow native payment module installation');
    initTest(array_search('configure-carriers.php', array_column($stages, 'script'), true) < array_search('configure-payments.php', array_column($stages, 'script'), true), 'Project carriers must precede payment permission synchronization');
    initTest(in_array('configure-carriers.php', array_column($stages, 'script'), true), 'Project carrier configuration is missing');
    initTest(in_array('configure-mail.php', array_column($stages,'script'), true), 'Polish email configuration is missing');
    initTest(in_array('configure-payments.php', array_column($stages,'script'), true), 'Offline payment configuration is missing');
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
    initTest(in_array('configure-content.php', $calls, true), 'Information pages/footer configuration is missing');
    initTest(array_search('configure-content.php', $calls, true) < array_search('configure-cookies.php', $calls, true), 'Cookie notice precedes its privacy page');
    initTest(array_search('configure-content.php', $calls, true) < array_search('configure-module-texts.php', $calls, true), 'Module wording precedes required information pages');
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
        foreach (['products.json', 'categories.json', 'state.json'] as $filename) {
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
