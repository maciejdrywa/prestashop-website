<?php
declare(strict_types=1);

/** Kontrole odczytowe podziału konfiguracji i ochrony backendu D2.5. */
require_once __DIR__ . '/../lib/initialization-state.php';
try {
    ShopInit\boot();
    $before = array_column(ShopInit\apiInitializationStages('prepare'), 'script');
    $after = array_column(ShopInit\apiInitializationStages('finalize'), 'script');
    if (!in_array('configure-webservice.php', $before, true) || in_array('configure-menu.php', $before, true)
        || !in_array('configure-menu.php', $after, true) || !in_array('configure-promotions.php', $after, true)
        || array_intersect($before, $after) !== []) throw new RuntimeException('Invalid API stage split.');
    if (in_array('configure-from-export.php', $before, true)
        || end($after) !== 'configure-from-export.php') {
        throw new RuntimeException('Configuration export must run last, after catalog finalization.');
    }
    foreach (['configure-menu.php', 'configure-promotions.php', 'configure-omnibus.php', 'configure-theme-content.php'] as $dependency) {
        $position = array_search($dependency, $after, true);
        if ($position === false || $position >= count($after) - 1) {
            throw new RuntimeException('Missing export prerequisite in API finalization: ' . $dependency);
        }
    }
    foreach ([...$before, ...$after] as $script) {
        if (!str_starts_with($script, 'configure-')) throw new RuntimeException('Native catalog write in API pipeline.');
    }
    $path = _PS_ROOT_DIR_ . '/var/import/catalog-backend.json';
    $marker = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($marker['backend'] !== 'api') throw new RuntimeException('Run on an API test installation.');
    $rejected = false;
    try { ShopInit\claimCatalogBackend('php'); } catch (RuntimeException $error) { $rejected = true; }
    if (!$rejected) throw new RuntimeException('Native backend was allowed on an API installation.');
    echo "OK: API preparation/finalization contain only configurators; PHP backend blocked. Read only.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
