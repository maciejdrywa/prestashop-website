<?php
declare(strict_types=1);

/**
 * Wznowienie inicjalizacji i trwały dziennik powiązań źródła ze sklepem.
 *
 * Wywoływana przez init-shop.php --resume po preflight i uzyskaniu init-shop.lock.
 *
 * Dziennik w var/import wiąże instalację z odciskami wejścia i checkpointami produktów. Kontrole odczytują
 * bazę i pliki, a brakujące własne etapy mogą wykonywać zapisy.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/initialization.php';
require_once __DIR__ . '/cookies.php';
require_once __DIR__ . '/theme.php';

/**
 * Odczytuje dziennik zgodny z instalacją i odciskami wejścia.
 *
 * Ścisłe porównanie identity i sources odrzuca dziennik obcej instalacji lub innego zestawu danych. Brak
 * pliku daje stan początkowy. Sprawdzany jest format głównych pól, a kontrola rzeczywistych rekordów
 * następuje podczas wznowienia.
 *
 * @param string $path Ścieżka istniejącego lub przyszłego dziennika inicjalizacji.
 * @param string $identity Odcisk identyfikujący instalację, do której należy dziennik.
 * @param array $fingerprint Mapa ścieżek źródłowych na SHA-256, uporządkowana tak jak zapis w dzienniku.
 * @return array{version: int, identity: string, sources: array, stages: array, records: array}
 * @throws \RuntimeException Przy obcej instalacji, zmienionych źródłach albo niezgodnym formacie głównych pól dziennika.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function readInitializationState(string $path, string $identity, array $fingerprint): array
{
    $state = is_file($path)
        ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
        : ['version' => 1, 'identity' => $identity, 'sources' => $fingerprint, 'stages' => [], 'records' => []];
    if (!is_array($state) || ($state['version'] ?? null) !== 1 || ($state['identity'] ?? null) !== $identity
        || ($state['sources'] ?? null) !== $fingerprint
        || !is_array($state['stages'] ?? null) || !is_array($state['records'] ?? null)) {
        throw new RuntimeException('Dziennik dotyczy innej instalacji lub zmienionych danych. '
            . 'Wykonaj kopię zapasową, zastosuj wybrane importy ręcznie, a następnie zarchiwizuj initialization-state.json.');
    }
    return $state;
}

/**
 * Zapisuje dziennik przez podmianę pliku w tym samym systemie plików.
 *
 * Plik tymczasowy powstaje obok dziennika, aby rename wykonał pojedynczą podmianę. Wywołujący musi zapewnić
 * katalog i blokadę inicjalizacji. Dziennik potwierdza wykonane zapisy. Transakcje bazy i podmiany plików
 * mają odrębne granice zatwierdzania.
 *
 * @param string $path Docelowa ścieżka dziennika w istniejącym katalogu roboczym.
 * @param array $state Dziennik instalacji z odciskami źródeł, etapami i powiązaniami produktów.
 * @return void
 * @throws \RuntimeException Przy błędzie utworzenia pliku tymczasowego lub atomowego zapisu dziennika.
 * @throws \JsonException Przy niepoprawnym JSON lub błędzie kodowania danych planu.
 */
