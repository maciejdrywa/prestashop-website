# Skrypty przygotowania sklepu

Uruchamiaj z głównego katalogu projektu, w działającym kontenerze:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/NAZWA.php

## Jedna komenda inicjalizacji

W działającym środowisku Docker, z głównego katalogu projektu, uruchom:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/init-shop.php

Wymagane lokalne dane:

    scraper-results/
      products.json
      categories.json
      images/

Pliki JSON muszą pochodzić z aktualnego scrapera: produkty zawierają oba opisy,
pełne category_paths, zweryfikowane ceny kombinacji i cover_image.
Każdy URL w images produktu musi mieć odpowiadający plik JPEG w images/.
Istniejący mount Compose udostępnia cały folder w /opt/shop/scraper-results.
PrestaShop musi być już zainstalowany i uruchomiony; po odtworzeniu pustych
wolumenów poczekaj na zakończenie jego automatycznej instalacji, a potem wykonaj
powyższą komendę. Sam start kontenerów nadal nie uruchamia importu danych.

Komenda wykonuje 10 etapów:

1. Usunięcie produktów demonstracyjnych oraz wyłączenie przykładowych slajdów i bloku tekstowego.
2. Kategorie oraz produkty, w tym ceny, VAT i oba opisy.
3. Menu górne z kategoriami i podkategoriami ze scrapera.
4. Grupy i wartości atrybutów.
5. Kombinacje i modyfikatory cen.
6. Galerie, okładki i miniatury z lokalnych plików.
7. Kontrola produktów i kombinacji.
8. Kontrola galerii.
9. Stany 0–10 i aktywacja.
10. Końcowa kontrola stanów, dostępności i aktywacji.

Przed pierwszym importem sprawdzane są wszystkie źródła i dostępne reguły VAT,
kompletność lokalnych zdjęć oraz minimalna struktura kategorii. Walidacja zdjęć
obsługuje też świeżą bazę, w której produkty dopiero powstaną. Kategorie są
przygotowywane przez import-products.php, więc osobna komenda nie jest potrzebna.
Program pokazuje numer etapu, szczegółowy postęp importerów oraz czas wykonania.
Blokada chroni przed równoległym wykonaniem init-shop.php. Nie uruchamiaj w tym
czasie pojedynczych importerów ani scrapera zapisującego JSON/zdjęcia.

Błąd podprocesu kończy inicjalizację kodem 1 z nazwą etapu i zatrzymuje dalsze
kroki. Zmiana JSON w trakcie pracy również zatrzymuje proces. Zakończone etapy
mogą pozostawić zapisy: popraw przyczynę i powtórz tę samą komendę. Importy
rozpoznają istniejące rekordy. Nowe produkty są aktywowane po walidacji danych
i galerii; aktywność produktów istniejących wcześniej jest zachowywana podczas
wcześniejszych etapów. Ponowienie inicjalizacji odtwarza początkowy plan stanów.
Produkty demonstracyjne PrestaShop są usuwane przed importem.

Pusta galeria źródłowa, np. produktu 539, jest jawnie raportowana. Taki przebieg
kończy się COMPLETE WITH SOURCE WARNINGS; nie oznacza potwierdzenia wyjątku
brakującego zdjęcia przy odbiorze projektu.

Samą walidację i plan, bez importu lub aktywacji, uruchomisz poleceniem:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/init-shop.php --dry-run

Pomoc jest dostępna przez --help. Na tym etapie wymagany jest lokalny folder
images/. Pobieranie archiwum z Release, ścieżka zastępcza z URL-i i pozostała
konfiguracja W3 nadal wymagają osobnych prac.

## Usuwanie zawartości demonstracyjnej

`configure-storefront.php` rozpoznaje 19 produktów instalatora PrestaShop 8.2.8
po dokładnej parze referencja `demo_*` i domyślna nazwa. Nie opiera się na ID
produktu ani na założeniu, że każdy produkt spoza scrapera jest testowy.
Zmienione nazwy przy referencjach demo są zachowywane i zgłaszane ostrzeżeniem.
Produkty są usuwane przez `Product::delete()`, wraz z obsługiwanymi przez
PrestaShop powiązaniami, wariantami i zdjęciami. Kategorie demo nie są usuwane.

Skrypt wyłącza moduły `ps_imageslider` i `ps_customtext` w bieżącym sklepie.
Usuwa to ze strony głównej przykładowe slajdy oraz blok „Custom Text Block”
z tekstem lorem ipsum. Moduły pozostają zainstalowane, ale konfiguracja
celowo wyłącza je przy każdym uruchomieniu skryptu, również po ręcznym włączeniu.
Pozostałe bloki produktów i inne treści sklepu nie są wyłączane.

