# Testy projektu

Polecenia wykonuj z głównego katalogu repozytorium. Testy E2E korzystają z Pythona 3.11+, pytest i Playwright.

## Instalacja E2E

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r tests/requirements.txt
python -m playwright install chromium
```

Przed uruchomieniem zapewnij działający sklep, indeks wyszukiwarki, aktywny InPost i płatność przy odbiorze. Produkty muszą być dostępne w wymaganych ilościach, a koszyk kwalifikować się do dostawy do 50 kg. Indeks można przebudować w panelu: **Parametry sklepu → Wyszukiwanie → Przebuduj cały indeks**.

Pobranie faktury wymaga konfiguracji z `deploy/init/configure-orders.php`, wykonywanej przez bootstrap: włączonych faktur (`PS_INVOICE`), statusu „Oczekiwanie na płatność przy odbiorze” bez faktury i statusu „Płatność zaakceptowana” z fakturą. Test potwierdza płatność w panelu, dlatego potrzebuje konta administratora. Dane odczytuje ze zmiennych `ADMIN_EMAIL` i `ADMIN_PASSWORD`, a brakujące uzupełnia z `.env` w katalogu głównym. Hasło z `.env` działa tylko wtedy, gdy nie zmieniono go po pierwszej instalacji. Adres panelu można zmienić zmienną `ADMIN_PATH` (domyślnie `/admin-dev/`). Brak danych lub nieudane logowanie kończą test błędem przed złożeniem zamówienia.

Na istniejącej instancji, na której nie wykonano jeszcze tego etapu, konfigurację faktur można zastosować ponownie:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/configure-orders.php
```

## Uruchomienie E2E

W aktywnym środowisku wirtualnym:

```powershell
$env:BASE_URL="https://localhost"; python -m pytest -c pytest-e2e.ini
```

Test działa domyślnie w Chromium headless, z locale `pl-PL` i strefą `Europe/Warsaw`, i akceptuje samodzielnie podpisany certyfikat HTTPS. Adres można również podać przez `--base-url`, który ma pierwszeństwo przed `BASE_URL`. Domyślny adres to `https://localhost`.

Dodatkowe opcje:

```powershell
# Widoczna przeglądarka i logi etapów w terminalu
python -m pytest -c pytest-e2e.ini --headed -s

# Trzy kolejne wykonania bez resetowania bazy
python -m pytest -c pytest-e2e.ini --count=3
```

`pytest-e2e.ini` konfiguruje testy przeglądarkowe. Domyślny `pytest.ini` służy testom scrapera offline i blokuje połączenia sieciowe.

## Scenariusz W4.1–W4.4

Test `tests/e2e/test_checkout.py` wykonuje w jednej sesji:

1. Dodanie 10 różnych produktów z dwóch kategorii, w ilościach 1–3 sztuki.
2. Wyszukanie produktu (domyślnie `klamka`) i dodanie losowego dostępnego wyniku.
3. Usunięcie trzech produktów oraz kontrolę pozostałych ośmiu pozycji i ich ilości.
4. Rejestrację konta z unikalnym e-mailem i zapis fikcyjnego adresu w Polsce.
5. Wybór InPost, płatności przy odbiorze i zatwierdzenie zamówienia.
6. Sprawdzenie potwierdzenia, przewoźnika, płatności, produktów, wariantów, ilości oraz końcowej kwoty brutto z dostawą i rabatami.
7. Otwarcie historii zamówień z konta klienta i odszukanie zamówienia po referencji z potwierdzenia. Wiersz musi wystąpić dokładnie raz i mieć kwotę z kasy.
8. Kontrola statusu „Oczekiwanie na płatność przy odbiorze” oraz braku faktury w historii i w kontrolerze PDF.
9. Potwierdzenie płatności w panelu w osobnej sesji: zmiana statusu na „Płatność zaakceptowana”, która wystawia fakturę.
10. Ponowna kontrola statusu w historii, pobranie faktury i sprawdzenie pliku: nazwa `.pdf`, sygnatura `%PDF-`, znacznik końca pliku, co najmniej jedna strona, niepusty tekst i referencja zamówienia.

Brak zamówienia, inny status, brak odnośnika do faktury, komunikat sklepu zamiast pliku albo niepoprawny PDF kończą test błędem.

Każdy etap ma asercje. Test pomija niedostępnych kandydatów i kończy się błędem, jeśli nie znajdzie wymaganej liczby produktów. Czeka na stan strony zamiast stosować stałe opóźnienia. Frazę wyszukiwania można zmienić zmienną `FRAZA`.

Test tworzy rzeczywiste konta, zamówienia i faktury na wskazanej instancji, zmniejsza stany magazynowe, zmienia status zamówienia na opłacone i wysyła wiadomości do Mailpit. Dane pozostają do kontroli.

Kwota jest zapamiętywana po wyborze adresu, dostawy i płatności, przed zatwierdzeniem zakupu, a następnie porównywana dokładnie z potwierdzeniem w PLN.

Cały scenariusz ma limit 300 sekund. Przekroczenie limitu kończy proces i może uniemożliwić zapis raportów.

Kontrolę parsowania kwot, odrzucania niezgodnej sumy, oczekiwania na dane potwierdzenia, odczytu historii zamówień, pobrania i walidacji PDF oraz odczytu konta administratora można uruchomić bez składania zamówień:

```powershell
python -m pytest -c pytest-e2e.ini tests/unit
```

## Raporty

- HTML: `tests/playwright-report/python/index.html`.
- JUnit XML z logami etapów: `tests/test-results/python/junit.xml`.
- Zrzuty ekranu i ślady Playwright: podkatalogi `tests/test-results/python/`, zachowywane przy błędzie. Sesja panelu administratora nie jest nagrywana, żeby ślad nie zawierał hasła.

```powershell
Start-Process ".\tests\playwright-report\python\index.html"
```

Wyniki są wykluczone z Git. Kolejne uruchomienie zastępuje wcześniejsze wyniki E2E.

## Python i JavaScript

Testy scrapera offline oraz obsługi komunikatu cookies:

```powershell
python -m pip install -r scraper/requirements-dev.txt
python -m pytest -q
node --test deploy/init/tests/cookie-notice.test.cjs
```

Testy integracyjne Webservice i importu kategorii wymagają osobnej instancji testowej; mogą zmieniać dane:

```powershell
python scraper/test_api_client.py
python -m pytest -o addopts= scraper/test_category_import.py
```

## PHP

Po inicjalizacji osobnej instancji uruchom wybrany plik `deploy/init/tests/*-test.php`, np.:

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/tests/source-test.php
```

Testy zmieniające dane uruchamiaj na osobnych wolumenach. Szczegóły inicjalizacji opisuje [instrukcja](../deploy/init/README.md), a ograniczenia weryfikacji — [audyt](../docs/AUDIT.md).
