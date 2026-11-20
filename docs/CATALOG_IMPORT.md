# D2.5 — jedna komenda importu przez API

Domyślny tor inicjalizacji katalogu działa na hoście w Pythonie i zapisuje kategorie, produkty, cechy, kombinacje, magazyn, zdjęcia oraz aktywność przez Webservice. Konfiguratory PHP przygotowują ustawienia sklepu przed importem i funkcje zależne od katalogu po jego odbiorze. Importery katalogu PHP nie są uruchamiane w tym torze.

## Uruchomienie

Wymagane: Docker z Compose v2, Python 3.10+, zależności `scraper/requirements.txt`, `.env` uzupełniony na podstawie istniejącego `.env.example` oraz eksport produktów i kategorii. Pełny lokalny katalog zdjęć nie jest wymagany — przygotuje go cache D2.4.

```sh
python -m pip install -r scraper/requirements.txt
python scraper/import_catalog.py --bootstrap --resume
```

Komenda wykonuje walidację danych, przygotowanie cache, build/start usług jedynego `deploy/compose.yaml`, przygotowanie sklepu i API, import kategorii, produktów i magazynu, import zdjęć, niezależny odczyt kontrolny, aktywację nowych produktów i konfigurację końcową. API musi wskazywać instancję tego samego projektu Compose; tożsamości eksportu PHP i endpointu API są porównywane przed zapisem katalogu.

Nowa instalacja usuwa wyłącznie rozpoznane produkty demonstracyjne przez istniejący konfigurator storefront. Chronione są zmodyfikowane lub obce produkty. Aktywowane są wyłącznie produkty utworzone przez importer i jeszcze nieodebrane; po zapisanej aktywacji późniejsza dezaktywacja administratora pozostaje zachowana. Stany po sprzedaży są zachowywane.

Pełna konfiguracja wymaga HTTPS: konfigurator komunikatu bezpieczeństwa połączenia sprawdza włączenie SSL. Dla instancji testowej ustaw zgodne `PS_DOMAIN`, `PRESTASHOP_API_URL`, port HTTPS i `PS_ENABLE_SSL=1`. Lokalny certyfikat można wskazać przez standardowe `REQUESTS_CA_BUNDLE`; przy certyfikacie self-signed dotychczasowa konfiguracja dopuszcza lokalne `PRESTASHOP_API_VERIFY_SSL=false`.

## Tryby i pliki

| Opcja | Działanie |
|---|---|
| `--bootstrap` | Build i uruchomienie usług Compose z oczekiwaniem na gotowość |
| `--resume` | Kontynuacja tego samego zestawu danych; domyślny tryb |
| `--sync` | Jawna synchronizacja zmienionych JSON-ów lub zawartości zdjęć |
| `--reset-stock` | Jawne odtworzenie początkowych ilości; bez tej opcji sprzedaż nie jest resetowana |
| `--dry-run` | Walidacja konfiguracji i danych oraz plan bez Compose, pobierania, zapisów sklepu i plików stanu/raportu |
| `--env-file` | Domyślnie istniejący `.env`; wybrany plik ma pierwszeństwo, środowisko procesu uzupełnia brakujące wartości |
| `--products-file`, `--categories-file` | Własne pliki wejściowe |
| `--cache-dir` | Domyślnie `scraper-results/images/` |
| `--state-dir`, `--report-file` | Domyślnie `logs/catalog-import/<host>/` i `report.json` |
| `--mapping-file` | Opcjonalna mapa kategorii z dotychczasowego importu |
| `--product-state-file`, `--image-state-file` | Opcjonalne istniejące dzienniki D2.3/D2.4 |
| `--no-release` | Tylko cache i źródło, bez przypiętego wydania zdjęć |

Przykład planu, również przy wyłączonych kontenerach:

```sh
python scraper/import_catalog.py --bootstrap --resume --dry-run
```

Stan łączy backend, URL, tożsamość instalacji, SHA-256 obu JSON-ów i zdjęć oraz checkpointy aktywacji. Nie usuwaj go pomiędzy przebiegami. Zmienione dane przy `--resume` są odrzucane. Jeśli poprzedni przebieg pozostawił niepewny POST bez potwierdzonego ID, najpierw uzgodnij go przez `--resume`. Jednoznacznie odrzucony zapis nie blokuje poprawienia danych i ponowienia przez `--sync`. Zmiana instalacji pod tym samym adresem wymaga osobnego stanu, bez przenoszenia starych mapowań.

Orkiestrator ponownie kontroluje etapy przy wznowieniu; pominięcie zapisów wynika z porównania faktycznych danych i dzienników D2.2–D2.4. Nie pomija kontroli tylko dlatego, że poprzedni raport oznaczał etap jako ukończony. Po odebraniu katalogu zmiana zarządzanych pól w panelu zatrzymuje `--resume` przed ich nadpisaniem; świadome przywrócenie źródła wymaga `--sync`. Synchronizacja aktualizuje rekordy obecne w wejściu; nie usuwa produktów pominiętych w nowym pliku. Zasady uzgadniania niepewnych POST pozostają opisane w dokumentacji importerów.

