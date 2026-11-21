# Inicjalizacja i import danych

Domyślna inicjalizacja przez API: `python scraper/import_catalog.py --bootstrap --resume`. Przygotowanie, finalizację i zachowaną ścieżkę PHP opisuje [D2.5](CATALOG_IMPORT.md).

Poniższe tryby, kolejność i dziennik dotyczą inicjalizatora PHP `init-shop.php`. Podział przygotowania i finalizacji oraz stan domyślnego importera Python opisuje [import katalogu](CATALOG_IMPORT.md).

## Tryby

`init-shop.php --dry-run` sprawdza wejście i wyświetla plan. `--resume` rozpoznaje istniejące rekordy, uzupełnia niedokończoną inicjalizację i zachowuje edycje oraz sprzedaż. Wywołanie bez opcji wykonuje pełne, jawne odtworzenie konfiguracji, danych produktów i początkowych stanów. Ten ostatni tryb może nadpisać zmiany administratora.

Zalecana komenda startowa z [instrukcji wdrożenia](DEPLOYMENT.md) korzysta z `--resume`.

## Kolejność

| Etap | Operacja |
|---|---|
| 1 | Nazwa sklepu i usunięcie demonstracji |
| 2 | Synchronizacja i aktywacja motywu potomnego PAN DOMU |
| 3–6 | Poczta, przewoźnicy, płatności, statusy i faktury |
| 7 | Kategorie i produkty wraz z opisami, cenami, VAT i wagą |
| 8–11 | Menu, strony i stopka, cookies, polskie treści modułów |
| 12 | Włączenie Webservice API i uprawnienia klucza Least Privilege |
| 13–14 | Słownik atrybutów i kombinacje z modyfikatorami netto |
| 15–17 | Galerie, okładki, miniatury i kontrola katalogu |
| 18–19 | Początkowe ilości, aktywacja i kontrola magazynu |
| 20–21 | Promocje cenowe i historia cen Dyrektywy Omnibus |
| 22 | Kafle kategorii, odnośnik „O firmie”, popularne produkty, indeks wyszukiwania i filtry |
| 23 | Konfiguracja z eksportu `deploy/config/shop-config.json` |

Nowe produkty są nieaktywne do zakończenia kontroli danych i galerii. Produkt bez zdjęć źródłowych jest jawnie zgłaszany. Obecnie dotyczy to ID 539 i wymaga potwierdzonego wyjątku przy odbiorze.

## Dziennik i wznowienie

Dziennik `var/import/initialization-state.json` znajduje się w trwałym wolumenie sklepu. Zawiera wersję formatu, identyfikację instalacji, SHA-256 obu JSON-ów i zdjęć, ukończone etapy, własność tworzonych produktów oraz ich ID i potwierdzone operacje. Jest zapisywany atomowo. `init-shop.lock` blokuje drugą inicjalizację.

Pierwsze uruchomienie sprawdza istniejące produkty po referencjach i zachowuje je jako zastane rekordy. Własność nowych rekordów zapisuje przed ich utworzeniem. Po przerwaniu kontynuuje niedokończone etapy i produkty. Kontrola potwierdza rzeczywiste powiązania w bazie i pliki galerii także dla ukończonych etapów.

