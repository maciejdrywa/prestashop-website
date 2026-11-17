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

## Bootstrap

Sprawdź pełny start na nowych wolumenach odrębnego projektu Compose, powtórzenie komendy, wznowienie po przerwaniu oraz zachowanie ręcznych zmian produktu, CMS i magazynu. Zmiana `COMPOSE_PROJECT_NAME` rozdziela wolumeny, ale porty hosta wymagają osobnych wartości albo usunięcia publikacji w lokalnym pliku nadpisującym.

Końcowe kontrole bootstrapu obejmują gotowość usług i podstawowe odpowiedzi HTTP. Aktualny zakres testów obejmuje testy jednostkowe i integracyjne klas, bez osobnego projektu Playwright E2E. Wyniki i ograniczenia platform opisuje [audyt](../docs/AUDIT.md).
