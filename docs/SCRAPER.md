# Scraper

## Uruchamianie

Wymagany jest Python 3.10+ oraz zależności z `scraper/requirements.txt`. BeautifulSoup odczytuje HTML, Pydantic opisuje eksport, Playwright utrzymuje sesję i odświeża kombinacje, a Pillow normalizuje zdjęcia.

```powershell
python -m venv venv
.\venv\Scripts\python.exe -m pip install -r scraper/requirements.txt
.\venv\Scripts\python.exe -m playwright install chromium
.\venv\Scripts\python.exe scraper/main_scraper.py
.\venv\Scripts\python.exe scraper/scrapping_images_module.py
```

Na macOS/Linux odpowiednikiem interpretera jest `venv/bin/python`. Pierwsze narzędzie pobiera dane, drugie zdjęcia i ZIP. Główny crawler obsługuje `--headless`, `--output-dir`, `--refresh` i `--refresh-variants`. Domyślnie korzysta z widocznej przeglądarki oraz wznawia zapis po ID. Cookie źródła można przekazać przez `SCRAPER_CF_CLEARANCE`.

## Odkrywanie katalogu

Crawler odczytuje menu, sekcje podkategorii i wszystkie strony paginacji. Uwzględnia kolekcję polecanych produktów, ignoruje filtry oraz sortowanie, deduplikuje produkty po ID i sprawdza cykle. Podczas wznowienia aktualizuje przypisania istniejących produktów. Kategorie rodzicielskie są odtwarzane z pełnych ścieżek.

`products.json` zawiera listę produktów, `categories.json` drzewo z adresami i ID produktów, a `scrape-report.json` kompletność oraz przyczyny błędów. Zapisy JSON są atomowe i używają UTF-8. Niepełny przebieg kończy się błędem z zachowaniem wcześniejszych danych.

## Kontrakt produktu

| Pola | Znaczenie |
|---|---|
| `product_id`, `product_url`, `name` | Identyfikacja źródła i nazwa |
| `breadcrumbs` | Preferowana ścieżka produktu wraz z początkiem i nazwą produktu |
| `category_paths`, `category_source_urls` | Pełne przypisania do gałęzi i ich źródła |
| `description_short`, `description` | Krótki opis i pełny HTML |
| `price_netto`, `price_brutto`, `vat_rate` | Zweryfikowane ceny bazowe i VAT |
| `images`, `cover_image` | Uporządkowana galeria i jawny URL okładki |
| `weight` | Masa źródłowa w kg lub brak danych |
| `variants` | Rzeczywiste kombinacje i ich ceny |
| `stock` | Pole źródłowe zachowane w eksporcie, importer stosuje własny plan początkowy |

Kombinacja zawiera `variant_id`, nazwę, mapę `attributes`, ceny netto/brutto, `price_modifier` i stan. Modyfikator jest różnicą netto względem bazowej ceny produktu, obliczaną przez `Decimal` do sześciu miejsc. Crawler potwierdza wybory aktualnego formularza przez kontroler produktu. Starsze nieweryfikowane warianty odświeża przy wznowieniu. Przeliczenie po imporcie weryfikuje natywny mechanizm PrestaShop.

Gdy źródło udostępnia dwa opisy, eksport zachowuje oba, również identyczne. Pojedynczy opis trafia do `description`, pozostawiając pusty krótki opis. `source_breadcrumbs` jest obsługiwane jako migracja starszego wejścia i pomijane w nowym eksporcie.

## Zdjęcia

Pliki są zapisywane na bieżąco w ignorowanym `scraper-results/images/`. Normalizacja uwzględnia EXIF, białe tło przezroczystości, JPEG, dłuższy bok 800–1600 px i limit 300000 bajtów. Najpierw zmniejsza jakość, następnie wymiary do dolnego limitu. Niepoprawny nowy obraz pozostawia dotychczasowy plik.

Do `scraper-results/samples/` trafia maksymalnie 20 poprawnych próbek. Po udanym pobraniu powstaje `scraper-results/product_images.zip`. Pełne obrazy i ZIP pozostają poza Git. Paczkę można opublikować w GitHub Releases. Obecny paker wymaga ręcznej kontroli limitu 1 GB. Inicjalizacja korzysta z przygotowanego folderu lokalnego.

Produkt źródłowy 539 nie ma galerii i pozostaje z `images: []`. Raport pokazuje ten wyjątek. Crawler danych przechowuje sesję w `.browser_profile/` w repozytorium. Narzędzie zdjęć używa własnego profilu `scraper/.browser_profile/` i dotychczasowej konfiguracji sesji.

## Narzędzia ukierunkowanego odświeżania

`refresh_descriptions.py` i `refresh_image_covers.py` uzupełniają istniejący eksport bez ponownego pobierania całego katalogu. Są zachowane dla starszych plików i regresji. Pełny nowy przebieg głównego scrapera dostarcza te same wymagane pola.

Testy korzystają z lokalnego HTML i atrap odpowiedzi. Polecenia zebrano w [instrukcji testów](../tests/README.md).