Skrypt jest pierwszym etapem `init-shop.php`. Dodatkowo obraz sklepu zawiera
hook `/tmp/init-scripts/20-configure-storefront.sh`, który uruchamia go jako
`www-data` po instalacji PrestaShop i przy kolejnych startach kontenera.
Nie wymaga plików scrapera, dzięki czemu działa również przed pierwszym importem.
Samo uruchomienie kontenera nadal nie importuje katalogu produktów.

Po aktualizacji Dockerfile przebuduj obraz i odtwórz kontener (bez kasowania wolumenów):

    docker compose --env-file .env -f deploy/compose.yaml up -d --build shop

Można też wykonać samo czyszczenie istniejącej instalacji:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-storefront.php

Dodanie `--dry-run` wyświetla plan bez zmian. Ponowienie zwykłego wykonania
nie usuwa produktów ze scrapera ani innych produktów bez sygnatury instalatora.
Błąd usuwania lub wyłączenia modułu kończy proces kodem 1. Operacji usuwania
produktów nie obejmuje transakcja: usunięte zdjęcia i wykonane hooki nie mogą
być odtworzone przez SQL rollback.

## Menu kategorii

`configure-menu.php` zastępuje pozycje modułu `ps_mainmenu` sześcioma działami
w kolejności menu źródłowego: Klamki do drzwi, Okucia drzwiowe, Bramy i ogrodzenia,
Okucia budowlane, Dom i ogród, Akcesoria meblowe. Podkategoriami są wszystkie
ścieżki tych działów z `scraper-results/categories.json`, również puste kategorie
i dalsze poziomy. Obecnie jest to 79 węzłów. Gałąź „Produkty polecane” pozostaje
poza menu. Kategorie demonstracyjne są usuwane z konfiguracji nawigacji,
ale nie są kasowane z bazy.

Skrypt rozpoznaje kategorie po pełnej ścieżce i lokalnych ID, tworzy brakujące,
aktywuje je i odtwarza kolejność rodzeństwa z pliku źródłowego. Następnie zapisuje
`MOD_BLOCKTOPMENU_ITEMS` dla bieżącego sklepu, włącza moduł oraz czyści cache
menu i Smarty. Nie zmienia produktów ani ich przypisań do kategorii.
Nie pobiera strony źródłowej podczas inicjalizacji.

Menu jest konfigurowane po imporcie kategorii i produktów w `init-shop.php`.
Hook startowy `30-configure-menu.sh` odtwarza je również po instalacji i przy
starcie kontenera, jeśli istnieje czytelny `categories.json`. Może wówczas utworzyć
kategorie jeszcze przed importem produktów. Gdy pliku brakuje, hook wypisuje
komunikat o odroczeniu; po dostarczeniu źródeł zwykłe `init-shop.php` ustawi menu.
Niepoprawny plik, brak wymaganej kategorii głównej lub błąd zapisu kończy skrypt
kodem 1. Po zmianie Dockerfile potrzebne jest `up -d --build shop`, jak wyżej.

Samodzielna konfiguracja istniejącej instalacji:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-menu.php

Opcja `--dry-run` wyświetla pełną planowaną hierarchię bez zapisów w bazie.
Test `tests/menu-test.php` sprawdza plan, zagnieżdżenia i błędne dane.
`tests/menu-http-test.php` sprawdza wyrenderowane menu, kolejność oraz HTTP 200
wszystkich odnośników. Ten drugi test jest przeznaczony dla lokalnego Compose
pod adresem `https://localhost`, z usługą nginx i już skonfigurowanym menu;
pomija weryfikację lokalnego certyfikatu TLS wyłącznie w swoim kliencie testowym.

## Pliki i kolejność

1. **check-environment.php** ładuje PrestaShop, ustala sklep, sprawdza połączenie
   z bazą przez SELECT 1 i dostępność bcmath. Nie zmienia danych.
   To test przed importem, a nie samodzielny etap ładowania danych.
2. **configure-mail.php** zapisuje i sprawdza SMTP mailpit:1025.
   Opróżnia login i hasło, wyłącza szyfrowanie dla lokalnego Mailpit i ustawia
   wysyłkę HTML oraz tekstową. Nie wysyła wiadomości. Jest też wywoływany przez
   skrypt startowy obrazu sklepu; zachowano tę ścieżkę.
