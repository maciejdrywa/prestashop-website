<?php
declare(strict_types=1);

/**
 * Odtworzenie konfiguracji sklepu z pliku eksportu w repozytorium.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/configure-from-export.php [--dry-run] [--file=PLIK]
 *
 * Ostatni etap inicjalizacji. Domyślnie czyta deploy/config/shop-config.json. Wymaga istniejących języków
 * i walut z pliku oraz plików modułów. Przy --dry-run wypisuje elementy do zmiany bez zapisu.
 *
 * Deleguje do lib/config-export.php: walidacja pliku i kontrola sekretów, zapis różnic, kontrolny eksport
 * i zapis odcisku pliku w SHOP_INIT_CONFIG_EXPORT_SHA256. Przed zatwierdzeniem sprawdza warunki wcześniejszych
 * etapów inicjalizacji, a ich naruszenie wycofuje zapisy.
 */

require_once __DIR__ . '/lib/initialization-state.php';

try {
    $dryRun = false;
    $path = null;
    foreach (array_slice($argv, 1) as $option) {
        if ($option === '--dry-run') {
            $dryRun = true;
        } elseif (str_starts_with($option, '--file=') && strlen($option) > 7) {
            $path = substr($option, 7);
        } else {
            throw new RuntimeException('Użycie: php configure-from-export.php [--dry-run] [--file=PLIK]');
        }
    }
    $path ??= ShopInit\configExportPath();
    $context = ShopInit\boot();
    echo '[KONFIGURACJA] Plik ' . $path . PHP_EOL;
    $export = ShopInit\readConfigExport($path);
    $differences = ShopInit\applyConfigExport($export['data'], $export['sha256'], $context, $dryRun,
        fn () => ShopInit\assertInitializationConfigurationReady($context));
    foreach ($differences as $difference) {
        echo ($dryRun ? 'PLAN: ' : 'ZMIENIONO: ') . $difference . PHP_EOL;
    }
    if ($differences === []) {
        echo "OK: konfiguracja sklepu jest zgodna z plikiem.\n";
    } else {
        echo 'OK: ' . ($dryRun ? 'tryb próbny, do zmiany ' : 'odtworzono ') . count($differences) . " elementów konfiguracji.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'BŁĄD odtwarzania konfiguracji: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
