<?php

declare(strict_types=1);

/**
 * Kontrola podstawowych funkcjonalności sklepu po odtworzeniu z kopii (deploy/verify-restore.sh).
 *
 * Wywołanie w kontenerze shop środowiska weryfikacyjnego:
 *   SMOKE_BASE_URL=https://localhost:19443 SMOKE_CONNECT_TO=nginx:443 SMOKE_API_KEY=... \
 *   php /opt/shop/deploy/verify/restore-smoke.php
 *
 * Dane testowe (kategoria, produkt z kombinacją, zdjęcie, promocja) wybiera zapytaniami do bazy sklepu.
 * Żądania HTTP idą przez nginx środowiska (CURLOPT_CONNECT_TO), więc sprawdzają cały łańcuch
 * nginx -> Apache -> PrestaShop z tym samym adresem, którego używa przeglądarka. Certyfikat lokalny nie jest
 * weryfikowany, bo kontener shop nie ma go w magazynie zaufanych.
 *
 * Każda kontrola wypisuje wiersz "PASS|nazwa|szczegóły", "FAIL|nazwa|szczegóły", "SKIP|nazwa|powód"
 * albo "INFO|nazwa|wartość". Brak wariantu lub promocji jest FAIL, gdy baza zawiera kombinacje lub
 * aktualne ceny promocyjne, a SKIP tylko wtedy, gdy sklep ich nie ma.
 * Kod wyjścia 1 oznacza co najmniej jedną kontrolę FAIL. Skrypt nie zapisuje nic w bazie poza koszykiem
 * i statystykami odwiedzin tworzonymi przez sam sklep.
 */

$baseUrl = rtrim((string) getenv('SMOKE_BASE_URL'), '/');
$connectTo = (string) getenv('SMOKE_CONNECT_TO');
$apiKey = (string) getenv('SMOKE_API_KEY');
$adminDir = (string) (getenv('SMOKE_ADMIN_DIR') ?: 'admin-dev');
$parsed = parse_url($baseUrl);
if ($baseUrl === '' || !is_array($parsed) || !isset($parsed['host'])) {
    fwrite(STDERR, "Ustaw SMOKE_BASE_URL, np. https://localhost:19443\n");
    exit(2);
}
$port = $parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80);

$failed = false;
$cookieJar = tempnam(sys_get_temp_dir(), 'smoke');
register_shutdown_function(static function () use ($cookieJar): void {
    @unlink($cookieJar);
});

function report(string $status, string $name, string $detail): void
{
    global $failed;
    if ($status === 'FAIL') {
        $failed = true;
    }
    // Separator | i nowe linie w szczegółach psułyby format raportu.
    $detail = str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $detail);
    echo $status . '|' . $name . '|' . $detail . PHP_EOL;
}

/**
 * @return array{status: int, body: string, type: string, url: string, error: string}
 */
function http(string $path, ?array $post = null): array
{
    global $baseUrl, $connectTo, $parsed, $port, $cookieJar;
    $url = str_starts_with($path, 'http') ? $path : $baseUrl . '/' . ltrim($path, '/');
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'restore-smoke/1.0',
        CURLOPT_HTTPHEADER => ['Accept-Language: pl'],
    ];
    if ($connectTo !== '') {
        $options[CURLOPT_CONNECT_TO] = [$parsed['host'] . ':' . $port . ':' . $connectTo];
    }
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        $options[CURLOPT_HTTPHEADER][] = 'X-Requested-With: XMLHttpRequest';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $result = [
        'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
        'body' => is_string($body) ? $body : '',
        'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
        'url' => (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
        'error' => curl_error($curl),
    ];
    curl_close($curl);
    return $result;
}

function describe(array $response): string
{
    return 'HTTP ' . $response['status'] . ($response['error'] !== '' ? ' (' . $response['error'] . ')' : '')
        . ' ' . $response['url'];
}

// --- Dane testowe z bazy -------------------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_SERVER') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    report('FAIL', 'Połączenie z bazą', $e->getMessage());
    exit(1);
}
$p = (string) (getenv('DB_PREFIX') ?: 'ps_');

$one = static function (string $sql, array $params = []) use ($pdo) {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetch(PDO::FETCH_ASSOC);
    return $value === false ? null : $value;
};

$config = static function (string $name) use ($one, $p): string {
    $row = $one("SELECT value FROM {$p}configuration WHERE name = ? ORDER BY id_shop IS NULL DESC, id_shop LIMIT 1", [$name]);
    return (string) ($row['value'] ?? '');
};

$langId = (int) $config('PS_LANG_DEFAULT');
$shopName = $config('PS_SHOP_NAME');

