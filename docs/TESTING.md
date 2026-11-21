# Testowanie projektu

Polecenia wykonuj z głównego katalogu repozytorium. Używaj Pythona 3.11+; CI scrapera działa na Pythonie 3.14. Testy korzystają z pytest, a zestawy przeglądarkowe także z Playwright.

## Instalacja zależności

```powershell
$env:PYTHONUTF8="1"
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r scraper/requirements-dev.txt -r tests/requirements.txt
python -m playwright install chromium
```

Bash (Linux/macOS; dla testów skryptów na Windows użyj WSL i linuksowego Pythona):

```sh
python -m venv .venv
source .venv/bin/activate
python -m pip install -r scraper/requirements-dev.txt -r tests/requirements.txt
python -m playwright install chromium
```

Na Linux, jeśli brakuje bibliotek systemowych Chromium, użyj `python -m playwright install --with-deps chromium`.

## Szybki start testów jednostkowych

Oba zestawy uruchamiaj z katalogu głównego repozytorium, w aktywnym środowisku z powyższymi zależnościami:

```sh
# Scraper offline: bez sklepu i bez połączeń sieciowych
python -m pytest -q

# Pomocniki, lokalne strony testowe i konfiguracja
python -m pytest -c pytest-e2e.ini tests/unit
```

Pierwsza komenda używa `pytest.ini`: zbiera wyłącznie `scraper/`, blokuje sieć i pomija testy integracyjne oraz kontrolę żywego API. Nie uruchamia `tests/unit` ani E2E.

Druga komenda korzysta z ustawień Playwright i raportowania z `pytest-e2e.ini`. Nie wymaga działającego sklepu ani nie składa zamówień; część testów otwiera Chromium i lokalny serwer HTTP. `tests/unit/test_backup_script.py` uruchamia skrypt Bash, również bezpośrednio jako plik wykonywalny, dlatego pełny zestaw wymaga środowiska POSIX (Linux/macOS lub WSL z linuksowym Pythonem). Samo zainstalowanie Bash w Windows nie zapewnia zgodności tych testów.

Na natywnym Windows uruchamiaj testy w trybie UTF-8 (`$env:PYTHONUTF8="1"` przed uruchomieniem Pythona lub `python -X utf8 -m pytest ...`), ponieważ część testów odczytuje pliki bez jawnego kodowania. Jest też znany błąd testu ZIP `test_unsafe_zip_never_writes_outside_cache[images\escape.jpg]`: Windows normalizuje ścieżkę przy tworzeniu testowego archiwum. Tego błędu nie należy opisywać jako poprawnego przejścia pełnego zestawu; CI działa na Linux.

Kod wyjścia 0 oznacza sukces. Szczegóły zestawów i dodatkowych narzędzi opisano poniżej.

## Przygotowanie sklepu do E2E

Przed uruchomieniem zapewnij działający sklep, indeks wyszukiwarki, aktywnych przewoźników InPost i DPD oraz płatność przy odbiorze. Produkty muszą być dostępne w wymaganych ilościach, a koszyk kwalifikować się do dostawy do 50 kg. Indeks można przebudować w panelu: **Parametry sklepu → Wyszukiwanie → Przebuduj cały indeks**.

Pobranie faktury wymaga konfiguracji z `deploy/init/configure-orders.php`, wykonywanej przez bootstrap: włączonych faktur (`PS_INVOICE`), statusu „Oczekiwanie na płatność przy odbiorze” bez faktury i statusu „Płatność zaakceptowana” z fakturą. Test potwierdza płatność w panelu, dlatego potrzebuje konta administratora. Dane odczytuje ze zmiennych `ADMIN_EMAIL` i `ADMIN_PASSWORD`, a brakujące uzupełnia z `.env` w katalogu głównym. Hasło z `.env` działa tylko wtedy, gdy nie zmieniono go po pierwszej instalacji. Adres panelu można zmienić zmienną `ADMIN_PATH` (domyślnie `/admin-dev/`). Brak danych lub nieudane logowanie kończą test błędem przed złożeniem zamówienia.

