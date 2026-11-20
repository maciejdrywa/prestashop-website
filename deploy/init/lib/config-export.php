<?php
declare(strict_types=1);

/**
 * Eksport i odtwarzanie konfiguracji sklepu z pliku przechowywanego w repozytorium.
 *
 * Używana przez export-config.php, configure-from-export.php i wznowienie inicjalizacji. Wymaga boot()
 * istniejącej instalacji.
 *
 * Eksport obejmuje wyłącznie jawnie wymienione obszary: języki, waluty, kraje, reguły podatkowe, wybrane
 * klucze Configuration, przewoźników, uprawnienia płatności, strony CMS, motyw i stan modułów. Odwołania
 * zapisuje kluczami naturalnymi (ISO, link_rewrite, klucz przewoźnika), dlatego plik nie zależy od ID
 * konkretnej instalacji. Danych klientów, zamówień, pracowników i kluczy API eksport w ogóle nie odczytuje,
 * a każda wartość przechodzi kontrolę sekretów i danych osobowych przed zapisem oraz przed odtworzeniem.
 */

namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/prestashop.php';
require_once __DIR__ . '/theme.php';

/** Wersja formatu pliku. Zmiana struktury wymaga podniesienia wartości i obsługi w readConfigExport. */
const CONFIG_EXPORT_FORMAT = 1;

/** Klucz Configuration z SHA-256 ostatnio zastosowanego pliku eksportu. */
const CONFIG_EXPORT_APPLIED_SETTING = 'SHOP_INIT_CONFIG_EXPORT_SHA256';

/** Kolejność sekcji w pliku i podczas odtwarzania. Konfiguracja jest ostatnia, bo odwołuje się do CMS i przewoźników. */
const CONFIG_EXPORT_SECTIONS = ['languages', 'currencies', 'countries', 'customer_groups', 'taxes', 'modules', 'theme', 'cms', 'carriers', 'payments', 'configuration'];

/**
 * Zwraca ścieżkę pliku eksportu w repozytorium.
 *
 * Zmienna SHOP_CONFIG_EXPORT_FILE pozwala wskazać inny plik. Domyślnie wybierany jest
 * deploy/config/shop-config.json względem katalogu skryptów, a przy montowaniu init osobno
 * /opt/shop/deploy/config/shop-config.json.
 *
 * @return string Ścieżka pliku eksportu, także nieistniejącego.
 */
function configExportPath(): string
{
    $override = getenv('SHOP_CONFIG_EXPORT_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    // Bootstrap uruchamia skrypty z /opt/shop/deploy/init, a exec z osobnego montowania /opt/shop/init.
    foreach ([dirname(__DIR__, 2) . '/config/shop-config.json', '/opt/shop/deploy/config/shop-config.json'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return dirname(__DIR__, 2) . '/config/shop-config.json';
}

/**
 * Lista eksportowanych kluczy Configuration i typ ich wartości.
 *
 * Typ plain oznacza wartość skalarną, lang wartość wielojęzyczną zapisywaną według ISO języka, a pozostałe
 * typy odwołanie do obiektu zapisywane kluczem naturalnym. Lista jest allowlistą: klucze spoza niej, w tym
 * hasła SMTP, DKIM, sole, adresy e-mail, flagi środowiska (domena, SSL, Webservice) i ID zależne od
 * instalacji, nie są eksportowane.
 *
 * @return array<string, string> Klucz Configuration => plain|lang|country|currency|language|carrier|cms.
 */
function exportedConfigurationKeys(): array
{
    $keys = [
        // Dane sklepu i wystawcy dokumentów (fikcyjne dane demonstracyjne projektu).
        'PS_SHOP_NAME' => 'plain', 'PS_SHOP_ADDR1' => 'plain', 'PS_SHOP_ADDR2' => 'plain', 'PS_SHOP_CODE' => 'plain',
        'PS_SHOP_CITY' => 'plain', 'PS_SHOP_COUNTRY_ID' => 'country', 'PS_SHOP_DETAILS' => 'plain', 'PS_SHOP_ACTIVITY' => 'plain',
        // Lokalizacja, jednostki i zaokrąglenia.
        'PS_LANG_DEFAULT' => 'language', 'PS_CURRENCY_DEFAULT' => 'currency', 'PS_COUNTRY_DEFAULT' => 'country',
        'PS_LOCALE_LANGUAGE' => 'plain', 'PS_LOCALE_COUNTRY' => 'plain', 'PS_TIMEZONE' => 'plain',
        'PS_DETECT_LANG' => 'plain', 'PS_DETECT_COUNTRY' => 'plain', 'PS_WEIGHT_UNIT' => 'plain',
        'PS_DIMENSION_UNIT' => 'plain', 'PS_VOLUME_UNIT' => 'plain', 'PS_DISTANCE_UNIT' => 'plain',
        'PS_BASE_DISTANCE_UNIT' => 'plain', 'PS_PRICE_ROUND_MODE' => 'plain', 'PS_ROUND_TYPE' => 'plain',
        // Podatki.
        'PS_TAX' => 'plain', 'PS_TAX_DISPLAY' => 'plain', 'PS_TAX_ADDRESS_TYPE' => 'plain', 'PS_USE_ECOTAX' => 'plain',
        // Dostawa.
        'PS_SHIPPING_HANDLING' => 'plain', 'PS_SHIPPING_FREE_PRICE' => 'plain', 'PS_SHIPPING_FREE_WEIGHT' => 'plain',
        'PS_SHIPPING_METHOD' => 'plain', 'PS_CARRIER_DEFAULT' => 'carrier', 'PS_CARRIER_DEFAULT_SORT' => 'plain',
        'PS_CARRIER_DEFAULT_ORDER' => 'plain', 'PS_ORDER_RECALCULATE_SHIPPING' => 'plain',
        // Zamówienia, faktury i regulamin.
        'PS_INVOICE' => 'plain', 'PS_INVOICE_PREFIX' => 'lang', 'PS_INVOICE_MODEL' => 'plain',
        'PS_INVOICE_LEGAL_FREE_TEXT' => 'lang', 'PS_DELIVERY_PREFIX' => 'lang', 'PS_RETURN_PREFIX' => 'lang',
        'PS_CONDITIONS' => 'plain', 'PS_CONDITIONS_CMS_ID' => 'cms', 'PS_GUEST_CHECKOUT_ENABLED' => 'plain',
        'PS_PURCHASE_MINIMUM' => 'plain', 'PS_ORDER_OUT_OF_STOCK' => 'plain', 'PS_ORDER_RETURN' => 'plain',
        'PS_ORDER_RETURN_NB_DAYS' => 'plain', 'PS_GIFT_WRAPPING' => 'plain', 'PS_GIFT_WRAPPING_PRICE' => 'plain',
        'PS_RECYCLABLE_PACK' => 'plain',
        // Treści modułu przelewu. Projekt używa jawnie demonstracyjnych danych, które skaner sprawdza pod kątem IBAN.
        'BANK_WIRE_OWNER' => 'plain', 'BANK_WIRE_DETAILS' => 'plain', 'BANK_WIRE_ADDRESS' => 'plain',
        'BANK_WIRE_CUSTOM_TEXT' => 'lang', 'BANK_WIRE_PAYMENT_INVITE' => 'plain',
        // Poczta bez danych logowania SMTP i DKIM.
        'PS_MAIL_METHOD' => 'plain', 'PS_MAIL_SERVER' => 'plain', 'PS_MAIL_SMTP_PORT' => 'plain',
        'PS_MAIL_SMTP_ENCRYPTION' => 'plain', 'PS_MAIL_TYPE' => 'plain', 'PS_MAIL_THEME' => 'plain',
        'PS_MAIL_COLOR' => 'plain', 'PS_MAIL_SUBJECT_PREFIX' => 'plain', 'PS_LOG_EMAILS' => 'plain',
        // Katalog i magazyn.
        'PS_STOCK_MANAGEMENT' => 'plain', 'PS_DISPLAY_QTIES' => 'plain', 'PS_LAST_QTIES' => 'plain',
        'PS_PRODUCTS_PER_PAGE' => 'plain', 'PS_PRODUCTS_ORDER_BY' => 'plain', 'PS_PRODUCTS_ORDER_WAY' => 'plain',
        'PS_NB_DAYS_NEW_PRODUCT' => 'plain', 'PS_DISP_UNAVAILABLE_ATTR' => 'plain', 'PS_SHOW_LABEL_OOS_LISTING_PAGES' => 'plain',
        'PS_LABEL_IN_STOCK_PRODUCTS' => 'lang', 'PS_LABEL_OOS_PRODUCTS_BOA' => 'lang', 'PS_LABEL_OOS_PRODUCTS_BOD' => 'lang',
        'PS_CATALOG_MODE' => 'plain', 'PS_CATALOG_MODE_WITH_PRICES' => 'plain', 'PS_DISPLAY_PRODUCT_WEIGHT' => 'plain',
        'PS_PRODUCT_WEIGHT_PRECISION' => 'plain', 'PS_ATTRIBUTE_CATEGORY_DISPLAY' => 'plain',
        'PS_ATTRIBUTE_ANCHOR_SEPARATOR' => 'plain', 'PS_DISPLAY_BEST_SELLERS' => 'plain', 'PS_DISPLAY_MANUFACTURERS' => 'plain',
        'PS_DISPLAY_SUPPLIERS' => 'plain', 'PS_PRODUCT_SHORT_DESC_LIMIT' => 'plain',
        // Konta klientów i zgody (ustawienia formularzy, bez danych klientów).
        'PS_CUSTOMER_OPTIN' => 'plain', 'PS_CUSTOMER_BIRTHDATE' => 'plain', 'PS_CUSTOMER_CREATION_EMAIL' => 'plain',
        'PSGDPR_CREATION_FORM_SWITCH' => 'plain', 'PSGDPR_CUSTOMER_FORM_SWITCH' => 'plain',
        'PSGDPR_CREATION_FORM' => 'lang', 'PSGDPR_CUSTOMER_FORM' => 'lang',
        // Wyszukiwanie i adresy URL.
        'PS_SEARCH_AJAX' => 'plain', 'PS_SEARCH_FUZZY' => 'plain', 'PS_SEARCH_MINWORDLEN' => 'plain',
        'PS_SEARCH_INDEXATION' => 'plain', 'PS_SEARCH_WEIGHT_PNAME' => 'plain', 'PS_SEARCH_WEIGHT_REF' => 'plain',
        'PS_SEARCH_WEIGHT_SHORTDESC' => 'plain', 'PS_SEARCH_WEIGHT_DESC' => 'plain', 'PS_SEARCH_WEIGHT_CNAME' => 'plain',
        'PS_SEARCH_WEIGHT_MNAME' => 'plain', 'PS_SEARCH_WEIGHT_TAG' => 'plain', 'PS_SEARCH_WEIGHT_ATTRIBUTE' => 'plain',
        'PS_SEARCH_WEIGHT_FEATURE' => 'plain', 'PS_CANONICAL_REDIRECT' => 'plain',
        'PS_COOKIE_SAMESITE' => 'plain', 'PS_COOKIE_LIFETIME_FO' => 'plain', 'PS_COOKIE_LIFETIME_BO' => 'plain',
        // Moduły motywu.
        'SHOP_COOKIE_INFO_CMS_ID' => 'cms', 'HOME_FEATURED_NBR' => 'plain', 'HOME_FEATURED_RANDOMIZE' => 'plain',
        'BLOCK_CATEG_MAX_DEPTH' => 'plain', 'BLOCK_CATEG_DHTML' => 'plain', 'BLOCK_CATEG_NBR_COLUMN_FOOTER' => 'plain',
        'PS_LAYERED_SHOW_QTIES' => 'plain', 'PS_LAYERED_FULL_TREE' => 'plain', 'PS_LAYERED_FILTER_PRICE_USETAX' => 'plain',
        'PS_LAYERED_FILTER_CATEGORY_DEPTH' => 'plain', 'PS_LAYERED_FILTER_PRICE_ROUNDING' => 'plain',
        'PS_LAYERED_FILTER_SHOW_OUT_OF_STOCK_LAST' => 'plain', 'PSR_HOOK_CHECKOUT' => 'plain', 'PSR_HOOK_FOOTER' => 'plain',
        'PSR_HOOK_HEADER' => 'plain', 'PSR_HOOK_PRODUCT' => 'plain', 'PSR_ICON_COLOR' => 'plain', 'PSR_TEXT_COLOR' => 'plain',
        'blockwishlist_CreateButtonLabel' => 'lang', 'blockwishlist_WishlistDefaultTitle' => 'lang',
        'blockwishlist_WishlistPageName' => 'lang', 'PRODUCT_COMMENTS_ALLOW_GUESTS' => 'plain',
        'PRODUCT_COMMENTS_MODERATE' => 'plain', 'PRODUCT_COMMENTS_USEFULNESS' => 'plain',
        'PRODUCT_COMMENTS_COMMENTS_PER_PAGE' => 'plain', 'PRODUCT_COMMENTS_MINIMAL_TIME' => 'plain',
        'PRODUCT_COMMENTS_ANONYMISATION' => 'plain',
    ];
    ksort($keys, SORT_STRING);
    return $keys;
}

/**
 * Sprawdza, czy nazwa pola wskazuje na sekret lub dane kontaktowe.
 *
 * Dotyczy nazw kluczy na każdym poziomie pliku. Allowlista nie zawiera takich kluczy, a kontrola chroni przed
 * przypadkowym dopisaniem ich w przyszłości. Flagi włączające wysyłkę wiadomości nie przechowują adresów i są
 * wyjątkami.
 *
 * @param string $key Nazwa pola.
 * @return bool True dla nazwy hasła, klucza, tokenu, soli lub adresu e-mail.
 */
function sensitiveKeyName(string $key): bool
{
    if (in_array($key, ['PS_CUSTOMER_CREATION_EMAIL', 'PS_LOG_EMAILS'], true)) {
        return false;
    }
    return (bool) preg_match('/passw|secret|token|salt|private|api_?key|cookie_?(key|iv)|dkim|credential|iban|swift/i', $key)
        || (bool) preg_match('/(^|_)KEY$|_RAND$|MAIL_USER$|(^|_)(E?MAILS?|MAIL_RECEIVERS|EMAIL_RECEIVERS)$/', $key);
}

/**
 * Zwraca znane sekrety bieżącej instalacji używane do kontroli wartości eksportu.
 *
 * Obejmuje klucze szyfrowania i hasło bazy z parameters.php, klucze Webservice, zmienne środowiskowe haseł
 * i klucza API oraz sole modułów. Krótkie wartości są pomijane, aby uniknąć fałszywych trafień.
 *
 * @return list<string> Wartości, których wystąpienie w pliku przerywa eksport i odtwarzanie.
 */
function knownSecretValues(): array
{
    $values = [];
    foreach (['_COOKIE_KEY_', '_COOKIE_IV_', '_NEW_COOKIE_KEY_', '_DB_PASSWD_', '_RIJNDAEL_KEY_', '_RIJNDAEL_IV_'] as $constant) {
        if (defined($constant)) {
            $values[] = (string) constant($constant);
        }
    }
    foreach (['PRESTASHOP_API_KEY', 'ADMIN_PASSWORD', 'ADMIN_PASSWD', 'DB_PASSWORD', 'DB_PASSWD', 'DB_ROOT_PASSWORD'] as $variable) {
        $value = getenv($variable);
        if (is_string($value)) {
            $values[] = $value;
        }
    }
    if (class_exists('\Db', false)) {
        $rows = \Db::getInstance()->executeS('SELECT `key` FROM ' . _DB_PREFIX_ . 'webservice_account', true, false);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $values[] = (string) $row['key'];
        }
        foreach (['NW_SALT', 'PS_NEWSLETTER_RAND', 'PS_MAIL_PASSWD', 'PS_MAIL_DKIM_KEY', 'MB_SECRET_WORD'] as $setting) {
            $values[] = (string) \Configuration::get($setting);
        }
    }
    return array_values(array_unique(array_filter($values, fn (string $value): bool => strlen(trim($value)) >= 12)));
}

/**
 * Sprawdza sumę kontrolną numeru PESEL, NIP lub karty płatniczej.
 *
 * @param string $digits Same cyfry kandydata.
 * @return string|null Nazwa rozpoznanego identyfikatora albo null.
 */
function personalNumberKind(string $digits): ?string
{
    $length = strlen($digits);
    if (trim($digits, $digits[0] ?? '0') === '') {
        return null;
    }
    if ($length === 11) {
        $weights = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }
        if ((10 - $sum % 10) % 10 === (int) $digits[10]) {
            return 'numer PESEL';
        }
    }
    if ($length === 10) {
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }
        if ($sum % 11 !== 10 && $sum % 11 === (int) $digits[9]) {
            return 'numer NIP';
        }
    }
    if ($length >= 13 && $length <= 19) {
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $digit) {
            $value = (int) $digit * ($i % 2 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }
        if ($sum % 10 === 0) {
            return 'numer karty płatniczej';
        }
    }
    return null;
}

