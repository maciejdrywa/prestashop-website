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

Webservice API jest konfigurowane podczas inicjalizacji. Importer Python synchronizuje kategorie oraz produkty, cechy, kombinacje i magazyn; bootstrap nadal używa natywnych importerów PHP do katalogu. Parametry połączenia określa `.env`:
- `PRESTASHOP_API_URL` — adres API (domyślnie `https://localhost/api`),
- `PRESTASHOP_API_KEY` — 32-znakowy klucz alfanumeryczny z uprawnieniami *Least Privilege* (zasoby katalogu, bez dostępu do zamówień i klientów),
- `PRESTASHOP_API_VERIFY_SSL` — weryfikacja certyfikatu TLS (`false` dla środowiska lokalnego).

Kontrolę połączenia, tożsamości instalacji i uprawnień, bez zapisywania danych, wykonuje:

```sh
python3 scraper/test_api_client.py
```

Konfigurację i wznowienie kategorii opisuje [Webservice](docs/WEBSERVICE.md), a komendy i wyniki D2.3 — [import produktów](docs/PRODUCT_IMPORT.md). Klucz importera nie ma uprawnień DELETE; stary test tworzący i usuwający kategorię zastąpiła kontrola tylko do odczytu.

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
| `tests/` | Informacja o bieżącym zakresie testowania |

Baza korzysta z MariaDB 10.11.14, HTTPS obsługuje Nginx. Pełny katalog danych zawiera obecnie 574 produkty, 80 kategorii, 1269 kombinacji i 1388 zdjęć. Produkt 539 nie ma galerii źródłowej. Zestaw zdjęć i archiwum są wykluczone z Gita, a do dystrybucji służy GitHub Releases.

## Zespół

| Imię i nazwisko | Numer indeksu |
|---|---|
| Maciej Drywa | s203556 |
| Paweł Krajewski | s203918 |
| Wiktor Adamczyk | s203519 |

## Współpraca

Zmiany przechodzą przez GitHub Issue, osobną gałąź, Pull Request, recenzję i scalenie do `main`. PR należy powiązać z Issue. Istotny udział AI opisujemy w `docs/AI_LOG.md` i oznaczamy etykietą `ai-assisted`. Historia decyzji i wpisy dziennika pozostają zachowane.