// --- 1. Strona główna ----------------------------------------------------------------------------
$home = http('/');
if ($home['status'] === 200 && ($shopName === '' || str_contains($home['body'], htmlspecialchars($shopName, ENT_QUOTES) ) || str_contains($home['body'], $shopName))) {
    report('PASS', 'Strona główna', describe($home) . ($shopName !== '' ? ", zawiera nazwę sklepu \"$shopName\"" : ''));
} else {
    report('FAIL', 'Strona główna', describe($home) . ', oczekiwano 200 i nazwy sklepu');
}

// --- 2. Kategoria z produktami -------------------------------------------------------------------
$category = $one(
    "SELECT cp.id_category, cl.name, COUNT(*) AS products
       FROM {$p}category_product cp
       JOIN {$p}category c ON c.id_category = cp.id_category AND c.active = 1 AND c.is_root_category = 0 AND c.id_parent > 0
       JOIN {$p}category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ? AND cl.id_shop = 1
       JOIN {$p}product_shop ps ON ps.id_product = cp.id_product AND ps.id_shop = 1 AND ps.active = 1
             AND ps.visibility IN ('both', 'catalog')
      WHERE c.id_category > 2
      GROUP BY cp.id_category, cl.name
      ORDER BY products DESC, cp.id_category
      LIMIT 1",
    [$langId]
);
if ($category === null) {
    report('FAIL', 'Strona kategorii', 'brak aktywnej kategorii z produktami w bazie');
} else {
    $response = http('/index.php?controller=category&id_category=' . (int) $category['id_category']);
    $ok = $response['status'] === 200 && str_contains($response['body'], 'product-miniature');
    report($ok ? 'PASS' : 'FAIL', 'Strona kategorii',
        describe($response) . ' "' . $category['name'] . '"' . ($ok ? ', lista produktów widoczna' : ', brak listy produktów'));
}

// --- 3. Produkt z wariantami ---------------------------------------------------------------------
$product = $one(
    "SELECT pa.id_product, pa.id_product_attribute, pl.name, pl.link_rewrite,
            GREATEST(1, pas.minimal_quantity) AS minimal_quantity
       FROM {$p}product_attribute pa
       JOIN {$p}product_shop ps ON ps.id_product = pa.id_product AND ps.id_shop = 1 AND ps.active = 1
             AND ps.visibility IN ('both', 'catalog') AND ps.available_for_order = 1
       JOIN {$p}product_attribute_shop pas ON pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = 1
       JOIN {$p}product_lang pl ON pl.id_product = pa.id_product AND pl.id_lang = ? AND pl.id_shop = 1
       JOIN {$p}stock_available sa ON sa.id_product = pa.id_product AND sa.id_product_attribute = pa.id_product_attribute
             AND sa.id_shop = 1
      WHERE sa.quantity >= GREATEST(1, pas.minimal_quantity)
      ORDER BY pa.id_product, pa.id_product_attribute
      LIMIT 1",
    [$langId]
);
$productPage = null;
if ($product === null) {
    $combinations = (int) ($one("SELECT COUNT(*) AS n FROM {$p}product_attribute")['n'] ?? 0);
    report($combinations > 0 ? 'FAIL' : 'SKIP', 'Produkt z wariantami', $combinations > 0
        ? "baza ma $combinations kombinacji, ale żadna aktywna nie jest dostępna na stanie"
        : 'sklep nie ma kombinacji');
} else {
    $productPage = http('/index.php?controller=product&id_product=' . (int) $product['id_product']
        . '&id_product_attribute=' . (int) $product['id_product_attribute']);
    $hasName = str_contains($productPage['body'], htmlspecialchars((string) $product['name'], ENT_QUOTES))
        || str_contains($productPage['body'], (string) $product['name']);
    $ok = $productPage['status'] === 200 && $hasName && str_contains($productPage['body'], 'product-variants');
    report($ok ? 'PASS' : 'FAIL', 'Produkt z wariantami',
        describe($productPage) . ' "' . $product['name'] . '" (id ' . $product['id_product'] . ')'
        . ($ok ? ', nazwa i selektor wariantów widoczne' : ', brak nazwy lub selektora wariantów'));
}

