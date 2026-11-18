# Weryfikacja projektu

## Python i JavaScript

Polecenia wykonuj z katalogu repozytorium po instalacji zależności scrapera.

```powershell
.\venv\Scripts\python.exe -m unittest discover -s scraper -p 'test_*.py'
.\venv\Scripts\python.exe scraper/test_product_parser.py
.\venv\Scripts\python.exe scraper/test_category_parser.py
node --test deploy/init/tests/cookie-notice.test.cjs
```

Parsery dawnych fixture są funkcjami testowymi. Są dołączone do odkrywania unittest przez `load_tests`, łącznie dając 58 przypadków. Uruchomienie ich plików również wykonuje lokalne przypadki. Testy Pythona korzystają z zapisanych HTML i atrap odpowiedzi. Test JavaScript sprawdza zamknięcie cookies oraz zachowanie przy niedostępnym magazynie przeglądarki.

## PHP

Po inicjalizacji osobnego środowiska testowego użyj poniższego wzorca dla każdego zachowanego pliku `deploy/init/tests/*-test.php`.

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/source-test.php
```

Testy `source`, `weights`, `initialization`, `menu`, `content`, `module-texts`, `storefront` i `orders-config` sprawdzają kontrakt danych i konfigurację. `prestashop`, `images`, `stock`, `carriers` i `shipping-threshold` wykonują operacje klas PrestaShop na danych testowych. Testy koszyka tworzą pomocnicze produkty, adresy i koszyki, a następnie je sprzątają. Test `mail-templates` generuje szablony porównawcze i wysyła testowe wiadomości do lokalnego Mailpit. Uruchamiaj testy mutujące na osobnych wolumenach.

## Testy E2E (Playwright)

Każdy krok zawiera asercje. Testy generują raport HTML lub JUnit XML oraz zrzut ekranu przy błędzie. Pełny scenariusz musi zakończyć się w ciągu 5 minut.

### Jednorazowa instalacja
```
cd tests
npm install
npx playwright install chromium
```

### Uruchomienie testów
Adres sklepu przekazujemy zmienną środowiskową `BASE_URL`. Zmiana adresu nie wymaga edycji kodu.

PowerShell (Windows):
```
$env:BASE_URL="https://localhost"; npm test
```

Testy działają w trybie headless. Samodzielnie podpisany certyfikat HTTPS jest akceptowany.

### Wymagania przed uruchomieniem na lokalnym sklepie
1. Sklep musi być uruchomiony (`https://localhost`), zgodnie z głównym README.
2. Indeks wyszukiwarki musi być zbudowany. Skrypty importu nie indeksują produktów, przez co wyszukiwarka zwraca „Brak wyników wyszukiwania”, a test koszyka nie przechodzi kroku wyszukiwania. Indeks buduje się raz po imporcie, poleceniem z głównego katalogu repozytorium:
```
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php -r "require '/var/www/html/config/config.inc.php'; var_dump(Search::indexation(true));"
```
Wynik `bool(true)` oznacza poprawne zbudowanie indeksu. To samo można zrobić w panelu: Parametry sklepu → Wyszukiwanie → „Przebuduj cały indeks”.

### Uruchomienie pojedynczego testu
```
$env:BASE_URL="https://localhost"; npx playwright test cart
$env:BASE_URL="https://localhost"; npx playwright test cart --headed
$env:BASE_URL="https://localhost"; npx playwright test order --headed
$env:BASE_URL="https://localhost"; npx playwright test invoice --headed
```
Opcja `--headed` pokazuje okno przeglądarki.

### Opis testów

#### `e2e/shop-available.spec.ts`: dostępność sklepu
Otwiera stronę główną pod adresem z `BASE_URL`. Sprawdza, że serwer odpowiada poprawnym kodem HTTP i że strona ma niepusty tytuł.

#### `e2e/cart.spec.ts`: scenariusz koszyka
Wykonuje scenariusz koszyka wymagany na odbiorze. Każda operacja kończy się asercją sprawdzającą jej efekt. Kroki znajdują się w funkcji `scenariuszKoszyka()` w pliku `e2e/helpers/koszyk.ts`, bo ten sam scenariusz otwiera test zamówienia.

