<?php
declare(strict_types=1);

/**
 * Wspólny punkt wejścia usług certificates, healthchecka shop i jednorazowego bootstrapu.
 *
 * Compose przekazuje odpowiednio --certificates, --check-shop albo brak argumentu.
 * Przygotowanie TLS wymaga obu JSON-ów, lokalnych JPEG i zapisywalnego /certs.
 * Bootstrap działa jako www-data na wspólnym shop_data, po starcie usług zależnych.
 * Wywołuje init-shop.php --resume, zapisujący konfigurację i brakujące dane katalogu.
 * Kontrola HTTP korzysta z Nginx i Mailpit w sieci Compose. Błąd kończy proces kodem 1.
 */

/**
 * Sprawdza wejście i przygotowuje lokalny certyfikat bez zależności od bazy.
 *
 * Waliduje katalog i lokalne JPEG przed uruchomieniem bazy. Akceptuje poprawną istniejącą parę TLS albo
 * zapisuje nowy samopodpisany certyfikat i klucz w /certs. Niekompletna para przerywa start. Pliki są
 * podmieniane pojedynczo, więc błąd pomiędzy podmianami może pozostawić parę wymagającą sprawdzenia.
 *
 * @return void
 * @throws \RuntimeException Przy błędzie wejścia, istniejącej pary TLS, generowania lub zapisu.
 */
