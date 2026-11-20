<?php
declare(strict_types=1);

/**
 * Konfigurator modułu Dyrektywy Omnibus dla sklepu PAN DOMU.
 *
 * Wywołanie:
 *   php configure-omnibus.php [--dry-run]
 *
 * 1. Synchronizuje pliki autorskiego modułu modules/shopomnibus.
 * 2. Instaluje i włącza moduł, rejestrując hooki displayProductPriceBlock i aktualizacji cen.
 * 3. Zapewnia tabelę historii cen ps_shopomnibus_price_history.
 * 4. Zapewnia dane demonstracyjne historii cen dla 25 produktów objętych promocjami.
 * 5. Czyści pamięć podręczną Smarty i zasobów motywu.
 */

require_once __DIR__ . '/lib/prestashop.php';
require_once __DIR__ . '/lib/omnibus.php';

try {
    $dryRun = in_array('--dry-run', $argv, true);
    if ($argc > ($dryRun ? 2 : 1)) {
        fwrite(STDERR, "Użycie: php configure-omnibus.php [--dry-run]\n");
        exit(1);
    }

    echo "[1/4] Inicjalizacja środowiska i synchronizacja plików modułu shopomnibus...\n";
    $context = ShopInit\boot();
    ShopInit\priceContext($context);

    // Boot Symfony kernel dla pełnego wsparcia instalacji modułów
    global $kernel;
    require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
    $kernel = new AppKernel('prod', false);
    $kernel->boot();

    $catalog = ShopInit\readCatalog();
    $filesChanged = ShopInit\installOmnibusModuleFiles();

    echo "[2/4] Weryfikacja instalacji i rejestracja hooków modułu shopomnibus...\n";
    if (!$dryRun) {
        ShopInit\ensureOmnibusModuleInstalled($context);
    }

    echo "[3/4] Przygotowanie tabeli historii, zasilanie demo i baseline dla katalogu...\n";
    $resultDemo = ShopInit\seedOmnibusDemoData($context, $catalog, $dryRun);
    echo "      Demo (25 promocji): dodano {$resultDemo['inserted']}, pominięto {$resultDemo['skipped']}.\n";
    $resultBaseline = ShopInit\seedBaselinePricesForAllProducts($context, $dryRun);
    echo "      Baseline (katalog): dodano {$resultBaseline['inserted']}, pominięto {$resultBaseline['skipped']}.\n";

    echo "[4/4] Odświeżanie pamięci podręcznej PrestaShop...\n";
    if (!$dryRun) {
        if ($filesChanged) {
            Media::clearCache();
        }
        Tools::clearSmartyCache();
    }

    $modeNotice = $dryRun ? ' (TRYB DRY-RUN — brak zmian w bazie)' : '';
    echo "OK: Moduł Dyrektywy Omnibus został pomyślnie skonfigurowany{$modeNotice}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD konfiguracji modułu Omnibus: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
