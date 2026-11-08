# Scraper

Katalog zawiera kod narzędzia pobierającego kategorie, podkategorie, dane produktów i zdjęcia ze sklepu źródłowego.

Główny crawler wykonuje zapytania kolejno, z przerwą jednej sekundy przed każdą nawigacją. Obsługuje wznawianie pobierania po ID produktu i kontrolę kompletności list kategorii.

Testy jednostkowe parsowania korzystają z lokalnych przykładowych stron HTML.

## Wyjątek od wymagań W3
Całkowita liczba produktów w asortymencie sklepu wynosi około 600


W2.2

Scraper-parser.py jest glownym plikiem, ktory w tym momencie przechwytuje to co mamy na jakims fake-html.
Wyciagniete powinny byc katalog,descriptiom.id,images,name,pretax-price,price,variants,weight

Wszystkie elementy scrapowane maja osobny plik w folderze scraper-elements

parser-classes jest katalogiem z klasami product, product_variant i category_page

scraper-test jest programem testujacym, ktore sprawdza przykladowe scrapowanie produktu dla fake-html
odpalamy scraper-test przez komende: 

```pytest scraper/scraper.test.py```

test_category_scraper.py jest testem sprawdzajacym scrapowanie kategorii z pyrzkladowego fake-html
odpalamy test_category_parser przez: 

```pytest scraper/test_category_parser.py```

plan.json przedstawia przykladowy wyglad produktow, ktore beda zescrapowane w formacie json

## Pobieranie katalogu wraz z pełnymi przypisaniami kategorii

Wszystkie poniższe polecenia wykonujemy z głównego katalogu repozytorium.
Wymagany jest Python 3.10 lub nowszy.

```powershell
python -m venv venv
.\venv\Scripts\python.exe -m pip install -r scraper/requirements.txt
.\venv\Scripts\python.exe -m playwright install chromium
.\venv\Scripts\python.exe scraper/main_scraper.py
```

Jeżeli środowisko `venv` już istnieje, pomijamy jego tworzenie.
Domyślnie przeglądarka jest widoczna. Opcja `--headless` uruchamia ją bez okna.
Opcjonalny cookie źródła można przekazać przez zmienną środowiskową
`SCRAPER_CF_CLEARANCE`, bez wpisywania sekretów do kodu. Profil przeglądarki
znajduje się w ignorowanym przez Git katalogu `.browser_profile` w repozytorium.

Scraper odczytuje hierarchię menu strony głównej, następnie na każdej kategorii
odkrywa również sekcję `Podkategorie` i schodzi do kolejnych poziomów. Zachowuje
także dotychczasową kolekcję `81-produkty-polecane`, jeśli brakuje jej w menu.
Na każdym poziomie przechodzi całą paginację. Nie przechodzi po linkach filtrów
i sortowania. Nazwy podkategorii odczytuje z pełnego tytułu, jeśli etykieta jest
skrócona wielokropkiem.

Wyniki:

- `scraper-results/products.json` — jeden rekord na źródłowe ID produktu.
- `scraper-results/categories.json` — kategorie z pełną ścieżką, adresem rodzica,
  listą znalezionych ID produktów i liczbą stron.
- `scraper-results/scrape-report.json` — podsumowanie kompletności, błędy produktów
  oraz wcześniejsze produkty, których nie odnaleziono podczas bieżącego przebiegu.
  Lista `products_without_images` jawnie wskazuje produkty bez galerii w źródle.

Każdy produkt zawiera nowe pola:

```json
{
  "category_paths": [["Akcesoria meblowe", "PODNOŚNIKI SZAFEK GÓRNYCH"]],
  "category_source_urls": ["https://pandomu.com.pl/72-podnosniki-szafek-gornych"],
  "source_breadcrumbs": ["Strona główna", "Nazwa produktu"]
}
```