3. **check-price-settings.php** wymaga PLN, Polski jako kraju domyślnego i włączonego
   VAT. Wypisuje ogólne aktywne reguły podatkowe dla Polski i sprawdza dostępność
   stawek występujących w danych. Weryfikuje ceny produktów i kombinacji,
   raportując korekty precyzji. Niczego nie konfiguruje.
4. **import-category.php** zbiera pełne category_paths, tworzy rodziców przed
   dziećmi i rozpoznaje istniejące kategorie po nazwie, rodzicu, języku oraz sklepie.
   Jednakowe nazwy pod różnymi rodzicami oznaczają różne kategorie.
   Opcja --all dodaje również puste kategorie z categories.json.
   Ten etap tworzy drzewo; import-products.php zapisuje przypisania produktów.
   Importer produktów sam przygotowuje potrzebne kategorie, więc osobne wykonanie
   import-category.php przed nim jest opcjonalne.
5. **plan-variants.php** pokazuje rzeczywiste kombinacje: źródłowe ID, wszystkie
   grupy i wartości, modyfikator netto oraz oczekiwaną cenę brutto.
   Odrzuca brakujące ceny, powtórzone ID i zestawy atrybutów oraz niespójne grupy.
   To opcjonalny podgląd diagnostyczny, bez dostępu do bazy.
6. **import-attributes.php** przygotowuje słownik wszystkich grup i wartości
   występujących w wybranych produktach. Powtórzenia wartości w kombinacjach
   są usuwane. Ponowne wykonanie wykorzystuje te same rekordy.
   Nie tworzy jeszcze wariantów produktów.
7. **import-combinations.php** wymaga istniejących produktów o referencjach
   pandomu-ID oraz wcześniej przygotowanych atrybutów. Sprawdza cenę bazową,
   zapisuje komplet atrybutów każdej kombinacji i jej modyfikator netto.
   Referencja kombinacji ma postać pandomu-ID-combination-ID_KOMBINACJI.
   Starsze warianty mogą zostać rozpoznane po pełnym zestawie atrybutów,
   dzięki czemu aktualizacja referencji nie powiela rekordów. Zachowuje poprawny
   wariant domyślny albo wybiera pierwszy źródłowy. Na końcu porównuje ceny wyliczone
   przez PrestaShop ze źródłowymi cenami brutto. Nie aktywuje produktów, nie ustawia
   stanów i nie usuwa kombinacji nieobecnych w aktualnym eksporcie.
8. **import-products.php** sprawdza cały wybrany zestaw przed zapisem: nazwy, opisy
   HTML, ceny, VAT, wagę i istniejące referencje. Przygotowuje kategorie i zapisuje
   produkty przez klasy PrestaShop. Importuje nazwę, pełny i krótki opis, slug, wagę, netto,
   regułę VAT oraz typ standard/combinations. Wszystkie dostępne języki otrzymują
   tekst źródłowy po polsku. HTML jest normalizowany przez purifier PrestaShop,
   jeżeli ta funkcja jest włączona; zmiany są raportowane, bez nadpisywania JSON.
   Nowe produkty są nieaktywne, ale mają stan STATE_SAVED i są widoczne w panelu.
   Przy aktualizacji zachowywana jest aktywność, zdjęcia, stany i kombinacje.
   Referencja pandomu-ID identyfikuje produkt przy ponownym uruchomieniu.
   Przypisania obejmują wszystkie źródłowe ścieżki i ich przodków; stare przypisania
   są zastępowane. Kategoria domyślna pochodzi z breadcrumbs, jeśli pasuje do
   category_paths; w przeciwnym razie wybierana jest najgłębsza ścieżka
   (przy remisie alfabetycznie). Na końcu każdego zapisu importer sprawdza pola,
   tłumaczenia, przypisania i cenę brutto obliczoną przez PrestaShop.
   Brak źródłowej wagi jest raportowany; zachowywana jest wcześniejsza wartość
   albo domyślne 0 nowego produktu. Wymagana jednostka sklepu to kg.
   Pole description trafia do opisu na dole strony, a description_short do opisu
   obok ceny. Dwa źródłowe opisy zachowujemy nawet przy identycznej treści.
   Przy jednym opisie scraper zapisuje go jako pełny i pozostawia krótki pusty;
   importer czyści wtedy wcześniejszy krótki opis, aby nie został stary tekst.
   Eksport bez pola description_short wymaga odświeżenia danych przed importem.
