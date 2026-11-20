# D2.4 — galerie przez Webservice API

Importer dodaje zdjęcia do produktów utworzonych w D2.3, rozpoznawanych po referencji `pandomu-ID`. Obsługuje cache, przypięte wydanie zdjęć, źródło, kolejność i okładki, aktualizację zawartości oraz wznowienie. Zachowuje aktywność produktów i magazyn. Wspólna komenda [D2.5](CATALOG_IMPORT.md) uruchamia pełny tor API; historyczny bootstrap PHP pozostaje dostępny.

## Uruchomienie

W aktywnym środowisku Pythona z `scraper/requirements.txt`, po imporcie D2.3, korzystając z istniejącego `.env`:

```sh
python scraper/import_images.py --dry-run
python scraper/import_images.py
```

`--dry-run` waliduje katalog, dostępność produktów, sklep i schemat API. Nie pobiera zdjęć, nie tworzy cache, dziennika ani raportu i nie wykonuje zapisów do sklepu. Pełną kontrolę plików i galerii wykonuje właściwy import.

| Opcja | Znaczenie |
|---|---|
| `--products-file` | Domyślnie `scraper-results/products.json` |
| `--cache-dir` | Domyślnie `scraper-results/images/` |
| `--state-file`, `--report-file` | Domyślnie `logs/image-import/<host>/state.json` i `report.json` |
| `--ids 5 539` | Import wybranych źródłowych ID |
| `--shop-id` | Domyślnie 1; importer weryfikuje aktywny sklep i przynależność produktów |
| `--legacy-map-file` | Świeży eksport potwierdzonych mapowań PHP |
| `--no-release` | Pominięcie GitHub Releases, pozostają cache i źródło |

Ponowne wykonanie komendy wznawia operacje i porównuje pliki. Nie usuwaj dziennika pomiędzy przebiegami. Zawiera on URL źródła, produkt, hash wysłanego JPEG, hash oryginału odczytanego z API i ID obrazu. Stan, raport, pełne zdjęcia i ZIP pozostają poza Git.

## Pozyskiwanie i normalizacja