## Dotychczasowa instalacja i backend PHP

Przed pierwszym przejęciem istniejącej instalacji wykonywany jest eksport tożsamości i mapowań zdjęć tylko do odczytu. Potwierdzone galerie PHP są przejmowane bez uploadu. Jeżeli obrazy były wcześniej dodane przez API, wskaż ich dotychczasowy `--image-state-file`; dla aktywacji własnych produktów D2.3 zachowaj również `--product-state-file`.

W wolumenie sklepu powstaje `var/import/catalog-backend.json`. Znacznik `api` blokuje przypadkowy natywny import PHP. Przejęcie znacznika `php` przez API wymaga jawnego `--sync`. Historyczny dziennik inicjalizacji PHP pozostaje zachowany; konfiguracja API ma osobny plik `api-initialization-state.json`.

Dotychczasowy bootstrap PHP pozostaje dostępny dla instalacji używających backendu PHP:

```sh
docker compose --env-file .env -f deploy/compose.yaml run --build --rm bootstrap
```

Wewnętrzne fazy PHP `init-shop.php --api-prepare` i `--api-finalize` wykonują wyłącznie konfiguratory. Przygotowanie poprzedza zapis katalogu. Finalizacja obejmuje menu, promocje, Omnibus, wyszukiwanie i filtry po aktywacji odebranego katalogu. Utrata wymaganych powiązań ukończonego konfiguratora zatrzymuje wznowienie bez nadpisywania ustawień administratora. Wszystkie tryby korzystają z jednego pliku Compose.

Blokada orkiestratora obejmuje całą sesję API na tym hoście i dopuszcza wyłącznie jawnie zagnieżdżone etapy tej samej sesji. Blokady PHP chronią fazy konfiguracji. Znacznik backendu chroni przed uruchomieniem drugiego toru katalogowego; nie jest blokadą panelu administratora. Na czas importu wstrzymaj równoległą edycję katalogu.

## Weryfikacja i raport

Przed aktywacją odczytywane są wszystkie produkty, kombinacje, kategorie i magazyn. Kontrola obejmuje liczności, referencje, pola i opisy, netto i brutto kombinacji, VAT, wagi, przypisania kategorii, komplet i sumy stanów, warianty domyślne, galerie i okładki. Pliki i uploady są sprawdzane przez D2.4. Nowe początkowe ilości odpowiadają planowi 0–10; wznowienie zachowuje aktualny magazyn. Kontrola po finalizacji potwierdza katalog i dostępność strony.

`report.json` zapisuje wyniki etapów, utworzone/zaktualizowane/pominięte zasoby, błędy z etapem i źródłowym ID, statusy HTTP, wagi demonstracyjne i placeholdery, trafienia cache, liczby żądań/ponowień i metody API oraz czasy. `compose_seconds` jest mierzone osobno; `import_seconds` obejmuje pozyskanie brakujących zdjęć. Błąd daje niezerowy kod zakończenia. Diagnostyka Compose usuwa klucze, hasła i linie uwierzytelniania.

Sekcja `summary` jest zapisywana w raporcie i wypisywana w terminalu. Dla `categories`, `products`, `combinations` i `images` zawiera liczniki `created`, `updated` i `rejected` bieżącego przebiegu, również gdy import zakończy się błędami. `created` i `updated` oznaczają potwierdzone operacje; aktywacja i zmiana okładki nie są dodatkowym rekordem. `rejected` obejmuje błędy importu kategorii, produkty z nieukończonym importem, nieudane próby synchronizacji kombinacji oraz niepotwierdzone obrazy w nieukończonych galeriach. Błąd kombinacji może więc zwiększyć licznik kombinacji i produktu. Obrazy potwierdzone przed błędem galerii pozostają policzone jako utworzone, zaktualizowane lub pominięte. Błędy infrastruktury pozostają w `errors`, bez przypisywania ich do fikcyjnego rekordu; nieuruchomione etapy mają zerowe liczniki.

`idempotent=true` oznacza przebieg bez błędów i bez tworzenia/aktualizacji kategorii, produktów, słowników, kombinacji, zdjęć i aktywacji. Kontrole GET oraz idempotentne konfiguratory PHP mogą nadal wykonywać odczyty lub aktualizować własne pliki i indeksy.

## Benchmark 1000 produktów

Zestaw techniczny jest tworzony poza eksportem akademickim. Zachowuje 574 rekordy wejściowe i dodaje deterministyczne kopie o osobnych ID; dla bieżących danych daje 1000 produktów, 2211 kombinacji i 2434 przypisania obrazów. Powtarzane obrazy korzystają ze wspólnego cache, ale każde przypisanie jest przesyłane do galerii odpowiedniego produktu.

