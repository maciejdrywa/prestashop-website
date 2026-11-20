# Rozwiązywanie problemów

## Compose i instalacja

Uruchom `docker info` oraz `docker compose version`. Sprawdź `.env` przez `docker compose --env-file .env -f deploy/compose.yaml config --quiet`. Błąd wymaganej zmiennej wskaże brakującą wartość.

Stan usług pokaże `docker compose --env-file .env -f deploy/compose.yaml ps`. Logi instalacji odczytaj poleceniem `docker compose --env-file .env -f deploy/compose.yaml logs --tail 100 shop`. Dla problemów HTTPS sprawdź też `nginx`, a dla bazy `db`.

## Brak danych albo zdjęć

Przygotuj oba JSON-y oraz `scraper-results/images/`. Importer wymaga jednego lokalnego JPEG na każdy URL galerii. Zweryfikuj nazwę pliku, wymiary i limit 300000 bajtów. Pole `products_without_images` raportu źródła dotyczy produktów bez galerii w samym źródle.

## Uprawnienia

Skrypty uruchamiaj jako `www-data`. Instalacja i galerie korzystają z zapisywalnego wolumenu sklepu. Źródła pozostają zamontowane tylko do odczytu. Błędy `var/cache` po wcześniejszej pracy jako root wymagają sprawdzenia właściciela tego katalogu. Kontrola gotowości także korzysta z `www-data`.

## Przerwany import

Popraw przyczynę wskazaną w komunikacie i ponów bootstrap. Dziennik zachowuje ukończone rekordy, a mapowanie galerii umożliwia regenerację brakujących plików. Blokada jest zwalniana przez system po zakończeniu procesu.

Przy zmianie źródeł wykonaj kopię danych i zastosuj świadomy import zgodnie z [opisem inicjalizacji](INITIALIZATION.md). Konflikt tożsamości lub brak zapisanego rekordu wymaga wyjaśnienia przed dalszym importem.

## Logowanie administratora

Panel znajduje się pod `https://localhost/admin-dev/`. Konto i hasło pochodzą z pierwszej instalacji. Zmiana `ADMIN_PASSWORD` w `.env` przy istniejącej bazie pozostawia dotychczasowe konto. Wiadomości odzyskiwania hasła można sprawdzić w Mailpit.

## Certyfikat i adres

Ustaw `PS_DOMAIN=localhost`. `TLS_CERT_DIR` musi być bezwzględną ścieżką do tej samej pary certyfikatu i klucza. Niekompletna lub wygasła para powoduje czytelny błąd przygotowania. Import certyfikatu do zaufanych opisuje [wdrożenie](DEPLOYMENT.md).

## Scraper

`ModuleNotFoundError` wymaga instalacji zależności w używanym venv. Błąd braku Chromium rozwiązuje `python -m playwright install chromium` wykonane tym samym interpreterem. Przy niepełnym pobraniu sprawdź `scrape-report.json`, zwłaszcza `product_errors`. Cloudflare może wymagać widocznej sesji i rozwiązania wyzwania.

## Różnice kontroli początkowego planu

`check-products.php` i `check-stock.php` porównują aktualny sklep ze źródłem i początkowym magazynem. Edycja wagi, ceny albo zakup mogą powodować oczekiwaną różnicę. Bootstrap ze wznowieniem sprawdza trwałe powiązania i spójność danych, zachowując takie operacje.

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
