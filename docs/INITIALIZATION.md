# Inicjalizacja i import danych

## Tryby

`init-shop.php --dry-run` sprawdza wejście i wyświetla plan. `--resume` rozpoznaje istniejące rekordy, uzupełnia niedokończoną inicjalizację i zachowuje edycje oraz sprzedaż. Wywołanie bez opcji wykonuje pełne, jawne odtworzenie konfiguracji, danych produktów i początkowych stanów. Ten ostatni tryb może nadpisać zmiany administratora.

Zalecana komenda startowa z [instrukcji wdrożenia](DEPLOYMENT.md) korzysta z `--resume`.

## Kolejność

| Etap | Operacja |
|---|---|
| 1–5 | Nazwa sklepu i usunięcie demonstracji, poczta, przewoźnicy, płatności, statusy i faktury |
| 6 | Kategorie i produkty wraz z opisami, cenami, VAT i wagą |
| 7–10 | Menu, strony i stopka, cookies, polskie treści modułów |
| 11–12 | Słownik atrybutów i kombinacje z modyfikatorami netto |
| 13–15 | Galerie, okładki, miniatury i kontrola katalogu |
| 16–17 | Początkowe ilości, aktywacja i kontrola magazynu |

Nowe produkty są nieaktywne do zakończenia kontroli danych i galerii. Produkt bez zdjęć źródłowych jest jawnie zgłaszany. Obecnie dotyczy to ID 539 i wymaga potwierdzonego wyjątku przy odbiorze.

## Dziennik i wznowienie

Dziennik `var/import/initialization-state.json` znajduje się w trwałym wolumenie sklepu. Zawiera wersję formatu, identyfikację instalacji, SHA-256 obu JSON-ów i zdjęć, ukończone etapy, własność tworzonych produktów oraz ich ID i potwierdzone operacje. Jest zapisywany atomowo. `init-shop.lock` blokuje drugą inicjalizację.

Pierwsze uruchomienie sprawdza istniejące produkty po referencjach i zachowuje je jako zastane rekordy. Własność nowych rekordów zapisuje przed ich utworzeniem. Po przerwaniu kontynuuje niedokończone etapy i produkty. Kontrola potwierdza rzeczywiste powiązania w bazie i pliki galerii także dla ukończonych etapów.

Stan początkowy jest ustawiany wyłącznie podczas przygotowania nowych produktów. Aktywny produkt po przerwanym zapisie jest rozpoznawany jako udostępniony klientom i jego ilość pozostaje zachowana. Późniejsze kontrole sprawdzają spójność sum wariantów, przyjmując bieżące ilości. Edytowane nazwy, ceny, wagi, opisy, strony CMS i statusy istniejących zamówień pozostają zachowane.

Zmiana odcisków danych, utrata zapisanego rekordu lub konflikt referencji przerywa proces. Wykonaj kopię bazy i wolumenu, sprawdź przyczynę, a następnie zastosuj wybrane importery. Po świadomym zakończeniu aktualizacji zarchiwizuj dziennik, aby kolejne `--resume` ponownie rozpoznało istniejącą instalację. Usunięcie dziennika przy nieukończonym imporcie wymaga ręcznej kontroli nieaktywnych produktów i etapów.

## Komendy pojedynczych operacji

W działającym sklepie użyj wspólnego wzorca, zastępując nazwę skryptu i argumenty.

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all --dry-run
```

Importery produktów, atrybutów, kombinacji, zdjęć i stanów przyjmują źródłowe ID albo `--all` oraz `--dry-run`. `import-category.php` przygotowuje drzewo kategorii. `check-products.php --all --with-combinations`, `check-images.php --all` i `check-stock.php --all` porównują sklep z początkowym planem. Po sprzedaży lub edycji mogą zgłosić oczekiwane różnice.

`check-environment.php` sprawdza kontekst i bazę. `check-price-settings.php` pokazuje VAT i próbkę przeliczenia. `plan-variants.php` pokazuje źródłowe kombinacje. `audit-sales.php` wypisuje konfigurację sprzedaży z ukryciem szczegółów bankowych. Skrypty `configure-*.php` świadomie odtwarzają wskazany obszar ustawień, zwykle obsługując `--dry-run`. Poczta i cookies są wykonywane bez tej opcji.

## Zdjęcia i bezpieczeństwo zapisu

Wymagany jest pełny lokalny katalog `scraper-results/images/`. Nazwa JPEG powstaje z dwóch końcowych segmentów URL źródła, np. `1383-large_default_zamek-do-drzwi-gama-z-wkladka-gerda.jpg`. Import sprawdza format, 800–1600 px dłuższego boku i maksymalnie 300000 bajtów. Oryginały oraz miniatury trafiają do `img/p` w wolumenie sklepu.

Importer zapisuje mapowanie przed generowaniem obrazów i atomowo zastępuje pliki. Ponowienie niedokończonej galerii wykorzystuje istniejące ID. Hash źródła, miniatur i konfiguracji chroni przed uznaniem niekompletnych plików za gotowe. Kolejność i okładka pochodzą z `images` i `cover_image`.

W czasie inicjalizacji pozostaw źródła bez zmian. Samodzielne importery są narzędziami świadomej aktualizacji i należy uruchamiać je po zakończeniu wspólnego procesu.
