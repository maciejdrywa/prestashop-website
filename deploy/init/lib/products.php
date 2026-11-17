<?php
declare(strict_types=1);

/**
 * Planowanie, zapis i ścisła kontrola produktów wraz z przypisaniami kategorii.
 *
 * Używana przez import-products.php, check-products.php i inicjalizator, po przygotowaniu kontekstu PL/PLN
 * i VAT.
 *
 * Wymaga source.php, prestashop.php i weights.php. Zapis przez Product obejmuje opisy, ceny, VAT, masę oraz
 * powiązania sklepu.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';
require_once __DIR__ . '/weights.php';

/**
 * Wyszukuje unikalny produkt po referencji i potwierdza przynależność do sklepu.
 *
 * @param string $reference Trwała referencja produktu w formacie pandomu-ID.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int|null ID produktu o podanej referencji albo null.
 * @throws \RuntimeException Przy wieloznacznej referencji lub powiązaniu znalezionego produktu z innym sklepem.
 */
function productId(string $reference, \Context $context): ?int
{
    $query = new \DbQuery();
    $query->select('id_product AS id')->from('product');
    $query->where("reference = '" . \pSQL($reference) . "'");
    $id = uniqueId($query);
    if ($id !== null) {
        $query = new \DbQuery();
        $query->select('id_product AS id')->from('product_shop');
        $query->where('id_product = ' . $id)->where('id_shop = ' . (int) $context->shop->id);
        if (uniqueId($query) === null) {
            throw new RuntimeException("Reference {$reference} belongs to another shop.");
        }
    }
    return $id;
}

/**
 * Wybiera aktywną, jednoznaczną polską regułę podatkową dla podanej stawki VAT.
 *
 * Zapytanie wyklucza regionalne, pocztowe i nakładające się reguły polskiego VAT. Przy kilku równoważnych
 * grupach wybierana jest pierwsza według ID, a brak pasującej reguły przerywa planowanie.
 *
 * @param string $vat Stawka VAT w procentach, np. 23.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return int ID pierwszej pasującej grupy z pojedynczą ogólną regułą dla Polski.
 * @throws \RuntimeException Gdy brak ogólnej aktywnej grupy podatkowej spełniającej warunki dla podanej stawki VAT.
 */
function taxGroup(string $vat, \Context $context): int
{
    $country = (int) \Country::getByIso('PL');
    $query = new \DbQuery();
    // Reguła musi należeć do sklepu i obejmować Polskę ogólnie, bez wyjątków
    // stanowych, kodów pocztowych i złożonego sposobu naliczania VAT.
    $query->select('g.id_tax_rules_group')->from('tax_rules_group', 'g');
    $query->innerJoin('tax_rule', 'r', 'r.id_tax_rules_group = g.id_tax_rules_group');
    $query->innerJoin('tax', 't', 't.id_tax = r.id_tax');
    $query->innerJoin('tax_rules_group_shop', 's', 's.id_tax_rules_group = g.id_tax_rules_group');
    $query->where('s.id_shop = ' . (int) $context->shop->id);
    $query->where('g.active = 1 AND g.deleted = 0 AND t.active = 1 AND t.deleted = 0');
    $query->where('r.id_country = ' . $country);
    $query->where('r.id_state = 0 AND r.zipcode_from = 0 AND r.zipcode_to = 0 AND r.behavior = 0');
    $query->where("t.rate = '" . \pSQL($vat) . "'");
    // Pomijamy grupy z nakładającymi się lub złożonymi polskimi regułami podatku.
    $query->where('(SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tax_rule r2 WHERE r2.id_tax_rules_group = g.id_tax_rules_group AND r2.id_country = ' . $country . ') = 1');
    $query->orderBy('g.id_tax_rules_group');
    $rows = \Db::getInstance()->executeS($query);
    if (!$rows) {
        throw new RuntimeException("No unambiguous general Polish VAT rule for {$vat}% in this shop.");
    }
    return (int) $rows[0]['id_tax_rules_group'];
}

