<?php
declare(strict_types=1);

/**
 * Konfiguracja, synchronizacja i zasilenie danymi modułu Dyrektywy Omnibus (shopomnibus).
 *
 * Używana przez configure-omnibus.php oraz proces inicjalizacji init-shop.php (etap 21).
 */

namespace ShopInit;

use Context;
use Db;
use Module;
use Product;
use RuntimeException;
use SpecificPrice;
use DateTimeImmutable;

require_once __DIR__ . '/prestashop.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/promotions.php';

/**
 * Kopiuje pliki modułu shopomnibus z obrazu do instalacji PrestaShop.
 *
 * @return bool Czy podmieniono pliki i należy odświeżyć cache.
 * @throws RuntimeException Przy błędzie kopiowania.
 */
function installOmnibusModuleFiles(): bool
{
    $imageSource = '/opt/shop-modules/shopomnibus/';
    $localSource = _PS_ROOT_DIR_ . '/../shop/modules/shopomnibus/';
    $source = is_dir($imageSource) ? $imageSource : (is_dir($localSource) ? $localSource : null);

    if ($source === null) {
        // Sprawdź ścieżkę z repozytorium
        $repoSource = __DIR__ . '/../../../shop/modules/shopomnibus/';
        if (is_dir($repoSource)) {
            $source = $repoSource;
        } elseif (is_file(_PS_MODULE_DIR_ . 'shopomnibus/shopomnibus.php')) {
            // Pliki modułu są już obecne w katalogu modułów PrestaShop
            return false;
        } else {
            throw new RuntimeException('Brak katalogu źródłowego modułu shopomnibus.');
        }
    }

    $files = [
        'shopomnibus.php',
        'views/templates/hook/displayProductPriceBlock.tpl',
        'views/css/shopomnibus.css',
    ];

    $changed = false;
    $targetDir = _PS_MODULE_DIR_ . 'shopomnibus/';

    foreach ($files as $file) {
        $srcPath = $source . $file;
        $dstPath = $targetDir . $file;

        if (!is_readable($srcPath)) {
            throw new RuntimeException("Brak wymaganego pliku źródłowego modułu: {$file}");
        }

        if (is_file($dstPath) && hash_file('sha256', $dstPath) === hash_file('sha256', $srcPath)) {
            continue;
        }

        $dir = dirname($dstPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Nie można utworzyć katalogu docelowego: {$dir}");
        }

        $temp = tempnam($dir, '.omnibus-');
        if ($temp === false) {
            throw new RuntimeException("Nie można utworzyć pliku tymczasowego dla: {$file}");
        }

        try {
            if (!copy($srcPath, $temp) || !chmod($temp, 0644) || !rename($temp, $dstPath)) {
                throw new RuntimeException("Błąd zapisu pliku docelowego: {$file}");
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }

        $changed = true;
    }

    return $changed;
}

/**
 * Upewnia się, że moduł shopomnibus jest zainstalowany, włączony i ma zarejestrowane hooki.
 *
 * @param Context $context
 * @return void
 */
function ensureOmnibusModuleInstalled(Context $context): void
{
    require_once _PS_MODULE_DIR_ . 'shopomnibus/shopomnibus.php';

    $module = Module::getInstanceByName('shopomnibus');
    if (!$module) {
        throw new RuntimeException('Nie można załadować instancji modułu shopomnibus.');
    }

    if (!Module::isInstalled('shopomnibus')) {
        if (!$module->install()) {
            throw new RuntimeException('Instalacja modułu shopomnibus nie powiodła się.');
        }
        $module = Module::getInstanceByName('shopomnibus');
    }

    if (!Module::isEnabled('shopomnibus')) {
        if (!$module->enable()) {
            throw new RuntimeException('Włączenie modułu shopomnibus nie powiodło się.');
        }
    }

    $requiredHooks = [
        'displayProductPriceBlock',
        'actionFrontControllerSetMedia',
        'actionObjectProductUpdateAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionProductAttributeUpdate',
    ];

    foreach ($requiredHooks as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            try {
                $module->registerHook($hook);
            } catch (\Throwable $e) {
                // Hook mógł już być zarejestrowany w bazie
            }
        }
    }
}

/**
 * Zapewnia istnienie tabeli ps_shopomnibus_price_history.
 *
 * @return void
 */
function ensureOmnibusTable(): void
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'shopomnibus_price_history` (
        `id_price_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_product` INT UNSIGNED NOT NULL,
        `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
        `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
        `price_gross` DECIMAL(20, 6) NOT NULL,
        `price_net` DECIMAL(20, 6) NOT NULL,
        `is_promotional` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_price_history`),
        INDEX `idx_product_attr_date` (`id_product`, `id_product_attribute`, `date_add`),
        INDEX `idx_date_add` (`date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

    Db::getInstance()->execute($sql);
    Db::getInstance()->execute('CREATE OR REPLACE VIEW `' . _DB_PREFIX_ . 'omnibus_price_history` AS SELECT * FROM `' . _DB_PREFIX_ . 'shopomnibus_price_history`;');
}