Na istniejącej instancji, na której nie wykonano jeszcze tego etapu, konfigurację faktur można zastosować ponownie:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-orders.php
```

## Uruchomienie E2E

W aktywnym środowisku wirtualnym, z głównego katalogu repozytorium.

Bash (Linux, klaster):

```sh
BASE_URL=https://adres python -m pytest -c pytest-e2e.ini
```

PowerShell (Windows):

```powershell
$env:BASE_URL="https://localhost"; python -m pytest -c pytest-e2e.ini
```

Test działa domyślnie w Chromium headless, z locale `pl-PL` i strefą `Europe/Warsaw`, i akceptuje samodzielnie podpisany certyfikat HTTPS. Adres można również podać przez `--base-url`, który ma pierwszeństwo przed `BASE_URL`. Domyślny adres to `https://localhost`.

### Uruchomienie na klastrze

Na klastrze nie ma pliku `.env`, więc dane panelu trzeba podać w zmiennych. Ustaw `BASE_URL`, `ADMIN_EMAIL` i `ADMIN_PASSWORD`. `ADMIN_PATH` ustaw, jeśli panel ma inny adres niż `/admin-dev/`. Przy wolniejszym klastrze można podnieść `E2E_EXPECT_TIMEOUT_MS`.

```sh
export BASE_URL=https://adres
export ADMIN_EMAIL=admin@example.com
export ADMIN_PASSWORD='hasło'
export ADMIN_PATH=/admin-dev/
export E2E_EXPECT_TIMEOUT_MS=10000
python -m pytest -c pytest-e2e.ini
```

### Zmienne środowiskowe

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `BASE_URL` | `https://localhost` | Adres sklepu. `--base-url` ma pierwszeństwo. |
| `ADMIN_EMAIL` | z `.env` | E-mail administratora do potwierdzenia płatności. |
| `ADMIN_PASSWORD` | z `.env` | Hasło administratora. |
| `ADMIN_PATH` | `/admin-dev/` | Ścieżka panelu. |
| `FRAZA` | `klamka` | Fraza wyszukiwania w kroku 2. |
| `E2E_MAX_SECONDS` | `300` | Limit czasu samego scenariusza w sekundach. |
| `E2E_EXPECT_TIMEOUT_MS` | `5000` | Limit oczekiwania asercji `expect` w milisekundach. |
| `E2E_ALLOW_ANALYTICS` | brak | `1` wyłącza blokadę żądań analityki. |

Brakujące `ADMIN_EMAIL` i `ADMIN_PASSWORD` test uzupełnia z `.env` w katalogu głównym. Gdy nie ma ich ani w zmiennych, ani w `.env`, test kończy się błędem przed złożeniem zamówienia.

Dodatkowe opcje:

```powershell
# Widoczna przeglądarka i logi etapów w terminalu
python -m pytest -c pytest-e2e.ini --headed -s

# Trzy kolejne wykonania bez resetowania bazy
python -m pytest -c pytest-e2e.ini --count=3
```

Wyniki trzech przebiegów kontroli odbiorowej zapisuje się w [wynikach E2E](E2E_RESULTS.md).

`pytest-e2e.ini` konfiguruje testy przeglądarkowe. Domyślny `pytest.ini` służy testom scrapera offline i blokuje połączenia sieciowe.

## Scenariusz

Domyślny zestaw `pytest-e2e.ini` zbiera pliki z `tests/e2e/`, w tym scenariusz zakupowy oraz kontrole promocji i Omnibusa. Sam scenariusz zakupowy można wybrać poleceniem `python -m pytest -c pytest-e2e.ini tests/e2e/test_checkout.py`.

Test `tests/e2e/test_checkout.py` wykonuje pełny zakup w jednej sesji klienta, od koszyka do pobrania faktury VAT. Każdy etap jest nazwany w `with step("...")`. Tabela podaje etapy w kolejności wykonania. Etapy pomocnicze nie odpowiadają osobnemu krokowi prowadzącego, ale są potrzebne do kolejnych.

