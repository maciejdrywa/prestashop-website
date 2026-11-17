<?php

declare(strict_types=1);

namespace ShopInit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

const PROJECT_THEME = 'pandomu';

/**
 * Odczytuje aktywny motyw bez cache zapytań, również po aktywacji w osobnym procesie.
 */
function projectThemeActive(\Context $context): bool
{
    return \Db::getInstance()->getValue(
        'SELECT theme_name FROM ' . _DB_PREFIX_ . 'shop WHERE id_shop = ' . (int) $context->shop->id,
        false
    ) === PROJECT_THEME;
}

/**
 * Synchronizuje pliki motywu z obrazu Docker z trwałą instalacją PrestaShop.
 * Zapisuje listę wdrożonych plików po wyczyszczeniu pamięci podręcznej.
 * Dzięki temu wznowienie po przerwaniu odświeża zasoby i szablony.
 */
function deployProjectTheme(\Context $context): void
{
    $source = '/opt/shop-themes/' . PROJECT_THEME . '/';
    $target = _PS_ALL_THEMES_DIR_ . PROJECT_THEME . '/';
    $filesystem = new Filesystem();
    $stateDir = _PS_ROOT_DIR_ . '/var/import/';
    $filesystem->mkdir($stateDir);
    $lock = fopen($stateDir . 'pandomu-theme.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open the theme deployment lock.');
    }

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another theme deployment is in progress.');
        }
        if (!is_readable($source . 'config/theme.yml')) {
            throw new RuntimeException('Theme bundle is missing. Rebuild the shop image.');
        }
        $active = projectThemeActive($context);
        if (!$active) {
            verifyThemeImageTypes($source . 'config/theme.yml');
        }

        $manifest = $stateDir . 'pandomu-theme.json';
        $previous = is_file($manifest) ? json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR) : [];
        $current = [];
        $changed = false;
        $configChanged = false;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($source));
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                throw new RuntimeException('Cannot read the theme bundle file: ' . $relative);
            }
            $current[$relative] = hash('sha256', $content);
            if (!is_file($target . $relative) || hash_file('sha256', $target . $relative) !== $current[$relative]) {
                $filesystem->dumpFile($target . $relative, $content);
                $changed = true;
                $configChanged = $configChanged || $relative === 'config/theme.yml';
            }
        }
        // Usuwamy tylko pliki zapisane w poprzednim wykazie. Ścieżka musi pozostać w katalogu motywu.
        foreach (array_diff_key($previous, $current) as $relative => $hash) {
            if (!preg_match('~\A(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\z~', $relative)) {
                throw new RuntimeException('Invalid path in the theme deployment manifest.');
            }
            $filesystem->remove($target . $relative);
        }
        ksort($current);

        if (!$active) {
            $command = new Process([
                PHP_BINARY, 'bin/console', 'prestashop:theme:enable', PROJECT_THEME, '--env=prod', '--no-interaction',
            ], _PS_ROOT_DIR_);
            $command->mustRun();
            if (!projectThemeActive($context)) {
                throw new RuntimeException('PrestaShop did not persist the active theme.');
            }
        } elseif ($configChanged || ($previous['config/theme.yml'] ?? null) !== $current['config/theme.yml']) {
            // Po zmianie YAML PrestaShop powinien odczytać konfigurację ponownie.
            $filesystem->remove(_PS_CONFIG_DIR_ . 'themes/' . PROJECT_THEME . '/shop' . (int) $context->shop->id . '.json');
        }

        deployCategoryPagination($filesystem);

        if (!$active || $changed || $previous !== $current) {
            // Media::clearCache korzysta z motywu wczytanego na początku procesu.
            // Przy pierwszej aktywacji byłby to jeszcze Classic.
            if ($active) {
                \Media::clearCache();
            }
            \Tools::clearSmartyCache();
            $filesystem->dumpFile($manifest, json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            echo "OK: PAN DOMU theme deployed and active.\n";
        } else {
            echo "OK: PAN DOMU theme is active and its files are up to date.\n";
        }
    } finally {
        fclose($lock);
    }
}

/** Synchronizuje moduł paginacji z obrazem także na istniejącym wolumenie sklepu. */
function deployCategoryPagination(Filesystem $filesystem): void
{
    $name = 'shopcategorypagination';
    $file = $name . '/' . $name . '.php';
    $source = '/opt/shop-modules/' . $file;
    if (!is_readable($source) || ($content = file_get_contents($source)) === false) {
        throw new RuntimeException('Brakuje modułu paginacji. Przebuduj obraz sklepu.');
    }

    $target = _PS_MODULE_DIR_ . $file;
    if (!is_file($target) || hash_file('sha256', $target) !== hash('sha256', $content)) {
        $filesystem->dumpFile($target, $content);
    }

    if (!\Module::isInstalled($name)) {
        (new Process([PHP_BINARY, 'bin/console', 'prestashop:module', 'install', $name, '--env=prod', '--no-interaction'], _PS_ROOT_DIR_))->mustRun();
        \Module::resetStaticCache();
    }

    $module = \Module::getInstanceByName($name);
    if (!$module || !$module->id || (!\Module::isEnabled($name) && !$module->enable())
        || (!$module->isRegisteredInHook($module::QUERY_HOOK) && !$module->registerHook($module::QUERY_HOOK))) {
        throw new RuntimeException('Nie udało się włączyć paginacji kategorii.');
    }
}

/**
 * PrestaShop odtwarza tabelę typów obrazów podczas aktywacji motywu.
 * Kontrola galerii uwzględnia definicje i identyfikatory tych typów w odciskach miniatur.
 */
function verifyThemeImageTypes(string $config): void
{
    $types = Yaml::parseFile($config)['global_settings']['image_types'];
    $scopes = ['products', 'categories', 'manufacturers', 'suppliers', 'stores'];
    $expected = [];
    foreach ($types as $name => $type) {
        $row = ['id_image_type' => count($expected) + 1, 'name' => $name, 'width' => $type['width'], 'height' => $type['height']];
        foreach ($scopes as $scope) {
            $row[$scope] = (int) in_array($scope, $type['scope'], true);
        }
        $expected[] = $row;
    }
    $current = \Db::getInstance()->executeS(
        'SELECT id_image_type, name, width, height, products, categories, manufacturers, suppliers, stores FROM '
        . _DB_PREFIX_ . 'image_type ORDER BY id_image_type',
        true,
        false
    );
    if ($current != $expected) {
        throw new RuntimeException('The existing image types differ from the project defaults. Activation stopped before changing gallery settings.');
    }
}