/**
 * Sprawdza, czy struktura eksportu nie zawiera sekretów ani danych osobowych.
 *
 * Kontroluje nazwy kluczy oraz wartości tekstowe po zdekodowaniu encji HTML (także w atrybutach): adresy
 * e-mail, polskie i międzynarodowe numery telefonów, IBAN i długie ciągi cyfr, PESEL, NIP i numery kart
 * (z sumą kontrolną), tokeny i klucze API (w tym JWT i klucze z prefiksem) oraz wystąpienia znanych sekretów
 * instalacji. Nazwy plików w ścieżkach /img/ są pomijane przy wyszukiwaniu tokenów.
 *
 * @param mixed $data Struktura eksportu lub jej fragment.
 * @param list<string> $secrets Znane sekrety do wyszukania w wartościach.
 * @param string $path Ścieżka bieżącego fragmentu używana w komunikatach.
 * @return list<string> Opisy naruszeń bez ujawniania wartości.
 */
function configExportFindings($data, array $secrets = [], string $path = ''): array
{
    $findings = [];
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $child = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_string($key) && sensitiveKeyName($key)) {
                $findings[] = $child . ': nazwa pola wskazuje na sekret lub dane kontaktowe';
            }
            array_push($findings, ...configExportFindings($value, $secrets, $child));
        }
        return $findings;
    }
    if (!is_string($data) || $data === '') {
        return $findings;
    }
    // Encje są dekodowane w całym tekście, także w atrybutach (np. mailto:jan&#64;example.com).
    // Warianty są łączone znakiem nowej linii, którego wzorce nie przekraczają.
    $text = html_entity_decode(rawurldecode($data), ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n"
        . html_entity_decode(strip_tags($data), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
        'adres e-mail' => '/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)*\.[A-Z]{2,}/i',
        'numer rachunku IBAN' => '/\b[A-Z]{2}[0-9]{2}(?:[ ]?[0-9A-Z]{4}){3,7}(?:[ ]?[0-9A-Z]{1,4})?\b/',
        'długi ciąg cyfr (rachunek lub identyfikator)' => '/(?:\d[ \-]?){20,}/',
        'numer telefonu' => '/(?<![\d.,\/])(?:\+\d{2}[ \-]?|\(\d{2}\)[ \-]?)?(?:\d{3}[ \-]?\d{3}[ \-]?\d{3}|\d{2}[ \-]\d{3}[ \-]\d{2}[ \-]\d{2}|\d{3}[ \-]\d{2}[ \-]\d{2})(?![\d.,])/',
        'token lub klucz API' => '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}|\bAIza[0-9A-Za-z_\-]{30,}|\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
    ];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $text)) {
            $findings[] = $path . ': ' . $name;
        }
    }
    // Długie tokeny alfanumeryczne poza nazwami plików obrazów (np. skróty w /img/cms/).
    $withoutImages = (string) preg_replace('#/img/[^"\'\s)]+#', '', $text);
    if (preg_match('/(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]*\d)(?=[A-Za-z0-9_]*[A-Za-z])[A-Za-z0-9_]{32,}(?![A-Za-z0-9_])/', $withoutImages)) {
        $findings[] = $path . ': token lub klucz';
    }
    // Ciągi 10–19 cyfr (z separatorami) sprawdzane są sumą kontrolną PESEL, NIP i kart, aby nie odrzucać zwykłych liczb.
    if (preg_match_all('/(?<![\d.,])\d(?:[ \-]?\d){9,18}(?![\d.,])/', $text, $matches)) {
        foreach ($matches[0] as $candidate) {
            $kind = personalNumberKind((string) preg_replace('/\D/', '', $candidate));
            if ($kind !== null) {
                $findings[] = $path . ': ' . $kind;
            }
        }
    }
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($data, $secret)) {
            $findings[] = $path . ': wartość sekretu instalacji';
        }
    }
    return array_values(array_unique($findings));
}

