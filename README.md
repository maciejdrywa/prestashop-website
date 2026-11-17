# Twoje Drzwi do Sukcesu

Sklep edukacyjny na PrestaShop 8.2.8 realizowany w ramach Biznesu elektronicznego na Politechnice Gdańskiej. Katalog odwzorowuje sklep Pan Domu. Projekt obsługuje polskie treści, warianty i galerie produktów, przelew, pobranie, dostawy, demonstracyjne faktury VAT i pocztę w Mailpit.

## Uruchomienie

Wymagane są Docker Engine z Compose v2, plik `.env` uzupełniony na podstawie `.env.example` oraz `products.json`, `categories.json` i komplet lokalnych zdjęć w `scraper-results/images/`.

Z głównego katalogu repozytorium wykonaj jedną komendę.

```sh
docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.bootstrap.yaml run --build --rm bootstrap
```

Polecenie przygotowuje certyfikat, uruchamia usługi, instaluje pusty sklep i wznawia import. Przy ponownym wykonaniu zachowuje istniejące produkty, treści, zamówienia i magazyn. Zmienione dane źródłowe wymagają świadomego importu opisanego w dokumentacji.

Sklep jest dostępny pod `https://localhost`, panel pod `https://localhost/admin-dev/`, a poczta pod `http://localhost:8025`. Dane administratora określa `.env` podczas pierwszej instalacji. Lokalny certyfikat wymaga dodania do zaufanych zgodnie z instrukcją systemu.

## Dokumentacja

- Przygotowanie `.env`, certyfikat i uruchamianie ręczne opisuje [wdrożenie](docs/DEPLOYMENT.md).
- Kolejność etapów, wznowienie i jawne aktualizacje opisuje [inicjalizacja](docs/INITIALIZATION.md).
- Pobieranie katalogu i zdjęć opisuje [scraper](docs/SCRAPER.md).
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