// --- 4. Zdjęcia produktu -------------------------------------------------------------------------
$image = $one(
    "SELECT i.id_image, pl.link_rewrite
       FROM {$p}image i
       JOIN {$p}image_shop ish ON ish.id_image = i.id_image AND ish.id_shop = 1
       JOIN {$p}product_shop ps ON ps.id_product = i.id_product AND ps.id_shop = 1 AND ps.active = 1
       JOIN {$p}product_lang pl ON pl.id_product = i.id_product AND pl.id_lang = ? AND pl.id_shop = 1
      ORDER BY ish.cover DESC, i.id_product, i.position
      LIMIT 1",
    [$langId]
);
if ($image === null) {
    report('FAIL', 'Zdjęcie produktu', 'brak zdjęć aktywnych produktów w bazie');
} else {
    $id = (string) (int) $image['id_image'];
    $original = http('/img/p/' . implode('/', str_split($id)) . '/' . $id . '.jpg');
    $ok = $original['status'] === 200 && str_starts_with($original['type'], 'image/') && strlen($original['body']) > 0;
    report($ok ? 'PASS' : 'FAIL', 'Zdjęcie produktu (plik)', describe($original) . ', ' . $original['type'] . ', ' . strlen($original['body']) . ' B');
    // Przyjazny adres miniatury wymaga reguł .htaccess odtworzonych przez restore.sh.
    $thumb = http('/' . $id . '-home_default/' . $image['link_rewrite'] . '.jpg');
    $ok = $thumb['status'] === 200 && str_starts_with($thumb['type'], 'image/');
    report($ok ? 'PASS' : 'FAIL', 'Zdjęcie produktu (miniatura, przyjazny URL)', describe($thumb) . ', ' . $thumb['type']);
}

// --- 5. Promocja ---------------------------------------------------------------------------------
$promo = $one(
    "SELECT sp.id_product, pl.name
       FROM {$p}specific_price sp
       JOIN {$p}product_shop ps ON ps.id_product = sp.id_product AND ps.id_shop = 1 AND ps.active = 1
             AND ps.visibility IN ('both', 'catalog')
       JOIN {$p}product_lang pl ON pl.id_product = sp.id_product AND pl.id_lang = ? AND pl.id_shop = 1
      WHERE sp.id_cart = 0 AND sp.id_customer = 0 AND sp.id_group = 0 AND sp.id_country = 0
        AND sp.id_currency = 0 AND sp.id_shop IN (0, 1) AND sp.from_quantity <= 1 AND sp.reduction > 0
        AND sp.id_product_attribute = 0
        AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= NOW())
        AND (sp.`to` = '0000-00-00 00:00:00' OR sp.`to` >= NOW())
      ORDER BY sp.id_product
      LIMIT 1",
    [$langId]
);
if ($promo === null) {
    $current = (int) ($one("SELECT COUNT(*) AS n FROM {$p}specific_price sp WHERE sp.id_cart = 0 AND sp.reduction > 0
        AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= NOW())
        AND (sp.`to` = '0000-00-00 00:00:00' OR sp.`to` >= NOW())")['n'] ?? 0);
    report($current > 0 ? 'FAIL' : 'SKIP', 'Promocja na produkcie', $current > 0
        ? "baza ma $current aktualnych cen promocyjnych, ale żadnej dla wszystkich klientów na aktywnym produkcie"
        : 'sklep nie ma aktualnych cen promocyjnych');
} else {
    $response = http('/index.php?controller=product&id_product=' . (int) $promo['id_product']);
    // Cena regularna musi być w bloku cen tego produktu, nie w miniaturach innych produktów na stronie.
    $pricesAt = strpos($response['body'], 'product-prices');
    $prices = $pricesAt === false ? '' : substr($response['body'], $pricesAt, 4000);
    $ok = $response['status'] === 200 && str_contains($prices, 'regular-price') && str_contains($prices, 'discount');
    report($ok ? 'PASS' : 'FAIL', 'Promocja na produkcie',
        describe($response) . ' "' . $promo['name'] . '"' . ($ok ? ', cena regularna i promocyjna widoczne' : ', brak ceny regularnej (promocji)'));
}
$cartRules = $one("SELECT COUNT(*) AS n FROM {$p}cart_rule WHERE active = 1 AND date_to >= NOW()");
report('INFO', 'Reguły koszyka', 'aktywne: ' . (int) ($cartRules['n'] ?? 0));

// --- 6. Wyszukiwarka -----------------------------------------------------------------------------
$searchSource = $product['name'] ?? ($promo['name'] ?? '');
$words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', (string) $searchSource) ?: [], static fn ($w) => mb_strlen($w) >= 4));
if ($words === []) {
    report('SKIP', 'Wyszukiwarka', 'brak frazy do wyszukania');
} else {
    // Przyjazny adres strony wyszukiwania z meta_lang (np. /szukaj?s=...), tak jak wysyła formularz w nagłówku.
    // Przekierowanie kanoniczne z index.php?controller=search gubi parametr s (zachowanie PrestaShop,
    // także w sklepie źródłowym), więc ten adres jest używany tylko bez przyjaznych URL.
    $searchMeta = $one(
        "SELECT ml.url_rewrite FROM {$p}meta m JOIN {$p}meta_lang ml ON ml.id_meta = m.id_meta AND ml.id_lang = ? AND ml.id_shop = 1
          WHERE m.page = 'search' LIMIT 1",
        [$langId]
    );
    $searchPath = $config('PS_REWRITING_SETTINGS') === '1' && ($searchMeta['url_rewrite'] ?? '') !== ''
        ? '/' . rawurlencode((string) $searchMeta['url_rewrite']) . '?s='
        : '/index.php?controller=search&s=';
    $response = http($searchPath . rawurlencode($words[0]));
    $indexed = (int) ($one("SELECT COUNT(*) AS n FROM {$p}search_index")['n'] ?? 0);
    $ok = $response['status'] === 200 && str_contains($response['body'], 'product-miniature');
    report($ok ? 'PASS' : 'FAIL', 'Wyszukiwarka', describe($response) . ' fraza "' . $words[0] . '"'
        . ($ok ? ', są wyniki' : ', brak wyników') . ", indeks wyszukiwania: $indexed wpisów");
}

// --- 7. Koszyk -----------------------------------------------------------------------------------
if ($product === null || $productPage === null || $productPage['status'] !== 200) {
    report('SKIP', 'Dodanie do koszyka', 'brak strony produktu do testu');
} elseif (!preg_match('/"static_token"\s*:\s*"([0-9a-f]{32})"/', $productPage['body'], $match)) {
    report('FAIL', 'Dodanie do koszyka', 'nie znaleziono static_token na stronie produktu');
} else {
    $add = http('/index.php?controller=cart', [
        'token' => $match[1], 'id_product' => (int) $product['id_product'],
        'id_product_attribute' => (int) $product['id_product_attribute'], 'qty' => (int) $product['minimal_quantity'],
        'add' => 1, 'action' => 'update', 'ajax' => 1,
    ]);
    $json = json_decode($add['body'], true);
    $count = is_array($json) ? (int) ($json['cart']['products_count'] ?? 0) : 0;
    $ok = $add['status'] === 200 && is_array($json) && ($json['success'] ?? false) === true && $count >= 1;
    report($ok ? 'PASS' : 'FAIL', 'Dodanie do koszyka', describe($add) . ', produktów w koszyku: ' . $count
        . (!$ok && is_array($json) && isset($json['errors']) ? ', błędy: ' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE) : ''));
    $cart = http('/index.php?controller=cart&action=show');
    $ok = $cart['status'] === 200 && str_contains($cart['body'], 'cart-item');
    report($ok ? 'PASS' : 'FAIL', 'Podgląd koszyka', describe($cart) . ($ok ? ', pozycja w koszyku widoczna' : ', brak pozycji w koszyku'));
    $order = http('/index.php?controller=order');
    $ok = $order['status'] === 200 && str_contains($order['body'], 'checkout-personal-information-step');
    report($ok ? 'PASS' : 'FAIL', 'Rozpoczęcie zamówienia', describe($order) . ($ok ? ', formularz danych klienta widoczny' : ', brak kroku danych osobowych'));
}