/**
 * Przerywa operację, jeśli struktura eksportu zawiera sekret lub dane osobowe.
 *
 * Eksport sprawdza dodatkowo wystąpienia sekretów bieżącej instalacji. Odtwarzanie pliku z repozytorium
 * korzysta wyłącznie ze wzorców, bo sekrety innej instalacji nie mogą znaleźć się w pliku, a słabe lokalne
 * hasło mogłoby przypadkowo pasować do zwykłego tekstu i zablokować start.
 *
 * @param array $data Kompletny eksport.
 * @param bool $withInstallationSecrets Czy szukać wartości sekretów bieżącej instalacji.
 * @return void
 * @throws \RuntimeException Z listą ścieżek naruszeń, bez wartości.
 */
function assertConfigExportClean(array $data, bool $withInstallationSecrets = true): void
{
    $findings = configExportFindings($data, $withInstallationSecrets ? knownSecretValues() : []);
    if ($findings !== []) {
        throw new RuntimeException("Eksport konfiguracji zawiera dane, które nie mogą trafić do repozytorium:\n- "
            . implode("\n- ", array_unique($findings)));
    }
}

/**
 * Normalizuje wartość liczbową do stałej liczby miejsc po przecinku.
 *
 * @param mixed $value Wartość z bazy.
 * @param int $scale Liczba miejsc dziesiętnych.
 * @return string Liczba bez notacji wykładniczej.
 */
function exportDecimal($value, int $scale = 6): string
{
    return number_format((float) $value, $scale, '.', '');
}

/**
 * Wykonuje SELECT i zgłasza błąd odczytu zamiast zwracać false.
 *
 * @param string $sql Zapytanie zbudowane przez ten moduł.
 * @return list<array<string, mixed>> Wiersze wyniku.
 * @throws \RuntimeException Przy błędzie zapytania.
 */
function exportRows(string $sql): array
{
    $rows = \Db::getInstance()->executeS($sql, true, false);
    if ($rows === false) {
        throw new RuntimeException('Nie można odczytać konfiguracji: ' . \Db::getInstance()->getMsgError());
    }
    return $rows;
}

/**
 * Mapy ID na klucze naturalne i odwrotnie dla bieżącej instalacji.
 *
 * Klucze naturalne to ISO języków, walut i krajów, nazwy stref i grup podatkowych, role grup klientów,
 * link_rewrite stron CMS i klucze przewoźników SHOP_INIT_CARRIER_*. Mapy są budowane od nowa przy każdym
 * wywołaniu, bo odtwarzanie może tworzyć rekordy.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep.
 * @return array<string, array> Mapy id=>klucz w polach *_key oraz klucz=>id w polach *_id.
 * @throws \RuntimeException Przy niejednoznacznym kluczu naturalnym.
 */
function exportReferenceMaps(\Context $context): array
{
    $shopId = (int) $context->shop->id;
    $defaultLang = (int) \Configuration::get('PS_LANG_DEFAULT');
    $maps = [];
    $pairs = [
        'language' => exportRows('SELECT id_lang AS id, iso_code AS k FROM ' . _DB_PREFIX_ . 'lang'),
        'currency' => exportRows('SELECT id_currency AS id, iso_code AS k FROM ' . _DB_PREFIX_ . 'currency WHERE deleted=0'),
        'country' => exportRows('SELECT id_country AS id, iso_code AS k FROM ' . _DB_PREFIX_ . 'country'),
        'zone' => exportRows('SELECT id_zone AS id, name AS k FROM ' . _DB_PREFIX_ . 'zone'),
        'state' => exportRows('SELECT s.id_state AS id, CONCAT(c.iso_code, "-", s.iso_code) AS k FROM ' . _DB_PREFIX_
            . 'state s INNER JOIN ' . _DB_PREFIX_ . 'country c ON c.id_country=s.id_country'),
        'tax_group' => exportRows('SELECT id_tax_rules_group AS id, name AS k FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE deleted=0'),
        'cms' => exportRows('SELECT c.id_cms AS id, l.link_rewrite AS k FROM ' . _DB_PREFIX_ . 'cms c INNER JOIN '
            . _DB_PREFIX_ . 'cms_lang l ON l.id_cms=c.id_cms AND l.id_lang=' . $defaultLang . ' AND l.id_shop=' . $shopId),
        'cms_category' => exportRows('SELECT id_cms_category AS id, link_rewrite AS k FROM ' . _DB_PREFIX_
            . 'cms_category_lang WHERE id_lang=' . $defaultLang . ' AND id_shop=' . $shopId),
    ];
    // Grupy klientów instalatora mają stałe role. Pozostałe grupy identyfikuje nazwa w języku domyślnym.
    $groups = [];
    $roles = ['PS_UNIDENTIFIED_GROUP' => '@visitor', 'PS_GUEST_GROUP' => '@guest', 'PS_CUSTOMER_GROUP' => '@customer'];
    foreach (exportRows('SELECT id_group AS id, name AS k FROM ' . _DB_PREFIX_ . 'group_lang WHERE id_lang=' . $defaultLang) as $row) {
        $groups[(int) $row['id']] = ['id' => $row['id'], 'k' => (string) $row['k']];
    }
    foreach ($roles as $setting => $role) {
        $id = (int) \Configuration::get($setting);
        if (isset($groups[$id])) {
            $groups[$id]['k'] = $role;
        }
    }
    $pairs['group'] = array_values($groups);
    foreach ($pairs as $type => $rows) {
        $maps[$type . '_key'] = [];
        $maps[$type . '_id'] = [];
        foreach ($rows as $row) {
            $key = (string) $row['k'];
            if (isset($maps[$type . '_id'][$key])) {
                throw new RuntimeException('Niejednoznaczny klucz ' . $type . ': ' . $key . '. Nadaj unikalne nazwy przed eksportem.');
            }
            $maps[$type . '_key'][(int) $row['id']] = $key;
            $maps[$type . '_id'][$key] = (int) $row['id'];
        }
    }
    // Przewoźników identyfikują klucze SHOP_INIT_CARRIER_<KLUCZ>_REF zapisane przez inicjalizację lub odtwarzanie.
    $maps['carrier_reference'] = [];
    foreach (exportRows('SELECT name, value FROM ' . _DB_PREFIX_ . "configuration WHERE name LIKE 'SHOP\\_INIT\\_CARRIER\\_%\\_REF'") as $row) {
        if (preg_match('/^SHOP_INIT_CARRIER_(.+)_REF$/', (string) $row['name'], $match) && (int) $row['value'] > 0) {
            $maps['carrier_reference'][$match[1]] = (int) $row['value'];
        }
    }
    ksort($maps['carrier_reference']);
    $maps['carrier_key'] = [];
    foreach ($maps['carrier_reference'] as $key => $reference) {
        if (isset($maps['carrier_key'][$reference])) {
            throw new RuntimeException('Referencja przewoźnika ' . $reference . ' ma kilka kluczy SHOP_INIT_CARRIER_*.');
        }
        $maps['carrier_key'][$reference] = $key;
    }
    return $maps;
}

/**
 * Zwraca klucz naturalny obiektu albo zgłasza brak odwzorowania.
 *
 * @param array $maps Mapy z exportReferenceMaps.
 * @param string $type Typ odwołania.
 * @param int $id ID w bieżącej instalacji.
 * @return string Klucz naturalny.
 * @throws \RuntimeException Gdy ID nie ma klucza naturalnego.
 */
function exportKey(array $maps, string $type, int $id): string
{
    if (!isset($maps[$type . '_key'][$id])) {
        throw new RuntimeException('Brak klucza naturalnego dla ' . $type . ' ID ' . $id . '.');
    }
    return $maps[$type . '_key'][$id];
}

/**
 * Zwraca ID bieżącej instalacji dla klucza naturalnego albo zgłasza brak obiektu.
 *
 * @param array $maps Mapy z exportReferenceMaps.
 * @param string $type Typ odwołania.
 * @param string $key Klucz naturalny z pliku.
 * @return int ID obiektu.
 * @throws \RuntimeException Gdy obiekt nie istnieje.
 */
function exportId(array $maps, string $type, string $key): int
{
    if (!isset($maps[$type . '_id'][$key])) {
        throw new RuntimeException('W sklepie brakuje obiektu ' . $type . ' „' . $key . '” wymaganego przez eksport konfiguracji.');
    }
    return $maps[$type . '_id'][$key];
}

/**
 * Zwraca ID bieżącej wersji przewoźnika sklepu dla referencji.
 *
 * @param int $reference id_reference przewoźnika.
 * @param \Context $context Kontekst PrestaShop.
 * @param bool $includeDeleted Czy przy braku aktywnej wersji zwrócić ostatnią usuniętą (do przywrócenia).
 * @return int|null ID przewoźnika albo null.
 */
function carrierIdByReference(int $reference, \Context $context, bool $includeDeleted = false): ?int
{
    $query = new \DbQuery();
    $query->select('c.id_carrier AS id')->from('carrier', 'c');
    $query->innerJoin('carrier_shop', 's', 's.id_carrier=c.id_carrier');
    $query->where('c.id_reference=' . $reference)->where('c.deleted=0')->where('s.id_shop=' . (int) $context->shop->id);
    $id = uniqueId($query);
    if ($id !== null || !$includeDeleted) {
        return $id;
    }
    $deleted = (int) \Db::getInstance()->getValue('SELECT c.id_carrier FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN ' . _DB_PREFIX_
        . 'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.id_reference=' . $reference . ' AND s.id_shop=' . (int) $context->shop->id
        . ' ORDER BY c.id_carrier DESC', false);
    return $deleted ?: null;
}

/**
 * Uzupełnia pola wielojęzyczne dla języków spoza pliku wartością języka domyślnego z pliku.
 *
 * Dotyczy wyłącznie pustych pól (nowy obiekt lub nowy język), aby nie nadpisywać tłumaczeń z panelu.
 *
 * @param array $field Bieżące wartości id_lang => tekst.
 * @param array $values Wartości z pliku ISO => tekst.
 * @param array $maps Mapy odwołań.
 * @return array Wartości id_lang => tekst.
 */
