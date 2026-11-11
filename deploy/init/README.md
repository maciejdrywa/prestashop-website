# Skrypty przygotowania sklepu

Uruchamiaj z głównego katalogu projektu, w działającym kontenerze:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/NAZWA.php

To elementy budowanej inicjalizacji. Brakuje jeszcze importera produktów,
zdjęć z Release i adresów źródłowych, stanów magazynowych i jednej komendy
realizującej cały proces.

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
   Ten etap tworzy drzewo; przyszły importer produktów musi zapisać przypisania.
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

Diagnostyka pomaga wykryć błędy wcześniej. W docelowej jednej komendzie nie trzeba
osobno uruchamiać podglądu wariantów: jego walidacja jest też używana przez import.

## Parametry importerów i podglądu

import-category.php, plan-variants.php, import-attributes.php i
import-combinations.php przyjmują ID produktu źródłowego (domyślnie 5)
albo --all. Importery przyjmują również --dry-run, np.:

    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-category.php --all --dry-run
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/plan-variants.php 119
    docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-attributes.php --all --dry-run

Podgląd import-combinations.php --dry-run sprawdza tylko źródło. Kontrola istnienia
produktów i atrybutów w sklepie następuje przy uruchomieniu bez tej opcji.
check-price-settings.php domyślnie sprawdza cały katalog; można wskazać pojedyncze ID.

Po resecie sklepu import kombinacji zakończy się komunikatem o brakujących
produktach. To oczekiwane: następnym etapem prac jest importer produktów.

## Biblioteki wspólne

- **lib/source.php**: odczyt i walidacja products.json, wybór produktów z argumentów,
  budowa drzewa kategorii i słownika atrybutów oraz plan cen. Kategorie pochodzą
  z category_paths, a nie z nawigacyjnego breadcrumbs.
- **lib/prestashop.php**: kontekst sklepu, sprawdzanie konfiguracji cen, wyszukiwanie
  i zapis kategorii, atrybutów oraz kombinacji przez klasy PrestaShop.
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

prestashop-test.php wymaga bazy testowej bez produktów źródłowych
5, 119, 644, 463, 808 i 826. Tworzy całe drzewo i słownik atrybutów oraz tymczasowe
produkty i 39 kombinacji. Sprawdza ponowne wykonanie, migrację starszych referencji,
wiele grup oraz rzeczywiste ceny brutto silnika sklepu. Wyłącza hooki modułów
wyłącznie w procesie testowym, żeby umożliwić rollback. Nie testuje w ten sposób
efektów ubocznych modułów. Wycofuje zapisy i porównuje liczności tabel; wartości
AUTO_INCREMENT mogą wzrosnąć mimo wycofania.