9. **check-products.php** porównuje zapisany katalog ze źródłem bez zmian w bazie.
   Domyślnie sprawdza całość: pola, opisy po normalizacji HTML, tłumaczenia,
   kategorie, typy produktów, VAT, ceny i liczbę produktów. Sprawdza też minimum
   czterech kategorii głównych z dwiema niepustymi podkategoriami każda.
   Opcja --with-combinations dodatkowo sprawdza dokładną liczbę kombinacji,
   referencje, atrybuty, modyfikatory, domyślny wariant i rzeczywiste brutto.
   Nie jest to jeszcze kontrola gotowości zdjęć i stanów magazynowych.
10. **import-images.php** importuje zdjęcia z scraper-results/images/ dostępnego
    w kontenerze jako /opt/shop/scraper-results/images. Przyjmuje ID źródłowe
    (domyślnie 5), --all oraz --dry-run. Przed zapisem całego wybranego zestawu
    sprawdza produkty, galerie, nazwy plików i poprawność JPEG: 800–1600 px na
    dłuższym boku i maksymalnie 300000 bajtów. Oryginał jest kopiowany bez ponownej
    kompresji do katalogu zdjęć PrestaShop, a ImageManager generuje miniatury JPEG
    wszystkich typów produktu skonfigurowanych w sklepie. Miniatury mają rozmiary
    wymagane przez motyw; minimum 800 px dotyczy oryginałów, nie miniatur.
    Kolejność pochodzi z images w JSON. Okładkę wskazuje osobne pole cover_image
    odczytane ze źródła; nie musi to być pierwsze zdjęcie galerii.
    Dla jednego zdjęcia wybór jest jednoznaczny. Dla wielu zdjęć brak poprawnego
    cover_image zatrzymuje import zamiast ustawiać przypadkową okładkę.
    Nazwa produktu trafia do podpisów zdjęć, do limitu 128 znaków.
11. **check-images.php** domyślnie kontroluje cały katalog; można wskazać jedno ID.
    Sprawdza kompletność galerii, kolejność, okładkę, podpisy, zgodność bajtową
    oryginałów ze źródłowymi plikami oraz wymiary i sumy kontrolne miniatur.
    Niczego nie zapisuje i nie pobiera zdjęć z sieci.
12. **import-stock.php** ustawia stany oraz aktywuje wybrane produkty. Przyjmuje
    ID źródłowe (domyślnie 5), --all i --dry-run. Plan zawsze powstaje dla całego
    products.json: deterministycznie wybiera około 10% produktów ze stanem 0,
    zachowując wymagane 5–20%. Pozostałe otrzymują 1–10 sztuk. Nie korzysta
    z losowych pól stock zapisanych przez scraper i nie zmienia pliku JSON.
    Dla kombinacji rozdziela łączny stan produktu, zatem suma ilości wariantów
    jest w zakresie 0–10. Przy wielu wariantach część z nich może mieć 0 nawet
    dla dostępnego produktu; limit procentowy dotyczy produktów, nie wariantów.
    Jeśli stan jest dodatni, domyślna kombinacja musi mieć dodatnią ilość.
    Zachowuje istniejącą dostępną domyślną albo wybiera dostępną z planu.
    Aktywuje produkt, ustawia widoczność w katalogu i wyszukiwaniu oraz możliwość
    zamówienia. Dla braku towaru ustawia zakaz zamawiania, nadpisując ewentualną
    globalną zgodę na zamówienia bez stanu. Nie tworzy ruchów magazynowych.
    Wymaga włączonego zarządzania stanami oraz wcześniej zapisanych produktów
    i dokładnego kompletu kombinacji. Nie obsługuje zaawansowanych magazynów.
    Ponowne wykonanie przywraca ten sam stan dla tego samego katalogu.
13. **check-stock.php** domyślnie sprawdza wszystkie produkty, opcjonalnie jedno ID.
    Odczytuje rzeczywiste wiersze stock_available: weryfikuje stan produktu,
    każdą kombinację, sumy, domyślny wariant, aktywację oraz blokadę zamawiania
    niedostępnego towaru. Dla pełnego katalogu sprawdza też zakres 5–20% stanów 0.
    Stan 0 oznacza produkt aktywny, widoczny, ale niedostępny do zamówienia.
14. **init-shop.php** sprawdza komplet lokalnych danych i uruchamia całą sekwencję.
    Każdy etap działa w osobnym procesie PHP, żeby nie współdzielić pamięci cache
    PrestaShop między kolejnymi importerami.

