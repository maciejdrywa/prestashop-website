# Twoje Drzwi do Sukcesu

Sklep edukacyjny na PrestaShop 8.2.8 realizowany w ramach Biznesu elektronicznego na Politechnice Gdańskiej. Katalog odwzorowuje sklep Pan Domu. Projekt obsługuje polskie treści, warianty i galerie produktów, przelew, pobranie, dostawy, demonstracyjne faktury VAT i pocztę w Mailpit.

## Uruchomienie

Wymagane są Docker Engine z Compose v2, Python z zależnościami `scraper/requirements.txt`, plik `.env` uzupełniony na podstawie `.env.example` oraz `products.json` i `categories.json`. Brakujące zdjęcia pobiera importer.

Z głównego katalogu repozytorium wykonaj jedną komendę.

```sh
python scraper/import_catalog.py --bootstrap --resume
```

Polecenie przygotowuje zdjęcia i certyfikat, uruchamia usługi, instaluje sklep i importuje katalog przez Webservice API. Po kontroli aktywuje nowe produkty. Przy ponownym wykonaniu zachowuje istniejące produkty, treści, zamówienia i magazyn. Zmienione dane źródłowe wymagają świadomego importu opisanego w dokumentacji.

Sklep jest dostępny pod `https://localhost`, panel pod `https://localhost/admin-dev/`, a poczta pod `http://localhost:8025`. Dane administratora określa `.env` podczas pierwszej instalacji. Lokalny certyfikat wymaga dodania do zaufanych zgodnie z instrukcją systemu.

### Webservice API

Webservice API jest konfigurowane podczas inicjalizacji. Importer Python synchronizuje kategorie oraz produkty, cechy, kombinacje, magazyn i galerie; wspólna komenda używa API, a historyczny bootstrap PHP pozostaje dostępny jako osobny backend. Parametry połączenia określa `.env`:
- `PRESTASHOP_API_URL` — adres API (domyślnie `https://localhost/api`),
- `PRESTASHOP_API_KEY` — 32-znakowy klucz alfanumeryczny z uprawnieniami *Least Privilege* (zasoby katalogu, bez dostępu do zamówień i klientów),
- `PRESTASHOP_API_VERIFY_SSL` — weryfikacja certyfikatu TLS (`false` dla środowiska lokalnego).

Kontrolę połączenia, tożsamości instalacji i uprawnień, bez zapisywania danych, wykonuje:

```sh
python3 scraper/test_api_client.py
```

Konfigurację i wznowienie kategorii opisuje [Webservice](docs/WEBSERVICE.md), a komendy i wyniki D2.3 — [import produktów](docs/PRODUCT_IMPORT.md). Zdjęcia i wznowienie galerii opisuje [import zdjęć D2.4](docs/IMAGE_IMPORT.md). Klucz importera nie ma uprawnień DELETE; stary test tworzący i usuwający kategorię zastąpiła kontrola tylko do odczytu.
## Testy E2E

Test Playwright sprawdza pełny zakup: koszyk, rejestrację, dostawę, płatność przy odbiorze, status zamówienia i pobranie faktury VAT. W aktywnym środowisku wirtualnym z zależnościami z `tests/requirements.txt`:

```sh
BASE_URL=https://localhost python -m pytest -c pytest-e2e.ini
```

```powershell
$env:BASE_URL="https://localhost"; python -m pytest -c pytest-e2e.ini
```

Instalację, zmienne środowiskowe, scenariusz i raporty opisuje [instrukcja testów](tests/README.md).

Pełny przepływ, tryby `--resume`/`--sync`, raporty i benchmark opisuje [D2.5](docs/CATALOG_IMPORT.md).

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