function prepareCertificates(): void
{
    $domain = getenv('PS_DOMAIN') ?: '';
    if (!preg_match('/^localhost(?::([1-9][0-9]{0,4}))?$/D', $domain, $parts)
        || (isset($parts[1]) && (int) $parts[1] > 65535)) {
        throw new RuntimeException('Lokalny certyfikat wymaga PS_DOMAIN=localhost lub localhost:port.');
    }
    require_once __DIR__ . '/init/lib/images.php';
    $root = '/opt/shop/scraper-results';
    // Ten tryb działa przed bazą. Wspólne parsery sprawdzają format katalogu i menu,
    // zaś pełną zgodność rekordów z PrestaShop zweryfikuje późniejszy preflight.
    $products = ShopInit\readCatalog($root . '/products.json');
    ShopInit\readMainMenuPlan($root . '/categories.json');
    // Jedno zdjęcie może być wskazane wielokrotnie. Walidacja po ścieżce eliminuje
    // powtórne dekodowanie tego samego JPEG podczas sprawdzania wejścia.
    $seen = [];
    foreach ($products as $product) {
        foreach ($product['images'] as $url) {
            $file = $root . '/images/' . ShopInit\sourceImageFilename($url);
            if (!isset($seen[$file])) {
                ShopInit\validateSourceImage($file);
                $seen[$file] = true;
            }
        }
    }
    echo '[START] Dane wejściowe poprawne. Produkty ' . count($products) . ', zdjęcia ' . count($seen) . PHP_EOL;
    $certificate = '/certs/localhost.crt';
    $privateKey = '/certs/localhost.key';
    // Obecność któregokolwiek pliku oznacza istniejącą parę do sprawdzenia.
    // Kontrola obejmuje ważność, dopasowanie klucza i SAN dla localhost.
    if (file_exists($certificate) || file_exists($privateKey)) {
        $cert = @openssl_x509_read((string) @file_get_contents($certificate));
        $key = @openssl_pkey_get_private((string) @file_get_contents($privateKey));
        $details = $cert ? openssl_x509_parse($cert) : false;
        if (!$cert || !$key || !$details || $details['validTo_time_t'] <= time()
            || $details['validFrom_time_t'] > time() || !openssl_x509_check_private_key($cert, $key)
            || !str_contains($details['extensions']['subjectAltName'] ?? '', 'DNS:localhost')) {
            throw new RuntimeException('Niepoprawna lub wygasła para certyfikatu i klucza w TLS_CERT_DIR. Zachowaj kopię i przygotuj nową parę.');
        }
        echo "[START] Istniejący certyfikat jest poprawny.\n";
        return;
    }
    $configuration = tempnam(sys_get_temp_dir(), 'shop-tls-');
    if ($configuration === false) throw new RuntimeException('Nie można przygotować konfiguracji OpenSSL.');
    try {
        file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=extensions\nprompt=no\n[dn]\nCN=localhost\n[extensions]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nbasicConstraints=critical,CA:TRUE\n");
        // Opcje budują samopodpisany certyfikat RSA dla lokalnego Nginx.
        // Dodanie go do zaufanych na hoście jest odrębną czynnością systemową.
        $options = ['config' => $configuration, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        $request = $key ? openssl_csr_new(['commonName' => 'localhost'], $key, $options) : false;
        $cert = $request ? openssl_csr_sign($request, null, $key, 365, $options) : false;
        if (!$key || !$cert || !openssl_pkey_export($key, $keyText) || !openssl_x509_export($cert, $certText)) {
            throw new RuntimeException('Generowanie certyfikatu OpenSSL nie powiodło się.');
        }
        // Zapis przez pliki tymczasowe ogranicza ryzyko pozostawienia niepełnej pary.
        foreach ([$privateKey => $keyText, $certificate => $certText] as $file => $text) {
            $temporary = tempnam('/certs', '.tls-');
            if ($temporary === false || file_put_contents($temporary, $text) === false
                || !chmod($temporary, $file === $privateKey ? 0600 : 0644) || !rename($temporary, $file)) {
                throw new RuntimeException('Nie można zapisać certyfikatu w TLS_CERT_DIR. Sprawdź prawa dostępu.');
            }
        }
        echo "[START] Wygenerowano lokalną parę certyfikatu i klucza.\n";
    } finally {
        unlink($configuration);
    }
}

/**
 * Potwierdza zakończenie instalatora, dostępność bazy i serwera Apache.
 *
 * Uruchamiana jako www-data wewnątrz usługi shop. boot() potwierdza kontekst i bazę oraz może utworzyć
 * cache HTMLPurifier. Próba TCP dotyczy lokalnego Apache, a walidację odpowiedzi HTTPS wykonuje osobno
 * bootstrapShop.
 *
 * @return void
 * @throws \RuntimeException Gdy instalacja, połączenie z bazą lub Apache są niegotowe.
 */
function checkShopReady(): void
{
    $root = '/var/www/html';
    // Sam plik parameters.php może powstać jeszcze podczas instalacji.
    // Warunek blokady odracza ładowanie aplikacji do zakończenia instalatora.
    if (is_file($root . '/install.lock') || !is_file($root . '/app/config/parameters.php')) {
        throw new RuntimeException('Instalator PrestaShop jeszcze pracuje.');
    }
    require_once __DIR__ . '/init/lib/prestashop.php';
    ShopInit\boot($root);
    $socket = @fsockopen('127.0.0.1', 80, $errorCode, $errorMessage, 1);
    if ($socket === false) throw new RuntimeException('Apache jeszcze nie przyjmuje połączeń.');
    fclose($socket);
}

/**
 * Pobiera lokalną odpowiedź HTTP z weryfikacją certyfikatu wygenerowanego dla sklepu.
 *
 * Adres localhost jest rozwiązywany na usługę nginx w sieci Compose. Weryfikacja TLS korzysta z lokalnego
 * certyfikatu. Odpowiedź zachowuje kod przekierowania, ponieważ automatyczne podążanie za Location jest
 * wyłączone.
 *
 * @param string $url Lokalny URL z hostem localhost albo mailpit.
 * @return array{status: int, body: string|false, redirect: string, error: string}
 * @throws \RuntimeException Dla hosta spoza lokalnej listy dopuszczonych usług.
 */
function localResponse(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['localhost', 'mailpit'], true)) throw new RuntimeException('Nieobsługiwany adres lokalnej kontroli gotowości.');
    $curl = curl_init($url);
    // localhost wewnątrz bootstrapu wskazywałby własny kontener. CURLOPT_RESOLVE
    // kieruje transport do nginx, zachowując host localhost dla HTTP i weryfikacji TLS.
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5,
        CURLOPT_CAINFO => '/certs/localhost.crt', CURLOPT_RESOLVE => [
            'localhost:443:' . gethostbyname('nginx'), 'localhost:80:' . gethostbyname('nginx'),
        ]]);
    $body = curl_exec($curl);
    $response = ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => $body,
        'redirect' => curl_getinfo($curl, CURLINFO_REDIRECT_URL), 'error' => curl_error($curl)];
    curl_close($curl);
    return $response;
}

