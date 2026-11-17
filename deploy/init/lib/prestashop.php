<?php

declare(strict_types=1);

/**
 * Warstwa integracji planów źródłowych z natywnymi klasami PrestaShop.
 *
 * Dołączana przez biblioteki domenowe. Operacje na sklepie wymagają wcześniejszego boot() i konfiguracji
 * istniejącej instalacji.
 *
 * Udostępnia kontekst, transakcje, zapis Configuration, kategorii, atrybutów i kombinacji. boot() może
 * utworzyć cache purifier.
 */


namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/source.php';

/**
 * Ładuje kontekst CLI PrestaShop, sprawdza bazę i przygotowuje cache HTMLPurifier.
 *
 * Ładuje autoloader, stałe i konfigurację natywnego sklepu. Ustawia globalny kontekst jednego sklepu i
 * sprawdza SELECT 1. Przygotowanie katalogu purifier jest skutkiem ubocznym również w komendach
 * diagnostycznych.
 *
 * @param string $root Katalog z zainstalowanym PrestaShop i config/config.inc.php.
 * @return \Context Bieżący kontekst z ustawionym zakresem jednego sklepu.
 * @throws \RuntimeException Przy niepoprawnym trybie wykonania, brakującej instalacji, kontekście, bazie lub katalogu cache.
 */
function boot(string $root = '/var/www/html'): \Context
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Initialization scripts must run through PHP CLI.');
    }
    $config = $root . '/config/config.inc.php';
    if (!is_readable($config)) {
        throw new RuntimeException("PrestaShop is not installed or its configuration is unreadable: {$config}");
    }
    require_once $config;
    $context = \Context::getContext();
    if (!\Validate::isLoadedObject($context->shop)) {
        throw new RuntimeException('Cannot determine the current shop.');
    }
    // Jawny zakres sklepu jest wykorzystywany przez natywne modele, Configuration
    // i ograniczenia SQL w tabelach *_shop podczas całego procesu CLI.
    \Shop::setContext(\Shop::CONTEXT_SHOP, (int) $context->shop->id);
    if ((string) \Db::getInstance()->getValue('SELECT 1') !== '1') {
        throw new RuntimeException('Database connection failed.');
    }
    // Instalator może pozostawić pusty katalog cache. HTMLPurifier wymaga własnego podkatalogu.
    $purifier = _PS_CACHE_DIR_ . 'purifier';
    if (!is_dir($purifier) && !mkdir($purifier, 0775, true) && !is_dir($purifier)) {
        throw new RuntimeException('Nie można przygotować cache HTMLPurifier. Sprawdź właściciela var/cache.');
    }
    return $context;
}

/**
 * Zapisuje ustawienie dla bieżącego sklepu i grupy, zgłaszając nieudany zapis.
 *
 * Zakres zapisu obejmuje bieżący sklep i jego grupę. Dla ustawień wielojęzycznych wartość jest mapą id_lang
 * na tekst. Błąd updateValue przerywa konfigurację.
 *
 * @param string $key Klucz Configuration zapisywany w zakresie sklepu i grupy.
 * @param mixed $value Wartość ustawienia lub mapa id_lang na wartości wielojęzyczne.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $html Zezwala natywnemu mechanizmowi Configuration na zapis zawartości HTML.
 * @return void
 * @throws \RuntimeException Przy nieudanym zapisie Configuration w zakresie sklepu.
 */
function saveShopSetting(string $key, $value, \Context $context, bool $html = false): void
{
    if (!\Configuration::updateValue($key, $value, $html, (int) $context->shop->id_shop_group, (int) $context->shop->id)) {
        throw new RuntimeException('Nie można zapisać ustawienia sklepu: ' . $key);
    }
}