/**
 * Generuje deterministyczne dane demonstracyjne historii cen dla 25 produktów promocyjnych.
 *
 * Obejmuje 5 reprezentatywnych scenariuszy:
 * 1. Produkt standardowy (> 30 dni, stabilna cena przed obniżką) - ID 53 (Klamka NINA)
 * 2. Wahanie cen w okresie 30 dni (tymczasowa obniżka i powrót) - ID 22 (Zamek ZN-160)
 * 3. Sukcesywne (kolejne) obniżki cen - ID 43 (KLAMKA LIZA)
 * 4. Produkt oferowany krócej niż 30 dni (< 30 dni) - ID 21 (Zamek GAMA)
 * 5. Produkt z wariantami (kombinacje cech) - ID 32 (Wycieraczka metalowa z ramą)
 * Oraz pozostałe 20 produktów promocyjnych ze stabilną historią od 35 dni.
 *
 * @param Context $context
 * @param array $catalog
 * @param bool $dryRun
 * @return array{inserted: int, skipped: int}
 */
function seedOmnibusDemoData(Context $context, array $catalog, bool $dryRun = false): array
{
    ensurePriceContext($context);
    ensureOmnibusTable();

    $db = Db::getInstance();
    $tableName = _DB_PREFIX_ . 'shopomnibus_price_history';

    // Pobierz plan promocji z D3.1 (25 produktów)
    $plan = promotionsPlan($catalog, $context);
    // Stała data bazowa (anchor date) zapewnia 100% determinizmu i pełną idempotencję seeda
    $now = new DateTimeImmutable('2026-11-19 12:00:00');

    $inserted = 0;
    $skipped = 0;

    // Pomocnik do wstawiania wpisu historii (z deduplikacją)
    $insertHistory = function (
        int $productId,
        int $attributeId,
        float $gross,
        float $net,
        bool $isPromo,
        string $date
    ) use ($db, $tableName, $dryRun, &$inserted, &$skipped): void {
        // Sprawdź czy identyczny wpis już istnieje
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $tableName . '`
             WHERE `id_product` = ' . $productId . '
               AND `id_product_attribute` = ' . $attributeId . '
               AND `date_add` = "' . pSQL($date) . '"'
        );

        if ($exists > 0) {
            $skipped++;
            return;
        }

        if (!$dryRun) {
            $db->insert('shopomnibus_price_history', [
                'id_product' => $productId,
                'id_product_attribute' => $attributeId,
                'id_shop' => 1,
                'price_gross' => $gross,
                'price_net' => $net,
                'is_promotional' => $isPromo ? 1 : 0,
                'date_add' => pSQL($date),
            ]);
        }
        $inserted++;
    };

    foreach ($plan as $item) {
        $pId = (int) $item['product_id'];
        $sourceId = (string) ($item['source_id'] ?? '');
        $regGross = $item['regular_gross'];
        $regNet = round($regGross / 1.23, 6);
        $promoGross = $item['expected_promo_gross'];
        $promoNet = round($promoGross / 1.23, 6);

        // Upewnij się, że produkt ma date_add odpowiednie do scenariusza
        if ($sourceId === '5') {
            // SCENARIUSZ 4: Produkt oferowany krócej niż 30 dni (wprowadzony 14 dni temu) - Zamek GAMA (source 5)
            $dateAdd14 = $now->modify('-14 days')->format('Y-m-d 08:00:00');
            $datePromo2 = $now->modify('-2 days')->format('Y-m-d 10:00:00');

            if (!$dryRun) {
                $db->execute('UPDATE `' . _DB_PREFIX_ . 'product` SET `date_add` = "' . pSQL($dateAdd14) . '" WHERE `id_product` = ' . $pId);
                $db->execute('UPDATE `' . _DB_PREFIX_ . 'product_shop` SET `date_add` = "' . pSQL($dateAdd14) . '" WHERE `id_product` = ' . $pId);
            }

            // Wpis 1: Wprowadzenie do oferty 14 dni temu w cenie regularnej
            $insertHistory($pId, 0, $regGross, $regNet, false, $dateAdd14);
            // Wpis 2: Wprowadzenie promocji 2 dni temu
            $insertHistory($pId, 0, $promoGross, $promoNet, true, $datePromo2);

            $combos = Product::getProductAttributesIds($pId);
            foreach ($combos as $combo) {
                $attrId = (int) $combo['id_product_attribute'];
                $insertHistory($pId, $attrId, $regGross, $regNet, false, $dateAdd14);
                $insertHistory($pId, $attrId, $promoGross, $promoNet, true, $datePromo2);
            }
            continue;
        }

        if ($sourceId === '12') {
            // SCENARIUSZ 2: Wahania cen w oknie 30 dni (krótka przecena do 26.00 zł i powrót do 28.00 zł) - Zamek ZN-160 (source 12)
            $date40 = $now->modify('-40 days')->format('Y-m-d 09:00:00');
            $date20 = $now->modify('-20 days')->format('Y-m-d 09:00:00');
            $date15 = $now->modify('-15 days')->format('Y-m-d 09:00:00');
            $date1 = $now->modify('-1 day')->format('Y-m-d 10:00:00');

            // 40 dni temu: regularna cena 28.00 zł
            $insertHistory($pId, 0, 28.00, round(28.00 / 1.23, 6), false, $date40);
            // 20 dni temu: tymczasowa obniżka do 26.00 zł
            $insertHistory($pId, 0, 26.00, round(26.00 / 1.23, 6), true, $date20);
            // 15 dni temu: powrót do ceny regularnej 28.00 zł
            $insertHistory($pId, 0, 28.00, round(28.00 / 1.23, 6), false, $date15);
            // 1 dzień temu: obecna promocja do 25.20 zł
            $insertHistory($pId, 0, 25.20, round(25.20 / 1.23, 6), true, $date1);
            continue;
        }

        if ($sourceId === '541') {
            // SCENARIUSZ 3: Sukcesywne obniżki (69.99 -> 59.99 -> 49.99) - KLAMKA LIZA (source 541)
            $date45 = $now->modify('-45 days')->format('Y-m-d 09:00:00');
            $date15 = $now->modify('-15 days')->format('Y-m-d 09:00:00');
            $date2 = $now->modify('-2 days')->format('Y-m-d 10:00:00');

            // 45 dni temu: regularna 69.99 zł
            $insertHistory($pId, 0, 69.99, round(69.99 / 1.23, 6), false, $date45);
            // 15 dni temu: pierwsza obniżka do 59.99 zł
            $insertHistory($pId, 0, 59.99, round(59.99 / 1.23, 6), true, $date15);
            // 2 dni temu: pogłębienie obniżki do 49.99 zł
            $insertHistory($pId, 0, 49.99, round(49.99 / 1.23, 6), true, $date2);
            continue;
        }

        if ($sourceId === '944') {
            // SCENARIUSZ 5: Wycieraczka z ramą (warianty kombinacji 89 i 90) - source 944
            $combos = Product::getProductAttributesIds($pId);
            $date40 = $now->modify('-40 days')->format('Y-m-d 09:00:00');
            $date1 = $now->modify('-1 day')->format('Y-m-d 10:00:00');

            foreach ($combos as $combo) {
                $attrId = (int) $combo['id_product_attribute'];
                $varReg = (float) Product::getPriceStatic($pId, true, $attrId, 2, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);
                $varPromo = (float) Product::getPriceStatic($pId, true, $attrId, 2, null, false, true, 1, false, null, null, null, $dummy, false, false, $context);

                $insertHistory($pId, $attrId, $varReg, round($varReg / 1.23, 6), false, $date40);
                $insertHistory($pId, $attrId, $varPromo, round($varPromo / 1.23, 6), true, $date1);
            }

            // Wpis ogólny dla produktu bazowego
            $insertHistory($pId, 0, $regGross, $regNet, false, $date40);
            $insertHistory($pId, 0, $promoGross, $promoNet, true, $date1);
            continue;
        }

        // SCENARIUSZ 1 (ID 53 / source 139) oraz pozostałe standardowe produkty (> 30 dni w ofercie)
        $date40 = $now->modify('-40 days')->format('Y-m-d 09:00:00');
        $date1 = $now->modify('-1 day')->format('Y-m-d 10:00:00');

        $insertHistory($pId, 0, $regGross, $regNet, false, $date40);
        $insertHistory($pId, 0, $promoGross, $promoNet, true, $date1);

        // Jeśli produkt ma kombinacje, dodaj historię również dla kombinacji
        $combos = Product::getProductAttributesIds($pId);
        if (!empty($combos)) {
            foreach ($combos as $combo) {
                $attrId = (int) $combo['id_product_attribute'];
                $varReg = (float) Product::getPriceStatic($pId, true, $attrId, 2, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);
                $varPromo = (float) Product::getPriceStatic($pId, true, $attrId, 2, null, false, true, 1, false, null, null, null, $dummy, false, false, $context);

                $insertHistory($pId, $attrId, $varReg, round($varReg / 1.23, 6), false, $date40);
                $insertHistory($pId, $attrId, $varPromo, round($varPromo / 1.23, 6), true, $date1);
            }
        }
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}