function fillMissingLanguages(array $field, array $values, array $maps): array
{
    $fallback = $values[\Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT'))] ?? reset($values);
    foreach ($maps['language_id'] as $iso => $languageId) {
        if (!array_key_exists($iso, $values) && trim((string) ($field[$languageId] ?? '')) === '') {
            $field[$languageId] = $fallback;
        }
    }
    return $field;
}

/**
 * Przenosi odwołania do odtworzonej strony CMS na jej nowe ID.
 *
 * Bloki linków (stopka, „O firmie”), menu główne i treści w językach spoza pliku przechowują ID stron poza eksportem. Po ponownym
 * utworzeniu usuniętej strony wskazywałyby nieistniejący rekord.
 *
 * @param int $old Poprzednie ID strony.
 * @param int $new Nowe ID strony.
 * @param \Context $context Kontekst PrestaShop.
 * @return void
 * @throws \RuntimeException Przy błędzie zapisu.
 */
function remapCmsReferences(int $old, int $new, \Context $context): void
{
    $db = \Db::getInstance();
    $hasLinkBlocks = (bool) $db->getValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=\''
        . pSQL(_DB_PREFIX_ . 'link_block') . '\'', false);
    foreach ($hasLinkBlocks ? exportRows('SELECT id_link_block, content FROM ' . _DB_PREFIX_ . 'link_block') : [] as $row) {
        $content = json_decode((string) $row['content'], true);
        if (!is_array($content) || !is_array($content['cms'] ?? null) || !in_array((string) $old, array_map('strval', $content['cms']), true)) {
            continue;
        }
        $content['cms'] = array_map(fn ($id) => (string) $id === (string) $old ? (string) $new : $id, $content['cms']);
        if (!$db->update('link_block', ['content' => pSQL(json_encode($content))], 'id_link_block=' . (int) $row['id_link_block'])) {
            throw new RuntimeException('Nie można przenieść odnośnika bloku linków do strony CMS ' . $new);
        }
    }
    // Treści w językach spoza pliku nie są nadpisywane, więc ich odnośniki do strony przenosimy bezpośrednio.
    if (!$db->execute('UPDATE ' . _DB_PREFIX_ . "cms_lang SET content=REPLACE(content, '/content/" . $old . "-', '/content/" . $new . "-')")) {
        throw new RuntimeException('Nie można przenieść odnośników treści do strony CMS ' . $new);
    }
    $menu = (string) \Configuration::get('MOD_BLOCKTOPMENU_ITEMS');
    $items = explode(',', $menu);
    if (in_array('CMS' . $old, $items, true)) {
        saveShopSetting('MOD_BLOCKTOPMENU_ITEMS', implode(',', array_map(fn (string $item): string => $item === 'CMS' . $old ? 'CMS' . $new : $item, $items)), $context);
    }
}

/**
 * Przekształca wartość Configuration na postać z pliku eksportu.
 *
 * @param string $key Klucz Configuration.
 * @param string $type Typ z exportedConfigurationKeys.
 * @param array $maps Mapy odwołań.
 * @param \Context $context Kontekst PrestaShop.
 * @return mixed Wartość skalarna, mapa ISO języka => tekst albo klucz naturalny.
 * @throws \RuntimeException Przy odwołaniu do nieistniejącego obiektu.
 */
function exportConfigurationValue(string $key, string $type, array $maps, \Context $context)
{
    if ($type === 'lang') {
        $values = [];
        foreach ($maps['language_key'] as $id => $iso) {
            $value = \Configuration::get($key, $id);
            $values[$iso] = $value === false || $value === null ? null : (string) $value;
        }
        ksort($values);
        return $values;
    }
    $value = \Configuration::get($key);
    if ($value === false || $value === null) {
        return null;
    }
    $value = (string) $value;
    if ($type === 'plain') {
        return $value;
    }
    if ($type === 'carrier') {
        // Wartości ujemne oznaczają natywne strategie wyboru, a nie konkretnego przewoźnika.
        if ((int) $value < 0) {
            return '@strategy' . (int) $value;
        }
        $carrier = new \Carrier((int) $value);
        if (!\Validate::isLoadedObject($carrier) || !isset($maps['carrier_key'][(int) $carrier->id_reference])) {
            throw new RuntimeException('Domyślny przewoźnik ' . $value . ' nie ma klucza SHOP_INIT_CARRIER_*. Wyeksportuj przewoźników.');
        }
        return $maps['carrier_key'][(int) $carrier->id_reference];
    }
    if ((int) $value === 0) {
        return null;
    }
    return exportKey($maps, $type, (int) $value);
}

/**
 * Odczytuje przewoźnika do postaci eksportu.
 *
 * @param array $row Wiersz ps_carrier.
 * @param array $maps Mapy odwołań.
 * @param \Context $context Kontekst PrestaShop.
 * @param bool $strict Przy true niejednoznaczna taryfa przerywa eksport, przy false jest oznaczana jako różnica.
 * @return array Ustawienia, strefy, grupy, podatek i zakresy z cenami netto według stref.
 * @throws \RuntimeException W trybie ścisłym przy niejednoznacznej taryfie.
 */
function exportCarrier(array $row, array $maps, \Context $context, bool $strict = true): array
{
    $id = (int) $row['id_carrier'];
    $shopId = (int) $context->shop->id;
    $delays = [];
    foreach (exportRows('SELECT id_lang, delay FROM ' . _DB_PREFIX_ . 'carrier_lang WHERE id_carrier=' . $id . ' AND id_shop=' . $shopId) as $lang) {
        $delays[exportKey($maps, 'language', (int) $lang['id_lang'])] = (string) $lang['delay'];
    }
    ksort($delays);
    $taxGroup = (int) \Db::getInstance()->getValue('SELECT id_tax_rules_group FROM ' . _DB_PREFIX_
        . 'carrier_tax_rules_group_shop WHERE id_carrier=' . $id . ' AND id_shop=' . $shopId, false);
    $zones = array_map(fn (array $zone): string => exportKey($maps, 'zone', (int) $zone['id_zone']),
        exportRows('SELECT id_zone FROM ' . _DB_PREFIX_ . 'carrier_zone WHERE id_carrier=' . $id));
    sort($zones, SORT_STRING);
    $groups = array_map(fn (array $group): string => exportKey($maps, 'group', (int) $group['id_group']),
        exportRows('SELECT id_group FROM ' . _DB_PREFIX_ . 'carrier_group WHERE id_carrier=' . $id));
    sort($groups, SORT_STRING);
    $ranges = [];
    foreach (['weight', 'price'] as $kind) {
        $list = [];
        foreach (exportRows('SELECT id_range_' . $kind . ' AS id, delimiter1, delimiter2 FROM ' . _DB_PREFIX_ . 'range_' . $kind
            . ' WHERE id_carrier=' . $id . ' ORDER BY delimiter1, delimiter2, id_range_' . $kind) as $range) {
            $prices = [];
            $priority = [];
            // Kolejność odpowiada Carrier::sqlDeliveryRangeShop: wiersz sklepu wygrywa z wierszem grupy i globalnym.
            // Dwa wiersze o tym samym priorytecie i różnych cenach dają nieprzewidywalną taryfę i przerywają eksport.
            foreach (exportRows('SELECT id_zone, price, id_shop, id_shop_group FROM ' . _DB_PREFIX_ . 'delivery WHERE id_carrier=' . $id
                . ' AND id_range_' . $kind . '=' . (int) $range['id'] . ' AND (id_shop=' . $shopId . ' OR (id_shop IS NULL AND (id_shop_group IS NULL OR id_shop_group='
                . (int) $context->shop->id_shop_group . '))) ORDER BY id_shop DESC, id_shop_group DESC, id_delivery') as $delivery) {
                $zone = exportKey($maps, 'zone', (int) $delivery['id_zone']);
                $rank = [(int) $delivery['id_shop'], (int) $delivery['id_shop_group']];
                if (!isset($prices[$zone])) {
                    $prices[$zone] = exportDecimal($delivery['price']);
                    $priority[$zone] = $rank;
                } elseif ($priority[$zone] === $rank && $prices[$zone] !== exportDecimal($delivery['price'])) {
                    if (!$strict) {
                        // Odtwarzanie traktuje niejednoznaczną taryfę jako różnicę i zastępuje ją cenami z pliku.
                        $prices[$zone] = 'niejednoznaczna';
                        continue;
                    }
                    throw new RuntimeException('Przewoźnik ' . $row['name'] . ' ma niejednoznaczne ceny w strefie ' . $zone
                        . '. Zapisz taryfę w panelu i powtórz eksport.');
                }
            }
            ksort($prices, SORT_STRING);
            $list[] = ['from' => exportDecimal($range['delimiter1']), 'to' => exportDecimal($range['delimiter2']), 'prices' => $prices];
        }
        $ranges[$kind] = $list;
    }
    return [
        'name' => (string) $row['name'],
        'active' => (bool) $row['active'],
        'is_free' => (bool) $row['is_free'],
        'shipping_handling' => (bool) $row['shipping_handling'],
        'shipping_method' => (int) $row['shipping_method'],
        'range_behavior' => (bool) $row['range_behavior'],
        'max_weight' => exportDecimal($row['max_weight']),
        'max_width' => (int) $row['max_width'],
        'max_height' => (int) $row['max_height'],
        'max_depth' => (int) $row['max_depth'],
        'grade' => (int) $row['grade'],
        'position' => (int) $row['position'],
        'url' => (string) $row['url'],
        'delay' => $delays,
        'tax_rules_group' => $taxGroup ? exportKey($maps, 'tax_group', $taxGroup) : null,
        'zones' => $zones,
        'groups' => $groups,
        'weight_ranges' => $ranges['weight'],
        'price_ranges' => $ranges['price'],
    ];
}

/**
 * Zwraca bazowy adres sklepu używany w treściach CMS.
 *
 * @return string Adres zakończony ukośnikiem, np. https://localhost/.
 */
function shopBaseUrl(): string
{
    return rtrim(\Tools::getShopDomainSsl(true) . __PS_BASE_URI__, '/') . '/';
}

/**
 * Zastępuje w treści CMS adresy zależne od instalacji znacznikami.
 *
 * Odnośnik do strony CMS (zawiera jej ID, opcjonalnie z prefiksem języka) staje się {{cms:link_rewrite}}, a adres
 * sklepu {{shop_url}}.
 * Dzięki temu treść nie zależy od ID stron ani domeny i pozostaje poprawna po odtworzeniu w innej instalacji.
 *
 * @param string $html Treść strony.
 * @param array $maps Mapy odwołań.
 * @return string Treść ze znacznikami.
 */
function cmsContentToExport(string $html, array $maps): string
{
    $base = shopBaseUrl();
    $html = (string) preg_replace_callback('#' . preg_quote($base, '#') . '(?:[a-z]{2}(?:-[a-z]{2})?/)?content/(\d+)-[A-Za-z0-9\-]+#',
        fn (array $match): string => isset($maps['cms_key'][(int) $match[1]]) ? '{{cms:' . $maps['cms_key'][(int) $match[1]] . '}}' : $match[0], $html);
    return str_replace($base, '{{shop_url}}', $html);
}

