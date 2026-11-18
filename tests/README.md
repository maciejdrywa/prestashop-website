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

## Uruchomienie E2E

W aktywnym środowisku wirtualnym:

```powershell
$env:BASE_URL="https://localhost"; python -m pytest -c pytest-e2e.ini
```

Test działa domyślnie w Chromium headless i akceptuje samodzielnie podpisany certyfikat HTTPS. Adres można również podać przez `--base-url`, który ma pierwszeństwo przed `BASE_URL`. Domyślny adres to `https://localhost`.

Dodatkowe opcje:

```powershell
# Widoczna przeglądarka i logi etapów w terminalu
python -m pytest -c pytest-e2e.ini --headed -s

# Trzy kolejne wykonania bez resetowania bazy
python -m pytest -c pytest-e2e.ini --count=3
```

`pytest-e2e.ini` konfiguruje testy przeglądarkowe. Domyślny `pytest.ini` służy testom scrapera offline i blokuje połączenia sieciowe.

## Scenariusz W4.1–W4.3

Test `tests/e2e/test_checkout.py` wykonuje w jednej sesji:

1. Dodanie 10 różnych produktów z dwóch kategorii, w ilościach 1–3 sztuki.
2. Wyszukanie produktu (domyślnie `klamka`) i dodanie losowego dostępnego wyniku.
3. Usunięcie trzech produktów oraz kontrolę pozostałych ośmiu pozycji i ich ilości.
4. Rejestrację konta z unikalnym e-mailem i zapis fikcyjnego adresu w Polsce.
5. Wybór InPost, płatności przy odbiorze i zatwierdzenie zamówienia.
6. Sprawdzenie potwierdzenia, przewoźnika, płatności oraz zgodności produktów, wariantów i ilości z koszykiem.

Każdy etap ma asercje. Test pomija niedostępnych kandydatów i kończy się błędem, jeśli nie znajdzie wymaganej liczby produktów. Czeka na stan strony zamiast stosować stałe opóźnienia. Frazę wyszukiwania można zmienić zmienną `FRAZA`.

Test tworzy rzeczywiste konta i zamówienia na wskazanej instancji oraz zmniejsza stany magazynowe. Dane pozostają do kontroli. Status zamówienia i pobranie faktury nie są jeszcze objęte scenariuszem.

Cały scenariusz ma limit 300 sekund. Przekroczenie limitu kończy proces i może uniemożliwić zapis raportów.

## Raporty

- HTML: `tests/playwright-report/python/index.html`.
- JUnit XML z logami etapów: `tests/test-results/python/junit.xml`.
- Zrzuty ekranu i ślady Playwright: podkatalogi `tests/test-results/python/`, zachowywane przy błędzie.

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