Starszy eksport można uzupełnić samymi metadanymi okładek:

    .\venv\Scripts\python.exe scraper/refresh_image_covers.py

Opcja --product-id 661 ogranicza aktualizację do produktu źródłowego.
Opcja --refresh wymusza ponowne sprawdzenie już zapisanych okładek.
Skrypt zapisuje cover_image i image-cover-report.json. Nie pobiera ponownie zdjęć
ani nie zmienia ich kolejności, cen lub wariantów. Nie uruchamiaj go jednocześnie
z innym skryptem zapisującym products.json. Główny scraper również zapisuje
cover_image i uzupełnia brakujące okładki przy wznowieniu.

Starsze opisy można rozdzielić bez ponownego pobierania cen i wariantów:

    .\venv\Scripts\python.exe scraper/refresh_descriptions.py

Skrypt uzupełnia wyłącznie description oraz description_short i zapisuje
description-report.json. Opcje --product-id ID i --refresh działają jak dla okładek.
Przy zwykłym nowym scrapowaniu ani wznowieniu dodatkowy skrypt nie jest wymagany.

Diagnostyka pomaga wykryć błędy wcześniej. W jednej komendzie nie trzeba
osobno uruchamiać podglądu wariantów: jego walidacja jest też używana przez import.

## Parametry importerów i podglądu

import-category.php, import-products.php, plan-variants.php, import-attributes.php i
import-combinations.php przyjmują ID produktu źródłowego (domyślnie 5)
albo --all. Importery przyjmują również --dry-run, np.:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-category.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/plan-variants.php 119
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-attributes.php --all --dry-run

Podgląd import-combinations.php --dry-run sprawdza tylko źródło. Kontrola istnienia
produktów i atrybutów w sklepie następuje przy uruchomieniu bez tej opcji.
check-price-settings.php domyślnie sprawdza cały katalog; można wskazać pojedyncze ID.

Powyższy init-shop.php wykonuje cały proces. Do ręcznej diagnostyki kolejnych
etapów można nadal używać następującej sekwencji:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-attributes.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-combinations.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-products.php --all --with-combinations
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-images.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-images.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-images.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-stock.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-stock.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-stock.php --all

Zamiast --all można wskazać ID źródłowe, np. 5. Bez ID importer produktów wybiera 5.
Przykładowy check-products.php 5 --with-combinations sprawdza tylko ten produkt.
Brak produktów lub atrybutów w imporcie kombinacji oznacza pominięcie poprzedniego
etapu. Żaden z tych skryptów nie usuwa demonstracyjnych produktów PrestaShop.

## Biblioteki wspólne

- **lib/source.php**: odczyt i walidacja products.json, wybór produktów z argumentów,
  budowa drzewa kategorii i słownika atrybutów oraz plan cen. Kategorie pochodzą
  z category_paths, a nie z nawigacyjnego breadcrumbs.
- **lib/prestashop.php**: kontekst sklepu, sprawdzanie konfiguracji cen, wyszukiwanie
  i zapis kategorii, atrybutów oraz kombinacji przez klasy PrestaShop.
- **lib/products.php**: plan danych produktów, dobór aktywnej ogólnej reguły VAT
  dla Polski i bieżącego sklepu, zapis produktów oraz kontrola zapisanych pól.
- **lib/images.php**: mapowanie źródłowych URL-i na ID zdjęć, walidacja plików,
  zapis galerii, generowanie i sprawdzanie miniatur.
- **lib/stock.php**: powtarzalny plan ilości według źródłowego ID, podział na
  kombinacje, zapis stanów, aktywacja i kontrola faktycznych stanów.
- **lib/initialization.php**: walidacja wejścia, lista etapów, uruchamianie procesów
  i zatrzymywanie po błędzie lub zmianie JSON.
  Biblioteki nie uruchamiają samodzielnie importu.

Obliczenia cen używają dziesiętnej arytmetyki bcmath. Cena bazowa netto to
brutto / (1 + VAT/100), zapisana do sześciu miejsc. Źródłowy price_modifier
jest różnicą netto, także ujemną. Zachowujemy go, jeżeli daje właściwe brutto.
Przy granicy zaokrąglenia dopuszczamy minimalną korektę precyzji i jawnie ją
raportujemy; w razie potrzeby różnicę netto wyliczamy z docelowego brutto.
Niespójne źródłowe netto, VAT i brutto powodują błąd. JSON nie jest nadpisywany.

