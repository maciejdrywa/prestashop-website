# Skrypty inicjalizacji

Skrypty przygotowują sklep przez natywne klasy PrestaShop. Wszystkie uruchamiamy jako `www-data` w kontenerze `shop`.

```sh
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/init-shop.php --resume
```

Pełny start z instalacją, certyfikatem i kontrolą usług opisuje [wdrożenie](../../docs/DEPLOYMENT.md). Kolejność 18 etapów, format dziennika i skutki trybów CLI opisuje [inicjalizacja](../../docs/INITIALIZATION.md).

| Skrypty | Odpowiedzialność |
|---|---|
| `init-shop.php` | Walidacja wejścia, blokada i wykonanie etapów lub wznowienie |
| `import-category.php`, `import-products.php` | Drzewo kategorii, nazwy, dwa opisy, ceny, VAT i masa |
| `import-attributes.php`, `import-combinations.php` | Słownik atrybutów i kombinacje z modyfikatorami netto |
| `import-images.php` | Lokalne JPEG, galerie, okładki, miniatury i trwałe mapowanie |
| `import-stock.php` | Początkowe ilości 0–10, rozdział na warianty i aktywacja |
| `configure-storefront.php`, `configure-menu.php` | Nazwa, usunięcie demonstracji i menu źródłowe |
| `configure-theme.php` | Synchronizacja i aktywacja motywu potomnego PAN DOMU |
| `configure-content.php`, `configure-cookies.php`, `configure-module-texts.php` | CMS, stopka, cookies i polskie treści |
| `configure-carriers.php`, `configure-payments.php`, `configure-orders.php` | Dostawa, płatności, statusy i demonstracyjne faktury |
| `configure-mail.php` | SMTP Mailpit i natywne polskie szablony e-mail |
| `check-environment.php`, `check-price-settings.php` | Diagnostyka środowiska, waluty i VAT |
| `check-products.php`, `check-images.php`, `check-stock.php` | Porównanie zapisu z początkowym planem |
| `plan-variants.php`, `audit-sales.php` | Podgląd wariantów i konfiguracji sprzedaży |

`lib/` zawiera wspólną logikę, `content/` początkowe szablony HTML, a `tests/` zachowane testy jednostkowe i integracyjne. Format źródła opisuje [scraper](../../docs/SCRAPER.md), reguły biznesowe [konfiguracja](../../docs/CONFIGURATION.md), a polecenia testów [instrukcja testów](../../tests/README.md).
