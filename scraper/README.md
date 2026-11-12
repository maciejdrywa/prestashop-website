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
  "category_source_urls": ["https://pandomu.com.pl/72-podnosniki-szafek-gornych"]
}
```

Powyższy JSON ilustruje strukturę danych. `category_source_urls` zapisuje faktycznie
odwiedzone adresy, w tej samej kolejności co ścieżki. Produkt należący do kilku
gałęzi zachowuje wszystkie najdokładniejsze znalezione ścieżki. Jeżeli występuje
w kategorii nadrzędnej oraz jej podkategorii, redundantna ścieżka nadrzędna jest
pomijana. Przypisanie wyłącznie do kategorii głównej pozostaje tylko wtedy, gdy
produktu nie odnaleziono w żadnej jej podkategorii.

Eksport zawiera tylko jedno pole `breadcrumbs`: stronę główną, główną ścieżkę
kategorii i nazwę produktu. Dawne `source_breadcrumbs` jest usuwane również
podczas aktualizacji istniejących rekordów. Jeśli dotychczasowa ścieżka pasuje
do odkrytych przypisań, jest preferowana; inaczej wybierana jest najgłębsza, a remisy
rozstrzygane alfabetycznie. To wybór głównej ścieżki eksportu, nie deklaracja
domyślnej kategorii w źródle. Importer wszystkich przypisań powinien czytać
`category_paths`, a nie wyłącznie `breadcrumbs`.

Ponowne uruchomienie skanuje drzewo i aktualizuje przypisania już zapisanych
produktów. Zweryfikowane ceny wariantów są zachowywane. Stare rekordy wariantów,
które nie zawierają `price_netto` i `price_brutto`, są automatycznie ponownie
pobierane — dawne zera `price_modifier` nie są uznawane za zweryfikowane ceny.
W tym trybie odświeżane są ceny bazowe, VAT i kombinacje; pozostałe dane produktu
pozostają zapisane. Aby ponownie pobrać również wszystkie szczegóły produktów:

```powershell
.\venv\Scripts\python.exe scraper/main_scraper.py --refresh
```

## Rzeczywiste kombinacje i ceny wariantów

Scraper odczytuje cenę wybranej kombinacji z `data-product`. Pozostałe wybory
sprawdza żądaniami `action=refresh` do kontrolera produktu, korzystając z sesji
przeglądarki. Nie wysyła żądań dodania produktu do koszyka. Sprawdza również
kombinacje wielu grup, np. kolor + rozmiar + wersja, oraz opcje ujawnione przez
kolejne odpowiedzi źródła. Każde żądanie jest poprzedzone sekundową przerwą.

Źródło może zastąpić nieistniejący zestaw domyślną kombinacją. Taka odpowiedź
jest pomijana, jeśli jej atrybuty nie odpowiadają żądanemu zestawowi. Brak ceny,
błąd HTTP lub nieprawidłowa odpowiedź zatrzymuje aktualizację danego produktu
i trafia do raportu; nie jest zastępowany modyfikatorem zero. Cały rekord produktu
jest zapisywany dopiero po sprawdzeniu wszystkich kandydatów kombinacji.

Niektóre adresy źródłowe wskazują starą kombinację, której grupy nie pasują już
do aktualnego formularza (np. produkt 631: stary rozmiar zamiast kolorów).
Scraper sprawdza wtedy aktualne opcje przez kontroler produktu, zamiast
odrzucać produkt lub dodawać starą grupę jako nowy wybór. Stałe atrybuty,
których formularz nie pokazuje jako osobnego wyboru, są zachowywane w danych
potwierdzonej kombinacji. Wszystkie aktywnie wybrane atrybuty muszą się zgadzać.
Raport `product_errors` zapisuje przyczynę błędu dla każdego ID produktu,
a końcowy komunikat wymienia produkty, których aktualizacja się nie udała.

`variant_id` oznacza teraz źródłowe ID rzeczywistej kombinacji
(`id_product_attribute`), a nie ID pojedynczej opcji formularza. `attributes`
zawiera wszystkie atrybuty tej kombinacji. Każda kombinacja zawiera ponadto:

- `price_netto` i `price_brutto` — zaobserwowane ceny całej kombinacji;
- `price_modifier` — różnicę NETTO względem `price_netto` produktu bazowego.

Różnica jest obliczana przez `Decimal` i zaokrąglana do sześciu miejsc po
przecinku. Obsługiwane są dopłaty, obniżki i rzeczywiste zera. Bazą jest cena
produktu odczytana przy otwarciu strony; w ramach tego samego odświeżenia
aktualizowane są zarówno cena bazowa, jak i zależne od niej modyfikatory.
`parse_product()` bez sieci potwierdza tylko kombinację obecną w danym HTML;
pełną listę zbiera dopiero crawler.

Przykład zweryfikowany w źródle dla produktu 644: bazowe 23,89 zł brutto,
wariant 29,89 zł brutto i różnica netto 4,878049 zł przy VAT 23%.
Żeby odświeżyć także wcześniej zweryfikowane ceny wszystkich wariantów:

```powershell
.\venv\Scripts\python.exe scraper/main_scraper.py --refresh-variants
```

Pierwsze odświeżenie starego katalogu trwa dłużej: trzeba odczytać ceny
kombinacji wszystkich 228 dotychczasowych produktów z wariantami.
Pola raportu `variant_prices_complete` oraz
`products_with_unverified_variant_prices` wskazują status tej migracji.

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
.\venv\Scripts\python.exe -m unittest discover -s scraper -p "test_*.py" -v
```

