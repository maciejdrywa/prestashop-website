# Dane katalogu i wyniki scrapowania

Dane w `scraper-results/` są eksportem sklepu źródłowego Pan Domu do użytku dydaktycznego. Sposób ich pobierania opisuje [scraper](SCRAPER.md), a zasilania sklepu [import katalogu](CATALOG_IMPORT.md).

## Pliki i odpowiedzialności

| Ścieżka względem repozytorium | Zawartość |
|---|---|
| `scraper-results/products.json` | Lista produktów z opisami, cenami, wariantami i adresami zdjęć |
| `scraper-results/categories.json` | Drzewo kategorii, adresy źródłowe i przypisania ID produktów |
| `scraper-results/scrape-report.json` | Kompletność przebiegu i przyczyny błędów |
| `scraper-results/images/` | Pełne lokalne zdjęcia, wykluczone z Gita |
| `scraper-results/samples/` | Maksymalnie 20 przykładowych zdjęć w repozytorium |
| `scraper-results/product_images.zip` | Archiwum pełnych zdjęć, wykluczone z Gita |

JSON używa UTF-8 i jest zapisywany atomowo. Modele w `scraper/parser_classes/` opisują dane produktów. Niepełny przebieg zachowuje wcześniejsze wyniki i kończy się błędem; szczegóły sprawdzaj w raporcie kompletności.

## Kontrakt produktu

| Pola | Znaczenie |
|---|---|
| `product_id`, `product_url`, `name` | Identyfikacja źródła i nazwa |
| `breadcrumbs` | Preferowana ścieżka produktu wraz z początkiem i nazwą produktu |
| `category_paths`, `category_source_urls` | Pełne przypisania do gałęzi i ich źródła |
| `description_short`, `description` | Krótki opis i pełny HTML |
| `price_netto`, `price_brutto`, `vat_rate` | Zweryfikowane ceny bazowe i VAT |
| `source_placeholder` | Opcjonalny URL źródłowej grafiki braku zdjęcia, oddzielony od rzeczywistej galerii |
| `images`, `cover_image` | Uporządkowana galeria i jawny URL okładki |
| `weight` | Masa źródłowa w kg lub brak danych |
| `features` | Opcjonalna lista par `name`/`value`, odczytana z metadanych lub tabeli cech produktu; domyślnie `[]` |
| `variants` | Rzeczywiste kombinacje i ich ceny |
| `stock` | Pole źródłowe zachowane w eksporcie, importer stosuje własny plan początkowy |

Kombinacja zawiera `variant_id`, nazwę, mapę `attributes`, ceny netto/brutto, `price_modifier` i stan. Modyfikator jest różnicą netto względem bazowej ceny produktu, obliczaną przez `Decimal` do sześciu miejsc. Crawler potwierdza wybory aktualnego formularza przez kontroler produktu. Starsze nieweryfikowane warianty odświeża przy wznowieniu. Przeliczenie po imporcie weryfikuje natywny mechanizm PrestaShop.

Gdy źródło udostępnia dwa opisy, eksport zachowuje oba, również identyczne. Pojedynczy opis trafia do `description`, pozostawiając pusty krótki opis. `source_breadcrumbs` jest obsługiwane jako migracja starszego wejścia i pomijane w nowym eksporcie.

## Zdjęcia i dystrybucja

Narzędzie `python scraper/scrapping_images_module.py`, uruchamiane po przygotowaniu eksportu i zależności [scrapera](SCRAPER.md), zapisuje JPEG z dłuższym bokiem 800–1600 px i limitem 300000 bajtów oraz przygotowuje próbki i ZIP. Pełnego zbioru nie dodawaj do Git ani Git LFS. Archiwum do 1 GB można udostępnić w GitHub Releases; obecny paker wymaga ręcznej kontroli rozmiaru.

Do zwykłego uruchomienia sklepu używaj wersjonowanych `products.json` i `categories.json`. Importer API przygotowuje brakujące zdjęcia z przypiętego wydania lub adresów źródłowych; konfigurację i cache opisuje [import zdjęć](IMAGE_IMPORT.md). Historyczna inicjalizacja PHP wymaga gotowego lokalnego `images/`.

## Ograniczenia i aktualizacje

Dotychczasowa dokumentacja eksportu podaje 574 produkty, 80 kategorii, 1269 kombinacji i 1388 rzeczywistych zdjęć. Import API dodatkowo wykorzystuje jeden placeholder produktu 539, co daje 1389 obrazów w sklepie. Są to dane konkretnego eksportu; po ponownym scrapowaniu sprawdź bieżące pliki i raport. Produkt źródłowy 539 nie ma galerii i jest jawnie raportowany; obsługę jego placeholdera opisuje [import zdjęć](IMAGE_IMPORT.md).

Podczas importu nie zmieniaj danych wejściowych. Zmiana eksportu wymaga świadomej synchronizacji zgodnie z [instrukcją importu](CATALOG_IMPORT.md), ponieważ wznowienie kontroluje odciski danych.

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