| Krok prowadzącego | Etap testu | Co sprawdza asercja |
|---|---|---|
| pomocniczy | `Log in to the back office` | Logowanie administratora w osobnej sesji. Brak danych lub nieudane logowanie przerywa test przed zakupem. |
| 1. Dodanie 10 produktów z dwóch kategorii | `Add 10 products from two categories` | Po 5 produktów z kategorii „Akcesoria meblowe” i „Klamki do drzwi”, w ilościach 1–3. Produkty są różne i mają stan magazynowy co najmniej równy ilości. Po każdym dodaniu widać okno potwierdzenia. Koszyk ma 10 pozycji z właściwymi ilościami. |
| 2. Wyszukanie i dodanie losowego wyniku | `Search and add a random product` | Strona wyników dla frazy `FRAZA` ma produkty. Losowy dostępny wynik spoza koszyka zostaje dodany w ilości 1. Koszyk ma 11 pozycji. |
| 3. Usunięcie 3 produktów | `Remove three products and verify the final cart` | Po każdym usunięciu znika pozycja i maleje liczba wierszy. Zostaje 8 pozycji z właściwymi ilościami. Test zapamiętuje nazwy z wariantami do późniejszego porównania. |
| 5. Złożenie zamówienia na zawartość koszyka | `Proceed from the cart to checkout` | Otwiera się strona zamówienia z aktywnym etapem danych klienta. |
| 4. Rejestracja nowego konta | `Register a new account` | Formularz z unikalnym e-mailem, hasłem i wymaganymi zgodami. Etap danych jest zakończony, klient jest zalogowany (widać imię i nazwisko), aktywny jest etap adresu. |
| pomocniczy | `Save the shipping and billing address` | Fikcyjny adres w Polsce, ten sam dla dostawy i faktury. Zapisany adres zawiera wpisane dane, aktywny jest etap dostawy. |
| 7. Wybór jednego z dwóch nowych przewoźników | `Select InPost shipping` | Dostępni są InPost i DPD. Test wybiera InPost, a wybór zostaje zachowany po przejściu do płatności. |
| 6. Płatność przy odbiorze | `Select cash on delivery and accept required terms` | Opcja płatności przy odbiorze istnieje i jest zaznaczona. Wymagane zgody są zaznaczone, przycisk zatwierdzenia jest aktywny. |
| pomocniczy | `Read the final checkout total` | Odczyt końcowej kwoty brutto w PLN po wyborze adresu, dostawy i płatności. |
| 8. Zatwierdzenie zamówienia | `Confirm the order` | Strona potwierdzenia z komunikatem o potwierdzeniu zamówienia i e-mailem klienta. Test odczytuje referencję zamówienia. |
| 5. Złożenie zamówienia na zawartość koszyka | `Verify products, quantities, shipping, payment and total` | Potwierdzenie podaje InPost i płatność przy odbiorze. Lista produktów z wariantami i ilościami jest identyczna z koszykiem. Kwota do zapłaty jest równa odczytanej przed zatwierdzeniem. |
| 9. Sprawdzenie statusu zamówienia | `Find the order in the customer's order history` | Zamówienie występuje w historii klienta dokładnie raz i ma kwotę z kasy. |
| 9. Sprawdzenie statusu zamówienia | `Check the initial status and that no invoice is issued yet` | Status „Oczekiwanie na płatność przy odbiorze”. Historia nie ma odnośnika do faktury, a kontroler PDF nie zwraca pliku. |
| pomocniczy | `Accept the payment in the back office, which issues the invoice` | W panelu otwiera się zamówienie o tej referencji. Zmiana statusu na „Płatność zaakceptowana” zostaje zapisana w historii statusów, a panel pokazuje fakturę. |
| 9. Sprawdzenie statusu zamówienia | `Check the updated status in the order history` | Status w historii klienta to „Płatność zaakceptowana”. |
| 10. Pobranie faktury VAT | `Download the VAT invoice and verify the PDF` | Odnośnik do faktury w historii pobiera plik `.pdf`. Plik zaczyna się od `%PDF-`, ma znacznik końca, co najmniej jedną stronę, niepusty tekst i referencję zamówienia. |