/**
 * Wymaga PLN, Polski i podatków oraz izoluje obliczenia od bieżącego koszyka klienta.
 *
 * Zastępuje kontekst waluty, koszyka i klienta w bieżącym procesie. Dzięki temu sprawdzenie ceny źródłowej
 * korzysta z PLN i domyślnego kraju bez wpływu kontekstu aktualnego klienta lub koszyka.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Gdy domyślna waluta, kraj lub ustawienia podatków nie odpowiadają PLN i Polsce.
 */
function priceContext(\Context $context): void
{
    $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
    $poland = (int) \Country::getByIso('PL');
    if (!\Validate::isLoadedObject($context->currency) || $context->currency->iso_code !== 'PLN'
        || !$poland || (int) \Configuration::get('PS_COUNTRY_DEFAULT') !== $poland
        || !\Configuration::get('PS_TAX')) {
        throw new RuntimeException('Expected PLN, Poland as default country, and enabled taxes.');
    }
    // Kalkulator ma działać w kontrolowanym kontekście. Podstawienie pustych
    // obiektów usuwa zależność od ewentualnego klienta i koszyka procesu.
    $context->cart = new \Cart();
    $context->customer = new \Customer();
}

/**
 * Zwraca pojedynczy identyfikator lub null. Błąd odczytu i duplikaty przerywają wyszukiwanie.
 *
 * Odróżnia poprawny brak rekordu od błędu zapytania i wieloznacznego dopasowania. Wywołujący decyduje, czy
 * null oznacza utworzenie obiektu, czy błąd brakującej zależności.
 *
 * @param \DbQuery $query Zapytanie SELECT zwracające identyfikator pod aliasem id.
 * @return int|null Identyfikator z kolumny id albo null przy pustym wyniku.
 * @throws \RuntimeException Przy błędzie zapytania lub więcej niż jednym dopasowanym rekordzie.
 */
function uniqueId(\DbQuery $query): ?int
{
    $rows = \Db::getInstance()->executeS($query);
    if ($rows === false || count($rows) > 1) {
        throw new RuntimeException('Lookup failed or duplicate records exist.');
    }
    return $rows === [] ? null : (int) $rows[0]['id'];
}

/**
 * Wykonuje operację w transakcji. Przy błędzie żąda rollbacku i zgłasza możliwe skutki hooków.
 *
 * Callback działa na wspólnym połączeniu Db. Wyjątek powoduje próbę ROLLBACK i jest opakowany w
 * RuntimeException z zachowaną przyczyną. Hook wykonujący TRUNCATE lub inne operacje z implicit commit
 * ogranicza możliwość cofnięcia całej operacji.
 *
 * @param callable $callback Operacja objęta transakcją, której wynik zostanie zwrócony bez przekształcenia.
 * @return mixed Wynik przekazanego callbacku po zatwierdzeniu transakcji.
 * @throws \RuntimeException Przy nieudanej transakcji lub wyjątku callbacku, z zachowaną przyczyną.
 */
function transaction(callable $callback)
{
    $db = \Db::getInstance();
    // Transakcja obejmuje callback na tym połączeniu. Kod nie tworzy savepointów,
    // więc wywołujący odpowiada za poprawne granice transakcji i sekwencję operacji.
    if (!$db->execute('START TRANSACTION')) {
        throw new RuntimeException('Cannot start initialization transaction.');
    }
    try {
        $result = $callback();
        if (!$db->execute('COMMIT')) {
            throw new RuntimeException('Cannot commit initialization transaction.');
        }
        return $result;
    } catch (\Throwable $error) {
        $db->execute('ROLLBACK');
        // Hooki modułów mogą wykonać TRUNCATE, zatwierdzające transakcję MySQL.
        throw new RuntimeException($error->getMessage() . ' Rollback requested; module hooks may have committed earlier writes. Correct the error and rerun.', 0, $error);
    }
}