1. **Dodanie 10 różnych produktów z dwóch kategorii**: po 5 z kategorii „Akcesoria meblowe” i „Klamki do drzwi”, w różnych ilościach (1–3 szt.). Po każdym dodaniu test sprawdza, że pojawiło się okno potwierdzenia koszyka. Następnie sprawdza, że koszyk zawiera 10 pozycji z właściwymi ilościami.
2. **Wyszukanie produktu po nazwie**: wpisuje frazę (domyślnie `klamka`) w wyszukiwarkę. Czeka na stronę wyników dla tej frazy (adres z parametrem `s`) i sprawdza, że lista wyników nie jest pusta. Losowanie korzysta tylko z tej listy.
3. **Losowanie produktu z wyników**: wybiera losowo produkt, którego nie ma jeszcze w koszyku i który jest dostępny, a potem dodaje go do koszyka. Test sprawdza, że koszyk ma 11 pozycji.
4. **Usunięcie 3 produktów**: po każdym usunięciu sprawdza, że zniknął właściwy wiersz i liczba pozycji zmalała o 1.
5. **Stan końcowy**: koszyk zawiera 8 pozycji, pozostałe ilości są niezmienione, a usunięte produkty nie występują.

Sposób działania:
- **Dobór produktów.** Test nie ma zapisanych na sztywno adresów ani ID produktów. Otwiera kategorie przez menu, a na stronie produktu odczytuje ID i stan magazynowy (`data-stock`). Bierze tylko produkty z aktywnym przyciskiem „Dodaj do koszyka” i stanem nie mniejszym niż wymagana ilość. Produkty bez widocznego stanu są pomijane, bo aktywny przycisk potwierdza dostępność tylko 1 sztuki. Dlatego test nie próbuje dodać niedostępnych produktów i działa przy losowych stanach 0–10 w lokalnym sklepie.
- **Rozpoznawanie pozycji w koszyku.** Pozycje są rozpoznawane po ID produktu, a nie po nazwie.
- **Brak stałych opóźnień.** Test czeka na stan strony, np. na okno koszyka albo zniknięcie wiersza.
- **Limit czasu.** Cały scenariusz ma limit 5 minut.
- **Zmiana frazy.** Frazę wyszukiwania można zmienić bez edycji kodu, zmienną `FRAZA`:
```
$env:BASE_URL="https://localhost"; $env:FRAZA="zawias"; npx playwright test cart
```
- **Wyniki w konsoli.** Test wypisuje dodane produkty (nazwa, ID, ilość, stan) i wylosowany produkt.

#### `e2e/order.spec.ts`: zakup przez nowego klienta
Kontynuuje scenariusz koszyka i składa zamówienie jako nowy klient. Każdy krok kończy się asercją sprawdzającą jego efekt. Kroki 1–6 znajdują się w funkcji `scenariuszZamowienia()` w pliku `e2e/helpers/zamowienie.ts`, bo ten sam zakup otwiera test faktury.

1. **Operacje na koszyku**: cały scenariusz z `cart.spec.ts`. W koszyku zostaje 8 pozycji.
2. **Rejestracja nowego konta**: imię, nazwisko, unikalny e-mail (`w43.<czas>.<losowe>@example.test`), hasło i wymagane zgody. Test sprawdza, że klient jest zalogowany, formularz nie zgłosił błędu, a koszyk po rejestracji nadal ma te same 8 pozycji i ilości.
3. **Adres**: ulica, kod pocztowy, miasto i telefon. Test sprawdza, że kraj to Polska, krok adresu jest zaliczony i aktywny jest krok dostawy.
4. **Przewoźnik**: test sprawdza, że dostępni są obaj nowi przewoźnicy (InPost i DPD), zaznacza jednego z nich i sprawdza przejście do kroku płatności.
5. **Płatność przy odbiorze**: zaznacza płatność modułu `ps_cashondelivery`, sprawdza jej etykietę, akceptuje regulamin i składa zamówienie. Test sprawdza, że otworzyła się strona potwierdzenia.
6. **Potwierdzenie**: numer zamówienia, wybrany przewoźnik i płatność przy odbiorze. Lista produktów ma tyle samo pozycji co koszyk, z tymi samymi nazwami i ilościami.
7. **Historia klienta**: zamówienie o tym numerze jest na koncie klienta ze statusem płatności przy odbiorze.