/**
 * Zastępuje znaczniki z pliku adresami bieżącej instalacji.
 *
 * @param string $html Treść ze znacznikami.
 * @param array $maps Mapy odwołań po zapisaniu stron.
 * @param bool $strict Przy true brakująca strona przerywa operację, przy false znacznik pozostaje.
 * @return string Treść z adresami.
 * @throws \RuntimeException W trybie ścisłym, gdy znacznik wskazuje nieistniejącą stronę.
 */
function cmsContentFromExport(string $html, array $maps, bool $strict): string
{
    $link = \Context::getContext()->link;
    $html = (string) preg_replace_callback('/\{\{cms:([A-Za-z0-9\-]+)\}\}/', function (array $match) use ($maps, $link, $strict): string {
        if (!isset($maps['cms_id'][$match[1]])) {
            if ($strict) {
                throw new RuntimeException('Treść CMS odwołuje się do nieistniejącej strony ' . $match[1]);
            }
            return $match[0];
        }
        return $link->getCMSLink((int) $maps['cms_id'][$match[1]]);
    }, $html);
    return str_replace('{{shop_url}}', shopBaseUrl(), $html);
}

/**
 * Tworzy kompletny eksport konfiguracji bieżącego sklepu.
 *
 * Wynik jest deterministyczny: klucze są sortowane, liczby mają stałą precyzję, a plik nie zawiera daty
 * eksportu ani ID instalacji. Dzięki temu ponowny eksport bez zmian daje identyczny plik i czytelny diff.
 *
 * @param \Context $context Kontekst PrestaShop wyznaczający sklep.
 * @param bool $strict Przy false niejednoznaczne taryfy są oznaczane zamiast przerywać odczyt (porównanie przed odtworzeniem).
 * @return array Struktura gotowa do encodeConfigExport.
 * @throws \RuntimeException Przy odwołaniach bez klucza naturalnego lub błędzie odczytu.
 */
function exportShopConfiguration(\Context $context, bool $strict = true): array
{
    $maps = exportReferenceMaps($context);
    $shopId = (int) $context->shop->id;
    $data = ['format' => CONFIG_EXPORT_FORMAT, 'prestashop' => _PS_VERSION_];

    $data['languages'] = [];
    foreach (exportRows('SELECT iso_code, active FROM ' . _DB_PREFIX_ . 'lang ORDER BY iso_code') as $row) {
        $data['languages'][(string) $row['iso_code']] = ['active' => (bool) $row['active']];
    }
    $data['currencies'] = [];
    foreach (exportRows('SELECT iso_code, active, conversion_rate, `precision` FROM ' . _DB_PREFIX_
        . 'currency WHERE deleted=0 ORDER BY iso_code') as $row) {
        $data['currencies'][(string) $row['iso_code']] = ['active' => (bool) $row['active'],
            'conversion_rate' => exportDecimal($row['conversion_rate']), 'precision' => (int) $row['precision']];
    }
    $data['countries'] = ['active' => [], 'zones' => []];
    foreach (exportRows('SELECT iso_code, id_zone FROM ' . _DB_PREFIX_ . 'country WHERE active=1 ORDER BY iso_code') as $row) {
        $data['countries']['active'][] = (string) $row['iso_code'];
        $data['countries']['zones'][(string) $row['iso_code']] = exportKey($maps, 'zone', (int) $row['id_zone']);
    }
    // Sposób wyświetlania cen (brutto lub netto) jest ustawieniem podatkowym grupy klientów.
    $data['customer_groups'] = [];
    foreach (exportRows('SELECT id_group, price_display_method, show_prices FROM ' . _DB_PREFIX_ . 'group ORDER BY id_group') as $row) {
        $data['customer_groups'][exportKey($maps, 'group', (int) $row['id_group'])] = [
            'price_display_method' => (int) $row['price_display_method'], 'show_prices' => (bool) $row['show_prices']];
    }
    ksort($data['customer_groups'], SORT_STRING);

    $data['taxes'] = [];
    foreach (exportRows('SELECT id_tax_rules_group, name, active FROM ' . _DB_PREFIX_ . 'tax_rules_group WHERE deleted=0 ORDER BY name') as $group) {
        $rules = [];
        foreach (exportRows('SELECT r.id_country, r.id_state, r.zipcode_from, r.zipcode_to, r.behavior, r.description, t.rate FROM '
            . _DB_PREFIX_ . 'tax_rule r INNER JOIN ' . _DB_PREFIX_ . 'tax t ON t.id_tax=r.id_tax WHERE r.id_tax_rules_group='
            . (int) $group['id_tax_rules_group']) as $rule) {
            $rules[] = ['country' => exportKey($maps, 'country', (int) $rule['id_country']),
                'state' => (int) $rule['id_state'] ? exportKey($maps, 'state', (int) $rule['id_state']) : null,
                'zipcode_from' => (string) $rule['zipcode_from'], 'zipcode_to' => (string) $rule['zipcode_to'],
                'behavior' => (int) $rule['behavior'], 'description' => (string) $rule['description'],
                'rate' => exportDecimal($rule['rate'], 3)];
        }
        usort($rules, fn (array $a, array $b): int => [$a['country'], (string) $a['state'], $a['zipcode_from'], $a['zipcode_to']]
            <=> [$b['country'], (string) $b['state'], $b['zipcode_from'], $b['zipcode_to']]);
        $data['taxes'][(string) $group['name']] = ['active' => (bool) $group['active'], 'rules' => $rules];
    }

    // Moduł jest włączony, gdy ma powiązanie module_shop z bieżącym sklepem.
    $data['modules'] = [];
    foreach (exportRows('SELECT m.name, (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module_shop s WHERE s.id_module=m.id_module AND s.id_shop='
        . $shopId . ') AS enabled FROM ' . _DB_PREFIX_ . 'module m ORDER BY m.name') as $row) {
        $data['modules'][(string) $row['name']] = ['enabled' => (int) $row['enabled'] > 0];
    }
    $data['theme'] = ['name' => (string) $context->shop->theme_name];

    $data['cms'] = [];
    $initKeys = [];
    foreach (exportRows('SELECT name, value FROM ' . _DB_PREFIX_ . "configuration WHERE name LIKE 'SHOP\\_INIT\\_CMS\\_%'") as $row) {
        $initKeys[(int) $row['value']] = substr((string) $row['name'], strlen('SHOP_INIT_CMS_'));
    }
    foreach (exportRows('SELECT c.id_cms, c.id_cms_category, c.active, c.indexation, c.position FROM ' . _DB_PREFIX_ . 'cms c INNER JOIN '
        . _DB_PREFIX_ . 'cms_shop s ON s.id_cms=c.id_cms AND s.id_shop=' . $shopId . ' ORDER BY c.id_cms') as $page) {
        $id = (int) $page['id_cms'];
        $langs = [];
        foreach (exportRows('SELECT id_lang, meta_title, head_seo_title, meta_description, meta_keywords, content, link_rewrite FROM '
            . _DB_PREFIX_ . 'cms_lang WHERE id_cms=' . $id . ' AND id_shop=' . $shopId) as $lang) {
            $iso = exportKey($maps, 'language', (int) $lang['id_lang']);
            unset($lang['id_lang']);
            $langs[$iso] = array_map(fn ($value): string => (string) $value, $lang);
            $langs[$iso]['content'] = cmsContentToExport($langs[$iso]['content'], $maps);
        }
        ksort($langs);
        $data['cms'][exportKey($maps, 'cms', $id)] = [
            'init_key' => $initKeys[$id] ?? null,
            'category' => exportKey($maps, 'cms_category', (int) $page['id_cms_category']),
            'active' => (bool) $page['active'], 'indexation' => (bool) $page['indexation'],
            'position' => (int) $page['position'], 'lang' => $langs,
        ];
    }
    ksort($data['cms'], SORT_STRING);

    $data['carriers'] = [];
    foreach (exportRows('SELECT c.* FROM ' . _DB_PREFIX_ . 'carrier c INNER JOIN ' . _DB_PREFIX_ . 'carrier_shop s ON s.id_carrier=c.id_carrier'
        . ' WHERE c.deleted=0 AND s.id_shop=' . $shopId . ' ORDER BY c.id_reference') as $row) {
        if ((bool) $row['is_module']) {
            throw new RuntimeException('Przewoźnik modułu ' . $row['external_module_name'] . ' nie jest obsługiwany przez eksport.');
        }
        // Przewoźnik bez klucza inicjalizacji otrzymuje klucz od trwałej referencji z chwili eksportu.
        $key = $maps['carrier_key'][(int) $row['id_reference']] ?? 'CUSTOM_' . (int) $row['id_reference'];
        if (isset($data['carriers'][$key])) {
            throw new RuntimeException('Dwóch przewoźników ma klucz ' . $key . '. Usuń zbędny wpis SHOP_INIT_CARRIER_' . $key
                . '_REF albo przewoźnika i powtórz eksport.');
        }
        $data['carriers'][$key] = exportCarrier($row, $maps, $context, $strict);
    }
    ksort($data['carriers'], SORT_STRING);
    $carrierKeys = $maps['carrier_key'];
    foreach ($data['carriers'] as $key => $carrier) {
        if (str_starts_with($key, 'CUSTOM_')) {
            $carrierKeys[(int) substr($key, 7)] = $key;
        }
    }

    $data['payments'] = [];
    foreach (exportRows('SELECT DISTINCT m.id_module, m.name FROM ' . _DB_PREFIX_ . 'module m INNER JOIN ' . _DB_PREFIX_
        . 'hook_module hm ON hm.id_module=m.id_module INNER JOIN ' . _DB_PREFIX_ . "hook h ON h.id_hook=hm.id_hook WHERE h.name='paymentOptions'"
        . ' ORDER BY m.name') as $module) {
        $id = (int) $module['id_module'];
        $where = ' WHERE id_module=' . $id . ' AND id_shop=' . $shopId;
        $countries = array_map(fn (array $r): string => exportKey($maps, 'country', (int) $r['id_country']), exportRows('SELECT id_country FROM ' . _DB_PREFIX_ . 'module_country' . $where));
        $currencies = array_map(fn (array $r): string => (int) $r['id_currency'] < 0 ? '@special' . (int) $r['id_currency']
            : exportKey($maps, 'currency', (int) $r['id_currency']), exportRows('SELECT id_currency FROM ' . _DB_PREFIX_ . 'module_currency' . $where));
        $groups = array_map(fn (array $r): string => exportKey($maps, 'group', (int) $r['id_group']), exportRows('SELECT id_group FROM ' . _DB_PREFIX_ . 'module_group' . $where));
        // Uprawnienie do referencji usuniętego przewoźnika nie ma znaczenia dla checkoutu i jest pomijane.
        $carriers = [];
        foreach (exportRows('SELECT id_reference FROM ' . _DB_PREFIX_ . 'module_carrier' . $where) as $r) {
            if (isset($carrierKeys[(int) $r['id_reference']]) && isset($data['carriers'][$carrierKeys[(int) $r['id_reference']]])) {
                $carriers[] = $carrierKeys[(int) $r['id_reference']];
            }
        }
        foreach ([&$countries, &$currencies, &$groups, &$carriers] as &$list) {
            $list = array_values(array_unique($list));
            sort($list, SORT_STRING);
        }
        unset($list);
        $data['payments'][(string) $module['name']] = compact('countries', 'currencies', 'groups', 'carriers');
    }

    $data['configuration'] = [];
    foreach (exportedConfigurationKeys() as $key => $type) {
        if (!\Configuration::hasKey($key) && !\Configuration::hasKey($key, (int) \Configuration::get('PS_LANG_DEFAULT'))) {
            continue;
        }
        if (($type === 'lang') !== (bool) \Configuration::isLangKey($key)) {
            throw new RuntimeException('Klucz ' . $key . ' jest wielojęzyczny, a allowlista oznacza go jako ' . $type . '.');
        }
        $value = exportConfigurationValue($key, $type, ['carrier_key' => $carrierKeys] + $maps, $context);
        $data['configuration'][$key] = $value;
    }
    return $data;
}