/**
 * Zapisuje początkowy stan cen (baseline) dla wszystkich aktywnych produktów i kombinacji w sklepie.
 *
 * Zapobiega sytuacji, w której nowa promocja dodana w panelu admina dla produktu spoza seeda
 * nie ma w historii ceny sprzed obniżki i musi posiłkować się fallbackiem.
 *
 * @param Context $context
 * @param bool $dryRun
 * @return array{inserted: int, skipped: int}
 */
function seedBaselinePricesForAllProducts(Context $context, bool $dryRun = false): array
{
    ensurePriceContext($context);
    ensureOmnibusTable();

    $db = Db::getInstance();
    $tableName = _DB_PREFIX_ . 'shopomnibus_price_history';

    // Pobierz wszystkie aktywne produkty
    $products = $db->executeS('
        SELECT p.id_product, p.date_add
        FROM `' . _DB_PREFIX_ . 'product` p
        WHERE p.active = 1
        ORDER BY p.id_product ASC
    ');

    if (empty($products)) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    // Pobierz listę produktów, które mają już jakiekolwiek wpisy w historii
    $existingProductIds = $db->executeS('SELECT DISTINCT id_product FROM `' . $tableName . '`');
    $existingMap = [];
    foreach ($existingProductIds as $row) {
        $existingMap[(int) $row['id_product']] = true;
    }

    $inserted = 0;
    $skipped = 0;
    $batchValues = [];
    $batchSize = 100;

    $flushBatch = function () use (&$batchValues, $db, $tableName, $dryRun): void {
        if (empty($batchValues)) {
            return;
        }
        if (!$dryRun) {
            $sql = 'INSERT INTO `' . $tableName . '` (`id_product`, `id_product_attribute`, `id_shop`, `price_gross`, `price_net`, `is_promotional`, `date_add`) VALUES '
                . implode(', ', $batchValues);
            $db->execute($sql);
        }
        $batchValues = [];
    };

    foreach ($products as $p) {
        $productId = (int) $p['id_product'];
        $dateAdd = !empty($p['date_add']) && $p['date_add'] !== '0000-00-00 00:00:00'
            ? $p['date_add']
            : '2026-10-10 09:00:00';

        // Jeśli produkt ma już wpisy w historii (np. z seeda 25 promocji), pomijamy go
        if (isset($existingMap[$productId])) {
            $skipped++;
            continue;
        }

        // 1. Zapis bazowego produktu (id_product_attribute = 0)
        $gross = (float) Product::getPriceStatic($productId, true, null, 6, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);
        $net = (float) Product::getPriceStatic($productId, false, null, 6, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);

        if ($gross > 0) {
            $batchValues[] = '(' . $productId . ', 0, 1, ' . $gross . ', ' . $net . ', 0, "' . pSQL($dateAdd) . '")';
            $inserted++;
            if (count($batchValues) >= $batchSize) {
                $flushBatch();
            }
        }

        // 2. Zapis wariantów (kombinacji)
        $combos = Product::getProductAttributesIds($productId);
        if (!empty($combos)) {
            foreach ($combos as $combo) {
                $attrId = (int) ($combo['id_product_attribute'] ?? 0);
                if ($attrId > 0) {
                    $varGross = (float) Product::getPriceStatic($productId, true, $attrId, 6, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);
                    $varNet = (float) Product::getPriceStatic($productId, false, $attrId, 6, null, false, false, 1, false, null, null, null, $dummy, false, false, $context);

                    if ($varGross > 0) {
                        $batchValues[] = '(' . $productId . ', ' . $attrId . ', 1, ' . $varGross . ', ' . $varNet . ', 0, "' . pSQL($dateAdd) . '")';
                        $inserted++;
                        if (count($batchValues) >= $batchSize) {
                            $flushBatch();
                        }
                    }
                }
            }
        }
    }

    $flushBatch();

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}

/**
 * Sprawdza czy moduł Omnibus jest w pełni skonfigurowany (dla --resume).
 *
 * @param Context $context
 * @return bool
 */
function omnibusConfigured(Context $context): bool
{
    if (!Module::isInstalled('shopomnibus') || !Module::isEnabled('shopomnibus')) {
        return false;
    }

    $module = Module::getInstanceByName('shopomnibus');
    if (!$module || !$module->isRegisteredInHook('displayProductPriceBlock')) {
        return false;
    }

    $db = Db::getInstance();
    $tableName = _DB_PREFIX_ . 'shopomnibus_price_history';

    $tableExists = (bool) $db->executeS("SHOW TABLES LIKE '{$tableName}'");
    if (!$tableExists) {
        return false;
    }

    // Weryfikacja: historia musi obejmować co najmniej 25 unikalnych produktów
    $uniqueProducts = (int) $db->getValue("SELECT COUNT(DISTINCT id_product) FROM `{$tableName}`");
    return ($uniqueProducts >= 25);
}