/**
 * Uruchamia bezpieczne wznowienie i kończy kontrolą faktycznej dostępności usług.
 *
 * Limit czasu obejmuje oczekiwanie na instalator i HTTPS. Po tej fazie init-shop.php --resume działa jako
 * podproces z wyjściem przekazywanym do konsoli. Końcowe kontrole wymagają strony głównej, przekierowania
 * HTTP, panelu i Mailpit.
 *
 * @return void
 * @throws \RuntimeException Po upływie czasu gotowości, błędzie inicjalizacji lub końcowej kontroli usług.
 */
function bootstrapShop(): void
{
    $started = microtime(true);
    $timeout = (int) (getenv('BOOTSTRAP_TIMEOUT') ?: 900);
    if ($timeout < 1) throw new RuntimeException('BOOTSTRAP_TIMEOUT musi być dodatni.');
    echo "[START] Oczekiwanie na zakończenie instalacji, Apache i HTTPS...\n";
    // Termin ogranicza fazę gotowości przed importem. Czas wykonywania samego
    // init-shop.php zależy od katalogu i pozostaje poza tą pętlą oczekiwania.
    // Termin jest sprawdzany po próbie HTTP, która ma własny timeout 5 s.
    $deadline = time() + $timeout;
    $reported = time();
    $response = ['status' => 0, 'error' => 'Instalator jeszcze nie zakończył pracy.'];
    do {
        if (!is_file('/var/www/html/install.lock') && is_file('/var/www/html/app/config/parameters.php')) {
            $response = localResponse('https://localhost/');
            if ($response['status'] === 200) break;
        }
        if (time() >= $deadline) {
            throw new RuntimeException('Przekroczono czas gotowości sklepu. HTTP ' . $response['status'] . '. '
                . $response['error'] . ' Sprawdź logi shop/nginx i certyfikat.');
        }
        if (time() - $reported >= 15) {
            echo '[START] Instalacja lub serwer nadal się uruchamia. Pozostało ' . max(0, $deadline - time()) . ' s.' . PHP_EOL;
            $reported = time();
        }
        clearstatcache();
        usleep(500000);
    } while (true);
    echo "[START] Wznawianie konfiguracji i importu...\n";
    // Postęp i błędy podprocesu trafiają bezpośrednio do konsoli Compose.
    // Kod wyjścia jest warunkiem przejścia do końcowej kontroli usług.
    $process = proc_open([PHP_BINARY, __DIR__ . '/init/init-shop.php', '--resume'],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, __DIR__);
    if (!is_resource($process)) throw new RuntimeException('Nie można uruchomić inicjalizacji.');
    fclose($pipes[0]);
    if (proc_close($process) !== 0) throw new RuntimeException('Inicjalizacja przerwana. Popraw wskazaną przyczynę i powtórz tę samą komendę.');
    // Sprawdzamy efekt inicjalizacji przez publiczne punkty wejścia usług.
    // Dla HTTP wymagamy konkretnego przekierowania, dla panelu dopuszczamy ekran lub redirect.
    $https = localResponse('https://localhost/');
    $http = localResponse('http://localhost/');
    $admin = localResponse('https://localhost/admin-dev/');
    $mail = localResponse('http://mailpit:8025/');
    if ($https['status'] !== 200 || $http['status'] !== 301 || $http['redirect'] !== 'https://localhost/'
        || !in_array($admin['status'], [200, 302], true) || $mail['status'] !== 200) {
        throw new RuntimeException('Końcowa kontrola usług nie powiodła się. Kody HTTPS ' . $https['status']
            . ', HTTP ' . $http['status'] . ', panel ' . $admin['status'] . ', Mailpit ' . $mail['status']
            . '. Sprawdź logi kontenerów.');
    }
    echo '[START] Gotowe w ' . number_format(microtime(true) - $started, 1) . " s.\nSklep https://localhost\nPanel https://localhost/admin-dev/\nPoczta http://localhost:8025\n";
}

try {
    require_once __DIR__ . '/init/lib/menu.php';
    // Tryb wybiera entrypoint usługi certificates, healthcheck shop lub bootstrap.
    // Wspólna granica wyjątków daje Compose kod 1 przy nieudanym przygotowaniu.
    $mode = $argv[1] ?? '';
    if ($mode === '--certificates') prepareCertificates();
    elseif ($mode === '--check-shop') checkShopReady();
    elseif ($mode === '') bootstrapShop();
    else throw new RuntimeException('Nieznany tryb bootstrap.');
} catch (Throwable $error) {
    fwrite(STDERR, '[START] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