/**
 * Rozwiązuje drzewo kategorii po rodzicach i nazwach, opcjonalnie tworząc brakujące węzły.
 *
 * Wyszukuje węzeł przez rodzica, nazwę w języku domyślnym i powiązanie ze sklepem. Tworzone kategorie
 * otrzymują tłumaczenia dla wszystkich języków. Istniejące dopasowane węzły są wykorzystywane do budowania
 * mapy ID.
 *
 * @param array $plan Węzły z categoryPlan, uporządkowane od rodziców do dzieci.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @param bool $create Pozwala utworzyć brakujące kategorie. Przy false brak węzła kończy się błędem.
 * @return array<string, int> Mapa pełnych ścieżek JSON na ID kategorii.
 * @throws \RuntimeException Przy niepoprawnej nazwie, brakującym rodzicu, wieloznacznym dopasowaniu lub błędzie zapisu.
 */
function importCategories(array $plan, \Context $context, bool $create = true): array
{
    // Wszystkie nazwy sprawdzamy przed pierwszą operacją zapisu.
    foreach ($plan as $entry) {
        if (!\Validate::isCatalogName($entry['name']) || mb_strlen($entry['name']) > 128
            || !\Validate::isLinkRewrite(\Tools::link_rewrite($entry['name']))) {
            throw new RuntimeException('Invalid PrestaShop category name: ' . $entry['name']);
        }
    }
    $shopId = (int) $context->shop->id;
    $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
    $home = (int) $context->shop->id_category;
    if (!\Validate::isLoadedObject(new \Category($home, $languageId, $shopId))) {
        throw new RuntimeException('Shop home category does not exist.');
    }
    $ids = [];
    foreach ($plan as $key => $entry) {
        $parentId = $entry['parent'] === null ? $home : ($ids[$entry['parent']] ?? null);
        if ($parentId === null) {
            throw new RuntimeException('Category parent was not resolved.');
        }
        $query = new \DbQuery();
        $query->select('c.id_category AS id')->from('category', 'c');
        $query->innerJoin('category_lang', 'cl', 'cl.id_category = c.id_category');
        $query->innerJoin('category_shop', 'cs', 'cs.id_category = c.id_category');
        // Identyfikacja po rodzicu, języku i sklepie rozróżnia kategorie o tej samej
        // nazwie występujące w różnych gałęziach. uniqueId odrzuca wieloznaczne dopasowanie.
        $query->where('c.id_parent = ' . $parentId);
        $query->where('cl.id_lang = ' . $languageId);
        $query->where('cl.id_shop = ' . $shopId)->where('cs.id_shop = ' . $shopId);
        $query->where("cl.name = '" . \pSQL($entry['name']) . "'");
        $id = uniqueId($query);
        $status = 'EXISTS';
        if ($id === null) {
            if (!$create) {
                throw new RuntimeException('Missing category: ' . implode(' > ', $entry['path']) . '; run import-category.php or import-products.php first.');
            }
            $category = new \Category();
            $category->id_parent = $parentId;
            $category->id_shop_default = $shopId;
            $category->active = true;
            $category->is_root_category = false;
            foreach (\Language::getLanguages(false) as $language) {
                $languageIdForValue = (int) $language['id_lang'];
                $category->name[$languageIdForValue] = $entry['name'];
                $category->link_rewrite[$languageIdForValue] = \Tools::link_rewrite($entry['name']);
            }
            if (!$category->add()) {
                throw new RuntimeException('Cannot create category: ' . $entry['name']);
            }
            $id = (int) $category->id;
            $status = 'CREATED';
        }
        $ids[$key] = $id;
        echo $status . ': ' . implode(' > ', $entry['path']) . ", ID: {$id}" . PHP_EOL;
    }
    return $ids;
}

/**
 * Wyszukuje grupę atrybutów po nazwie w bieżącym sklepie.
 *
 * @param string $name Nazwa wyszukiwanego obiektu w języku domyślnym sklepu.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int|null ID grupy albo null przy braku dopasowania.
 * @throws \RuntimeException Przy wieloznacznym dopasowaniu lub błędzie odczytu identyfikatora.
 */
