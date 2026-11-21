<?php
declare(strict_types=1);

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/storefront.php';

/** Jeden backend zapisu katalogu; przejęcie PHP → API wymaga jawnego --sync. */
function claimCatalogBackend(string $backend, bool $switch = false): void
{
    $path = _PS_ROOT_DIR_ . '/var/import/catalog-backend.json';
    $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
    if ($state !== null && (!is_array($state) || !in_array($state['backend'] ?? null, ['api', 'php'], true) || ($state['identity'] ?? null) !== webserviceInstanceIdentity())) {
        throw new RuntimeException('Niepoprawny znacznik backendu katalogu.');
    }
    if ($state !== null && $state['backend'] !== $backend && !($switch && $backend === 'api')) {
        throw new RuntimeException('Instalacja używa backendu ' . $state['backend'] . '. Przejęcie przez API wymaga --sync; nie uruchamiaj drugiego toru importu.');
    }
    if ($state === null || $state['backend'] !== $backend) {
        saveInitializationState($path, ['backend' => $backend, 'identity' => webserviceInstanceIdentity(), 'previous' => $state['backend'] ?? null]);
    }
}

/** Konfiguratory przed API i po odebraniu/aktywacji katalogu. Żadnych importerów PHP. */
function apiInitializationStages(string $phase): array
{
    // Eksport sprawdza warunki wszystkich konfiguratorów i wymaga ich modułów.
    // Musi pozostać ostatni, po utworzeniu katalogu i konfiguracji jego treści.
    $after = ['configure-menu.php', 'configure-promotions.php', 'configure-omnibus.php', 'configure-theme-content.php', 'configure-from-export.php'];
    return array_values(array_filter(initializationStages(), fn (array $stage): bool =>
        str_starts_with($stage['script'], 'configure-')
        && (in_array($stage['script'], $after, true) === ($phase === 'finalize'))));
}

function runApiInitialization(\Context $context, string $phase, bool $sync): void
{
    $products = readCatalog();
    readMainMenuPlan(dirname(SOURCE_FILE) . '/categories.json');
    $sources = [hash_file('sha256', SOURCE_FILE), hash_file('sha256', dirname(SOURCE_FILE) . '/categories.json')];
    $path = _PS_ROOT_DIR_ . '/var/import/api-initialization-state.json';
    $identity = webserviceInstanceIdentity();
    $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : ['version' => 1, 'backend' => 'api', 'identity' => $identity, 'sources' => $sources, 'stages' => []];
    if (!is_array($state) || ($state['version'] ?? null) !== 1 || ($state['backend'] ?? null) !== 'api'
        || ($state['identity'] ?? null) !== $identity || !is_array($state['stages'] ?? null)) {
        throw new RuntimeException('Niepoprawny stan przygotowania API.');
    }
    if ($state['sources'] !== $sources) {
        if (!$sync) throw new RuntimeException('Zmienione źródło wymaga --sync.');
        foreach (apiInitializationStages('finalize') as $stage) unset($state['stages'][$stage['script']]);
        $state['sources'] = $sources;
    }
    if ($phase === 'finalize' && !isset($state['stages']['configure-webservice.php'])) {
        throw new RuntimeException('Najpierw wykonaj przygotowanie API.');
    }
    claimCatalogBackend('api', $sync);
    saveInitializationState($path, $state);
    $fingerprint = [SOURCE_FILE => $sources[0], dirname(SOURCE_FILE) . '/categories.json' => $sources[1]];
    foreach (apiInitializationStages($phase) as $stage) {
        unchangedInitializationSources($fingerprint);
        $script = $stage['script'];
        if ($script === 'configure-cookies.php' && installCookieModuleFiles()) {
            \Media::clearCache();
            \Tools::clearSmartyCache();
        }
        $present = configurationPresent($script, $context);
        if ($script === 'configure-storefront.php' && demoProductPlan($context) !== []) $present = false;
        $force = $script === 'configure-theme.php' || ($phase === 'finalize' && $sync);
        if (!$present && isset($state['stages'][$script]) && !$force) {
            throw new RuntimeException('Brakuje zapisanych powiązań etapu ' . $script . '. Sprawdź konfigurację ręcznie; nie nadpisano ustawień.');
        }
        if ($present && !$force) {
            if (!isset($state['stages'][$script])) {
                $state['stages'][$script] = gmdate(DATE_ATOM);
                saveInitializationState($path, $state);
            }
            continue;
        }
        if (runInitializationScript($stage) !== 0) throw new RuntimeException('Nieudany konfigurator API: ' . $script);
        \Configuration::resetStaticCache();
        \Module::resetStaticCache();
        \Cache::clean('Module::*');
        if (!configurationPresent($script, $context)) throw new RuntimeException('Niepotwierdzony konfigurator API: ' . $script);
        unchangedInitializationSources($fingerprint);
        $state['stages'][$script] = gmdate(DATE_ATOM);
        saveInitializationState($path, $state);
    }
    echo '[API ' . $phase . '] OK, źródło ' . count($products) . " produktów; katalog zapisuje wyłącznie Webservice.\n";
}
