# Twoje Drzwi do Sukcesu

Sklep edukacyjny na PrestaShop 8.2.8 realizowany w ramach Biznesu elektronicznego na Politechnice Gdańskiej. Katalog odwzorowuje sklep Pan Domu. Projekt obsługuje polskie treści, warianty i galerie produktów, przelew, pobranie, dostawy, demonstracyjne faktury VAT i pocztę w Mailpit.

## Uruchomienie

Wymagane są Docker Engine z Compose v2, plik `.env` uzupełniony na podstawie `.env.example` oraz `products.json`, `categories.json` i komplet lokalnych zdjęć w `scraper-results/images/`.

Z głównego katalogu repozytorium wykonaj jedną komendę.

```sh
docker compose --env-file .env -f deploy/compose.yaml run --build --rm bootstrap
```

Polecenie przygotowuje certyfikat, uruchamia usługi, instaluje pusty sklep i wznawia import. Przy ponownym wykonaniu zachowuje istniejące produkty, treści, zamówienia i magazyn. Zmienione dane źródłowe wymagają świadomego importu opisanego w dokumentacji.

Sklep jest dostępny pod `https://localhost`, panel pod `https://localhost/admin-dev/`, a poczta pod `http://localhost:8025`. Dane administratora określa `.env` podczas pierwszej instalacji. Lokalny certyfikat wymaga dodania do zaufanych zgodnie z instrukcją systemu.

### Webservice API

Webservice API jest konfigurowane podczas inicjalizacji. Importer Python synchronizuje kategorie oraz produkty, cechy, kombinacje, magazyn i galerie; bootstrap nadal używa natywnych importerów PHP do katalogu. Parametry połączenia określa `.env`:
- `PRESTASHOP_API_URL` — adres API (domyślnie `https://localhost/api`),
- `PRESTASHOP_API_KEY` — 32-znakowy klucz alfanumeryczny z uprawnieniami *Least Privilege* (zasoby katalogu, bez dostępu do zamówień i klientów),
- `PRESTASHOP_API_VERIFY_SSL` — weryfikacja certyfikatu TLS (`false` dla środowiska lokalnego).

Kontrolę połączenia, tożsamości instalacji i uprawnień, bez zapisywania danych, wykonuje:

```sh
python3 scraper/test_api_client.py
```

Konfigurację i wznowienie kategorii opisuje [Webservice](docs/WEBSERVICE.md), a komendy i wyniki D2.3 — [import produktów](docs/PRODUCT_IMPORT.md). Zdjęcia i wznowienie galerii opisuje [import zdjęć D2.4](docs/IMAGE_IMPORT.md). Klucz importera nie ma uprawnień DELETE; stary test tworzący i usuwający kategorię zastąpiła kontrola tylko do odczytu.
## Kopie zapasowe i odtwarzanie

Polecenia uruchamiamy z głównego katalogu repozytorium w Bashu: na Linuksie i macOS w terminalu, na Windows w PowerShellu przez `bash …` (WSL) albo w Git Bash. Wymagany jest działający Docker i plik `.env`.

**Kopia zapasowa** działającego sklepu (baza MariaDB, zdjęcia produktów `img/p`, pliki konfiguracyjne i manifest z sumami SHA-256):

```sh
bash deploy/backup.sh                 # bez przerwy w działaniu sklepu
bash deploy/backup.sh --maintenance   # sklep wyłączony na czas kopii, pełna spójność bazy i zdjęć
```

Archiwum `backup_RRRRMMDD_GGMMSS.tar.gz` trafia do `deploy/backups/` (katalog wykluczony z Gita). Zawiera `.env` i klucze sklepu, więc przechowuj je jak hasła.

**Odtworzenie** sklepu z archiwum:

```sh
bash deploy/restore.sh --verify-only deploy/backups/backup_RRRRMMDD_GGMMSS.tar.gz   # tylko kontrola archiwum
bash deploy/restore.sh deploy/backups/backup_RRRRMMDD_GGMMSS.tar.gz                 # odtworzenie wskazanej kopii
bash deploy/restore.sh --latest                                                     # odtworzenie najnowszej kopii
```

Skrypt sprawdza archiwum, prosi o wpisanie nazwy bazy, robi kopię bezpieczeństwa bieżącego stanu, podmienia bazę, zdjęcia i konfigurację, a na końcu sprawdza odpowiedź sklepu. Błąd na dowolnym etapie cofa zmiany. Odtwarzaj wyłącznie archiwa z zaufanego źródła.

**Na nowym komputerze:**

1. Sklonuj repozytorium i przygotuj `scraper-results/` (`products.json`, `categories.json`, komplet zdjęć w `images/` z GitHub Releases). Kopia ich nie zawiera, a usługa `certificates`, od której zależy start bazy, sprawdza ten katalog.
2. Skopiuj archiwum do `deploy/backups/`.
3. Utwórz `.env` z `.env.example` albo użyj `--use-backup-env` (tworzy `.env` z kopii). W obu przypadkach ustaw `TLS_CERT_DIR` na katalog certyfikatów tego komputera.
4. Uruchom `bash deploy/restore.sh --latest` (lub `--latest --use-backup-env`). Skrypt uruchomi kontenery, zainstaluje pusty PrestaShop i zastąpi go kopią. Nie uruchamiaj potem bootstrapu z importem.

