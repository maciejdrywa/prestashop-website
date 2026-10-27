# Testy E2E

Katalog zawiera testy Playwright sprawdzające koszyk, wyszukiwanie, rejestrację, złożenie zamówienia, jego status i pobranie faktury VAT.

Testy działają w trybie headless, są uruchamiane jedną komendą i przyjmują adres sklepu przez parametr lub zmienną środowiskową.

Każdy krok zawiera asercje. Testy generują raport HTML lub JUnit XML oraz zrzut ekranu przy błędzie. Pełny scenariusz musi zakończyć się w ciągu 5 minut.