Brak zamówienia, inny status, brak odnośnika do faktury, komunikat sklepu zamiast pliku albo niepoprawny PDF kończą test błędem.

Test pomija niedostępnych kandydatów i kończy się błędem, jeśli nie znajdzie wymaganej liczby produktów. Czeka na stan strony zamiast stosować stałe opóźnienia (`sleep`).

Kwota jest zapamiętywana po wyborze adresu, dostawy i płatności, przed zatwierdzeniem zakupu, a następnie porównywana dokładnie z potwierdzeniem i historią zamówień w PLN.

Test tworzy rzeczywiste konta, zamówienia i faktury na wskazanej instancji, zmniejsza stany magazynowe, zmienia status zamówienia na opłacone i wysyła wiadomości do Mailpit. Dane pozostają do kontroli.

### Limit czasu

Czas samego scenariusza mierzy `tests/conftest.py`. Limit wynosi domyślnie 300 s i można go zmienić zmienną `E2E_MAX_SECONDS`. Czas jest porównywany z limitem po zakończeniu scenariusza. Przekroczenie limitu kończy test zwykłym błędem, a raport i zrzut ekranu zostają zapisane. Jeśli scenariusz wcześniej zakończył się innym błędem, raport podaje ten błąd. Log zawiera wiersz `DURATION: x s (limit y s)`.

`pytest-timeout` w `pytest-e2e.ini` ma limit 360 s. To bezpiecznik na zawieszenie testu. Po jego przekroczeniu proces jest przerywany i raport może się nie zapisać.

### Testy jednostkowe pomocników

Kontrolę parsowania kwot, odrzucania niezgodnej sumy, oczekiwania na dane potwierdzenia, odczytu historii zamówień, pobrania i walidacji PDF oraz odczytu konta administratora można uruchomić bez składania zamówień:

```powershell
python -m pytest -c pytest-e2e.ini tests/unit
```

`tests/unit/test_config_export.py` sprawdza plik `deploy/config/shop-config.json` bez uruchamiania sklepu. Kontroluje komplet sekcji, wymagane ustawienia, odwołania kluczami naturalnymi i brak sekretów oraz danych osobowych.

## Dane testowe i analityka

Klienci testowi mają e-mail `e2e-<uuid>@example.com` oraz imię i nazwisko „Jan Testowy”. Zamówienia testowe znajdziesz w panelu w **Zamówienia → Zamówienia**, filtrując kolumnę klienta po „Testowy” albo e-mail po `e2e-`.

Testy domyślnie blokują żądania do `google-analytics.com`, `analytics.google.com`, `googletagmanager.com` i `doubleclick.net`. Chromium nie rozwiązuje tych nazw (`--host-resolver-rules`), więc ruch testowy nie trafia do GA4. Blokada działa dla sesji klienta i panelu. Nie zadziała, jeśli przeglądarka łączy się przez serwer proxy, bo nazwy rozwiązuje wtedy proxy.

`E2E_ALLOW_ANALYTICS=1` wyłącza blokadę, np. do ręcznego sprawdzenia tagowania. Wtedy ruch testowy trafia do GA4 i trzeba go odfiltrować, np. regułą ruchu wewnętrznego dla adresu maszyny testowej albo przy analizie zamówień — po kliencie „Jan Testowy” i e-mailu `e2e-`. Projekt nie ma gotowego filtra GA4 dla testów.

## Raporty

| Raport | Ścieżka | Zawartość |
|---|---|---|
| HTML | `tests/playwright-report/python/index.html` | Wynik i czas każdego testu, logi wyjścia. |
| JUnit XML | `tests/test-results/python/junit.xml` | Wynik, czas w atrybucie `time` elementu `testcase`, logi w `system-out`. |
| Zrzut ekranu i ślad | `tests/test-results/python/<nazwa testu>/` | Tylko przy błędzie. |

Odczyt:

- HTML: otwórz plik w przeglądarce. Przy teście jest wynik i czas; po rozwinięciu wiersza widać logi etapów.
- JUnit: w `system-out` każdy etap ma wiersz `STEP: <nazwa>`, a po sukcesie `OK: <nazwa> (x s)` z czasem etapu. Na końcu jest `DURATION: x s (limit y s)`. Log zawiera też `Order <referencja>, ID <id>` i `Invoice <plik>`.
- Błąd: komunikat zawiera notatkę `Scenario step: <nazwa>` z etapem, w którym test się zatrzymał (dodaje ją `tests/helpers/steps.py`). Katalog testu w `tests/test-results/python/` zawiera zrzut ekranu (`.png`) i ślad Playwright (`trace.zip`). Nazwa katalogu pochodzi od identyfikatora testu, np. `tests-e2e-test-checkout-py-test-cart-registration-and-order-chromium`.

Ślad otwiera polecenie:

```sh
python -m playwright show-trace tests/test-results/python/<nazwa testu>/trace.zip
```

Sesja panelu administratora nie jest nagrywana, żeby ślad nie zawierał hasła.

```powershell
Start-Process ".\tests\playwright-report\python\index.html"
```

Wyniki są wykluczone z Git. Kolejne uruchomienie z `pytest-e2e.ini`, również dla `tests/unit`, zastępuje raporty HTML i JUnit w tych samych ścieżkach.

## Python i JavaScript

Testy scrapera offline oraz obsługi komunikatu cookies:

```powershell
python -m pip install -r scraper/requirements-dev.txt
python -m pytest -q
node --test deploy/init/tests/cookie-notice.test.cjs
```

Kontrola połączenia Webservice jest tylko do odczytu:

```sh
python scraper/test_api_client.py
```

Testy zapisu Webservice, kategorii i produktów są oznaczone `integration`. Wymagają przygotowanej odrębnej instancji tego samego `deploy/compose.yaml`: projekt `prestashop-d21-tests`, osobne wolumeny, aktywne API oraz PLN, kg i polska reguła VAT. W konfiguracji testowej ustaw porty `HTTP_PORT=18080`, `HTTPS_PORT=18443`, `MAILPIT_PORT=18025`. Używaj istniejącego `deploy/api-test.env.example` jako podstawy prywatnej konfiguracji testowej; nie zapisuj haseł i klucza w repozytorium. Nie wskazuj wolumenów zwykłego sklepu przez `DB_VOLUME_NAME` lub `SHOP_VOLUME_NAME`.

Po ustawieniu w środowisku `PRESTASHOP_API_KEY` instancji testowej oraz `REQUESTS_CA_BUNDLE` na jej zaufany certyfikat:

```sh
export D21_TEST_API_URL=https://localhost:18443/api
python -m pytest -o addopts= -q scraper/test_api_integration.py scraper/test_product_import_integration.py scraper/test_image_import_integration.py
```

HTTP używa `http://localhost:18080/api` i wymaga testowej konfiguracji Nginx obsługującej API bez przekierowania na domyślny port HTTPS. Fixture ustawia domeny i flagi SSL wyłącznie w kontenerze `prestashop-d21-tests-shop-1`; test odrzuca pozostałe adresy API. Przygotowanie usług i konfiguratorów PHP opisują [wdrożenie](DEPLOYMENT.md) oraz [inicjalizacja](INITIALIZATION.md).

Test całego eksportu pozostawia kategorie w testowym sklepie. Testy produktów tworzą sześć własnych rekordów na scenariusz i sprzątają je oraz własną cechę przez lokalny model PHP. Klucz API pozostaje bez DELETE. Domyślne testy offline nie zapisują map w `scraper-results/` ani nie uruchamiają importu po wykryciu brakującego pliku.

D2.3 obejmuje ceny i rzeczywisty wariant domyślny, cechy, kombinacje, idempotencję, zmianę opisów i cen, zachowanie magazynu i metadanych, jawny reset ilości, utratę odpowiedzi POST/PUT, przejęcie referencji bez dziennika oraz dry run bez zapisu. Reguły i odbiór pełnego eksportu opisuje [import produktów](PRODUCT_IMPORT.md).

## PHP

