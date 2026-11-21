# Wdrożenie lokalne

Domyślna inicjalizacja przez API: `python scraper/import_catalog.py --bootstrap --resume`. Przygotowanie, finalizację i zachowaną ścieżkę PHP opisuje [D2.5](CATALOG_IMPORT.md).

## Wymagania

Zainstaluj Docker Engine lub Docker Desktop z Compose v2 i uruchom silnik. Przygotuj aktualne `scraper-results/products.json` i `categories.json` oraz Python 3.11+ z zależnościami `scraper/requirements.txt`. Importer API pobiera brakujące zdjęcia. Historyczny tor PHP wymaga gotowego katalogu JPEG; Node służy testom modułu cookies.

Skopiuj `.env.example` do `.env` i uzupełnij hasła bazy, dane administratora oraz `PRESTASHOP_API_KEY` (32 znaki alfanumeryczne). Ustaw bezwzględną ścieżkę `TLS_CERT_DIR` do lokalnego katalogu certyfikatów. Przykładowe wartości to `C:/Users/Jan/.prestashop-website/certs` na Windows albo `/home/jan/.prestashop-website/certs` na Linux. Użyj `PS_DOMAIN=localhost`, zgodnego z lokalnym Nginx i certyfikatem.

| Zmienna | Znaczenie |
|---|---|
| `COMPOSE_PROJECT_NAME` | Nazwa projektu i przestrzeń nazw wolumenów |
| `DB_NAME`, `DB_USER` | Baza i użytkownik aplikacji |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Hasła wymagane przy instalacji MariaDB |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Konto administratora tworzone podczas pierwszej instalacji |
| `PS_DOMAIN` | Lokalna domena sklepu, obecnie `localhost` |
| `TLS_CERT_DIR` | Katalog pary `localhost.crt` i `localhost.key` |
| `PRESTASHOP_API_URL`, `PRESTASHOP_API_KEY` | Adres lokalnego API i klucz importera; szczegóły w [instrukcji API](WEBSERVICE.md) |
| `PRESTASHOP_API_VERIFY_SSL` | Weryfikacja TLS API; lokalny przykład z samopodpisanym certyfikatem używa `false` |
| `BOOTSTRAP_TIMEOUT` | Limit oczekiwania bootstrapu na instalację i HTTPS w sekundach, domyślnie 900 |

## Nowa instalacja a istniejące dane

Katalog repozytorium i wolumeny Dockera mają osobny cykl życia. Kolejny klon z tą samą nazwą projektu Compose korzysta z wcześniejszych wolumenów. Dla nowej instalacji wybierz nieużywaną nazwę `COMPOSE_PROJECT_NAME`, pozostaw `DB_VOLUME_NAME` i `SHOP_VOLUME_NAME` nieustawione oraz zwolnij porty zajęte przez poprzednią instancję. Nie przenoś jej dzienników `logs/catalog-import/`. Przy wznowieniu istniejącej instalacji zachowaj dotychczasowe `.env`, wolumeny i katalog stanu.

## Jedna komenda API

```sh
python scraper/import_catalog.py --bootstrap --resume
```

Walidację, cache, tryby synchronizacji, aktywację i raport opisuje [D2.5](CATALOG_IMPORT.md).

## Historyczny bootstrap PHP

Dla instalacji z backendem PHP i gotowymi zdjęciami:

```sh
docker compose --env-file .env -f deploy/compose.yaml run --build --rm bootstrap
```

Compose buduje obraz, wykonuje sprawdzenie danych i certyfikatu, uruchamia bazę, Mailpit, PrestaShop oraz Nginx. Bootstrap czeka na zakończenie instalatora i działający HTTPS z ograniczeniem czasu, następnie wykonuje `init-shop.php --resume`. Na końcu sprawdza HTTPS, przekierowanie HTTP, panel oraz Mailpit. Błąd kończy polecenie kodem różnym od zera. Można poprawić przyczynę i wykonać tę samą komendę.

Gotowość sklepu potwierdzają konfiguracja instalacji, brak `install.lock`, połączenie z bazą i otwarty port Apache. Compose próbuje tę kontrolę co 5 sekund, do 180 nieudanych prób. Bootstrap ma własny limit oczekiwania 900 sekund, obejmujący instalację i odpowiedź HTTPS, oraz raportuje oczekiwanie co 15 sekund. Nginx uruchamia się podczas instalacji, dzięki czemu bootstrap sam kontroluje ten limit. Czas pobierania obrazów i importu zależy od komputera oraz pamięci podręcznej Dockera.

## HTTPS

Certyfikat jest samopodpisany, ważny rok i zawiera `localhost` oraz `127.0.0.1`. Istniejąca poprawna para jest wykorzystywana ponownie. Niekompletna, wygasła lub niedopasowana para wymaga zachowania kopii i przygotowania nowej. Klucz pozostaje w katalogu wskazanym przez `.env`.

Na Windows zaimportuj `localhost.crt` do zaufanych głównych urzędów certyfikacji bieżącego użytkownika. Na macOS służy do tego Pęk kluczy lub `bash deploy/start-macos.sh --trust`. Na Linux dodaj certyfikat zgodnie z mechanizmem dystrybucji i przeglądarki. Zaufanie systemowe wymaga operacji na komputerze użytkownika.

