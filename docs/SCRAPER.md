# Scraper

## Uruchamianie

Wymagany jest Python 3.11+ oraz zależności z `scraper/requirements.txt`. BeautifulSoup odczytuje HTML, Pydantic opisuje eksport, Playwright utrzymuje sesję i odświeża kombinacje, a Pillow normalizuje zdjęcia.

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r scraper/requirements.txt
python -m playwright install chromium
python scraper/main_scraper.py
python scraper/scrapping_images_module.py
```

Na macOS/Linux aktywuj środowisko przez `source .venv/bin/activate`, a następnie używaj tych samych poleceń `python`. Wszystkie komendy wykonuj z katalogu głównego repozytorium. Pierwsze narzędzie pobiera dane, drugie zdjęcia i ZIP. Główny crawler obsługuje `--headless`, `--output-dir`, `--refresh` i `--refresh-variants`. Domyślnie korzysta z widocznej przeglądarki oraz wznawia zapis po ID. Cookie źródła można przekazać przez `SCRAPER_CF_CLEARANCE`.

## Tempo i sesja pobierania

`StoreCrawler.fetch_page` odczekuje sekundę przed nawigacją, a narzędzie zdjęć stosuje sekundową przerwę. Te opóźnienia nie są globalnym limitem wszystkich żądań przeglądarki, np. zasobów strony i zapytań wariantów.

## Reguły robots.txt

Moduł `scraper/robots_policy.py` odczytuje lokalny plik `scraper/html_files_and_robots/robots.txt`. Przed nowym pobieraniem zaktualizuj tę kopię zgodnie z plikiem publikowanym przez źródło. Scraper nie pobiera jej automatycznie; zmiany pliku wymagają ponownego uruchomienia procesu. Brak pliku, błąd odczytu lub brak poprawnej dyrektywy `User-agent` zatrzymuje pobieranie.

Parser Protego obsługuje `Allow`, `Disallow`, wzorce `*`, zakończenie `$` oraz reguły dla konkretnego agenta. Wspólny User-Agent żądań to `TwojeDrzwiScraper/1.0 (+https://github.com/maciejdrywa/prestashop-website)`. Reguły obowiązują dla `https://pandomu.com.pl` na domyślnym porcie HTTPS; inne domeny, protokoły i adresy z danymi logowania są blokowane, ponieważ nie mają przypisanej polityki.

Kontrola obejmuje strony kategorii i produktów, zasoby przeglądarki, odświeżanie wariantów, pobieranie zdjęć oraz narzędzia odświeżania opisów i okładek. Każdy krok przekierowania GET/HEAD jest sprawdzany przed wysłaniem kolejnego żądania; limit wynosi sześć żądań w łańcuchu. Przekierowania zapytań POST wariantów nie są wykonywane. Service workers są wyłączone, aby nie omijały kontroli zasobów. Zablokowane żądanie jest raportowane jako błąd lub komunikat w logu.

Odczyt poprawnych zdjęć z lokalnego cache i pobieranie przypiętego archiwum GitHub Releases nie korzystają z reguł domeny źródłowej. Kontrola nie dotyczy też Webservice własnego sklepu. Implementacja nie wymusza `Crawl-delay` ani `Request-rate`.

## Odkrywanie katalogu

Crawler odczytuje menu, sekcje podkategorii i wszystkie strony paginacji. Uwzględnia kolekcję polecanych produktów, ignoruje filtry oraz sortowanie, deduplikuje produkty po ID i sprawdza cykle. Podczas wznowienia aktualizuje przypisania istniejących produktów. Kategorie rodzicielskie są odtwarzane z pełnych ścieżek.

`products.json` zawiera listę produktów, `categories.json` drzewo z adresami i ID produktów, a `scrape-report.json` kompletność oraz przyczyny błędów. Zapisy JSON są atomowe i używają UTF-8. Niepełny przebieg kończy się błędem z zachowaniem wcześniejszych danych.

## Format wyników

Pliki wynikowe, kontrakt produktu i zasady przechowywania zdjęć opisują [dane katalogu](DATA.md).

## Zdjęcia

Pliki są zapisywane na bieżąco w ignorowanym `scraper-results/images/`. Normalizacja uwzględnia EXIF, białe tło przezroczystości, JPEG, dłuższy bok 800–1600 px i limit 300000 bajtów. Najpierw zmniejsza jakość, następnie wymiary do dolnego limitu. Niepoprawny nowy obraz pozostawia dotychczasowy plik.

Do `scraper-results/samples/` trafia maksymalnie 20 poprawnych próbek. Po udanym pobraniu powstaje `scraper-results/product_images.zip`. Pełne obrazy i ZIP pozostają poza Git. Paczkę można opublikować w GitHub Releases. Obecny paker wymaga ręcznej kontroli limitu 1 GB. Historyczny backend PHP wymaga przygotowanego folderu lokalnego; importer API potrafi pobrać brakujące zdjęcia ([import galerii](IMAGE_IMPORT.md)).

Produkt źródłowy 539 nie ma galerii i pozostaje z `images: []`. Raport pokazuje ten wyjątek. Crawler danych przechowuje sesję w `.browser_profile/` w repozytorium. Narzędzie zdjęć używa własnego profilu `scraper/.browser_profile/` i dotychczasowej konfiguracji sesji.

## Narzędzia ukierunkowanego odświeżania

`refresh_descriptions.py` i `refresh_image_covers.py` uzupełniają istniejący eksport bez ponownego pobierania całego katalogu. Są zachowane dla starszych plików i regresji. Pełny nowy przebieg głównego scrapera dostarcza te same wymagane pola.

Testy korzystają z lokalnego HTML i atrap odpowiedzi. Polecenia zebrano w [instrukcji testów](TESTING.md).

Import eksportu przez API opisuje [D2.3](PRODUCT_IMPORT.md). Importer nie dopisuje wymyślonych cech do istniejącego eksportu.

Import galerii przez Webservice, pobieranie przypiętego wydania i obsługę placeholdera produktu 539 opisuje [D2.4](IMAGE_IMPORT.md).

Wspólną komendę przygotowania, importu API i finalizacji opisuje [D2.5](CATALOG_IMPORT.md).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