/**
 * Koduje eksport do stabilnego JSON z końcowym znakiem nowej linii.
 *
 * @param array $data Struktura eksportu.
 * @return string Treść pliku.
 * @throws \JsonException Przy błędzie kodowania.
 */
function encodeConfigExport(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Wczytuje i waliduje plik eksportu z repozytorium.
 *
 * Odrzuca kodowanie inne niż UTF-8 (np. UTF-16 z przekierowania w PowerShell), nieznaną wersję formatu,
 * brakujące sekcje, klucze Configuration spoza allowlisty oraz wartości wyglądające na sekrety lub dane
 * osobowe.
 *
 * @param string $path Ścieżka pliku.
 * @return array{data: array, sha256: string} Struktura i odcisk treści pliku.
 * @throws \RuntimeException Przy nieczytelnym lub niepoprawnym pliku.
 * @throws \JsonException Przy niepoprawnym JSON.
 */
function readConfigExport(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Brak pliku eksportu konfiguracji: ' . $path . '. Przygotuj go poleceniem export-config.php.');
    }
    $content = (string) file_get_contents($path);
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }
    if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF") || !mb_check_encoding($content, 'UTF-8')) {
        throw new RuntimeException('Plik eksportu musi być zapisany w UTF-8. W PowerShell użyj --output i docker compose cp zamiast przekierowania >.');
    }
    $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['format'] ?? null) !== CONFIG_EXPORT_FORMAT) {
        throw new RuntimeException('Nieobsługiwany format pliku eksportu konfiguracji. Oczekiwano format=' . CONFIG_EXPORT_FORMAT . '.');
    }
    foreach (CONFIG_EXPORT_SECTIONS as $section) {
        if (!isset($data[$section]) || !is_array($data[$section])) {
            throw new RuntimeException('Plik eksportu nie zawiera sekcji ' . $section . '.');
        }
    }
    $unknown = array_diff(array_keys($data['configuration']), array_keys(exportedConfigurationKeys()));
    if ($unknown !== []) {
        throw new RuntimeException('Plik eksportu zawiera klucze spoza allowlisty: ' . implode(', ', $unknown) . '.');
    }
    if (!is_array($data['countries']['active'] ?? null) || !is_array($data['countries']['zones'] ?? null)) {
        throw new RuntimeException('Sekcja countries wymaga list active i zones.');
    }
    assertConfigExportClean($data, false);
    return ['data' => $data, 'sha256' => hash_file('sha256', $path)];
}

/**
 * Ogranicza wartości wielojęzyczne bieżącego sklepu do języków z pliku.
 *
 * Język dodany w panelu po eksporcie nie jest różnicą dla odtwarzania. Jego teksty pozostają bez zmian, a
 * porównanie dotyczy wyłącznie języków zapisanych w pliku.
 *
 * @param array $current Eksport bieżącego sklepu.
 * @param list<string> $languages ISO języków z pliku.
 * @return array Eksport z wartościami tylko dla wskazanych języków.
 */
function restrictConfigExportLanguages(array $current, array $languages): array
{
    $keep = array_flip($languages);
    foreach ($current['configuration'] ?? [] as $key => $value) {
        if (is_array($value)) {
            $current['configuration'][$key] = array_intersect_key($value, $keep);
        }
    }
    foreach ($current['cms'] ?? [] as $key => $page) {
        $current['cms'][$key]['lang'] = array_intersect_key($page['lang'], $keep);
    }
    foreach ($current['carriers'] ?? [] as $key => $carrier) {
        $current['carriers'][$key]['delay'] = array_intersect_key($carrier['delay'], $keep);
    }
    return $current;
}

/**
 * Zwraca listę elementów, które różnią się między sklepem a plikiem.
 *
 * Odtwarzanie porównuje wyłącznie elementy obecne w pliku i języki zapisane w pliku. Obiekty istniejące
 * tylko w sklepie (np. dodatkowe strony CMS, moduły, języki) nie są różnicą i pozostają bez zmian. Wyjątkiem
 * są aktywne kraje, porównywane w całości. Tryb symetryczny (export-config.php --check) raportuje także
 * elementy obecne tylko w sklepie, czyli sygnał, że plik wymaga ponownego eksportu.
 *
 * @param array $current Eksport bieżącego sklepu.
 * @param array $desired Zawartość pliku.
 * @param bool $symmetric Czy raportować elementy obecne tylko w sklepie.
 * @return list<string> Ścieżki w postaci sekcja/element.
 */
function configExportDifferences(array $current, array $desired, bool $symmetric = false): array
{
    if (!$symmetric) {
        $current = restrictConfigExportLanguages($current, array_keys($desired['languages']));
    }
    $differences = [];
    foreach (CONFIG_EXPORT_SECTIONS as $section) {
        if ($section === 'countries' || $section === 'theme') {
            if (($current[$section] ?? null) !== $desired[$section]) {
                $differences[] = $section;
            }
            continue;
        }
        foreach ($desired[$section] as $key => $value) {
            if (!array_key_exists($key, $current[$section] ?? []) || $current[$section][$key] !== $value) {
                $differences[] = $section . '/' . $key;
            }
        }
        if ($symmetric) {
            foreach (array_diff_key($current[$section] ?? [], $desired[$section]) as $key => $value) {
                $differences[] = $section . '/' . $key . ' (tylko w sklepie)';
            }
        }
    }
    return $differences;
}

/**
 * Sprawdza, czy bieżący plik eksportu został już zastosowany w tej instalacji.
 *
 * @param \Context $context Kontekst PrestaShop.
 * @param string|null $path Ścieżka pliku, domyślnie configExportPath().
 * @return bool True, gdy zapisany odcisk odpowiada treści pliku.
 */
function configExportApplied(\Context $context, ?string $path = null): bool
{
    $path ??= configExportPath();
    return is_file($path) && hash_file('sha256', $path) === (string) \Configuration::get(CONFIG_EXPORT_APPLIED_SETTING);
}

/**
 * Uruchamia kernel Symfony wymagany do instalacji modułu z CLI.
 *
 * @return void
 */
function bootModuleKernel(): void
{
    global $kernel;
    if (!isset($kernel) || !$kernel) {
        require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
        $kernel = new \AppKernel('prod', false);
        $kernel->boot();
    }
}

/**
 * Zapisuje reguły grupy podatkowej, tworząc brakującą grupę lub podatek o danej stawce.
 *
 * Reguły grupy są zastępowane w całości. Podatek o wymaganej stawce jest wyszukiwany wśród nieusuniętych
 * podatków, a w razie braku tworzony z nazwą „VAT <stawka>%”.
 *
 * @param string $name Nazwa grupy reguł.
 * @param array $group Definicja z pliku.
 * @param array $maps Mapy odwołań.
 * @return void
 * @throws \RuntimeException Przy błędzie zapisu.
 */
function applyTaxGroup(string $name, array $group, array $maps): void
{
    $db = \Db::getInstance();
    $taxGroup = new \TaxRulesGroup($maps['tax_group_id'][$name] ?? null);
    $taxGroup->name = $name;
    $taxGroup->active = (bool) $group['active'];
    if (!$taxGroup->save()) {
        throw new RuntimeException('Nie można zapisać grupy podatkowej ' . $name);
    }
    if (!$db->delete('tax_rule', 'id_tax_rules_group=' . (int) $taxGroup->id)) {
        throw new RuntimeException('Nie można usunąć reguł grupy podatkowej ' . $name);
    }
    foreach ($group['rules'] as $rule) {
        $taxId = (int) $db->getValue('SELECT id_tax FROM ' . _DB_PREFIX_ . 'tax WHERE deleted=0 AND rate=' . (float) $rule['rate']
            . ' ORDER BY active DESC, id_tax', false);
        if (!$taxId) {
            $tax = new \Tax();
            $tax->rate = (float) $rule['rate'];
            $tax->active = true;
            foreach ($maps['language_id'] as $languageId) {
                $tax->name[$languageId] = 'VAT ' . rtrim(rtrim($rule['rate'], '0'), '.') . '%';
            }
            if (!$tax->add()) {
                throw new RuntimeException('Nie można utworzyć podatku ' . $rule['rate'] . '%');
            }
            $taxId = (int) $tax->id;
        }
        $taxRule = new \TaxRule();
        $taxRule->id_tax_rules_group = (int) $taxGroup->id;
        $taxRule->id_country = exportId($maps, 'country', $rule['country']);
        $taxRule->id_state = $rule['state'] === null ? 0 : exportId($maps, 'state', $rule['state']);
        $taxRule->zipcode_from = $rule['zipcode_from'];
        $taxRule->zipcode_to = $rule['zipcode_to'];
        $taxRule->behavior = (int) $rule['behavior'];
        $taxRule->description = $rule['description'];
        $taxRule->id_tax = $taxId;
        if (!$taxRule->add()) {
            throw new RuntimeException('Nie można zapisać reguły podatkowej ' . $name . ' / ' . $rule['country']);
        }
    }
}