function saveInitializationState(string $path, array $state): void
{
    $temporary = tempnam(dirname($path), '.initialization-');
    if ($temporary === false) {
        throw new RuntimeException('Nie można utworzyć pliku dziennika.');
    }
    try {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $path)) {
            throw new RuntimeException('Nie można atomowo zapisać dziennika inicjalizacji.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * Rozpoznaje ustawienia istniejącej instalacji bez przywracania wartości początkowych.
 *
 * Jest to kontrola wybranych warunków gotowości etapu. Zapisany etap z utraconym wymaganym powiązaniem
 * powoduje zatrzymanie wznowienia w funkcji
 * nadrzędnej.
 *
 * @param string $script Nazwa obsługiwanego skryptu etapu z initializationStages.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return bool Wynik kontroli wybranych ustawień, rekordów i plików danego etapu.
 * @throws \RuntimeException Dla nazwy skryptu nieobsługiwanej przez kontrolę konfiguracji.
 */
function configurationPresent(string $script, \Context $context): bool
{
    // Wznowienie rozpoznaje wymagane powiązania i zasoby, zachowując dalsze edycje.
    // Poszczególne gałęzie określają faktyczny zakres kontroli danego konfiguratora.
    switch ($script) {
        case 'configure-storefront.php':
            return \Configuration::get('PS_SHOP_NAME') !== 'PrestaShop'
                && trim((string) \Configuration::get('PS_SHOP_NAME')) !== ''
                && !\Module::isEnabled('ps_imageslider') && !\Module::isEnabled('ps_banner');
        case 'configure-theme.php':
            return projectThemeActive($context);
        case 'configure-mail.php':
            if ((int) \Configuration::get('PS_MAIL_METHOD') !== 2
                || (string) \Configuration::get('PS_MAIL_SERVER') === '') {
                return false;
            }
            foreach (['account', 'order_conf', 'bankwire', 'payment'] as $template) {
                foreach (['html', 'txt'] as $extension) {
                    $file = _PS_MAIL_DIR_ . 'pl/' . $template . '.' . $extension;
                    if (!is_readable($file) || filesize($file) === 0) {
                        return false;
                    }
                }
            }
            return true;
        case 'configure-carriers.php':
            foreach (['STANDARD', 'EXPRESS'] as $key) {
                $reference = (int) \Configuration::get('SHOP_INIT_CARRIER_' . $key . '_REF');
                if (!$reference || !\Carrier::getCarrierByReference($reference)) {
                    return false;
                }
            }
            return true;
        case 'configure-payments.php':
            return \Module::isInstalled('ps_wirepayment') && \Module::isInstalled('ps_cashondelivery')
                && trim((string) \Configuration::get('BANK_WIRE_DETAILS')) !== '';
        case 'configure-orders.php':
            $state = new \OrderState((int) \Configuration::get('PS_OS_PAYMENT'));
            return \Validate::isLoadedObject($state) && (bool) $state->invoice
                && trim((string) \Configuration::get('PS_SHOP_ADDR1')) !== ''
                && trim((string) \Configuration::get('PS_SHOP_CITY')) !== '';
        case 'configure-menu.php':
            $items = explode(',', (string) \Configuration::get('MOD_BLOCKTOPMENU_ITEMS'));
            if (count($items) < 6) {
                return false;
            }
            foreach ($items as $item) {
                if (!preg_match('/^CAT([0-9]+)$/', $item, $match)
                    || !\Validate::isLoadedObject(new \Category((int) $match[1]))) {
                    return false;
                }
            }
            return true;
        case 'configure-content.php':
            foreach (array_keys(informationalPageDefinitions()) as $key) {
                $id = ownedContentId('SHOP_INIT_CMS_' . strtoupper($key), 'cms', 'id_cms', $context);
                if ($id === null) {
                    return false;
                }
            }
            return true;
        case 'configure-cookies.php':
            return \Module::isInstalled('shopcookieinfo')
                && \Validate::isLoadedObject(new \CMS((int) \Configuration::get('SHOP_COOKIE_INFO_CMS_ID')));
        case 'configure-module-texts.php':
            foreach (['SECURITY', 'DELIVERY', 'TERMS'] as $key) {
                $id = (int) \Configuration::get('SHOP_INIT_REASSURANCE_' . $key);
                if (!$id || !\Db::getInstance()->getValue('SELECT id_psreassurance FROM '
                    . _DB_PREFIX_ . 'psreassurance WHERE id_psreassurance=' . $id, false)) {
                    return false;
                }
            }
            return true;
         case 'configure-webservice.php':
            $enabled = (int) \Configuration::get('PS_WEBSERVICE') === 1;
            if (!$enabled) {
                return false;
            }
            $key = getenv('PRESTASHOP_API_KEY') ?: '';
            if ($key !== '') {
                return (bool) \Db::getInstance()->getValue(
                    'SELECT 1 FROM ' . _DB_PREFIX_ . 'webservice_account WHERE `key` = \'' . pSQL($key) . '\' AND active = 1'
                );
            }
            return (bool) \Db::getInstance()->getValue(
                'SELECT 1 FROM ' . _DB_PREFIX_ . 'webservice_account WHERE active = 1'
            );
    }
    throw new RuntimeException('Nieznany etap konfiguracji ' . $script);
}

/**
 * Sprawdza tożsamość produktu, jego powiązania i kompletność wariantów bez porównywania edytowalnych cen.
 *
 * Sprawdza istnienie, referencję, zapisany stan i przypisanie kategorii. Dodatkowa kontrola wariantów
 * obejmuje ich liczbę, referencje, obecność atrybutów i zerowy wpływ na masę, zachowując edytowane ceny i
 * treści.
 *
 * @param array $source Pojedynczy rekord źródłowy produktu.
 * @param int $id ID sprawdzanego rekordu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $withCombinations Włącza kontrolę referencji wariantów, ich atrybutów i wpływu na masę.
 * @return void
 * @throws \RuntimeException Przy brakującym produkcie, kategorii, wariancie lub wartościach jego atrybutów.
 */
function verifyResumedProduct(array $source, int $id, \Context $context, bool $withCombinations): void
{
    $product = new \Product($id, false, (int) $context->language->id, (int) $context->shop->id);
    if (!\Validate::isLoadedObject($product) || $product->reference !== 'pandomu-' . $source['product_id']
        || (int) $product->state !== \Product::STATE_SAVED
        || trim((string) $product->name) === '' || !\Product::getProductCategories($id)
        || !\Validate::isLoadedObject(new \Category((int) $product->id_category_default))) {
        throw new RuntimeException('Niekompletny istniejący produkt ' . $source['product_id'] . '. Użyj świadomie import-products.php ID.');
    }
    if ($withCombinations) {
        // Plan stanów sprawdza referencje i liczbę wariantów, nie zapisuje ilości.
        stockPlans([$source], [(string) $source['product_id'] => 0], $context);
        verifyUniformCombinationWeights($id, $context);
        // Spójność samych referencji nie gwarantuje działającego wyboru wariantu.
        // NOT EXISTS wykrywa kombinacje pozbawione przypisanych wartości atrybutów.
        $missing = \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product_attribute pa'
            . ' WHERE pa.id_product=' . $id . ' AND NOT EXISTS (SELECT 1 FROM '
            . _DB_PREFIX_ . 'product_attribute_combination pac WHERE pac.id_product_attribute=pa.id_product_attribute)', false);
        if ($missing === false || (int) $missing !== 0) {
            throw new RuntimeException('Brak wartości atrybutów kombinacji produktu ' . $source['product_id']);
        }
    }
}

/**
 * Sprawdza pliki galerii, zachowując edytowane podpisy, kolejność i okładkę.
 *
 * Wymaga jednej okładki, powiązania obrazów ze sklepem oraz aktualnych hashy plików. Metadane edytowalne,
 * takie jak podpis i kolejność, pozostają poza porównaniem ze źródłowym planem.
 *
 * @param array $plans Plany galerii powiązanych z istniejącymi produktami.
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy niejednoznacznej okładce, błędnym powiązaniu lub nieaktualnych artefaktach galerii.
 */
function verifyResumedImages(array $plans, array $types, \Context $context): void
{
    $signature = thumbnailSignature($types);
    foreach ($plans as $plan) {
        if ($plan['images'] !== []) {
            $covers = \Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'image_shop s'
                . ' INNER JOIN ' . _DB_PREFIX_ . 'image i ON i.id_image=s.id_image WHERE i.id_product='
                . $plan['product_id'] . ' AND s.id_shop=' . (int) $context->shop->id . ' AND s.cover=1', false);
            if ((int) $covers !== 1) {
                throw new RuntimeException('Galeria wymaga jednej okładki produktu ' . $plan['source_id']);
            }
        }
        foreach ($plan['images'] as $entry) {
            $mapping = imageMapping($plan['source_id'], $entry['url'], $context);
            $image = new \Image((int) ($mapping['id_image'] ?? 0), null, (int) $context->shop->id);
            if (!\Validate::isLoadedObject($image) || (int) $image->id_product !== $plan['product_id']
                || !$image->isAssociatedToShop((int) $context->shop->id)
                || !artifactsCurrent($mapping, imageArtifacts($image, $types), $entry['source']['hash'], $signature)) {
                throw new RuntimeException('Niekompletna galeria produktu ' . $plan['source_id']
                    . '. Sprawdź pliki i uruchom import-images.php ID.');
            }
        }
    }
}