Uwagi:
- **Ponowne uruchomienie.** Każdy bieg tworzy konto z nowym adresem e-mail, więc nie dochodzi do konfliktu z istniejącym kontem.
- **Wybór przewoźnika.** Domyślnie InPost. Można go zmienić bez edycji kodu zmienną `PRZEWOZNIK`:
```
$env:BASE_URL="https://localhost"; $env:PRZEWOZNIK="DPD"; npx playwright test order
```
- **Stany magazynowe.** Każde zamówienie zmniejsza stany. Test dobiera dostępne produkty na bieżąco, ale po wielu uruchomieniach może zabraknąć produktów w wymaganych ilościach. Test celowo nie zmienia magazynu sam. Początkowe stany (plan 0–10 szt.) przywraca komenda z głównego katalogu repozytorium, warto ją wykonać przed odbiorem:
```
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/import-stock.php --all
```

#### `e2e/invoice.spec.ts`: obsługa zamówienia po zakupie i faktura VAT
Składa zamówienie jak `order.spec.ts`, a potem sprawdza jego obsługę. Każdy krok kończy się asercją sprawdzającą jego efekt.

1. **Zakup**: cały scenariusz `scenariuszZamowienia()` z płatnością przy odbiorze.
2. **Historia klienta przed opłaceniem**: zamówienie odszukane po numerze (referencji), status „Oczekiwanie na płatność przy odbiorze”, brak linku do faktury.
3. **Potwierdzenie płatności w panelu**: test loguje się do panelu, otwiera to zamówienie, zmienia status na „Płatność zaakceptowana” i sprawdza komunikat sukcesu oraz nowy bieżący status.
4. **Historia klienta po opłaceniu**: status „Płatność zaakceptowana” i link do faktury tego zamówienia.
5. **Pobranie faktury**: test pobiera plik i sprawdza, że ma rozszerzenie `.pdf`, ponad 2 KB, nagłówek `%PDF-`, znacznik `%%EOF` i co najmniej jedną stronę. Faktura jest dołączona do raportu HTML.

Brak faktury, nieudane pobranie albo plik, który nie jest PDF-em, kończą test błędem.

Konfiguracja sklepu: płatność przy odbiorze zaczyna się bez faktury. Fakturę generuje zmiana statusu na „Płatność zaakceptowana” (`deploy/init/lib/orders.php`, `docs/CONFIGURATION.md`). Test wykonuje tę zmianę tak jak administrator i nie zmienia ustawień sklepu.

Dane administratora:
- `ADMIN_EMAIL` i `ADMIN_PASSWORD` ze zmiennych środowiskowych. Gdy ich nie ustawiono, test czyta je z pliku `.env` w głównym katalogu repozytorium (tego samego co Docker Compose). Hasło nie trafia do kodu ani do repozytorium.
- `ADMIN_PATH`: katalog panelu, domyślnie `admin-dev`.
```
$env:BASE_URL="https://localhost"; npx playwright test invoice
$env:BASE_URL="https://localhost"; $env:ADMIN_EMAIL="admin@example.test"; $env:ADMIN_PASSWORD="..."; npx playwright test invoice
```

### Wyniki
- Raport HTML: `tests/playwright-report/` (podgląd: `npx playwright show-report`)
- Raport JUnit XML: `tests/test-results/junit.xml`
- Zrzuty ekranu po błędach: `tests/test-results/`

Katalogi z wynikami są wykluczone z Git.

## Bootstrap

Sprawdź pełny start na nowych wolumenach odrębnego projektu Compose, powtórzenie komendy, wznowienie po przerwaniu oraz zachowanie ręcznych zmian produktu, CMS i magazynu. Zmiana `COMPOSE_PROJECT_NAME` rozdziela wolumeny, ale porty hosta wymagają osobnych wartości albo usunięcia publikacji w lokalnym pliku nadpisującym.

Końcowe kontrole bootstrapu obejmują gotowość usług i podstawowe odpowiedzi HTTP. Zakres testów obejmuje testy jednostkowe i integracyjne klas oraz testy E2E w Playwright (`tests/`). Wyniki i ograniczenia platform opisuje [audyt](../docs/AUDIT.md).