**Weryfikacja odtwarzania** na czystym, odizolowanym środowisku (osobny projekt Compose, nowe wolumeny, hasła i port 19443):

```sh
bash deploy/verify-restore.sh              # kopia działającego sklepu, odtworzenie, porównanie, testy, raport
bash deploy/verify-restore.sh --recreate   # ponowienie na nowo utworzonym środowisku
bash deploy/verify-restore.sh --cleanup    # usunięcie środowiska weryfikacyjnego
```

Skrypt mierzy czas odtwarzania (limit 10 minut), porównuje kategorie, produkty, warianty, promocje, zdjęcia i konfigurację z działającym sklepem oraz sprawdza stronę główną, kategorię, produkt, zdjęcia, wyszukiwarkę, koszyk, start zamówienia, panel i API. Wynik zapisuje w [docs/RESTORE_VERIFICATION.md](docs/RESTORE_VERIFICATION.md). Odtworzony sklep zostaje uruchomiony pod `https://localhost:19443` do pokazania podczas odbioru. Szczegóły, opcje i ograniczenia opisuje [deploy/BACKUP.md](deploy/BACKUP.md).

## Testy E2E

Test Playwright sprawdza pełny zakup: koszyk, rejestrację, dostawę, płatność przy odbiorze, status zamówienia i pobranie faktury VAT. W aktywnym środowisku wirtualnym z zależnościami z `tests/requirements.txt`:

```sh
BASE_URL=https://localhost python -m pytest -c pytest-e2e.ini
```

```powershell
$env:BASE_URL="https://localhost"; python -m pytest -c pytest-e2e.ini
```

Instalację, zmienne środowiskowe, scenariusz i raporty opisuje [instrukcja testów](tests/README.md).

## Sprawdzanie jakości kodu scrapera

W aktywnym środowisku wirtualnym Pythona uruchom poniższe polecenia z głównego katalogu repozytorium. Ruff sprawdza pliki Pythona w `scraper/` według reguł z [`ruff.toml`](ruff.toml), a pytest uruchamia [testy scrapera offline](tests/README.md#python-i-javascript).

```sh
python -m pip install -r scraper/requirements-dev.txt
python -m ruff check scraper
python -m pytest -q
```

GitHub Actions uruchamia `Scraper CI` przy każdym pull requeście. W zakładce **Checks** są wyniki zadań **Ruff** i **Scraper unit tests**. Scalaj PR do `main`, gdy oba zadania mają status **Success** dla najnowszej wersji PR-a i recenzent zaakceptował zmiany.

## Dokumentacja

- Przygotowanie `.env`, certyfikat i uruchamianie ręczne opisuje [wdrożenie](docs/DEPLOYMENT.md).
- Kolejność etapów, wznowienie i jawne aktualizacje opisuje [inicjalizacja](docs/INITIALIZATION.md).
- Pobieranie katalogu i zdjęć opisuje [scraper](docs/SCRAPER.md).
- Konfigurację i uprawnienia Webservice API opisuje [API](docs/WEBSERVICE.md).
- Reguły sprzedaży, treści i faktury opisuje [konfiguracja](docs/CONFIGURATION.md).
- Kopie zapasowe, odtwarzanie i jego weryfikację opisuje [deploy/BACKUP.md](deploy/BACKUP.md), a wynik ostatniej weryfikacji [raport](docs/RESTORE_VERIFICATION.md).
- Pozostałe dokumenty i testy zbiera [indeks dokumentacji](docs/README.md).

## Struktura

| Katalog | Odpowiedzialność |
|---|---|
| `shop/` | Obraz PHP 8.1, konfiguracja i autorski moduł cookies |
| `scraper/` | Crawler Playwright, parsery i testy lokalnych danych |
| `scraper-results/` | Eksport UTF-8 i do 20 próbek zdjęć, pełne zdjęcia lokalnie |
| `deploy/` | Compose, HTTPS, inicjalizacja i testy klas PHP |
| `docs/` | Instrukcje, audyt i dziennik AI |
| `tests/` | Testy E2E Playwright scenariusza zakupu i testy jednostkowe pomocników |

Baza korzysta z MariaDB 10.11.14, HTTPS obsługuje Nginx. Pełny katalog danych zawiera obecnie 574 produkty, 80 kategorii, 1269 kombinacji i 1388 zdjęć. Produkt 539 nie ma galerii źródłowej. Zestaw zdjęć i archiwum są wykluczone z Gita, a do dystrybucji służy GitHub Releases.

## Zespół

| Imię i nazwisko | Numer indeksu |
|---|---|
| Maciej Drywa | s203556 |
| Paweł Krajewski | s203918 |
| Wiktor Adamczyk | s203519 |

## Współpraca

Zmiany przechodzą przez GitHub Issue, osobną gałąź, Pull Request, recenzję i scalenie do `main`. PR należy powiązać z Issue. Istotny udział AI opisujemy w `docs/AI_LOG.md` i oznaczamy etykietą `ai-assisted`. Historia decyzji i wpisy dziennika pozostają zachowane.
