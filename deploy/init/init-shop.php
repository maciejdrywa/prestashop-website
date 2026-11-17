<?php
declare(strict_types=1);

/**
 * Orkiestracja konfiguracji i importu w 20 etapach.
 *
 * Wywołanie jako www-data w kontenerze shop
 * php /opt/shop/init/init-shop.php [--dry-run|--resume]
 *
 * Wymaga uruchomionej instalacji oraz obu JSON-ów i images/. Używa lib/initialization-state.php, blokady i
 * opcjonalnego dziennika w var/import.
 *
 * Tryb pełny odtwarza konfigurację, katalog i początkowy magazyn. Wznowienie kontroluje zastane dane i
 * uzupełnia niedokończone zapisy.
 */

require_once __DIR__ . '/lib/initialization-state.php';

/**
 * Obsługuje CLI, blokadę procesu oraz jawny import lub bezpieczne wznowienie.
 *
 * Przed zapisami waliduje komplet wejścia i obejmuje wykonanie blokadą init-shop.lock. Tryb --resume
 * korzysta z dziennika, a wywołanie bez opcji uruchamia pełne odtworzenie danych i ustawień. Obsługuje
 * wyjątki na granicy CLI i zwalnia blokadę w finally.
 *
 * @param array $arguments Argumenty CLI łącznie z nazwą uruchomionego skryptu.
 * @return int Kod CLI, 0 po sukcesie lub planie, 1 po obsłużonym błędzie.
 */
function initializeShopMain(array $arguments): int
{
    $lock = null;
    $started = microtime(true);
    try {
        $options = array_slice($arguments, 1);
        if ($options === ['--help']) {
            echo "Użycie: php init-shop.php [--dry-run|--resume]\nWymagane: products.json, categories.json i images/ w scraper-results.\n";
            return 0;
        }
        if ($options !== [] && $options !== ['--dry-run'] && $options !== ['--resume']) {
            throw new RuntimeException('Użycie: php init-shop.php [--dry-run|--resume]');
        }
        $dryRun = $options === ['--dry-run'];
        echo "[INIT] Wczytywanie PrestaShop...\n";
        $context = ShopInit\boot();
        // Blokada obowiązuje przez cały przebieg modyfikujący sklep, także podprocesy
        // uruchamiane synchronicznie przez inicjalizator. Tryb planowania pomija tę blokadę.
        if (!$dryRun) {
            $folder = _PS_ROOT_DIR_ . '/var/import';
            if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new RuntimeException('Nie można utworzyć katalogu roboczego inicjalizacji.');
            }
            $lock = fopen($folder . '/init-shop.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Inny proces inicjalizacji sklepu już działa.');
            }
        }
        echo "[INIT] Kontrola kompletu plików źródłowych przed importem...\n";
        // Walidacja całego katalogu poprzedza pierwszy etap zapisu. Jej odciski są
        // punktem odniesienia dla kontroli zmiany źródeł i dziennika wznowienia.
        $summary = ShopInit\initializationPreflight($context);
        echo '[INIT] Źródło zawiera ' . $summary['products'] . ' produktów, ' . $summary['categories']
            . ' kategorii i ' . $summary['images'] . ' zdjęć. Planowany brak stanu dla '
            . $summary['unavailable_products'] . " produktów.\n";
        if ($summary['without_images'] !== []) {
            echo '[INIT] UWAGA. Brak galerii źródłowej produktów ' . implode(', ', $summary['without_images'])
                . ". Wymagają potwierdzenia wyjątku przy odbiorze.\n";
        }
        $stages = ShopInit\initializationStages();
        if ($dryRun) {
            foreach ($stages as $index => $stage) {
                echo '[PLAN ' . ($index + 1) . '/' . count($stages) . '] ' . $stage['name']
                    . ': ' . $stage['script'] . ' ' . implode(' ', $stage['args']) . PHP_EOL;
            }
            echo "OK. Weryfikacja i plan zakończone.\n";
            return 0;
        }
        // Wznowienie rozpoznaje ukończone rekordy i ich własność. Pełna ścieżka poniżej
        // świadomie ponawia konfiguratory i importery, w tym początkowe ilości magazynowe.
        if ($options === ['--resume']) {
            ShopInit\resumeInitialization($context, $summary);
            echo '[INIT] Wznowienie zakończone. Zweryfikowano ' . $summary['products']
                . ' produktów. Zachowano istniejące dane i stany magazynowe.' . PHP_EOL;
            return 0;
        }
        ShopInit\runInitializationStages($stages, 'ShopInit\\runInitializationScript',
            fn () => ShopInit\unchangedInitializationSources($summary['source_hashes']));
        echo PHP_EOL . '[INIT] ZAKOŃCZONO' . ($summary['without_images'] !== [] ? ' Z UWAGAMI DO ŹRÓDŁA' : '')
            . '. Aktywne produkty ' . $summary['products'] . ', zdjęcia ' . $summary['images']
            . ', produkty bez stanu ' . $summary['unavailable_products'] . ', czas '
            . number_format(microtime(true) - $started, 1) . " s.\n";
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, '[INIT] BŁĄD. ' . $error->getMessage()
            . ' Ukończone etapy mogły zapisać dane. Popraw przyczynę i powtórz komendę.' . PHP_EOL);
        return 1;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

exit(initializeShopMain($argv));