/**
 * Waliduje źródło i przygotowuje plany cen, opisów, masy oraz przypisań kategorii.
 *
 * Łączy walidację ograniczeń pól PrestaShop, ewentualne oczyszczenie HTML i plany ceny oraz masy.
 * Preferowaną kategorię bierze z breadcrumbs, a przy braku zgodności wybiera najgłębszą ścieżkę z
 * deterministycznym rozstrzygnięciem remisów.
 *
 * @param array $products Lista zweryfikowanych rekordów źródłowych products.json.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return list<array> Pola docelowe produktu, price, paths, primary oraz metadane weight_origin i html_normalized.
 * @throws \RuntimeException Przy błędnych polach, zależnościach waluty i podatku lub konflikcie źródłowych i zapisanych wariantów.
 */
function productPlans(array $products, \Context $context): array
{
    priceContext($context);
    if (strtolower(trim((string) \Configuration::get('PS_WEIGHT_UNIT'))) !== 'kg') {
        throw new RuntimeException('Source weights are kilograms; configure PS_WEIGHT_UNIT=kg first.');
    }
    $plans = [];
    $taxes = [];
    foreach ($products as $source) {
        $sourceId = (string) $source['product_id'];
        $reference = 'pandomu-' . $sourceId;
        if (!\Validate::isCatalogName($source['name'])
            || mb_strlen($source['name']) > \Product::$definition['fields']['name']['size']) {
            throw new RuntimeException("Invalid or overlong product name: {$sourceId}");
        }
        $description = $source['description'] ?? null;
        $shortDescription = $source['description_short'] ?? null;
        if (!is_string($shortDescription) || !\Validate::isCleanHtml($shortDescription)
            || mb_strlen($shortDescription) > \Product::$definition['fields']['description_short']['size']) {
            throw new RuntimeException("Missing or invalid description_short: {$sourceId}; refresh source descriptions first.");
        }
        if (!is_string($description) || trim(strip_tags($description)) === ''
            || !\Validate::isCleanHtml($description)
            || mb_strlen($description) > \Product::$definition['fields']['description']['size']) {
            throw new RuntimeException("Missing or invalid HTML description: {$sourceId}");
        }
        // Normalizacja odpowiada sposobowi przechowywania HTML przez sklep. Plan
        // zapamiętuje zmianę, aby późniejsza kontrola porównywała tę samą reprezentację.
        if (\Configuration::get('PS_USE_HTMLPURIFIER')) {
            $description = \Tools::purifyHTML($description);
            $shortDescription = \Tools::purifyHTML($shortDescription);
        }
        $weightPlan = productWeightPlan($source);
        $price = pricePlan($source);
        $taxes[$price['vat']] ??= taxGroup($price['vat'], $context);
        $paths = $source['category_paths'];
        $primary = array_slice($source['breadcrumbs'] ?? [], 1, -1);
        // Breadcrumbs może wskazywać nieaktualną gałąź. Zapasowy wybór najgłębszej
        // ścieżki i nazwy daje powtarzalną kategorię domyślną przy wielu przypisaniach.
        if (!in_array($primary, $paths, true)) {
            $sorted = $paths;
            usort($sorted, fn (array $a, array $b): int => count($b) <=> count($a) ?: strcmp(implode(' > ', $a), implode(' > ', $b)));
            $primary = $sorted[0];
        }
        $slug = rtrim(mb_substr(\Tools::link_rewrite($source['name']), 0, 128), '-');
        if ($slug === '') {
            $slug = $reference;
        }
        $id = productId($reference, $context);
        if ($id !== null && $source['variants'] === []) {
            $query = new \DbQuery();
            $query->select('COUNT(*)')->from('product_attribute')->where('id_product = ' . $id);
            if ((int) \Db::getInstance()->getValue($query, false) > 0) {
                throw new RuntimeException("Product {$sourceId} now has no source variants but saved combinations exist; resolve before import.");
            }
        }
        $plans[] = [
            'source_id' => $sourceId, 'id' => $id, 'reference' => $reference,
            'name' => $source['name'], 'description' => $description,
            'description_short' => $shortDescription,
            'html_normalized' => $description !== $source['description'] || $shortDescription !== $source['description_short'],
            ...$weightPlan, 'price' => $price, 'tax_group' => $taxes[$price['vat']],
            'paths' => $paths, 'primary' => $primary, 'slug' => $slug,
            'type' => $source['variants'] === [] ? 'standard' : 'combinations',
        ];
    }
    return $plans;
}