Kolejność: poprawny cache → [wydanie `product-images-v1`](https://github.com/maciejdrywa/prestashop-website/releases/tag/product-images-v1) → źródłowy URL. Nazwy plików pozostają zgodne ze scraperem (`ID-large_default_nazwa.jpg`).

Archiwum `product_images.zip` jest pobierane strumieniowo najwyżej raz w przebiegu i zachowywane w cache jako `.product-images-v1.zip`. Suma SHA-256 przypiętego pliku: `2f48130e0b586ff40bcfd74803918dd607c34a91714cb0cfc216793bd5a870d7`. Importer kontroluje sumę także przy ponownym użyciu ZIP-a. Raport zapisuje URL, wersję, sumę oraz ewentualny błąd wydania; udany fallback źródłowy pozwala ukończyć import.

Limity: archiwum 128000000 bajtów, wypakowane pliki 512000000 bajtów, do 5000 wpisów, do 20000000 bajtów na obraz wejściowy i 40 milionów pikseli przy normalizacji. Cały katalog ZIP jest sprawdzany przed wypakowaniem; ścieżki wychodzące poza cache, linki, szyfrowanie i kolizje nazw są odrzucane. Uszkodzony obraz z archiwum wymaga poprawnego fallbacku.

Wejście może być JPEG albo WebP. Wspólna normalizacja scrapera uwzględnia EXIF, tryb RGB i białe tło przezroczystości. Do API trafia wyłącznie JPEG o dłuższym boku 800–1600 px, do **300000 bajtów**. Poprawny JPEG z cache lub wydania zachowuje bajty, bez dodatkowej kompresji.

Produkt 539 ma pustą galerię źródłową. Zgodnie z ustaleniem otrzymuje [źródłowy obraz braku zdjęcia](https://pandomu.com.pl/img/p/pl-default-medium_default.jpg), oznaczony `source_placeholder`. Parser zapisuje rozpoznany placeholder osobno od galerii w opcjonalnym `source_placeholder`. Eksport akademicki nie jest automatycznie przepisywany.

Zmiana zawartości pod tym samym URL jest wykrywana przez hash pliku cache i aktualizuje dotychczasowe ID. Aby pobrać nową wersję z żywego źródła, usuń odpowiedni lokalny plik i wykonaj import z `--no-release`; archiwum jest stałym snapshotem.

## Galerie i wznowienie

Uploady w obrębie galerii są sekwencyjne. Nie edytuj galerii w panelu i nie uruchamiaj importera PHP podczas importu API. Blokada wspólna z D2.2–D2.3 zatrzymuje drugi proces Python tej samej instalacji na tym hoście; nie blokuje innych komputerów ani panelu administracyjnego. Importer porównuje listę ID przed i po operacji i zatrzymuje produkt po wykryciu zmiany.

Przed POST zapisywany jest zamiar, hash pliku i lista dotychczasowych ID. PrestaShop 8.2.8 może zwrócić binarny JPEG po uploadzie; klient obsługuje również potwierdzenia XML/JSON. Nowe ID musi być jedynym dodanym obrazem na końcu galerii. Po utracie odpowiedzi importer odczytuje galerię, wymaga jednego nowego ID oraz sprawdza obraz względem pliku źródłowego. Zero albo wiele nowych ID oznacza konflikt, bez kolejnego POST.

PrestaShop ponownie kompresuje JPEG. Kontrola zawartości porównuje wymiary i odkodowany obraz (próbka 64×64, średnia różnica kanału do 4/255), a kolejne przebiegi wymagają identycznego SHA-256 oryginału z API. Uzgodnienie po utracie odpowiedzi zakłada wyłączny dostęp do galerii w czasie operacji.

Zmiana pliku pod znanym URL wykonuje aktualizację tego samego obrazu. PHP 8.1 wymaga multipart przez POST z `ps_method=PUT`; dispatcher PrestaShop sprawdza uprawnienie PUT. Po przerwaniu aktualizacji można ponowić PUT tego samego ID, bez tworzenia obrazu. Okładka jest ustawiana przez `id_default_image`, zgodnie z [dokumentacją Webservice](https://devdocs.prestashop-project.org/8/webservice/tutorials/create-product-az/).

Pusta relacja `images` może być pominięta w odpowiedzi produktu. Importer potwierdza pustkę osobnym odczytem listy XML; HTTP 404 jest w tym przypadku odpowiedzią pustej galerii wcześniej potwierdzonego produktu. Błędny dokument listy nie oznacza pustej galerii.

Nowe galerie zachowują kolejność źródłową dzięki kolejności uploadów. Zastane galerie muszą mieć potwierdzone mapowania i tę samą kolejność; inna kolejność, obce lub usunięte obrazy wymagają ręcznego uzgodnienia. Standardowy Webservice 8.2.8 nie udostępnia zapisu pozycji obrazu. Importer nie usuwa ani nie odtwarza galerii, żeby obejść to ograniczenie. Zmiana okładki działa niezależnie od kolejności.

## Przejęcie galerii importowanych przez PHP

Przed pierwszym importem API na instalacji z galeriami PHP wyeksportuj mapowania tylko do odczytu:

```sh
mkdir -p logs/image-import
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop \
  php /opt/shop/init/export-image-mapping.php > logs/image-import/php-map.json
python scraper/import_images.py --legacy-map-file logs/image-import/php-map.json
```

Eksport sprawdza źródłową referencję produktu, sklep, ID obrazu, kompletność mapowania i istniejący oryginał. Nie tworzy tabeli, nie aktualizuje sklepu i nie eksportuje sekretów. Jest ważny przez pięć minut przy rozpoczynaniu importu. Importer weryfikuje tożsamość instalacji i aktualny hash oryginału przez API. Potwierdzone obrazy przechodzą do dziennika API bez ponownego uploadu. Kolejne przebiegi można uruchamiać bez `--legacy-map-file`.

## Odbiór i raport

Błąd produktu nie zatrzymuje niezależnych galerii; powoduje niezerowy kod zakończenia i wpis z etapem, źródłowym ID oraz statusem HTTP, jeśli jest dostępny. Raport obejmuje utworzone, zaktualizowane, pominięte, przejęte i uzgodnione obrazy, zmiany okładek, trafienia cache, pobrania, placeholdery, czas i liczby zweryfikowanych produktów/obrazów. `source_images` oraz `source_placeholders` opisują plan; `verified_*` opisują ukończone galerie. `idempotent` oznacza przebieg bez błędów i bez zapisów obrazów/okładek.

Ręczna kontrola na instancji testowej: w panelu Katalog → Produkty wyszukaj `pandomu-5` (cztery zdjęcia, okładka zgodna z eksportem) i `pandomu-539` (grafika braku zdjęcia). Porównaj kolejność i okładki ze źródłem. Produkty pozostają nieaktywne do finalizacji D2.5.

## Wyniki kontroli — 19 listopada 2026 r.

Osobna instancja PrestaShop 8.2.8, projekt Compose `prestashop-d21-tests`, bez modyfikacji zwykłego sklepu:

- 574 zweryfikowane galerie, 1388 rzeczywistych zdjęć i jeden źródłowy placeholder.
- 436 produktów z co najmniej dwoma obrazami.
- Pierwszy pełny przebieg po próbce dwóch produktów: 345,384 s, utworzono pozostałe 1384 obrazy; cache był już przygotowany z wydania i źródła.
- Drugi pełny przebieg: 63,029 s, wszystkie 1389 obrazów pominięto, bez uploadów, aktualizacji i zmian okładek.
- Niezależny odczyt bazy i plików potwierdził kolejność, okładki, 1389 oryginałów i 6945 miniatur. Wszystkie 574 produkty pozostały nieaktywne.
- Wszystkie 1389 JPEG w cache spełniają limity; największy plik ma 259572 bajty. Placeholder 539 ma 840×840 px i 10634 bajty.
- Regresyjna kontrola natywna PHP potwierdziła nadal 574 produkty, opisy, kategorie, ceny, 98 wag demonstracyjnych i 1269 kombinacji.

Raporty wykonania: lokalny, ignorowany katalog `logs/d24-test/`, w szczególności `report-1.json`, `report-2.json` i `native-images-audit.json`. Pomiar nie obejmuje benchmarku 1000 produktów ze zdjęciami; to zakres D2.5. Ręczny odbiór panelu i porównanie ze źródłem pozostają do wykonania według instrukcji powyżej.

Kontrole kodu: 196 testów offline i 11 subtestów przeszło; 14 testów integracyjnych D2.1–D2.4 przeszło zarówno przez HTTP, jak i przez HTTPS z weryfikacją certyfikatu. Ruff oraz PHP lint eksportera bez błędów. Publiczny CLI sprawdzono dla importu i dry run; dry run nie utworzył cache, stanu ani raportu.

Wspólną komendę przygotowania, importu API i finalizacji opisuje [D2.5](CATALOG_IMPORT.md).

Pobieranie publicznych zdjęć i wydania używa niezależnego zestawu zaufanych CA, dzięki czemu prywatny `REQUESTS_CA_BUNDLE` dla lokalnego API nie blokuje źródła ani GitHub Releases.