`deploy/generate-cert.ps1` i `deploy/generate-cert.sh` wywołują tę samą implementację kontenerową. `start-macos.sh` wywołuje pełny bootstrap i zachowuje opcję `--trust`.

## Uruchamianie ręczne — backend PHP

Ta ścieżka wymaga lokalnych zdjęć i dotyczy backendu PHP. Nie uruchamiaj jej na instalacji oznaczonej backendem API; dla niej używaj wspólnej komendy Python. Przygotuj certyfikat skryptem zgodności, uruchom usługi i poczekaj na zakończenie instalatora widoczne w logach. Podgląd `logs -f shop` zakończ przez Ctrl+C przed kolejną komendą; nie zatrzymuje to sklepu.

```sh
docker compose --env-file .env -f deploy/compose.yaml up -d --build
docker compose --env-file .env -f deploy/compose.yaml logs -f shop
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-environment.php
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/init-shop.php --resume
```

Samo `up` przygotowuje certyfikat, sprawdza dane źródłowe i uruchamia usługi. Usługa `bootstrap` ma profil `init`, więc zwykłe `up` nie wykonuje importu. Import danych wykonuje ostatnia komenda. Dane źródłowe i skrypty są montowane tylko do odczytu, a galerie powstają wewnątrz zapisywalnego `shop_data`.

## Jedna definicja dla sklepu i testów

Wszystkie wywołania korzystają wyłącznie z `deploy/compose.yaml`. Plik `.env` określa właściwy sklep. Odrębny plik na podstawie `deploy/api-test.env.example` określa testy, z inną nazwą projektu, portami i hasłami.

Porty można zmienić przez `HTTP_PORT`, `HTTPS_PORT` i `MAILPIT_PORT`, źródła przez `SCRAPER_RESULTS_DIR`, a konfigurację proxy przez `NGINX_CONFIG`. Pełny bootstrap pozostaje przeznaczony dla standardowego lokalnego sklepu `localhost` na portach 80/443; testy API na portach 18080/18443 uruchamia się przez `up` i jawne skrypty odbiorcze.

Nazwy wolumenów domyślnie wynikają z projektu Compose. Dla istniejącego sklepu pozostają `prestashop-website_db_data` i `prestashop-website_shop_data`. `DB_VOLUME_NAME` i `SHOP_VOLUME_NAME` umożliwiają zachowanie starszych nazw przy migracji, np. testowych `prestashop-d21-tests_api_test_db` i `prestashop-d21-tests_api_test_shop`. Nie wskazuj w testach wolumenów zwykłego sklepu.

## Platformy i zatrzymanie

Obrazy PrestaShop i MariaDB korzystają z `linux/amd64`. Na Apple Silicon wymagają emulacji w Docker Desktop. Historyczny bootstrap PHP testowano na Windows z kontenerami Linux. Tor API D2.5 i benchmark sprawdzono na macOS z Apple M4 Pro. 21 listopada 2026 r. raport czystej inicjalizacji na Windows 11 z Pythonem 3.14 potwierdził 574 aktywne produkty, 1269 kombinacji, 1389 obrazów i HTTP 200; całość trwała około 642 s. Jest to wynik konkretnego przebiegu, nie gwarancja czasu na innym komputerze. Nie zastępuje kontroli E2E, backupu i każdej późniejszej zmiany modułów. Pełny start na natywnym Linuksie pozostaje osobno do potwierdzenia.

`docker compose --env-file .env -f deploy/compose.yaml stop` zatrzymuje usługi z zachowaniem danych. `down` usuwa kontenery i sieć. Opcja `down -v` usuwa bazę, pliki sklepu i dziennik, dlatego służy wyłącznie do świadomego odtworzenia środowiska po wykonaniu kopii.

## Kopia zapasowa

Przed usunięciem wolumenów lub wdrożeniem istotnych zmian utwórz kopię zapasową bazy MariaDB, plików konfiguracyjnych i zdjęć produktów:

```sh
bash deploy/backup.sh [--maintenance]
```

Archiwum zapisywane jest w katalogu `deploy/backups/` w formacie `backup_YYYYMMDD_HHMMSS.tar.gz` z uprawnieniami `0600`. Szczegóły parametrów i strukturę archiwum opisuje [dokumentacja deploy](BACKUP.md).

## Odtwarzanie z kopii

Sklep odtwarza się z archiwum jedną komendą:

```sh
bash deploy/restore.sh --latest
```

Skrypt sprawdza kompletność i sumy kontrolne archiwum, prosi o potwierdzenie, wykonuje kopię bezpieczeństwa bieżącego stanu, podmienia bazę, zdjęcia produktów i konfigurację, a na końcu sprawdza działanie sklepu. Błąd cofa zmiany. Odtwarzaj wyłącznie zaufane archiwa. Opcje i ograniczenia opisuje [dokumentacja deploy](BACKUP.md#odtwarzanie-sklepu-restoresh).

Poprawność i czas odtwarzania na czystym środowisku sprawdza `bash deploy/verify-restore.sh` ([opis](BACKUP.md#weryfikacja-odtwarzania-verify-restoresh), [wynik](RESTORE_VERIFICATION.md)).

[Indeks dokumentacji](README.md) · [Główne README](../README.md)
