# Testy E2E

Katalog zawiera testy Playwright sprawdzające koszyk, wyszukiwanie, rejestrację, złożenie zamówienia, jego status i pobranie faktury VAT.

Testy działają w trybie headless, są uruchamiane jedną komendą i przyjmują adres sklepu przez parametr lub zmienną środowiskową.

Każdy krok zawiera asercje. Testy generują raport HTML lub JUnit XML oraz zrzut ekranu przy błędzie. Pełny scenariusz musi zakończyć się w ciągu 5 minut.

## Uruchomienie 

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


### Wyniki
- Raport HTML: `tests/playwright-report/` (podgląd: `npx playwright show-report`)
- Raport JUnit XML: `tests/test-results/junit.xml`
- Zrzuty ekranu po błędach: `tests/test-results/`

Katalogi z wynikami są wykluczone z Git.