Stan początkowy jest ustawiany wyłącznie podczas przygotowania nowych produktów. Aktywny produkt po przerwanym zapisie jest rozpoznawany jako udostępniony klientom i jego ilość pozostaje zachowana. Późniejsze kontrole sprawdzają spójność sum wariantów, przyjmując bieżące ilości. Edytowane nazwy, ceny, wagi, opisy, strony CMS i statusy istniejących zamówień pozostają zachowane. Etap 23 stosuje eksport konfiguracji tylko po zmianie pliku w repozytorium ([konfiguracja](CONFIGURATION.md#eksport-konfiguracji-w-repozytorium)).

Zmiana odcisków danych, utrata zapisanego rekordu lub konflikt referencji przerywa proces. Wykonaj kopię bazy i wolumenu, sprawdź przyczynę, a następnie zastosuj wybrane importery. Po świadomym zakończeniu aktualizacji zarchiwizuj dziennik, aby kolejne `--resume` ponownie rozpoznało istniejącą instalację. Usunięcie dziennika przy nieukończonym imporcie wymaga ręcznej kontroli nieaktywnych produktów i etapów.

## Komendy pojedynczych operacji

W działającym sklepie użyj wspólnego wzorca, zastępując nazwę skryptu i argumenty.

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all --dry-run
```

Importery produktów, atrybutów, kombinacji, zdjęć i stanów przyjmują źródłowe ID albo `--all` oraz `--dry-run`. `import-category.php` przygotowuje drzewo kategorii. `check-products.php --all --with-combinations`, `check-images.php --all` i `check-stock.php --all` porównują sklep z początkowym planem. Po sprzedaży lub edycji mogą zgłosić oczekiwane różnice.

`check-environment.php` sprawdza kontekst i bazę. `check-price-settings.php` pokazuje VAT i próbkę przeliczenia. `plan-variants.php` pokazuje źródłowe kombinacje. `audit-sales.php` wypisuje konfigurację sprzedaży z ukryciem szczegółów bankowych. `export-config.php` zapisuje eksport konfiguracji, a `configure-from-export.php [--dry-run]` go odtwarza. Skrypty `configure-*.php` świadomie odtwarzają wskazany obszar ustawień, zwykle obsługując `--dry-run`. Poczta i cookies są wykonywane bez tej opcji.

## Zdjęcia i bezpieczeństwo zapisu

Wymagany jest pełny lokalny katalog `scraper-results/images/`. Nazwa JPEG powstaje z dwóch końcowych segmentów URL źródła, np. `1383-large_default_zamek-do-drzwi-gama-z-wkladka-gerda.jpg`. Import sprawdza format, 800–1600 px dłuższego boku i maksymalnie 300000 bajtów. Oryginały oraz miniatury trafiają do `img/p` w wolumenie sklepu.

Importer zapisuje mapowanie przed generowaniem obrazów i atomowo zastępuje pliki. Ponowienie niedokończonej galerii wykorzystuje istniejące ID. Hash źródła, miniatur i konfiguracji chroni przed uznaniem niekompletnych plików za gotowe. Kolejność i okładka pochodzą z `images` i `cover_image`.

W czasie inicjalizacji pozostaw źródła bez zmian. Samodzielne importery są narzędziami świadomej aktualizacji i należy uruchamiać je po zakończeniu wspólnego procesu.

## Mapa skryptów

Wszystkie skrypty PHP uruchamiaj jako `www-data` w kontenerze `shop`, zgodnie ze wzorcem powyżej.

| Skrypty | Odpowiedzialność |
|---|---|
| `init-shop.php` | Walidacja wejścia, blokada i wykonanie etapów lub wznowienie |
| `import-category.php`, `import-products.php` | Drzewo kategorii, nazwy, dwa opisy, ceny, VAT i masa |
| `import-attributes.php`, `import-combinations.php` | Słownik atrybutów i kombinacje z modyfikatorami netto |
| `import-images.php` | Lokalne JPEG, galerie, okładki, miniatury i trwałe mapowanie |
| `import-stock.php` | Początkowe ilości 0–10, rozdział na warianty i aktywacja |
| `configure-storefront.php`, `configure-menu.php` | Nazwa, usunięcie demonstracji i menu źródłowe |
| `configure-theme.php` | Synchronizacja i aktywacja motywu potomnego PAN DOMU |
| `configure-theme-content.php` | Linki motywu, popularne produkty i przygotowanie wyszukiwania oraz filtrów po aktywacji katalogu |
| `configure-content.php`, `configure-cookies.php`, `configure-module-texts.php` | CMS, stopka, cookies i polskie treści |
| `configure-webservice.php` | Włączenie Webservice API i uprawnienia klucza Least Privilege |
| `configure-carriers.php`, `configure-payments.php`, `configure-orders.php` | Dostawa, płatności, statusy i demonstracyjne faktury |
| `configure-mail.php` | SMTP Mailpit i natywne polskie szablony e-mail |
| `export-config.php`, `configure-from-export.php` | Eksport konfiguracji do `deploy/config/shop-config.json` i jej odtworzenie (ostatni etap) |
| `check-environment.php`, `check-price-settings.php` | Diagnostyka środowiska, waluty i VAT |
| `check-products.php`, `check-images.php`, `check-stock.php` | Porównanie zapisu z początkowym planem |
| `plan-variants.php`, `audit-sales.php` | Podgląd wariantów i konfiguracji sprzedaży |

`lib/` zawiera wspólną logikę, `content/` początkowe szablony HTML, a `tests/` zachowane testy jednostkowe i integracyjne. Format źródła opisuje [scraper](SCRAPER.md), reguły biznesowe [konfiguracja](CONFIGURATION.md), a polecenia testów [instrukcja testów](TESTING.md).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
