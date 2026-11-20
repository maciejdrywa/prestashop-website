<?php
declare(strict_types=1);

/**
 * Eksport konfiguracji sklepu do pliku przechowywanego w repozytorium.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/export-config.php [--output=PLIK] [--check]
 *
 * Bez opcji wypisuje JSON na standardowe wyjście. --output zapisuje plik atomowo (np. /tmp/shop-config.json,
 * skąd kopiuje go docker compose cp). --check porównuje sklep z deploy/config/shop-config.json i kończy
 * się kodem 2 przy różnicach, także obiektach dodanych w sklepie po eksporcie, bez zapisu.
 *
 * Eksport obejmuje allowlistę z lib/config-export.php i przed zapisem odrzuca sekrety oraz dane osobowe, a także
 * stan sprzeczny z warunkami etapów inicjalizacji.
 */

require_once __DIR__ . '/lib/initialization-state.php';

try {
    $options = array_slice($argv, 1);
    $output = null;
    $check = false;
    foreach ($options as $option) {
        if ($option === '--check') {
            $check = true;
        } elseif (str_starts_with($option, '--output=') && strlen($option) > 9) {
            $output = substr($option, 9);
        } else {
            throw new RuntimeException('Użycie: php export-config.php [--output=PLIK] [--check]');
        }
    }
    if ($check && $output !== null) {
        throw new RuntimeException('Opcje --check i --output wykluczają się.');
    }
    $context = ShopInit\boot();
    \Db::getInstance()->disableCache();
    $data = ShopInit\exportShopConfiguration($context);
    ShopInit\assertConfigExportClean($data);
    ShopInit\assertInitializationConfigurationReady($context);
    if ($check) {
        $path = ShopInit\configExportPath();
        $saved = ShopInit\readConfigExport($path);
        $differences = ShopInit\configExportDifferences($data, $saved['data'], true);
        if ($differences !== []) {
            fwrite(STDERR, '[EKSPORT] Sklep różni się od ' . $path . ":\n- " . implode("\n- ", $differences) . PHP_EOL);
            exit(2);
        }
        fwrite(STDERR, '[EKSPORT] Sklep jest zgodny z ' . $path . PHP_EOL);
        exit(0);
    }
    $json = ShopInit\encodeConfigExport($data);
    if ($output === null) {
        echo $json;
    } else {
        $temporary = tempnam(dirname($output), '.shop-config-');
        if ($temporary === false || file_put_contents($temporary, $json) === false || !rename($temporary, $output)) {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Nie można zapisać ' . $output);
        }
        chmod($output, 0644);
    }
    fwrite(STDERR, '[EKSPORT] Zapisano konfigurację: ' . count($data['configuration']) . ' ustawień, '
        . count($data['carriers']) . ' przewoźników, ' . count($data['payments']) . ' metod płatności, '
        . count($data['cms']) . ' stron CMS, ' . count($data['modules']) . " modułów.\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[EKSPORT] BŁĄD. ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