/**
 * Kontroluje sumę wariantów i obecność magazynu, akceptując ilości zmienione przez sprzedaż.
 *
 * Sprawdza komplet wierszy produktu i wariantów oraz sumę ilości. Akceptuje bieżące ilości po sprzedaży lub
 * ręcznej edycji. Zakres 0–10 i plan początkowy weryfikuje osobna ścieżka początkowego importu.
 *
 * @param int $id ID sprawdzanego rekordu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy braku wierszy wariantów, błędzie odczytu lub niespójnej sumie.
 */
function verifyResumedStock(int $id, \Context $context): void
{
    $rows = stockRows($id, $context);
    if (!isset($rows[0])) {
        throw new RuntimeException('Brak głównego stanu magazynowego produktu ' . $id);
    }
    $combinations = \Db::getInstance()->executeS('SELECT pa.id_product_attribute FROM ' . _DB_PREFIX_
        . 'product_attribute pa INNER JOIN ' . _DB_PREFIX_ . 'product_attribute_shop s'
        . ' ON s.id_product_attribute=pa.id_product_attribute WHERE pa.id_product=' . $id
        . ' AND s.id_shop=' . (int) $context->shop->id, true, false);
    if ($combinations === false) {
        throw new RuntimeException('Nie można odczytać wariantów produktu ' . $id);
    }
    // Zestaw wierszy magazynu musi obejmować sumę produktu i każdy zapisany wariant.
    // Dalsze porównanie dotyczy sumy aktualnych ilości, także po sprzedaży.
    $expected = [0, ...array_map('intval', array_column($combinations, 'id_product_attribute'))];
    $actual = array_keys($rows);
    sort($expected);
    sort($actual);
    if ($expected !== $actual) {
        throw new RuntimeException('Brak lub nadmiar stanów wariantów produktu ' . $id);
    }
    $total = (int) $rows[0]['quantity'];
    unset($rows[0]);
    if ($rows !== [] && array_sum(array_column($rows, 'quantity')) !== $total) {
        throw new RuntimeException('Niespójna suma stanów wariantów produktu ' . $id);
    }
}