Po inicjalizacji osobnej instancji uruchom wybrany plik `deploy/init/tests/*-test.php`, np.:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/source-test.php
```

`config-export-test.php` sprawdza eksport konfiguracji. Zmienia ustawienia, przewoźników, płatności, CMS, podatki, kraje i moduł, odtwarza je z eksportu i potwierdza idempotencję. Na końcu przywraca stan wyjściowy.

Testy zmieniające dane uruchamiaj na osobnych wolumenach. Szczegóły inicjalizacji opisuje [instrukcja](INITIALIZATION.md), a ograniczenia weryfikacji — [audyt](AUDIT.md).

Testy D2.4 w `scraper/test_image_import.py` sprawdzają ZIP, limity pobierania, JPEG/WebP, EXIF, przezroczystość, cache i uzgadnianie niepewnego POST. `scraper/test_image_import_integration.py` korzysta z tej samej odrębnej instancji D2 i własnych produktów fixture: upload, ponowienie, zmiana pliku pod tym samym URL, zmiana okładki, utrata odpowiedzi POST/PUT, przejęcie eksportu PHP i brak zapisu w dry run. Własne produkty oraz testowe mapowania PHP są sprzątane. Wyniki pełnego katalogu opisuje [D2.4](IMAGE_IMPORT.md).

## Orkiestrator D2.5

`python -m pytest -q scraper/test_catalog_import.py` sprawdza walidację offline, dry run bez Compose i plików, granice `--resume`/`--sync`, backend i adres stanu, aktywację tylko własnych nowych produktów, wznowienie po utracie odpowiedzi, zachowanie dezaktywacji administratora, blokadę innych procesów, filtrowanie sekretów i izolowany generator benchmarku. W kontenerze odrębnej instalacji API `php /opt/shop/init/tests/api-initialization-test.php` sprawdza podział faz i odmowę uruchomienia backendu PHP. Pełne przebiegi i benchmark są uruchamiane jawnie komendami z [D2.5](CATALOG_IMPORT.md), poza domyślną suite offline.

## Regresje uruchamiania na Windows

```sh
python -X utf8 -m pytest -q scraper/test_compose_encoding.py scraper/test_import_state.py
```

Testy sprawdzają UTF-8 wyjścia Compose, zachowanie właściwego błędu procesu oraz atomowy zapis dziennika przy krótkotrwałych blokadach plików Windows. Nie uruchamiają sklepu ani importu.

## Płatności demonstracyjne BLIK i PayPal

Na przygotowanej instancji testowej z zainstalowanym `shopdemopayments`:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/demo-payments-test.php
```

Test wymaga istniejącego klienta z koszykiem i polskimi adresami oraz prostego produktu na stanie. Tworzy i usuwa tymczasowy koszyk; zastępuje zapis zamówienia obiektem testowym. Sprawdza obie opcje, status bez opłacenia, kwotę wyliczoną przez serwer, token, klienta, błędne żądania i ponowienie. Nie tworzy zamówień ani wiadomości i nie jest pełnym testem E2E zapisu zamówienia w bazie.

## Kontrola jakości i CI

Regresje obsługi lokalnego `robots.txt` uruchom bez dostępu do sieci:

```sh
python -X utf8 -m pytest scraper/test_robots_policy.py scraper/test_variant_prices.py -q
```

Testy sprawdzają reguły ścieżek i agentów, zakres domeny, błędny plik polityki, blokowanie żądań oraz przekierowań w Playwright i Requests. Nie pobierają stron źródłowego sklepu.

```sh
python -m ruff check scraper
```

Workflow `.github/workflows/scraper-ci.yml` uruchamia na pull requestach zadania **Ruff** oraz **Scraper unit tests** (Python 3.14, `python -m pytest -q`). Nie obejmuje automatycznie zestawu `tests/unit`, PHP, JavaScript, integracji API ani E2E. Wyniki sprawdzaj w zakładce **Checks** dla najnowszej wersji PR-a.

Powiązane instrukcje: [scraper](SCRAPER.md), [inicjalizacja](INITIALIZATION.md), [backup](BACKUP.md).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
