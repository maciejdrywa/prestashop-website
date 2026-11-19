# PrestaShop Webservice API

Konfigurator `deploy/init/configure-webservice.php` włącza Webservice, tworzy lub aktualizuje klucz z `.env` i przypisuje go wyłącznie do domyślnego sklepu. Wznowienie inicjalizacji kontroluje faktyczne uprawnienia oraz przypisanie, zamiast sprawdzać tylko istnienie aktywnego klucza.

## Konfiguracja

| Zmienna | Znaczenie |
|---|---|
| `PRESTASHOP_API_URL` | Adres HTTP(S) kończący się `/api`, bez klucza w URL, parametrów i fragmentu; domyślnie `https://localhost/api` |
| `PRESTASHOP_API_KEY` | 32 znaki ASCII: litery i cyfry; `.env.example` pozostawia pustą wartość |
| `PRESTASHOP_API_VERIFY_SSL` | `true` albo `false`; klient domyślnie weryfikuje certyfikat, przykład lokalny wyłącza weryfikację dla self-signed |

Przy zaufanym certyfikacie użyj `true`; dodatkowy urząd certyfikacji można przekazać przez standardowe `REQUESTS_CA_BUNDLE`. Połączenia zdalne powinny korzystać z HTTPS. W kodzie klienta timeout wynosi domyślnie 15 sekund na żądanie; konstruktor przyjmuje dodatni `timeout` oraz `get_retries` od 0 do 5 (domyślnie 2).

## Uprawnienia

| Zasoby | Dozwolone metody |
|---|---|
| `categories`, `products`, `combinations`, `product_options`, `product_option_values`, `product_features`, `product_feature_values`, `images` | GET, POST, PUT |
| `stock_availables` | GET, PUT |
| `languages`, `shops`, `currencies`, `countries`, `taxes`, `tax_rules`, `tax_rule_groups` | GET |

Klucz nie ma DELETE, HEAD, PATCH ani dostępu do klientów, zamówień, pracowników i konfiguracji. Próba użycia niedozwolonej metody może zwrócić HTTP 405; brak uprawnień do zasobu zwraca zwykle 401/403. Do sprzątania instancji testowej służy osobny mechanizm PHP, bez rozszerzania uprawnień importera.

## Klient i tożsamość instalacji

`scraper/prestashop_api.py` stosuje HTTP Basic Auth: klucz jako login, puste hasło. Odczyt wymusza JSON, zapis serializuje XML, a zdjęcia są wysyłane jako multipart. Niepoprawne odpowiedzi dają `PrestaShopProtocolError`; nie są traktowane jako pusty katalog. Klient odrzuca przekierowania i nie umieszcza treści odpowiedzi serwera w komunikatach błędów.

Ponawiane są wyłącznie GET po timeoutach, błędach połączenia i HTTP 429/500/502/503/504. POST/PUT nie są automatycznie ponawiane. Ich niepewny wynik rozstrzyga dziennik importera.

Konfigurator dodaje publiczny nagłówek `X-PrestaShop-Import-Instance` przez blok w `.htaccess`, poza sekcją generowaną PrestaShop. Wymaga Apache `mod_headers`. Odcisk HMAC zmienia się po nowej instalacji, nie zawiera klucza API ani sekretu sklepu. Reverse proxy musi go zachować. Po pierwszym odczycie klient sprawdza zgodność nagłówka w kolejnych odpowiedziach.

## Kategorie i weryfikacja

```sh
python scraper/test_api_client.py
python scraper/import_categories.py
```

Pierwsza komenda kontroluje połączenie, macierz uprawnień i odmowę odczytu zasobów chronionych **bez zapisu danych**. Druga synchronizuje `scraper-results/categories.json`. Obsługuje `--categories-file`, `--mapping-file` i `--skip-existing`.

Mapa domyślna ma nazwę `category_mapping_<host>.json`. Obok niej powstaje `category_mapping_<host>.state.json`. Zachowuj oba pliki; są lokalne i wykluczone z Git. Dziennik umożliwia odzyskanie powiązania po utracie odpowiedzi lub przerwaniu przed zapisem mapy. Konflikt instalacji, uszkodzony stan i niejednoznaczny POST wymagają kontroli operatora, a nie usunięcia dziennika i ślepego ponowienia.

Testy offline, osobną instancję integracyjną, scenariusze awarii i wyniki opisuje [odbiór D2.1–D2.2](D2_1-D2_2.md). Bootstrap nadal importuje katalog bezpośrednio przez modele PHP; integracja toru API należy do D2.5.