/**
 * Wyszukuje lub tworzy przewoźnika dla klucza z pliku i aktualizuje jego ustawienia.
 *
 * Istniejący przewoźnik jest rozpoznawany przez SHOP_INIT_CARRIER_<KLUCZ>_REF. Klucz CUSTOM_<ref> bez zapisu
 * w konfiguracji może wskazać istniejącą referencję tylko przy zgodnej nazwie, w przeciwnym razie powstaje
 * nowy przewoźnik. Zakresy i ceny są zastępowane, gdy różnią się od pliku.
 *
 * @param string $key Klucz przewoźnika.
 * @param array $desired Definicja z pliku.
 * @param array|null $current Bieżąca definicja albo null dla nowego przewoźnika.
 * @param array $maps Mapy odwołań.
 * @param \Context $context Kontekst PrestaShop.
 * @return void
 * @throws \RuntimeException Przy błędzie zapisu.
 */
function applyCarrier(string $key, array $desired, ?array $current, array $maps, \Context $context): void
{
    $shopId = (int) $context->shop->id;
    $reference = $maps['carrier_reference'][$key] ?? null;
    // Klucz CUSTOM_<ref> bez zapisu w konfiguracji wskazuje referencję z instalacji, w której powstał eksport,
    // o ile nie należy ona do innego klucza. Nazwa może różnić się po edycji w panelu.
    if ($reference === null && preg_match('/^CUSTOM_(\d+)$/', $key, $match) && !isset($maps['carrier_key'][(int) $match[1]])
        && carrierIdByReference((int) $match[1], $context, true) !== null) {
        $reference = (int) $match[1];
    }
    // Usunięty przewoźnik jest przywracany z tą samą referencją, więc powiązania produktów i płatności pozostają ważne.
    $id = $reference === null ? null : carrierIdByReference($reference, $context, true);
    $carrier = new \Carrier($id);
    $carrier->id_shop_list = [$shopId];
    foreach (['name', 'url'] as $field) {
        $carrier->$field = $desired[$field];
    }
    foreach (['active', 'is_free', 'shipping_handling', 'range_behavior'] as $field) {
        $carrier->$field = (bool) $desired[$field];
    }
    foreach (['shipping_method', 'max_width', 'max_height', 'max_depth', 'grade', 'position'] as $field) {
        $carrier->$field = (int) $desired[$field];
    }
    $carrier->max_weight = (float) $desired['max_weight'];
    $carrier->deleted = false;
    $carrier->is_module = false;
    $carrier->shipping_external = false;
    $carrier->external_module_name = '';
    foreach ($desired['delay'] as $iso => $delay) {
        $carrier->delay[exportId($maps, 'language', $iso)] = $delay;
    }
    $carrier->delay = fillMissingLanguages((array) $carrier->delay, $desired['delay'], $maps);
    if (!($id === null ? $carrier->add() : $carrier->update())) {
        throw new RuntimeException('Nie można zapisać przewoźnika ' . $key);
    }
    $carrier = new \Carrier((int) $carrier->id);
    saveShopSetting('SHOP_INIT_CARRIER_' . $key . '_REF', (int) $carrier->id_reference, $context);
    $taxGroup = $desired['tax_rules_group'] === null ? 0 : exportId($maps, 'tax_group', $desired['tax_rules_group']);
    if (!$carrier->setTaxRulesGroup($taxGroup)) {
        throw new RuntimeException('Nie można zapisać podatku przewoźnika ' . $key);
    }
    $groups = array_map(fn (string $group): int => exportId($maps, 'group', $group), $desired['groups']);
    if (!$carrier->setGroups($groups)) {
        throw new RuntimeException('Nie można zapisać grup przewoźnika ' . $key);
    }
    $zones = array_map(fn (string $zone): int => exportId($maps, 'zone', $zone), $desired['zones']);
    foreach (exportRows('SELECT id_zone FROM ' . _DB_PREFIX_ . 'carrier_zone WHERE id_carrier=' . (int) $carrier->id) as $row) {
        if (!in_array((int) $row['id_zone'], $zones, true) && !$carrier->deleteZone((int) $row['id_zone'])) {
            throw new RuntimeException('Nie można usunąć strefy przewoźnika ' . $key);
        }
    }
    foreach ($zones as $zone) {
        if (!$carrier->getZone($zone) && !$carrier->addZone($zone)) {
            throw new RuntimeException('Nie można przypisać strefy przewoźnika ' . $key);
        }
    }
    if ($current !== null && $current['weight_ranges'] === $desired['weight_ranges'] && $current['price_ranges'] === $desired['price_ranges']) {
        return;
    }
    // Zakresy i ceny są zastępowane razem, ponieważ wiersze delivery wskazują ID zakresów.
    $db = \Db::getInstance();
    $carrierId = (int) $carrier->id;
    if (!$db->delete('delivery', 'id_carrier=' . $carrierId) || !$db->delete('range_weight', 'id_carrier=' . $carrierId)
        || !$db->delete('range_price', 'id_carrier=' . $carrierId)) {
        throw new RuntimeException('Nie można usunąć taryf przewoźnika ' . $key);
    }
    foreach (['weight' => '\RangeWeight', 'price' => '\RangePrice'] as $kind => $class) {
        foreach ($desired[$kind . '_ranges'] as $definition) {
            $range = new $class();
            $range->id_carrier = $carrierId;
            $range->delimiter1 = $definition['from'];
            $range->delimiter2 = $definition['to'];
            // Range*::add tworzy zerowe ceny dla stref przewoźnika. Usuwamy je przed zapisem cen z pliku,
            // aby dla strefy i zakresu pozostał jeden wiersz.
            if (!$range->add() || !$db->delete('delivery', 'id_carrier=' . $carrierId . ' AND id_range_' . $kind . '=' . (int) $range->id)) {
                throw new RuntimeException('Nie można zapisać zakresu przewoźnika ' . $key);
            }
            $rows = [];
            foreach ($definition['prices'] as $zone => $price) {
                $rows[] = ['id_carrier' => $carrierId, 'id_range_price' => $kind === 'price' ? (int) $range->id : null,
                    'id_range_weight' => $kind === 'weight' ? (int) $range->id : null, 'id_zone' => exportId($maps, 'zone', $zone),
                    'price' => $price];
            }
            if ($rows !== [] && !$carrier->addDeliveryPrice($rows)) {
                throw new RuntimeException('Nie można zapisać cen przewoźnika ' . $key);
            }
        }
    }
}

/**
 * Zastępuje uprawnienia modułu płatności: kraje, waluty, grupy i referencje przewoźników.
 *
 * @param string $name Nazwa modułu.
 * @param array $desired Definicja z pliku.
 * @param array $maps Mapy odwołań po odtworzeniu przewoźników.
 * @param \Context $context Kontekst PrestaShop.
 * @return void
 * @throws \RuntimeException Gdy moduł nie jest zainstalowany lub zapis się nie powiedzie.
 */
function applyPaymentPermissions(string $name, array $desired, array $maps, \Context $context): void
{
    $moduleId = (int) \Module::getModuleIdByName($name);
    if (!$moduleId) {
        throw new RuntimeException('Moduł płatności ' . $name . ' nie jest zainstalowany.');
    }
    $db = \Db::getInstance();
    $shopId = (int) $context->shop->id;
    $rows = [
        'module_country' => array_map(fn (string $iso): array => ['id_country' => exportId($maps, 'country', $iso)], $desired['countries']),
        'module_currency' => array_map(fn (string $iso): array => ['id_currency' => str_starts_with($iso, '@special')
            ? (int) substr($iso, 8) : exportId($maps, 'currency', $iso)], $desired['currencies']),
        'module_group' => array_map(fn (string $group): array => ['id_group' => exportId($maps, 'group', $group)], $desired['groups']),
        'module_carrier' => array_map(function (string $carrier) use ($maps): array {
            if (!isset($maps['carrier_reference'][$carrier])) {
                throw new RuntimeException('Brak przewoźnika ' . $carrier . ' dla uprawnień płatności.');
            }
            return ['id_reference' => $maps['carrier_reference'][$carrier]];
        }, $desired['carriers']),
    ];
    foreach ($rows as $table => $list) {
        if (!$db->delete($table, 'id_module=' . $moduleId . ' AND id_shop=' . $shopId)) {
            throw new RuntimeException('Nie można wyczyścić ' . $table . ' modułu ' . $name);
        }
        foreach ($list as $row) {
            if (!$db->insert($table, $row + ['id_module' => $moduleId, 'id_shop' => $shopId])) {
                throw new RuntimeException('Nie można zapisać ' . $table . ' modułu ' . $name);
            }
        }
    }
}

/**
 * Zapisuje stronę CMS, rozpoznając ją po kluczu inicjalizacji albo link_rewrite.
 *
 * @param string $key link_rewrite strony w języku domyślnym.
 * @param array $desired Definicja z pliku.
 * @param array $maps Mapy odwołań.
 * @param \Context $context Kontekst PrestaShop.
 * @return void
 * @throws \RuntimeException Przy błędzie zapisu lub braku kategorii.
 */
function applyCmsPage(string $key, array $desired, array $maps, \Context $context): void
{
    $id = null;
    $previous = 0;
    if ($desired['init_key'] !== null) {
        $owned = (int) \Configuration::get('SHOP_INIT_CMS_' . $desired['init_key']);
        $id = $owned && \Validate::isLoadedObject(new \CMS($owned)) ? $owned : null;
        $previous = $id === null ? $owned : 0;
    }
    $id ??= $maps['cms_id'][$key] ?? null;
    $page = new \CMS($id);
    $page->id_shop_list = [(int) $context->shop->id];
    $page->id_cms_category = exportId($maps, 'cms_category', $desired['category']);
    $page->active = (bool) $desired['active'];
    $page->indexation = (bool) $desired['indexation'];
    $page->position = (int) $desired['position'];
    foreach ($desired['lang'] as $iso => $fields) {
        $languageId = exportId($maps, 'language', $iso);
        foreach ($fields as $field => $value) {
            $page->{$field}[$languageId] = $field === 'content' ? cmsContentFromExport($value, $maps, false) : $value;
        }
    }
    foreach (array_keys(reset($desired['lang']) ?: []) as $field) {
        $values = array_map(fn (array $fields) => $field === 'content' ? cmsContentFromExport($fields[$field], $maps, false)
            : $fields[$field], $desired['lang']);
        $page->{$field} = fillMissingLanguages((array) $page->{$field}, $values, $maps);
    }
    if (!$page->save()) {
        throw new RuntimeException('Nie można zapisać strony CMS ' . $key);
    }
    if ($desired['init_key'] !== null) {
        saveShopSetting('SHOP_INIT_CMS_' . $desired['init_key'], (int) $page->id, $context);
    }
    if ($previous > 0 && $previous !== (int) $page->id) {
        remapCmsReferences($previous, (int) $page->id, $context);
    }
}