function findGroup(string $name, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('g.id_attribute_group AS id')->from('attribute_group', 'g');
    $query->innerJoin('attribute_group_lang', 'gl', 'gl.id_attribute_group = g.id_attribute_group');
    $query->innerJoin('attribute_group_shop', 'gs', 'gs.id_attribute_group = g.id_attribute_group');
    $query->where('gl.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where('gs.id_shop = ' . (int) $context->shop->id);
    $query->where("gl.name = '" . \pSQL($name) . "'");
    return uniqueId($query);
}

/**
 * Wyszukuje wartość we wskazanej grupie atrybutów sklepu.
 *
 * @param int $groupId ID grupy atrybutów w PrestaShop.
 * @param string $value Wartość przekazana do walidacji lub zapisu.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int|null ID wartości albo null przy braku dopasowania.
 * @throws \RuntimeException Przy wieloznacznym dopasowaniu lub błędzie odczytu identyfikatora.
 */
function findAttribute(int $groupId, string $value, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('a.id_attribute AS id')->from('attribute', 'a');
    $query->innerJoin('attribute_lang', 'al', 'al.id_attribute = a.id_attribute');
    $query->innerJoin('attribute_shop', 's', 's.id_attribute = a.id_attribute');
    $query->where('a.id_attribute_group = ' . $groupId);
    $query->where('al.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));
    $query->where('s.id_shop = ' . (int) $context->shop->id);
    $query->where("al.name = '" . \pSQL($value) . "'");
    return uniqueId($query);
}

/**
 * Tworzy brakujące grupy i wartości atrybutów, zachowując istniejące identyfikatory.
 *
 * Nazwy są sprawdzane przed zapisami. Grupy mają typ select, a wartości są dopisywane z kolejną pozycją.
 * Tworzenie grup i wartości wykorzystuje klasy PrestaShop, co zachowuje ich powiązania i hooki.
 *
 * @param array $groups Wynik attributePlan, nazwy grup z mapą unikalnych wartości.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array Mapa nazw grup i kluczy wartości z attributePlan na zapisane ID atrybutów.
 * @throws \RuntimeException Przy błędnych nazwach, konflikcie dopasowania lub nieudanym zapisie grupy i wartości.
 */
function importAttributes(array $groups, \Context $context): array
{
    foreach ($groups as $name => $values) {
        if (!\Validate::isGenericName($name) || mb_strlen($name) > 64) {
            throw new RuntimeException("Invalid attribute group name: {$name}");
        }
        foreach ($values as $value) {
            if (!\Validate::isGenericName($value) || mb_strlen($value) > 128) {
                throw new RuntimeException("Invalid attribute value: {$value}");
            }
        }
    }
    $result = [];
    foreach ($groups as $name => $values) {
        $groupId = findGroup($name, $context);
        if ($groupId === null) {
            $group = new \AttributeGroup();
            $group->group_type = 'select';
            $group->is_color_group = false;
            foreach (\Language::getLanguages(false) as $language) {
                $id = (int) $language['id_lang'];
                $group->name[$id] = $name;
                $group->public_name[$id] = $name;
            }
            if (!$group->add()) {
                throw new RuntimeException("Cannot create attribute group: {$name}");
            }
            $groupId = (int) $group->id;
            echo "CREATED GROUP: {$name}, ID: {$groupId}" . PHP_EOL;
        } else {
            echo "EXISTS GROUP: {$name}, ID: {$groupId}" . PHP_EOL;
        }
        foreach ($values as $key => $value) {
            $id = findAttribute($groupId, $value, $context);
            $status = 'EXISTS';
            if ($id === null) {
                $query = new \DbQuery();
                $query->select('MAX(position)')->from('attribute')->where('id_attribute_group = ' . $groupId);
                $attribute = new \ProductAttribute();
                $attribute->id_attribute_group = $groupId;
                $attribute->position = (int) \Db::getInstance()->getValue($query) + 1;
                foreach (\Language::getLanguages(false) as $language) {
                    $attribute->name[(int) $language['id_lang']] = $value;
                }
                if (!$attribute->add()) {
                    throw new RuntimeException("Cannot create attribute: {$value}");
                }
                $id = (int) $attribute->id;
                $status = 'CREATED';
            }
            $result[$name][$key] = $id;
            echo "{$status} VALUE: {$name} = {$value}, ID: {$id}" . PHP_EOL;
        }
    }
    return $result;
}

/**
 * Oblicza cenę brutto przez natywny mechanizm cen PrestaShop i zwraca zapis dziesiętny.
 *
 * Wywołuje natywny kalkulator z VAT, wyłączoną redukcją, redukcją grupową
 * i use_customer_price=false. Przed obliczeniem czyści cache cen. false wskazuje
 * cenę bazową, a konkretne ID uwzględnia wpływ wybranej kombinacji.
 *
 * @param int $productId Docelowe ID produktu w PrestaShop.
 * @param int|false $combinationId ID kombinacji lub false do obliczenia ceny bazowej bez wariantu.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return string Cena brutto w PLN zaokrąglona do dwóch miejsc.
 * @throws \RuntimeException Gdy natywny kalkulator zwróci null lub wynik nie przejdzie walidacji kwoty.
 */
function productGross(int $productId, $combinationId, \Context $context): string
{
    // Weryfikacja dotyczy świeżego zapisu. Cache ceny jest czyszczony przed
    // wywołaniem natywnego kalkulatora z jawnie wyłączonymi redukcjami.
    \Product::flushPriceCache();
    $gross = \Product::getPriceStatic(
        id_product: $productId, usetax: true, id_product_attribute: $combinationId,
        decimals: 2, usereduc: false, use_group_reduction: false,
        context: $context, use_customer_price: false
    );
    if ($gross === null) {
        throw new RuntimeException("Cannot calculate price for product {$productId}");
    }
    return rounded(decimal($gross, 'PrestaShop gross'), 2);
}

/**
 * Łączy zweryfikowane ceny źródłowe z produktami i wartościami atrybutów w sklepie.
 *
 * Przed zapisem wymaga istniejącego produktu z poprawnym netto i brutto bazowym oraz gotowego słownika
 * atrybutów. Posortowane attribute_ids pozwalają porównać zestawy wartości niezależnie od kolejności
 * źródła.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return list<array{product: \Product, source_id: string, prices: array}> Plan cen z rozwiązanymi attribute_ids wariantów.
 * @throws \RuntimeException Przy brakującym produkcie, niespójnej cenie bazowej lub nierozwiązanej wartości atrybutu.
 */
function combinationPlan(array $products, \Context $context): array
{
    priceContext($context);
    $result = [];
    $missing = [];
    foreach ($products as $source) {
        if ($source['variants'] === []) {
            continue;
        }
        $sourceId = (string) $source['product_id'];
        $query = new \DbQuery();
        $query->select('p.id_product AS id')->from('product', 'p');
        $query->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product');
        $query->where('ps.id_shop = ' . (int) $context->shop->id);
        $query->where("p.reference = 'pandomu-" . \pSQL($sourceId) . "'");
        $id = uniqueId($query);
        if ($id === null) {
            $missing[] = $sourceId;
            continue;
        }
        $plan = pricePlan($source);
        $product = new \Product($id, false, null, (int) $context->shop->id);
        if (rounded(decimal($product->price, 'saved product net'), 6) !== $plan['net']
            || productGross($id, false, $context) !== $plan['gross']) {
            throw new RuntimeException("Product {$sourceId}: import the normalized base price and correct VAT first.");
        }
        foreach ($plan['variants'] as &$variant) {
            $ids = [];
            foreach ($variant['attributes'] as $group => $value) {
                $groupId = findGroup($group, $context);
                $attributeId = $groupId === null ? null : findAttribute($groupId, $value, $context);
                if ($attributeId === null) {
                    throw new RuntimeException("Product {$sourceId}: import attributes first ({$group} = {$value}).");
                }
                $ids[] = $attributeId;
            }
            sort($ids, SORT_NUMERIC);
            $variant['attribute_ids'] = $ids;
        }
        unset($variant);
        $result[] = ['product' => $product, 'source_id' => $sourceId, 'prices' => $plan];
    }
    if ($missing !== []) {
        throw new RuntimeException('Product drafts are missing; run import-products.php first. Source IDs: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' ...' : ''));
    }
    return $result;
}

/**
 * Odczytuje warianty mające niezerowy wpływ na wagę produktu.
 *
 * @param int $productId Docelowe ID produktu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return list<array{id_product_attribute: string}> Warianty z niezerowym wpływem masy w tabeli bazowej lub sklepowej.
 * @throws \RuntimeException Przy nieudanym odczycie wpływu masy kombinacji.
 */
function nonzeroCombinationWeights(int $productId, \Context $context): array
{
    $rows = \Db::getInstance()->executeS('SELECT pa.id_product_attribute FROM ' . _DB_PREFIX_ . 'product_attribute pa INNER JOIN '
        . _DB_PREFIX_ . 'product_attribute_shop s ON s.id_product_attribute=pa.id_product_attribute WHERE pa.id_product=' . $productId
        . ' AND s.id_shop=' . (int) $context->shop->id . ' AND (pa.weight<>0 OR s.weight<>0)', true, false);
    if ($rows === false) throw new RuntimeException('Cannot read combination weight impacts.');
    return $rows;
}

/**
 * Odrzuca warianty zmieniające masę, ponieważ wszystkie warianty produktu mają ważyć jednakowo.
 *
 * @param int $productId Docelowe ID produktu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Gdy przynajmniej jeden wariant ma niezerowy wpływ na masę produktu.
 */
function verifyUniformCombinationWeights(int $productId, \Context $context): void
{
    if (nonzeroCombinationWeights($productId, $context) !== []) throw new RuntimeException('Combination weight must have zero impact for product ' . $productId);
}

/**
 * Zeruje wpływ wariantów na masę przez natywne obiekty Combination.
 *
 * @param int $productId Docelowe ID produktu w PrestaShop.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy błędzie odczytu lub aktualizacji masy kombinacji.
 */
function resetCombinationWeights(int $productId, \Context $context): void
{
    foreach (nonzeroCombinationWeights($productId, $context) as $row) {
        $combination = new \Combination((int) $row['id_product_attribute'], null, (int) $context->shop->id);
        $combination->weight = 0;
        if (!$combination->update()) throw new RuntimeException('Cannot reset combination weight: ' . $combination->id);
    }
}

/**
 * Zapisuje kombinacje i modyfikatory netto, wybiera domyślną kombinację oraz sprawdza brutto.
 *
 * Rozpoznaje wariant po referencji lub zestawie ID atrybutów i odrzuca sprzeczne dopasowania. Zapisuje
 * wpływ ceny netto oraz zerowy wpływ masy. Po ustawieniu wariantu domyślnego sprawdza każdą cenę brutto
 * przez PrestaShop. Transakcję zapewnia wywołujący.
 *
 * @param array $plans Plany combinationPlan zawierające obiekty Product i ID wartości atrybutów.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array<string, list<int>> Zapisane ID kombinacji pogrupowane według źródłowego ID produktu.
 * @throws \RuntimeException Przy konflikcie wariantów, błędzie zapisu albo rozbieżności docelowego brutto.
 */
function importCombinations(array $plans, \Context $context): array
{
    $result = [];
    foreach ($plans as $entry) {
        $product = $entry['product'];
        $query = new \DbQuery();
        $query->select('pa.id_product_attribute, pa.reference')->from('product_attribute', 'pa');
        $query->innerJoin('product_attribute_shop', 'pas', 'pas.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = ' . (int) $product->id)->where('pas.id_shop = ' . (int) $context->shop->id);
        $existing = \Db::getInstance()->executeS($query);
        if ($existing === false) {
            throw new RuntimeException('Cannot read existing combinations.');
        }
        // Dwa indeksy pozwalają rozpoznać wcześniejszy zapis po referencji lub zestawie
        // atrybutów. Ich konflikt oznacza niejednoznaczny stan do wyjaśnienia przed zapisem.
        $byReference = [];
        $byAttributes = [];
        foreach ($existing as $row) {
            $id = (int) $row['id_product_attribute'];
            $query = new \DbQuery();
            $query->select('id_attribute')->from('product_attribute_combination')->where('id_product_attribute = ' . $id)->orderBy('id_attribute');
            $attributes = \Db::getInstance()->executeS($query);
            if ($attributes === false) {
                throw new RuntimeException('Cannot read combination attributes.');
            }
            $signature = implode(',', array_map(fn (array $a): int => (int) $a['id_attribute'], $attributes));
            if (($row['reference'] !== '' && isset($byReference[$row['reference']])) || ($signature !== '' && isset($byAttributes[$signature]))) {
                throw new RuntimeException('Duplicate existing combination references or attribute sets.');
            }
            if ($row['reference'] !== '') {
                $byReference[$row['reference']] = $id;
            }
            if ($signature !== '') {
                $byAttributes[$signature] = $id;
            }
        }
        $ids = [];
        foreach ($entry['prices']['variants'] as $variant) {
            $signature = implode(',', $variant['attribute_ids']);
            $id = $byReference[$variant['reference']] ?? $byAttributes[$signature] ?? null;
            if (isset($byReference[$variant['reference']], $byAttributes[$signature])
                && $byReference[$variant['reference']] !== $byAttributes[$signature]) {
                throw new RuntimeException('Combination reference conflicts with its attribute set.');
            }
            $combination = $id === null ? new \Combination() : new \Combination($id, null, (int) $context->shop->id);
            $combination->id_product = (int) $product->id;
            $combination->reference = $variant['reference'];
            $combination->price = $variant['modifier'];
            // PrestaShop dodaje wpływ kombinacji do wagi bazowej. Zero zapewnia
            // jednakową masę wariantów, zachowując ich modyfikatory cen.
            $combination->weight = 0;
            $combination->minimal_quantity = 1;
            if (!($id === null ? $combination->add() : $combination->update())
                || !$combination->setAttributes($variant['attribute_ids'])) {
                throw new RuntimeException('Cannot save combination ' . $variant['source_id']);
            }
            $ids[] = (int) $combination->id;
            echo ($id === null ? 'CREATED' : 'UPDATED') . ': ' . $variant['reference'] . ', ID: ' . $combination->id . PHP_EOL;
        }
        // Zachowujemy poprawny wariant domyślny lub wybieramy pierwszy ze źródła.
        $default = in_array((int) $product->cache_default_attribute, $ids, true)
            ? (int) $product->cache_default_attribute : $ids[0];
        if (!$product->deleteDefaultAttributes() || !$product->setDefaultAttribute($default)) {
            throw new RuntimeException('Cannot set the default combination.');
        }
        // Sama zgodność zapisanego wpływu netto nie wystarcza. Ostateczną cenę każdej
        // kombinacji potwierdzamy kalkulatorem PrestaShop po ustawieniu atrybutów.
        foreach ($entry['prices']['variants'] as $index => $variant) {
            $gross = productGross((int) $product->id, $ids[$index], $context);
            if ($gross !== $variant['gross']) {
                throw new RuntimeException('Gross price mismatch for combination ' . $variant['source_id'] . ": {$gross} instead of " . $variant['gross']);
            }
            echo 'PRICE OK: ' . $variant['source_id'] . ", {$gross} PLN gross" . PHP_EOL;
        }
        $result[$entry['source_id']] = $ids;
        verifyUniformCombinationWeights((int) $product->id, $context);
    }
    return $result;
}