Każdy skrypt zgłasza ERROR na stderr i kod zakończenia 1 przy błędzie.
Zapisy są otaczane transakcją, ale hooki modułów mogą wykonać TRUNCATE, który
zatwierdza transakcję w MySQL. Nie należy zakładać pełnej atomowości importu.
Po błędzie popraw przyczynę i uruchom ponownie; import tych samych danych
rozpoznaje istniejące rekordy. Równoległe uruchamianie importerów nie jest obsługiwane.

## Wznawianie importu zdjęć

Tabela import_image_map (z prefiksem bazy PrestaShop) przechowuje parę:
sklep + ID produktu źródłowego + hash URL-a -> ID zdjęcia.
Mapowanie jest zapisywane przed generowaniem plików, więc przerwane generowanie
nie wymaga tworzenia kolejnego zdjęcia. Sumy SHA-256 plików i sygnatura ustawień
miniatur pozwalają pomijać poprawne pliki albo odtworzyć uszkodzone/brakujące.
Każdy plik jest zapisywany atomowo. Równoległy import zdjęć blokuje lokalny lock.
Zapisy plików nie są objęte transakcją SQL; po błędzie należy poprawić przyczynę
i ponowić tę samą komendę. Mapowanie i wygenerowane pliki są częścią danych sklepu
i powinny być zachowywane razem przy wykonywaniu kopii zapasowej.

Nieznane zdjęcia dołączone ręcznie, usunięte źródłowe URL-e i konflikty mapowania
zatrzymują import do przeglądu. Skrypt nie usuwa automatycznie istniejących zdjęć
i nie zmienia powiązań zdjęć z kombinacjami ani aktywności produktów.
Pusta galeria produktu 539 jest raportowana; nie przypisujemy przypadkowej grafiki.
Kod 0 oznacza import dostępnych zdjęć, nie zatwierdzenie wyjątku dla braków źródła.

Ten etap korzysta wyłącznie z lokalnych JPEG-ów przygotowanych przez scraper.
Nie rozpakowuje ZIP-a i nie pobiera jeszcze Release ani zdjęć ze źródłowych URL-i.
To osobny etap przygotowania plików, do dodania przed docelową jedną komendą.

## Testy

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/source-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/prestashop-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/images-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/stock-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/initialization-test.php

source-test.php sprawdza cały aktualny katalog, ceny, kolejność kategorii oraz
przypadki błędnych danych i ujemnych modyfikatorów.

prestashop-test.php używa prywatnych ID produktów z zakresu 900000000+, dzięki
czemu działa także po imporcie katalogu. Tworzy lub rozpoznaje całe drzewo
i słownik atrybutów oraz zapisuje tymczasowe produkty i 39 kombinacji.
Sprawdza ponowne wykonanie, aktualizację nazwy, opisu, wagi i wielu przypisań,
zachowanie aktywności i stanu, odrzucanie niebezpiecznego HTML przed zapisem,
ponowny import po dodaniu kombinacji, migrację starszych referencji,
wiele grup oraz rzeczywiste ceny brutto silnika sklepu. Wyłącza hooki modułów
wyłącznie w procesie testowym, żeby umożliwić rollback. Nie testuje w ten sposób
efektów ubocznych modułów. Wycofuje zapisy i porównuje liczności tabel; wartości
AUTO_INCREMENT mogą wzrosnąć mimo wycofania.

images-test.php używa prywatnego produktu 900000005 i czterech lokalnych zdjęć
produktu 5. Sprawdza limity źródeł, brak duplikatów, zmianę kolejności i okładki,
naprawę uszkodzonej miniatury i brakującego oryginału, wznowienie niedokończonego
zapisu oraz odrzucenie nieznanego zdjęcia w galerii. Usuwa własny produkt, obrazy,
pliki i mapowania po zakończeniu.

stock-test.php sprawdza stabilność planu po zmianie kolejności JSON, granice
0/1/10, podział między 12 wariantów, niedostępną domyślną kombinację, aktywację,
brak duplikacji ilości po ponowieniu oraz odrzucenie brakujących kombinacji.
Używa prywatnych ID 900000012 i 900000119; wyłącza hooki modułów w procesie
testowym i wycofuje zapisy. Nie uruchamiaj równolegle z innymi importerami.

initialization-test.php sprawdza brak wymaganych plików, minimalne kategorie,
zmianę źródła, kod błędu procesu potomnego oraz zatrzymanie przed aktywacją po
nieudanym imporcie. Sprawdza też walidację zdjęć przed istnieniem produktu
w bazie, bez tworzenia rekordów testowych.
