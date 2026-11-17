# Wdrożenie lokalne

## Wymagania

Zainstaluj Docker Engine lub Docker Desktop z Compose v2 i uruchom silnik. Przygotuj aktualne `scraper-results/products.json`, `categories.json` oraz pliki JPEG w `scraper-results/images/`. Do uruchamiania sklepu wystarcza Docker. Python jest potrzebny do scrapowania, a Node do testów modułu cookies.

Skopiuj `.env.example` do `.env` i uzupełnij hasła bazy oraz administratora. Ustaw bezwzględną ścieżkę `TLS_CERT_DIR` do lokalnego katalogu certyfikatów. Przykładowe wartości to `C:/Users/Jan/.prestashop-website/certs` na Windows albo `/home/jan/.prestashop-website/certs` na Linux. Użyj `PS_DOMAIN=localhost`, zgodnego z lokalnym Nginx i certyfikatem.

| Zmienna | Znaczenie |
|---|---|
| `COMPOSE_PROJECT_NAME` | Nazwa projektu i przestrzeń nazw wolumenów |
| `DB_NAME`, `DB_USER` | Baza i użytkownik aplikacji |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Hasła wymagane przy instalacji MariaDB |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Konto administratora tworzone podczas pierwszej instalacji |
| `PS_DOMAIN` | Lokalna domena sklepu, obecnie `localhost` |
| `TLS_CERT_DIR` | Katalog pary `localhost.crt` i `localhost.key` |
| `BOOTSTRAP_TIMEOUT` | Limit oczekiwania bootstrapu na instalację i HTTPS w sekundach, domyślnie 900 |

## Jedna komenda

```sh
docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.bootstrap.yaml run --build --rm bootstrap
```

Compose buduje obraz, wykonuje sprawdzenie danych i certyfikatu, uruchamia bazę, Mailpit, PrestaShop oraz Nginx. Bootstrap czeka na zakończenie instalatora i działający HTTPS z ograniczeniem czasu, następnie wykonuje `init-shop.php --resume`. Na końcu sprawdza HTTPS, przekierowanie HTTP, panel oraz Mailpit. Błąd kończy polecenie kodem różnym od zera. Można poprawić przyczynę i wykonać tę samą komendę.

Gotowość sklepu potwierdzają konfiguracja instalacji, brak `install.lock`, połączenie z bazą i otwarty port Apache. Compose próbuje tę kontrolę co 5 sekund, do 180 nieudanych prób. Bootstrap ma własny limit oczekiwania 900 sekund, obejmujący instalację i odpowiedź HTTPS, oraz raportuje oczekiwanie co 15 sekund. Nginx uruchamia się podczas instalacji, dzięki czemu bootstrap sam kontroluje ten limit. Czas pobierania obrazów i importu zależy od komputera oraz pamięci podręcznej Dockera.

## HTTPS

Certyfikat jest samopodpisany, ważny rok i zawiera `localhost` oraz `127.0.0.1`. Istniejąca poprawna para jest wykorzystywana ponownie. Niekompletna, wygasła lub niedopasowana para wymaga zachowania kopii i przygotowania nowej. Klucz pozostaje w katalogu wskazanym przez `.env`.

Na Windows zaimportuj `localhost.crt` do zaufanych głównych urzędów certyfikacji bieżącego użytkownika. Na macOS służy do tego Pęk kluczy lub `bash deploy/start-macos.sh --trust`. Na Linux dodaj certyfikat zgodnie z mechanizmem dystrybucji i przeglądarki. Zaufanie systemowe wymaga operacji na komputerze użytkownika.

`deploy/generate-cert.ps1` i `deploy/generate-cert.sh` wywołują tę samą implementację kontenerową. `start-macos.sh` wywołuje pełny bootstrap i zachowuje opcję `--trust`.

## Uruchamianie ręczne

Przygotuj certyfikat skryptem zgodności, uruchom usługi i poczekaj na zakończenie instalatora widoczne w logach.

```sh
docker compose --env-file .env -f deploy/compose.yaml up -d --build
docker compose --env-file .env -f deploy/compose.yaml logs -f shop
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/check-environment.php
docker compose --env-file .env -f deploy/compose.yaml exec -T --user www-data shop php /opt/shop/init/init-shop.php --resume
```

Samo `up` uruchamia usługi. Import danych wykonuje ostatnia komenda. Dane źródłowe i skrypty są montowane tylko do odczytu, a galerie powstają wewnątrz zapisywalnego `shop_data`.

## Platformy i zatrzymanie

Obrazy PrestaShop i MariaDB korzystają z `linux/amd64`. Na Apple Silicon wymagają emulacji w Docker Desktop. Testy tej zmiany wykonano na Windows z kontenerami Linux. Natywny Linux, macOS i Apple Silicon wymagają potwierdzenia na odpowiednich komputerach.

`docker compose --env-file .env -f deploy/compose.yaml stop` zatrzymuje usługi z zachowaniem danych. `down` usuwa kontenery i sieć. Opcja `down -v` usuwa bazę, pliki sklepu i dziennik, dlatego służy wyłącznie do świadomego odtworzenia środowiska po wykonaniu kopii.