```sh
python scraper/benchmark_catalog.py --output-dir logs/benchmark-data
python scraper/import_catalog.py --bootstrap --resume \
  --env-file /sciezka/prywatny-benchmark.env \
  --products-file logs/benchmark-data/products.json \
  --categories-file logs/benchmark-data/categories.json \
  --cache-dir logs/benchmark-run/cache \
  --state-dir logs/benchmark-run
```

Plik środowiskowy benchmarku musi wskazywać osobny projekt i wolumeny, zgodne porty HTTPS oraz osobne sekrety; użyj istniejącego szablonu środowiska. Cache benchmarku powinien być pusty przed pierwszym pomiarem. Nie używaj wolumenów sklepu ani katalogu stanu rzeczywistego importu. Kryterium: kompletny wynik 1000 produktów wraz ze zdjęciami i `import_seconds <= 1800`; build/start kontenerów są raportowane oddzielnie. Współbieżność pozostaje sekwencyjna do czasu pomiaru.

## Wyniki odbioru — 20 listopada 2026 r.

Testy uruchomiono na oddzielnych projektach i wolumenach `prestashop-d25-real` oraz `prestashop-d25-benchmark`. Zwykły sklep i wcześniejsza instancja D2.3–D2.4 nie były modyfikowane.

| Kontrola | Wynik |
|---|---|
| Katalog akademicki | 574 aktywne produkty, 1269 kombinacji, 1389 obrazów (1388 zdjęć + placeholder), 57 produktów bez stanu |
| Ponowne wykonanie katalogu akademickiego | `idempotent=true`, brak nowych/aktualizowanych rekordów katalogu |
| Dane A → B → A | Tylko jeden produkt aktualizowany; 1269 kombinacji i 1389 obrazów pomijanych |
| Zachowanie magazynu | Symulowana zmiana produktu 539 z 2 do 1 zachowana przez `--sync`; jawny `--reset-stock` przywrócił 2 |
| Ochrona edycji administratora | Zmiana nazwy produktu 539 zablokowała nadpisanie przez `--resume` bez PUT; jawny `--sync` przywrócił dane źródłowe |
| Rzeczywiste strony produktów 5 i 539 | Strony oraz obrazy HTTP 200, produkty aktywne, galerie odpowiednio 4 i 1 obraz |
| Benchmark | 1000 aktywnych produktów, 2211 kombinacji, 2434 obrazy, 100 produktów bez stanu |
| Czas benchmarku bez Compose | **953,303 s (15 min 53 s)**, obejmuje pozyskanie brakujących obrazów; limit 1800 s spełniony |
| Start/build Compose benchmarku | 26,531 s, świeże wolumeny, warstwy obrazu Dockera w cache |
| Cała komenda benchmarku | 979,834 s |
| Niezależna kontrola plików benchmarku | 2434 oryginały i 12170 miniatur, poprawne galerie i okładki; 757 produktów z wieloma obrazami |
| Przerwanie i wznowienie | Ctrl+C podczas ponownego importu: kod 130 i trwały stan błędu; `--resume` zakończone kodem 0 |
| Ponowny import benchmarku | 196,466 s, `idempotent=true`, 12260 żądań GET, **zero POST/PUT/DELETE** |

Sprzęt: Apple M4 Pro, 12 rdzeni CPU, 24 GiB RAM, macOS na arm64. Docker Engine 29.9.2 miał 12 CPU i 8319238144 bajtów RAM (około 7,75 GiB). Sklep PHP i MariaDB działały jako `linux/amd64` z emulacją. Import pozostał sekwencyjny, bez zwiększania współbieżności.

Natywne kontrole PHP potwierdziły pola, tłumaczenia, kategorie, ceny, wagi, kombinacje, ilości, warianty domyślne i politykę braku zamówień ponad stan. Odrębny odczyt bazy/plików potwierdził obrazy i miniatury. Test backendu odrzucił próbę uruchomienia toru PHP na instalacji API.

Końcowa suite offline: **246 testów i 11 podtestów zaliczonych**, 14 testów integracyjnych wyłączonych z przebiegu offline. Ruff i kontrola białych znaków Git przeszły bez błędów. Opisane powyżej testy rzeczywistego API i Compose wykonano osobno.

Szczegółowe raporty pozostają lokalnie w ignorowanych `logs/d25-real-v2/` i `logs/d25-benchmark/`: `report-activation.json`, `report-idempotent.json`, `report-sync-b.json`, `report-restore-a.json`, `report-cold.json`, `report-interrupted.json`, `report-resumed.json` i kontrole natywne. Zestaw techniczny jest osobno w `logs/d25-benchmark-data/`; `scraper-results/products.json` nadal zawiera 574 rzeczywiste produkty.

Ograniczenia: pomiar dotyczy wskazanego sprzętu i wersji; pełnego uruchomienia nowej komendy na Windows i natywnym Linux nie sprawdzono. Benchmark używa deterministycznych kopii istniejących danych i współdzielonego cache źródłowych URL-i, z osobnymi uploadami do każdej galerii. Nie jest rozszerzeniem katalogu akademickiego.

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