/**
 * Przekształca wartość z pliku na wartość Configuration bieżącej instalacji.
 *
 * @param string $key Klucz Configuration.
 * @param mixed $value Wartość z pliku.
 * @param array $maps Mapy odwołań.
 * @return mixed Wartość skalarna albo mapa id_lang => tekst.
 * @throws \RuntimeException Przy brakującym obiekcie docelowym.
 */
function importConfigurationValue(string $key, $value, array $maps)
{
    $type = exportedConfigurationKeys()[$key];
    if ($type === 'lang') {
        $values = [];
        foreach ((array) $value as $iso => $text) {
            $values[exportId($maps, 'language', (string) $iso)] = (string) $text;
        }
        return $values;
    }
    if ($value === null) {
        return $type === 'plain' ? '' : 0;
    }
    if ($type === 'plain') {
        return (string) $value;
    }
    if ($type === 'carrier') {
        if (str_starts_with((string) $value, '@strategy')) {
            return (int) substr((string) $value, 9);
        }
        if (!isset($maps['carrier_reference'][$value])) {
            throw new RuntimeException('Brak przewoźnika ' . $value . ' dla ' . $key);
        }
        return carrierIdByReference($maps['carrier_reference'][$value], \Context::getContext());
    }
    return exportId($maps, $type, (string) $value);
}

/**
 * Odtwarza ustawienia z pliku eksportu i potwierdza wynik ponownym eksportem.
 *
 * Zmieniane są tylko elementy różniące się od pliku. Aktywacja motywu i instalacja brakujących modułów
 * (operacje DDL) poprzedzają transakcję. Włączanie modułów i pozostałe zapisy wykonuje jedna transakcja.
 * Przed jej zatwierdzeniem kontrolny eksport musi odpowiadać plikowi, a opcjonalny $guard (inicjalizacja
 * przekazuje kontrolę warunków wcześniejszych etapów) nie może zgłosić błędu. W przeciwnym razie zapisy są
 * wycofywane, więc sprzeczny plik nie psuje instalacji. Na końcu zapisywany jest odcisk pliku.
 *
 * @param array $desired Zawartość pliku z readConfigExport.
 * @param string $sha256 Odcisk pliku.
 * @param \Context $context Kontekst PrestaShop.
 * @param bool $dryRun Przy true wyłącznie raportuje różnice.
 * @param callable|null $guard Kontrola wywoływana przed zatwierdzeniem, zgłaszająca wyjątek przy niezgodności.
 * @return list<string> Lista różnic przed zapisem.
 * @throws \RuntimeException Przy brakujących zależnościach, błędzie zapisu lub niezgodności po zapisie.
 */
function applyConfigExport(array $desired, string $sha256, \Context $context, bool $dryRun = false, ?callable $guard = null): array
{
    \Db::getInstance()->disableCache();
    $current = restrictConfigExportLanguages(exportShopConfiguration($context, false), array_keys($desired['languages']));
    $differences = configExportDifferences($current, $desired);
    if ($dryRun || $differences === []) {
        if (!$dryRun) {
            saveShopSetting(CONFIG_EXPORT_APPLIED_SETTING, $sha256, $context);
        }
        return $differences;
    }
    $maps = exportReferenceMaps($context);
    // Kontrola zależności poprzedza zapisy: języki i waluty muszą istnieć, bo ich instalacja wymaga pakietów danych.
    foreach (array_keys($desired['languages']) as $iso) {
        exportId($maps, 'language', $iso);
    }
    foreach (array_keys($desired['currencies']) as $iso) {
        exportId($maps, 'currency', $iso);
    }
    foreach ($desired['countries']['active'] as $iso) {
        exportId($maps, 'country', $iso);
    }
    foreach (array_keys($desired['customer_groups']) as $group) {
        exportId($maps, 'group', $group);
    }

    // Aktywacja motywu może sama zmienić stan modułów z theme.yml, dlatego poprzedza krok modułów.
    if ($current['theme'] !== $desired['theme']) {
        if ($desired['theme']['name'] !== PROJECT_THEME) {
            throw new RuntimeException('Eksport wymaga motywu ' . $desired['theme']['name'] . ', a odtwarzanie obsługuje motyw ' . PROJECT_THEME . '.');
        }
        deployProjectTheme($context);
    }
    $modules = [];
    foreach ($desired['modules'] as $name => $module) {
        if (($current['modules'][$name] ?? null) === $module) {
            continue;
        }
        $instance = \Module::getInstanceByName($name);
        if (!$instance) {
            throw new RuntimeException('Brak plików modułu ' . $name . ' wymaganego przez eksport konfiguracji.');
        }
        if (!\Module::isInstalled($name)) {
            bootModuleKernel();
            if (!$instance->install()) {
                throw new RuntimeException('Nie można zainstalować modułu ' . $name);
            }
        }
        $modules[$name] = [$instance, (bool) $module['enabled']];
    }

    transaction(function () use ($desired, $current, $context, $modules, $guard): void {
        foreach ($modules as $name => [$instance, $enabled]) {
            if ($enabled ? !$instance->enable() : !$instance->disable()) {
                throw new RuntimeException('Nie można zmienić stanu modułu ' . $name);
            }
            \Cache::clean('Module::isEnabled' . $name);
        }
        $maps = exportReferenceMaps($context);
        foreach ($desired['languages'] as $iso => $language) {
            if (($current['languages'][$iso] ?? null) !== $language) {
                $object = new \Language(exportId($maps, 'language', $iso));
                $object->active = (bool) $language['active'];
                if (!$object->update()) {
                    throw new RuntimeException('Nie można zapisać języka ' . $iso);
                }
            }
        }
        foreach ($desired['currencies'] as $iso => $currency) {
            if (($current['currencies'][$iso] ?? null) !== $currency) {
                $object = new \Currency(exportId($maps, 'currency', $iso));
                $object->active = (bool) $currency['active'];
                $object->conversion_rate = (float) $currency['conversion_rate'];
                $object->precision = (int) $currency['precision'];
                if (!$object->update()) {
                    throw new RuntimeException('Nie można zapisać waluty ' . $iso);
                }
            }
        }
        $db = \Db::getInstance();
        if ($current['countries'] !== $desired['countries']) {
            $ids = array_map(fn (string $iso): int => exportId($maps, 'country', $iso), $desired['countries']['active']);
            if (!$db->update('country', ['active' => 0], $ids === [] ? '1' : 'id_country NOT IN (' . implode(',', $ids) . ')')
                || ($ids !== [] && !$db->update('country', ['active' => 1], 'id_country IN (' . implode(',', $ids) . ')'))) {
                throw new RuntimeException('Nie można zapisać aktywnych krajów.');
            }
            foreach ($desired['countries']['zones'] as $iso => $zone) {
                if (!$db->update('country', ['id_zone' => exportId($maps, 'zone', $zone)], 'id_country=' . exportId($maps, 'country', $iso))) {
                    throw new RuntimeException('Nie można zapisać strefy kraju ' . $iso);
                }
            }
        }
        foreach ($desired['customer_groups'] as $group => $settings) {
            if (($current['customer_groups'][$group] ?? null) !== $settings && !$db->update('group', [
                'price_display_method' => (int) $settings['price_display_method'], 'show_prices' => (int) $settings['show_prices'],
            ], 'id_group=' . exportId($maps, 'group', $group))) {
                throw new RuntimeException('Nie można zapisać grupy klientów ' . $group);
            }
        }
        foreach ($desired['taxes'] as $name => $group) {
            if (($current['taxes'][$name] ?? null) !== $group) {
                applyTaxGroup((string) $name, $group, $maps);
            }
        }
        $changedPages = [];
        foreach ($desired['cms'] as $key => $page) {
            if (($current['cms'][$key] ?? null) !== $page) {
                applyCmsPage((string) $key, $page, exportReferenceMaps($context), $context);
                $changedPages[] = (string) $key;
            }
        }
        // Druga runda rozwiązuje odnośniki do stron utworzonych dopiero w pierwszej rundzie, także w treściach
        // uzupełnionych dla języków spoza pliku.
        $maps = exportReferenceMaps($context);
        foreach ($changedPages as $key) {
            $page = new \CMS(exportId($maps, 'cms', $key));
            foreach ($maps['language_id'] as $iso => $languageId) {
                $source = $desired['cms'][$key]['lang'][$iso]['content'] ?? (string) ($page->content[$languageId] ?? '');
                $page->content[$languageId] = cmsContentFromExport($source, $maps, true);
            }
            if (!$page->update()) {
                throw new RuntimeException('Nie można zapisać odnośników strony CMS ' . $key);
            }
        }
        foreach ($desired['carriers'] as $key => $carrier) {
            if (($current['carriers'][$key] ?? null) !== $carrier) {
                applyCarrier((string) $key, $carrier, $current['carriers'][$key] ?? null, exportReferenceMaps($context), $context);
            }
        }
        $maps = exportReferenceMaps($context);
        foreach ($desired['payments'] as $name => $permissions) {
            if (($current['payments'][$name] ?? null) !== $permissions) {
                applyPaymentPermissions((string) $name, $permissions, $maps, $context);
            }
        }
        $types = exportedConfigurationKeys();
        foreach ($desired['configuration'] as $key => $value) {
            if (!array_key_exists($key, $current['configuration']) || $current['configuration'][$key] !== $value) {
                // Jak w panelu: HTML dopuszczamy wyłącznie w tekstach wielojęzycznych.
                saveShopSetting((string) $key, importConfigurationValue((string) $key, $value, $maps), $context, $types[$key] === 'lang');
            }
        }
        // Kontrola przed zatwierdzeniem: niezgodność wycofuje wszystkie zapisy transakcji.
        \Configuration::resetStaticCache();
        \Module::resetStaticCache();
        \Carrier::resetStaticCache();
        \Cache::clean('*');
        $remaining = configExportDifferences(exportShopConfiguration($context), $desired);
        if ($remaining !== []) {
            throw new RuntimeException('Po odtworzeniu konfiguracja nadal różni się od pliku: ' . implode(', ', $remaining));
        }
        if ($guard !== null) {
            $guard();
        }
    });

    \Configuration::resetStaticCache();
    \Module::resetStaticCache();
    \Cache::clean('*');
    saveShopSetting(CONFIG_EXPORT_APPLIED_SETTING, $sha256, $context);
    \Tools::clearSmartyCache();
    return $differences;
}