/**
 * Wykonuje lub kontroluje jeden etap produktu. Modyfikuje wyłącznie rekordy należące do inicjalizacji.
 *
 * Flaga owned oznacza produkt nieobecny przy pierwszym rozpoznaniu, a pending dodatkowo brak checkpointu
 * skryptu. Zapisy domenowe są dopuszczane dla pending. Dla magazynu aktywny produkt jest traktowany jako
 * udostępniony i jego ilości pozostają zachowane.
 *
 * @param array $source Pojedynczy rekord źródłowy produktu.
 * @param array $record Rekord dziennika przekazywany przez referencję, z polami owned, id i done.
 * @param string $script Nazwa obsługiwanego skryptu etapu z initializationStages.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param array $stock Deterministyczny plan ilości początkowych dla całego katalogu.
 * @param array $types Definicje miniatur produktów zawierające name, width i height.
 * @return void
 * @throws \RuntimeException Przy konflikcie tożsamości produktu lub błędzie importu i kontroli rekordu danego etapu.
 */
function resumeCatalogRecord(array $source, array &$record, string $script, \Context $context, array $stock, array $types): void
{
    $key = (string) $source['product_id'];
    $id = productId('pandomu-' . $key, $context);
    if ($record['id'] !== null && $id !== $record['id']) {
        throw new RuntimeException('Zmieniona tożsamość produktu ' . $key . '. Sprawdź referencje w bazie.');
    }
    // Własność zapisujemy przy pierwszym rozpoznaniu katalogu. Brak checkpointu
    // dopuszcza ponowienie własnego etapu po awarii, a zastane rekordy przechodzą kontrolę.
    $pending = $record['owned'] && !in_array($script, $record['done'], true);
    if ($script === 'import-products.php') {
        if ($pending) {
            $categories = importCategories(categoryPlan([$source], false), $context);
            transaction(fn () => importProducts(productPlans([$source], $context), $categories, $context));
            $id = productId('pandomu-' . $key, $context);
        }
        if ($id === null) {
            throw new RuntimeException('Brak oczekiwanego produktu ' . $key);
        }
        $record['id'] = $id;
        verifyResumedProduct($source, $id, $context, false);
    } elseif ($script === 'import-combinations.php') {
        if ($pending && $source['variants'] !== []) {
            transaction(fn () => importCombinations(combinationPlan([$source], $context), $context));
        }
        verifyResumedProduct($source, $id, $context, true);
    } elseif ($script === 'import-images.php' || $script === 'check-images.php') {
        $plans = imagePlans([$source], $context);
        if ($pending && $script === 'import-images.php') {
            ensureImageMap();
            importProductImages($plans, $types, $context);
        }
        verifyResumedImages($plans, $types, $context);
    } elseif ($script === 'import-stock.php') {
        // Aktywacja następuje po zapisie ilości. Aktywny produkt mógł już zostać kupiony.
        $saved = new \Product($id, false, null, (int) $context->shop->id);
        if ($pending && !$saved->active) {
            transaction(fn () => importStock(stockPlans([$source], $stock, $context), $context));
        }
        verifyResumedStock($id, $context);
    } elseif ($script === 'check-stock.php') {
        verifyResumedStock($id, $context);
    } else {
        verifyResumedProduct($source, $id, $context, true);
    }
}