W2.4

Dodano scrapping_images_module scrapujacy wszystkie zdjecia, ktore sa w products, dodawane sa zdjecia do folderu images (ktory jest w gitignore) oraz 20 zdjec sa dodawane do scraper-result/samples (ktore sa na githubie aby pokazac, ze scraper-images dziala) 

Dodano pliki create_zip_archive (tworzacy zipa ze wszystkich zdjec) i process_and_save_images (sprawdzamy czy plik ma poprawny np format, skladnie i zapisujemy)

Przed uruchomieniem ustaw wartość cookies w pliku scrapping_images_module.py:

``` cookies = "" ```

i poprzez komende:

```powershell
.\venv\Scripts\python.exe scraper/scrapping_images_module.py
```

### Wymagania zdjęć

Opisy są odczytywane osobno: description_short z elementu
product-description-short-ID, description z właściwego kontenera opisu.
Oba istniejące opisy zachowujemy nawet wtedy, gdy mają identyczną treść.
Jeśli źródło ma tylko jeden, trafia do description (na dół strony), a
description_short pozostaje pusty. Nie łączymy obu pól w jeden blok HTML.
Nowe scrapowanie zapisuje oba pola, a wznowienie uzupełnia starsze rekordy.
Do jednorazowej migracji istniejącego eksportu można użyć:

    .\venv\Scripts\python.exe scraper/refresh_descriptions.py

Ten skrypt zapisuje tylko oba opisy i description-report.json; pozostałe pola
produktu pozostają bez zmian. Nie uruchamiaj go jednocześnie z innymi zapisami
products.json. Nie trzeba ponownie pobierać zdjęć ani tworzyć archiwum.

Pole images zachowuje kolejność galerii. Osobne cover_image wskazuje zdjęcie
okładki odczytane ze źródłowych danych produktu lub głównego obrazu strony.
Okładka nie musi być pierwsza: np. produkt 661 ma najpierw rysunek techniczny,
a fotografię okładkową na drugiej pozycji. Nie zmieniamy kolejności galerii,
aby wymusić okładkę. Brak jednoznacznego wskazania przy wielu zdjęciach jest błędem.

Główny scraper uzupełnia brakujące okładki przy wznowieniu bez odświeżania cen.
Można też odświeżyć wyłącznie te metadane, bez ponownego pobierania zdjęć:

    .\venv\Scripts\python.exe scraper/refresh_image_covers.py

Opcjonalne --product-id ID wybiera jeden produkt, a --refresh odświeża również
wcześniej zapisane wskazania. Błędy są raportowane w image-cover-report.json;
ponowne uruchomienie domyślnie pomija poprawnie zapisane okładki.
Nie uruchamiaj odświeżania jednocześnie z innymi zapisami products.json.

Zdjęcia są zapisywane jako JPEG RGB. Dłuższy bok musi mieć od 800 do 1600 px,
a plik maksymalnie 300 000 bajtów (300 KB). Mniejsze źródła są powiększane,
większe pomniejszane z zachowaniem proporcji. Obrót EXIF jest stosowany przed
skalowaniem; przezroczystość zastępowana jest białym tłem.

Skrypt najpierw obniża jakość JPEG. Jeżeli to nie wystarczy, zmniejsza wymiary,
zachowując minimum 800 px. Gdy nie da się spełnić ograniczeń, zgłasza błąd
i nie zapisuje niepoprawnego wyniku. Zapis odbywa się przez plik tymczasowy.

Przy wznowieniu istniejące pliki są sprawdzane i w razie potrzeby przetwarzane
ponownie. Uszkodzone zdjęcia są pobierane ponownie ze źródła. Błąd pobrania
lub przetworzenia kończy przebieg kodem 1 i blokuje utworzenie nowego archiwum.

Pełny zbiór pozostaje w ignorowanym scraper-results/images/, a wygenerowane archiwum
scraper-results/product_images.zip też jest ignorowane. W repozytorium może
pozostać do 20 zdjęć w scraper-results/samples/. Próbki podlegają tym samym
ograniczeniom, a poprawne pliki nie są niepotrzebnie kompresowane ponownie.

Testy przetwarzania obrazów, cache i próbek, bez połączenia ze sklepem:

```powershell
.\venv\Scripts\python.exe -m unittest discover -s scraper -p test_image_processing.py -v
```