Powyższy JSON ilustruje strukturę danych. `category_source_urls` zapisuje faktycznie
odwiedzone adresy, w tej samej kolejności co ścieżki. Produkt należący do kilku
gałęzi zachowuje wszystkie najdokładniejsze znalezione ścieżki. Jeżeli występuje
w kategorii nadrzędnej oraz jej podkategorii, redundantna ścieżka nadrzędna jest
pomijana. Przypisanie wyłącznie do kategorii głównej pozostaje tylko wtedy, gdy
produktu nie odnaleziono w żadnej jej podkategorii.

`source_breadcrumbs` zachowuje breadcrumbs ze strony produktu. Pole `breadcrumbs`
otrzymuje pełną ścieżkę zgodną z dotychczasowymi importerami: strona główna,
kategorie, nazwa produktu. Jeśli oryginalna ścieżka pasuje do odkrytych przypisań,
jest preferowana; w przeciwnym razie wybierana jest najgłębsza ścieżka, a remisy
rozstrzygane alfabetycznie. To wybór głównej ścieżki eksportu, nie deklaracja
domyślnej kategorii w źródle. Importer wszystkich przypisań powinien czytać
`category_paths`, a nie wyłącznie `breadcrumbs`.

Ponowne uruchomienie ponownie skanuje drzewo i aktualizuje przypisania również
już zapisanych produktów, zachowując ich pozostałe dane, w tym `price_modifier`.
Szczegóły pobierane są tylko dla nowych produktów. Aby ponownie pobrać również
szczegóły istniejących produktów:

```powershell
.\venv\Scripts\python.exe scraper/main_scraper.py --refresh
```

Ta zmiana dotyczy odkrywania katalogu i przypisań. Dotychczasowy parser wariantów
nadal eksportuje opcje atrybutów oraz zerowe modyfikatory; pobranie rzeczywistych
kombinacji i dopłat wymaga osobnego rozszerzenia. `--refresh` korzysta z tego
parsera, więc nadpisuje wcześniejsze dane wariantów jego aktualnymi wynikami.

Plik produktów zapisywany jest atomowo po każdym nowym lub odświeżonym produkcie.
Uszkodzony istniejący JSON zatrzymuje pracę. Błąd kategorii, pętla paginacji lub
niezgodność liczby produktów z licznikiem źródła zatrzymuje skan zamiast uznać
niepełny wynik za sukces. Błędy szczegółów produktów kończą przebieg kodem `1`
i trafiają do raportu. Nieodnalezione wcześniejsze produkty nie są automatycznie
usuwane; również wymagają sprawdzenia raportu. Kompletność dotyczy produktów
osiągalnych przez odkryte drzewo kategorii i kolekcję polecanych. Nie oznacza
kompletności danych wymaganych do odbioru sklepu: produkt bez zdjęć w źródle
pozostaje w eksporcie z `images: []` i jest wymieniony w raporcie. Nie zastępujemy
go fotografią innego produktu ani ogólnym obrazem zastępczym źródła.

Wyniki można odizolować od istniejącego katalogu opcją `--output-dir KATALOG`.
Jeśli nowy katalog nie ma `products.json`, pobierane są wszystkie znalezione
produkty od początku. Osobny `category-memberships.json` nie jest potrzebny przy
korzystaniu z nowego głównego scrapera.

Testy drzewa, paginacji, błędów i wznawiania (bez sieci i dodatkowego runnera):

```powershell
.\venv\Scripts\python.exe -m unittest discover -s scraper -p test_catalog_crawl.py -v
```

W2.4

Dodano scrapping_images_module scrapujacy wszystkie zdjecia, ktore sa w products, dodawane sa zdjecia do folderu images (ktory jest w gitignore) oraz 20 zdjec sa dodawane do scraper-result/samples (ktore sa na githubie aby pokazac, ze scraper-images dziala) 

Dodano pliki create_zip_archive (tworzacy zipa ze wszystkich zdjec) i process_and_save_images (sprawdzamy czy plik ma poprawny np format, skladnie i zapisujemy)

odpalamy scrapping_images_module poprzez zmienie cookies w pliku w linii 53 

``` cookies = "" ```

i poprzez komende:

``` python scrapping_images_module.py ```
