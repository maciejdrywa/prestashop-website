# Twoje Drzwi do Sukcesu

Edukacyjny sklep internetowy realizowany w ramach przedmiotu **Biznes elektroniczny** na Politechnice Gdańskiej. Odwzorowuje katalog i wygląd sklepu Pan Domu. Obsługuje polskie treści, warianty i galerie produktów, dostawy, płatności, demonstracyjne faktury VAT i pocztę testową.

Projekt łączy PrestaShop **8.2.8**, PHP **8.1**, MariaDB **10.11.14**, Nginx i Mailpit w Docker Compose z Pythonowym scraperem i importerami Webservice API. Testy wykorzystują pytest i Playwright. Dane źródłowe służą dydaktyce; sklepu z tymi danymi nie udostępniaj publicznie w internecie.

## Wymagania

- Git oraz działający Docker Engine lub Docker Desktop z Compose v2, w trybie kontenerów Linux.
- Python 3.11+ z pip i venv. CI scrapera używa Pythona 3.14.
- Wolne porty 80, 443 i 8025 dla domyślnej instalacji lokalnej.
- Pliki `scraper-results/products.json` i `categories.json` z repozytorium. Brakujące zdjęcia przygotuje importer.

Obrazy korzystają z `linux/amd64`; Apple Silicon wymaga emulacji. Szczegóły platform i dotychczasowej weryfikacji zawiera [wdrożenie](docs/DEPLOYMENT.md#platformy-i-zatrzymanie).

## Uruchomienie krok po kroku

### 1. Przygotuj repozytorium i Pythona

Sklonuj repozytorium i przejdź do jego katalogu głównego:

```sh
git clone https://github.com/maciejdrywa/prestashop-website.git
cd prestashop-website
```

Wszystkie dalsze polecenia wykonuj z tego katalogu.

PowerShell (Windows):

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r scraper/requirements.txt
Copy-Item .env.example .env
```

Bash (Linux/macOS):

```sh
python -m venv .venv
source .venv/bin/activate
python -m pip install -r scraper/requirements.txt
cp .env.example .env
```

### 2. Uzupełnij lokalny `.env`

Ustaw `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` oraz `PRESTASHOP_API_KEY` (32 znaki alfanumeryczne). `TLS_CERT_DIR` musi być bezwzględną ścieżką katalogu certyfikatów na Twoim komputerze, np. `C:/Users/Jan/.prestashop-website/certs` lub `/home/jan/.prestashop-website/certs`.

Dla domyślnego startu pozostaw `PS_DOMAIN=localhost`, `PRESTASHOP_API_URL=https://localhost/api` i domyślne porty. `PRESTASHOP_API_VERIFY_SSL=false` dotyczy lokalnego samopodpisanego certyfikatu. Plik `.env` pozostaje poza Gitem. Pełny opis ustawień znajduje się we [wdrożeniu](docs/DEPLOYMENT.md) i [instrukcji API](docs/WEBSERVICE.md).

### 3. Uruchom sklep i inicjalizację danych

W aktywnym środowisku Pythona:

```sh
python scraper/import_catalog.py --bootstrap --resume
```

Komenda przygotowuje zdjęcia i certyfikat, uruchamia kontenery, instaluje sklep i importuje katalog przez API. Po kontroli aktywuje nowe produkty. Sukces potwierdza zakończenie polecenia kodem 0 oraz dostępność usług poniżej. Przy błędzie sprawdź komunikat i [rozwiązywanie problemów](docs/TROUBLESHOOTING.md).

Po przerwaniu można wykonać tę samą komendę. Wznowienie zachowuje istniejące produkty, treści, zamówienia i magazyn; zmiany danych źródłowych wymagają świadomej synchronizacji opisanej w [imporcie katalogu](docs/CATALOG_IMPORT.md).

### 4. Otwórz usługi

| Usługa | Adres |
|---|---|
| Sklep | [https://localhost](https://localhost) |
| Panel administratora | [https://localhost/admin-dev/](https://localhost/admin-dev/) |
| Poczta testowa Mailpit | [http://localhost:8025](http://localhost:8025) |

Dane administratora pochodzą z `.env` podczas pierwszej instalacji. Ich późniejsza zmiana w pliku nie resetuje hasła istniejącego konta.

Certyfikat `localhost.crt` powstaje w `TLS_CERT_DIR`. Dodaj go do zaufanych certyfikatów systemu lub przeglądarki zgodnie z [instrukcją HTTPS](docs/DEPLOYMENT.md#https).

Aby zatrzymać usługi z zachowaniem danych:

```sh
docker compose --env-file .env -f deploy/compose.yaml stop
```

## Testy jednostkowe

W aktywnym środowisku `.venv` zainstaluj zależności testowe:

```sh
python -m pip install -r scraper/requirements-dev.txt -r tests/requirements.txt
python -m playwright install chromium
```

Na Linux brakujące biblioteki systemowe przeglądarki instaluje `python -m playwright install --with-deps chromium`.

**Scraper offline**, bez działającego sklepu i z zablokowaną siecią:

```sh
python -m pytest -q
```

Ta komenda zbiera wyłącznie testy `scraper/` i pomija integracje API. Odpowiada zestawowi uruchamianemu w CI.

**Pomocniki i konfiguracja**, bez działającego sklepu:

```sh
python -m pytest -c pytest-e2e.ini tests/unit
```

Część tych testów używa Chromium i lokalnego serwera HTTP. Pełny zestaw obejmuje też bezpośrednie uruchamianie skryptów Bash i wymaga Linux/macOS lub WSL z linuksowym Pythonem; natywny Windows nie spełnia wszystkich wymagań tych testów. Szczegóły, dodatkowe testy JavaScript/PHP oraz kontrolę Ruff opisuje [testowanie](docs/TESTING.md).

## Testy E2E

Po instalacji zależności testowych i Chromium, na uruchomionym sklepie:

```sh
python -m pytest -c pytest-e2e.ini --base-url https://localhost
```

Testy sprawdzają m.in. pełny zakup z rejestracją, dostawą, płatnością i fakturą. Tworzą konta i zamówienia oraz zmieniają stany magazynowe. Przygotowanie sklepu, dane administratora i raporty opisuje [instrukcja testów](docs/TESTING.md).

## Struktura projektu i dokumentacja

| Katalog | Odpowiedzialność | Instrukcja |
|---|---|---|
| `shop/` | Obraz sklepu, motyw potomny i własne moduły | [Sklep](docs/SHOP.md) |
| `scraper/` | Pobieranie, parsowanie i import danych | [Scraper](docs/SCRAPER.md), [import](docs/CATALOG_IMPORT.md) |
| `scraper-results/` | Eksport katalogu i próbki zdjęć | [Dane](docs/DATA.md) |
| `deploy/` | Kontenery, HTTPS, inicjalizacja i kopie | [Wdrożenie](docs/DEPLOYMENT.md), [backup](docs/BACKUP.md) |
| `tests/` | Testy jednostkowe i E2E | [Testowanie](docs/TESTING.md) |
| `docs/` | Instrukcje, raporty, audyty i dziennik AI | [Indeks dokumentacji](docs/README.md) |

Przepływ danych opisuje [architektura](docs/ARCHITECTURE.md), a reguły sprzedaży [konfiguracja sklepu](docs/CONFIGURATION.md).

## Zespół

| Imię i nazwisko | Numer indeksu |
|---|---|
| Maciej Drywa | s203556 |
| Paweł Krajewski | s203918 |
| Wiktor Adamczyk | s203519 |

## Współpraca

Zmiany przechodzą przez GitHub Issue, osobną gałąź, Pull Request, recenzję i scalenie do `main`. PR należy powiązać z Issue. Istotny udział AI opisujemy w `docs/AI_LOG.md` i oznaczamy etykietą `ai-assisted`. Historia decyzji i wpisy dziennika pozostają zachowane.