/**
 * Wznawia konfigurację i import z kontrolą rzeczywistego stanu instalacji.
 *
 * Zakłada posiadanie blokady init-shop.lock przez inicjalizator. Rejestruje własność przed tworzeniem
 * rekordów i zapisuje checkpoint po kontroli każdej operacji. Sprawdza odciski JSON między etapami i
 * komplet odcisków na końcu, a pliki modułu cookies synchronizuje także przy gotowej konfiguracji.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param array $summary Liczności oraz odciski źródła z initializationPreflight.
 * @return void
 * @throws \RuntimeException Przy konflikcie dziennika i źródeł, utracie zasobów etapu lub nieudanym zapisie i kontroli katalogu.
 */
function resumeInitialization(\Context $context, array $summary): void
{
    // Podprocesy konfiguracji zapisują bazę poza cache bieżącego procesu CLI.
    \Db::getInstance()->disableCache();
    $path = _PS_ROOT_DIR_ . '/var/import/initialization-state.json';
    $products = readCatalog();
    // Powiązanie bazy, sklepu i sekretu instalacji odróżnia dziennik innego sklepu.
    // Odciski JSON i JPEG wiążą checkpointy z konkretnym zestawem źródłowym.
    $identity = hash('sha256', _DB_NAME_ . '|' . _DB_PREFIX_ . '|' . $context->shop->id . '|'
        . \Configuration::get('PS_SHOP_CREATION_DATE') . '|' . _COOKIE_KEY_);
    $fingerprint = $summary['source_hashes'] + $summary['image_hashes'];
    ksort($fingerprint);
    $state = readInitializationState($path, $identity, $fingerprint);
    // Własność zapamiętujemy przed pierwszym zapisem produktu. Pozwala to rozpoznać przerwany import.
    foreach ($products as $source) {
        $key = (string) $source['product_id'];
        if (!isset($state['records'][$key])) {
            $id = productId('pandomu-' . $key, $context);
            $state['records'][$key] = ['owned' => $id === null, 'id' => $id, 'done' => []];
        }
    }
    saveInitializationState($path, $state);
    $stock = catalogStockPlan($products);
    $types = productImageTypes();
    $stages = initializationStages();
    foreach ($stages as $index => $stage) {
        unchangedInitializationSources($summary['source_hashes']);
        $script = $stage['script'];
        echo PHP_EOL . '[WZNOWIENIE ' . ($index + 1) . '/' . count($stages) . '] ' . $stage['name'] . PHP_EOL;
        // Konfiguratory mają checkpointy etapów i kontrolę zasobów. Import katalogu
        // ma dodatkowo checkpointy per produkt, ograniczające zakres ponowienia po awarii.
        if (str_starts_with($script, 'configure-')) {
            if ($script === 'configure-cookies.php' && installCookieModuleFiles()) {
                \Media::clearCache();
                \Tools::clearSmartyCache();
            }
            // Nowy obraz może zmienić pliki motywu także po zakończeniu etapu.
            $themeDeployment = $script === 'configure-theme.php';
            if ($themeDeployment || !configurationPresent($script, $context)) {
                if (!$themeDeployment && isset($state['stages'][$script])) {
                    throw new RuntimeException('Brakuje zapisanych powiązań etapu ' . $script . '. Sprawdź konfigurację ręcznie.');
                }
                if (runInitializationScript($stage) !== 0) {
                    throw new RuntimeException('Nieudany etap ' . $script);
                }
                // Zapis w podprocesie nie unieważnia pamięci bieżącego procesu PHP.
                // Przed kontrolą wyniku czyścimy lokalny cache konfiguracji i modułów.
                \Configuration::resetStaticCache();
                \Module::resetStaticCache();
                \Cache::clean('Module::*');
                if (!configurationPresent($script, $context)) {
                    throw new RuntimeException('Niepotwierdzony wynik etapu ' . $script);
                }
            }
        } elseif ($script === 'import-attributes.php') {
            // Słownik przygotowujemy dla własnych produktów z niedokończonym importem
            // kombinacji. Istniejący kompletny katalog przechodzi kontrolę wariantów bez dopisywania słownika.
            $pendingProducts = array_filter($products, fn (array $source): bool =>
                $state['records'][(string) $source['product_id']]['owned']
                && !in_array('import-combinations.php', $state['records'][(string) $source['product_id']]['done'], true));
            importAttributes(attributePlan($pendingProducts), $context);
        } else {
            foreach ($products as $offset => $source) {
                $key = (string) $source['product_id'];
                $record = &$state['records'][$key];
                resumeCatalogRecord($source, $record, $script, $context, $stock, $types);
                // Checkpoint jest zapisywany dopiero po wykonaniu i kontroli rekordu.
                // Po przerwaniu przed podmianą dziennika etap ponownie rozpoznaje zapis przez referencje.
                if (!in_array($script, $record['done'], true)) {
                    $record['done'][] = $script;
                    saveInitializationState($path, $state);
                }
                unset($record);
                if (($offset + 1) % 50 === 0 || $offset + 1 === count($products)) {
                    echo '[KATALOG] ' . ($offset + 1) . '/' . count($products) . PHP_EOL;
                }
            }
        }
        unchangedInitializationSources($summary['source_hashes']);
        $state['stages'][$script] = gmdate(DATE_ATOM);
        saveInitializationState($path, $state);
    }
    unchangedInitializationSources($fingerprint);
}