// --- 8. Panel administracyjny --------------------------------------------------------------------
$admin = http('/' . $adminDir . '/index.php?controller=AdminLogin');
$ok = $admin['status'] === 200 && str_contains($admin['body'], 'login_form');
report($ok ? 'PASS' : 'FAIL', 'Logowanie do panelu', describe($admin) . ($ok ? ', formularz logowania widoczny' : ', brak formularza logowania'));

// --- 9. Widoki bazy (np. historia cen Omnibus) ---------------------------------------------------
$views = $pdo->query('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')
    ->fetchAll(PDO::FETCH_COLUMN);
if ($views === []) {
    report('SKIP', 'Widoki bazy', 'baza nie ma widoków');
} else {
    $errors = [];
    $rows = [];
    foreach ($views as $view) {
        try {
            // Konto aplikacji sklepu: sprawdza też właściciela (DEFINER) i uprawnienia widoku.
            $rows[] = $view . ': ' . (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $view) . '`')->fetchColumn();
        } catch (Throwable $e) {
            $errors[] = $view . ': ' . $e->getMessage();
        }
    }
    report($errors === [] ? 'PASS' : 'FAIL', 'Widoki bazy', $errors === [] ? implode(', ', $rows) . ' wierszy' : implode('; ', $errors));
}

// --- 10. Webservice API ---------------------------------------------------------------------------
if ($apiKey === '') {
    report('SKIP', 'Webservice API', 'brak SMOKE_API_KEY');
} else {
    $curlApi = http('/api/categories?ws_key=' . rawurlencode($apiKey) . '&output_format=JSON&limit=1');
    $json = json_decode($curlApi['body'], true);
    $ok = $curlApi['status'] === 200 && is_array($json) && isset($json['categories']);
    report($ok ? 'PASS' : 'FAIL', 'Webservice API', 'HTTP ' . $curlApi['status'] . ' /api/categories' . ($ok ? ', klucz z .env działa' : ''));
}

exit($failed ? 1 : 0);
