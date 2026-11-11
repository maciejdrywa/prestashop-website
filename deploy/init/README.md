# Skrypty przygotowania sklepu

Uruchamiaj z głównego katalogu projektu, w działającym kontenerze:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/NAZWA.php

To elementy budowanej inicjalizacji. Import produktów, kategorii i kombinacji jest
dostępny. Brakuje jeszcze zdjęć z Release i adresów źródłowych, stanów magazynowych
oraz jednej komendy realizującej cały proces i pozostałej konfiguracji W3.

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
   produkty przez klasy PrestaShop. Importuje nazwę, opis, slug, wagę, netto,
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
9. **check-products.php** porównuje zapisany katalog ze źródłem bez zmian w bazie.
   Domyślnie sprawdza całość: pola, opisy po normalizacji HTML, tłumaczenia,
   kategorie, typy produktów, VAT, ceny i liczbę produktów. Sprawdza też minimum
   czterech kategorii głównych z dwiema niepustymi podkategoriami każda.
   Opcja --with-combinations dodatkowo sprawdza dokładną liczbę kombinacji,
   referencje, atrybuty, modyfikatory, domyślny wariant i rzeczywiste brutto.
   Nie jest to jeszcze kontrola gotowości zdjęć i stanów magazynowych.

Diagnostyka pomaga wykryć błędy wcześniej. W docelowej jednej komendzie nie trzeba
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

Po resecie sklepu kolejność przygotowania danych to:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-products.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-attributes.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-combinations.php --all
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-products.php --all --with-combinations

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

## Testy

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/source-test.php
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/prestashop-test.php

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
