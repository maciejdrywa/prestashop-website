# Lokalne środowisko sklepu
- `db` - baza dancyh MariaDB.
- `shop` - PrestaShop z serwerem Apache.
- `nginx` - obsługa HTTPS i przekierowania HTTP na HTTPS.

Sklep jest dostępny pod adresem https://localhost.

Nginx publikuje porty 80 i 443 wyłącznie na interfejsie lokalnym komputera. Porty sklepu i bazy nie są publikowane w hoście.

## Wymagania

- Docker z obsługą komputerów Linux.
- Docker Compose V2.
- OpsenSSL dosepny w zmiennych środowiskowych.

Sprawdzenie narzędzi:
```
docker version
docker compose version
openssl version
```
 [!IMPORTANT]
Wsystkie poniższe polecenia wykonujemy z głównego katalogu repozytorium.

## 1. Przygotowanie konfiguracji
Przy pierwszym uruchomieniu utwórz lokjalny plik `.env` na podstawie pliku `.env.example` i uzupełnij brakujące w nim części:
- `DB_PASSWORD` - hasło użytkownika bazy wykorzystywanego przez sklep.
- `DB_ROOT_PASSWORD` - hasło administratora bazy.
- `ADMIN_PASSWORD` - hasło administracyjne PrestaShop.
- `TLS_CERT_DIR` - bezwzględna ścierzka do katalogu certyfikatów.

Plik `.env` zawiera sekrety i nie może być dodawany do Git.
Nie nadpisuj isniejącego `.env` przy kolejnych uruchomieniach.

33 2. Generowanie lokalnego certyfikatu

Na windows:
```powershell
.\deploy\generate-cert.ps1
```

Skrypt tworzy samodzielnie przypisany certyfikat ważny przez 365 dni dla adresów `localhost` i `127.0.0.1`.

Pliki są zapisywane poza repozytorium:

```text
%USERPROFILE%\.prestashop-website\certs\localhost.crt
%USERPROFILE%\.prestashop-website\certs\localhost.key
```

- `localhost.crt` - publiczny certyfikat.
- `localhost.key` - klucz prywatny.

W lokalnym `.env` ustaw ścierzkę odpowiednią dla twojego użytkownika, np.:

```dotenv
TLS_CERT_DIR=C:/Users/NAZWA_UZYTKOWNIKA/.prestashop-website/certs
```

Nginx otrzymuje dostęp do katalogu certyfikatów tylko do odczytu. Klucz prywatnego nie należy udostępniać ani dodawać do repozytorium.

Skrypt nie nadpisuje istniejących plików. Przy kolejnych uruchomieniach środowiskanie trzeba ponownie generować certyfikatu.

## 3. Dodanie certyfikatu do zaufanych na Windows
Aby przeglądarka ufała lokalnemu certyfikatowi, wykonaj:

```powershell
certutil -user -addstore Root "$env:USERPROFILE\.prestashop-website\certs\localhost.crt"
```

Potwierdź import, jeśli pojawi się pytanie systemowe. Następnie całkowicie zamknij przeglądarkę i uruchom ją ponownie.

Importujemy jedynie publiczny certyfikat `.crt`, bec klucza `.key`. Ten krok wykonujemy oidzdzielnie na każdym komputerze.

## 4. Uruchomienie sklepu
Sprawdź konfigurację:

```powershell
docker compose --env-file .env -f deploy/compose.yaml config --quiet
```

Zbuduj i uruchom środowisko:

```powershell
docker compose --env-file .env -f deploy/compose.yaml logs -f shop
```

Podgląd postępu instalacji:

```powershell
docker compose --env-file .env -f deploy/compose.yaml logs -f shop
```

`Ctrl+C` kończy podgląd logów, pozostawiając kontenery uruchomione.

Adresy:
- Sklep: https://localhost
- Panel administracyjny: https://localhost/admin-dev

Dane administratora odpowiadają wartościom `ADMIN_EMAIL` i `ADMIN_PASSWORD` użytym podczas instalacji.

## 5. Weryfikacja HTTPS
Sprawdź przekierowania HTTP:

```powershell
curl.exe -I "http://localhost/test?x=1"
```

Oczekiwany wynik:

```text
HTTP/1.1 301 Moved Permanently
Location: https://localhost/test?x=1
```

Sprawdź stronę główną przez HTTPS:

```powershell
curl.exe -I https://localhost
```

Oczekiwany wynik: `200 OK` bez błądu certyfikatów.

Sprawdź brak dostępu przez dawny port HTTP:

```powershell
curl.exe -I --max-time 5 http://localhost:8080
```

Oczekiwany wynik: bład połączenia.

W przeglądarce sprawdź stronę główną, kategorię, produkt, logowanie klienta i koszyk. Obrazy, style i skrypty powinny ładować się przez HTTPS. Konsola przeglądarki nie powinna zgłaszać błędów `Mixed Content`.

## 6. Istniejąca instalacja PrestaShop

Zmienna `PS_ENABLE_SSL=1` jest przekazywana do instalatora. Zmiana tej zmiennej nie aktualizuje automatycznie ustwaień wcześniej zainstalowanego sklepu.

W istniejącej instalacji sprawdź:

- domenę sklepu i domenę SSL: `localhost`,
- opcję "Włącz SSL",
- opcję "Włącz protokół SSL na wszystkich stronach".

Włączone SSL w PrestaShop przy przekierowaniu HTTP na HTTPS w Nginx może powodować pętlę przekierowań.

Jeżeli panel nie pozwala włączyć SSL, uruchom klienta bazy:

```powershell
docker compose --env-file .env -f deploy/compose.yaml exec db mariadb -u prestashop -p prestashop
```

Podaj `DB_PASSWORD`, a następnie wykonaj:

```sql
UPDATE ps_configuration
SET value = '1'
WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE');

exit;
```

Polecenie zakłąda domyślną nazwę bazy, użytkownika i prefiks tabel z konfiguracji projektu.

## 7. Zatrzymanie i ponowne uruchomienie
Zatrzymanie:

```powershell
docker compose --env-file .env -f deploy/compose.yaml stop
```

Ponowne uruchomienie:

```powershell
docker compose --env-file .env -f deploy/compose.yaml up -d
```

Dane bazy i pliki sklepu są przechowywane w wolumenach Dockera. Restart kontenerów nie wymaga ponownej instalacji ani generowania certyfikatów.

[!WARNING]
`docker compose down --volumes` usuwa wolumeny,
a wraz z nimi lokalną bazę i pliki sklepu.

## 8. Odnowienie certyfikatu
Po wygaśnięciu certyfikatu:
1. Zatrzymaj Nginx.
2. Przenieś dotychczasowy certyfikat i klucz do bezpiecznego miejsca poza repozytorium.
3. Ponownie uruchom `generate-cert.ps1`.
4. Usuń z magazynu certyfikatów Windows, poprzedni certyfikat `localhost`.
5. Dodaj nowy certyfikat do zaufanych. 
6. Uruchom Nginx i ponownie sprawdź HTTPS.

[!NOTE]
Generator nie nadpisuje istniejącego certyfikatu ani klucza