/**
 * Rozwiązuje wszystkie liście i ich przodków na uporządkowany zbiór ID kategorii.
 *
 * @param array $plan Plan pojedynczego produktu zawierający listę paths.
 * @param array $categories Mapa pełnych ścieżek JSON na zapisane ID kategorii.
 * @return list<int> Unikalne ID kategorii uporządkowane numerycznie.
 * @throws \RuntimeException Gdy którejś wymaganej ścieżki lub jej przodka brakuje w mapie kategorii.
 */
function productCategoryIds(array $plan, array $categories): array
{
    $ids = [];
    // Przypisujemy źródłowy liść i jego przodków, aby lista rodzica obejmowała
    // całą gałąź niezależnie od sposobu agregacji w motywie.
    foreach ($plan['paths'] as $path) {
        for ($length = 1; $length <= count($path); $length++) {
            $key = json_encode(array_slice($path, 0, $length), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!isset($categories[$key])) {
                throw new RuntimeException('Unresolved category path: ' . $key);
            }
            $ids[] = $categories[$key];
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Porównuje zapisane pola, tłumaczenia, przypisania, wagę i cenę brutto z planem importu.
 *
 * To porównanie ze źródłowym planem obejmuje także wszystkie języki, dokładny zbiór przypisań i cenę
 * brutto. Ręczna edycja produktu może spowodować rozbieżność. Wznowienie ma odrębne kontrole akceptujące
 * edytowalne pola.
 *
 * @param array $plan Plan z productPlans, używany do ścisłego porównania ze źródłem.
 * @param int $id ID sprawdzanego rekordu w PrestaShop.
 * @param array $categories Mapa pełnych ścieżek JSON na zapisane ID kategorii.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return void
 * @throws \RuntimeException Przy rozbieżności pól, tłumaczeń, przypisań kategorii, masy lub brutto względem planu.
 */
function verifyProduct(array $plan, int $id, array $categories, \Context $context): void
{
    $saved = new \Product($id, false, null, (int) $context->shop->id);
    if (!\Validate::isLoadedObject($saved) || $saved->reference !== $plan['reference']
        || rounded(decimal($saved->price, 'saved net'), 6) !== $plan['price']['net']
        || rounded(decimal($saved->weight, 'saved weight'), 6) !== $plan['weight']
        || (int) $saved->id_tax_rules_group !== $plan['tax_group']
        || $saved->product_type !== $plan['type']
        || (int) $saved->state !== \Product::STATE_SAVED) {
        throw new RuntimeException('Saved product data mismatch: ' . $plan['source_id']);
    }
    verifyUniformCombinationWeights($id, $context);
    foreach (\Language::getLanguages(false) as $language) {
        $languageId = (int) $language['id_lang'];
        if (($saved->name[$languageId] ?? null) !== $plan['name']
            || ($saved->description[$languageId] ?? null) !== $plan['description']
            || ($saved->description_short[$languageId] ?? null) !== $plan['description_short']
            || ($saved->link_rewrite[$languageId] ?? null) !== $plan['slug']) {
            throw new RuntimeException('Saved product translation mismatch: ' . $plan['source_id']);
        }
    }
    $query = new \DbQuery();
    // Kontrola obejmuje dokładny zbiór przypisań, także przodków liści.
    // Osobno sprawdzana jest kategoria domyślna używana m.in. w adresie produktu.
    $query->select('id_category')->from('category_product')->where('id_product = ' . $id)->orderBy('id_category');
    $rows = \Db::getInstance()->executeS($query, true, false);
    $actual = $rows === false ? [] : array_map(fn (array $r): int => (int) $r['id_category'], $rows);
    $default = $categories[json_encode($plan['primary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    if ($actual !== productCategoryIds($plan, $categories) || (int) $saved->id_category_default !== $default) {
        throw new RuntimeException('Saved category assignments mismatch: ' . $plan['source_id']);
    }
    if (productGross($id, false, $context) !== $plan['price']['gross']) {
        throw new RuntimeException('PrestaShop base gross price mismatch: ' . $plan['source_id']);
    }
}

/**
 * Zapisuje produkty i sprawdza ich zgodność z planem źródłowym.
 *
 * Aktualizuje nazwy, dwa opisy, cenę, VAT, masę i przypisania istniejących produktów. Nowe produkty
 * zapisuje jako nieaktywne. Zeruje wpływ masy kombinacji i sprawdza zapis. Transakcję zapewnia wywołujący.
 *
 * @param array $plans Zweryfikowane plany produktów z productPlans.
 * @param array $categories Mapa pełnych ścieżek JSON na zapisane ID kategorii.
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep i zakres operacji.
 * @return array<string, int> Mapa źródłowych ID na zapisane ID produktów.
 * @throws \RuntimeException Przy błędzie zapisu produktu i kategorii lub niezgodnym wyniku weryfikacji.
 */
function importProducts(array $plans, array $categories, \Context $context): array
{
    $ids = [];
    $created = $updated = 0;
    foreach ($plans as $index => $plan) {
        // Ponowne wyszukanie umożliwia użycie planu więcej niż raz w jednym procesie.
        $id = productId($plan['reference'], $context);
        $product = $id === null ? new \Product() : new \Product($id, false, null, (int) $context->shop->id);
        if ($id === null) {
            $product->active = false;
            $product->id_shop_default = (int) $context->shop->id;
            $product->available_for_order = true;
            $product->show_price = true;
            $product->visibility = 'both';
            $product->minimal_quantity = 1;
            $product->condition = 'new';
        }
        // Obiekt zapisuje dane przez natywne modele i hooki. Aktywność jest ustawiana
        // na false tylko przy tworzeniu, a końcowe udostępnienie realizuje etap magazynu.
        $product->reference = $plan['reference'];
        $product->state = \Product::STATE_SAVED;
        $product->product_type = $plan['type'];
        $product->price = $plan['price']['net'];
        $product->weight = $plan['weight'];
        $product->id_tax_rules_group = $plan['tax_group'];
        $product->id_category_default = $categories[json_encode($plan['primary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        foreach (\Language::getLanguages(false) as $language) {
            $languageId = (int) $language['id_lang'];
            $product->name[$languageId] = $plan['name'];
            $product->description[$languageId] = $plan['description'];
            $product->description_short[$languageId] = $plan['description_short'];
            $product->link_rewrite[$languageId] = $plan['slug'];
        }
        if (!($id === null ? $product->add() : $product->update())
            || !$product->updateCategories(productCategoryIds($plan, $categories))) {
            throw new RuntimeException('Cannot save product: ' . $plan['source_id']);
        }
        $id === null ? $created++ : $updated++;
        $ids[$plan['source_id']] = (int) $product->id;
        resetCombinationWeights((int) $product->id, $context);
        verifyProduct($plan, (int) $product->id, $categories, $context);
        echo '[' . ($index + 1) . '/' . count($plans) . '] ' . ($id === null ? 'CREATED' : 'UPDATED')
            . ': ' . $plan['reference'] . ', ID: ' . $product->id
            . ', gross: ' . $plan['price']['gross'] . ' PLN, categories: '
            . count(productCategoryIds($plan, $categories))
            . ', weight: ' . $plan['weight'] . ' kg (' . $plan['weight_origin'] . ')' . PHP_EOL;
        if ($plan['html_normalized']) {
            echo 'HTML NORMALIZED: ' . $plan['reference'] . ' (PrestaShop purifier)' . PHP_EOL;
        }
        if ($plan['weight_origin'] === 'demo') {
            echo 'DEMO WEIGHT: ' . $plan['reference'] . ', rule: ' . $plan['weight_rule'] . PHP_EOL;
        }
    }
    echo "SUMMARY: {$created} created, {$updated} updated; all base prices and fields verified.\n";
    return $ids;
}
