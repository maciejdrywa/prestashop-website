# Środowisko Docker

`compose.yaml` definiuje bazę, PrestaShop, Nginx i Mailpit. `compose.bootstrap.yaml` dodaje przygotowanie certyfikatu, kontrolę gotowości i bezpieczną inicjalizację.

```sh
docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.bootstrap.yaml run --build --rm bootstrap
```

Pełną instrukcję `.env`, HTTPS, platform i pracy ręcznej zawiera [wdrożenie](../docs/DEPLOYMENT.md). Etapy i aktualizacje opisuje [inicjalizacja](../docs/INITIALIZATION.md), a reguły sklepu [konfiguracja](../docs/CONFIGURATION.md).

`bootstrap.php` jest wspólnym punktem wejścia kontenerów. `generate-cert.ps1`, `generate-cert.sh` i `start-macos.sh` udostępniają zgodne wywołania pomocnicze. `backup.sh` tworzy bezpieczną kopię zapasową bazy, plików konfiguracyjnych i zdjęć produktów. `nginx/default.conf` obsługuje lokalne HTTPS. W `init/` znajdują się importery i konfiguracja, a w `init/tests/` testy klas PHP oraz JavaScript.

## Kopia zapasowa sklepu (backup.sh)

Skrypt `deploy/backup.sh` umożliwia wykonanie spójnej, kompletnej i bezpiecznej kopii zapasowej działającego sklepu PrestaShop za pomocą jednej komendy.

```sh
# Standardowe wykonanie kopii zapasowej w tle (bez przestoju sklepu)
bash deploy/backup.sh

# Wykonanie z przełączeniem sklepu w tryb konserwacji na czas backupu
bash deploy/backup.sh --maintenance

# Zapis archiwum do wskazanego katalogu docelowego
bash deploy/backup.sh --output-dir /sciezka/do/kopii
```

### Zawartość archiwum (`backup_YYYYMMDD_HHMMSS.tar.gz`)

- `database.sql`: pełny, transakcyjny zrzut bazy MariaDB (`--single-transaction`, `--quick`, UTF-8).
- `images/p/`: kompletne drzewo katalogów ze zdjęciami produktów (oryginały i miniatury).
- `config/`: kluczowe pliki konfiguracyjne (`parameters.php` z kluczami cookie/szyfrowania, `.htaccess`, `.env` oraz opcjonalne `parameters.yml` i `defines_custom.inc.php`).
- `manifest.json`: metadane kopii (wersja PrestaShop, data ISO 8601, podsumowanie rozmiarów i sumy kontrolne SHA-256 dla każdego pliku).

### Bezpieczeństwo i uprawnienia

- **Poufność poświadczeń**: hasła bazy danych nie są przekazywane jako argumenty CLI na hoście ani wewnątrz kontenera (`ps aux`). Zmienna `MYSQL_PWD` jest przekazywana bezpośrednio do środowiska procesu `mariadb-dump` za pomocą `env`, eliminując poświadczenia z listy procesów (`/proc/$PID/cmdline`).
- **Kompatybilność z Windows / Git Bash**: skrypt definiuje `MSYS_NO_PATHCONV=1`, zapobiegając błędnej konwersji uniksowych ścieżek kontenerów (np. `/var/www/...`) na ścieżki Windows przez interpreter MSYS2 w środowisku Git Bash.
- **Restrykcyjne uprawnienia**: katalog docelowy `deploy/backups` tworzony jest z uprawnieniami `0700` (`drwx------`), a plik archiwum `0600` (`-rw-------`).
- **Ochrona przed wyciekiem do Git**: katalog `deploy/backups/` jest wykluczony w głównym `.gitignore` oraz zawiera lokalny plik `.gitignore` blokujący przypadkowe śledzenie archiwów.
- **Sprzątanie zasobów i diagnostyka awarii**: wszelkie operacje pośrednie realizowane są w katalogu tymczasowym `mktemp -d` usuwanym przez pułapkę sygnałów (`trap ... EXIT INT TERM`). W razie wystąpienia błędu (`set -e`) skrypt natychmiast rejestruje czytelny komunikat `[ERROR]` ze wskazaniem kodu wyjścia i usuwa pliki tymczasowe.
- **Gwarancja przywrócenia**: przy użyciu opcji `--maintenance` sklep jest automatycznie przywracany do stanu aktywnego nawet w przypadku przerwania działania skryptu błędem lub sygnałem SIGINT.

### Spójność transakcyjna bazy a synchronizacja zdjęć

Zrzut bazy MariaDB realizowany jest z flagami `--single-transaction` oraz `--quick`, co zapewnia pełną spójność transakcyjną (ACID, poziom izolacji `REPEATABLE READ`) dla silnika InnoDB w jednym punkcie czasu bez blokowania odczytów ze sklepu.

Zdjęcia produktów (`img/p`) są kopiowane z kontenera w osobnym kroku (`docker compose cp`). Jeśli w trakcie wykonywania kopii na sklepie odbywa się intensywny ruch (np. administrator dodaje, edytuje lub usuwa produkty w Back Office), stan plików graficznych na dysku może minimalnie rozbiec się ze zrzutem bazy SQL. Dodatkowo tryb konserwacji (`PS_SHOP_ENABLE=0`) wyłącza widok sklepu dla klientów, ale nie blokuje aktywności zalogowanych administratorów.

> **Rekomendacja:** W celu zagwarantowania 100% atomowej spójności całego środowiska (baza danych + pliki multimedialne) zaleca się wykonywanie kopii zapasowej z parametrem `--maintenance` poza godzinami szczytu lub w planowanym oknie serwisowym.

### Uprawnienia na partycjach NTFS / Windows

Restrykcyjne uprawnienia katalogu `0700` (`drwx------`) oraz pliku archiwum `0600` (`-rw-------`) opierają się na standardowym modelu uprawnień POSIX i działają w pełni na systemach Linux oraz macOS.

Na maszynach deweloperskich z systemem Windows korzystających z systemów plików NTFS / FAT32 (np. pod Git Bash lub WSL na ścieżkach `/mnt/c/...`), semantyka uprawnień POSIX może być emulowana lub ignorowana przez domyślne listy kontroli dostępu (ACL). W przypadku kopiowania lub przenoszenia archiwum na nośniki zewnętrzne lub do współdzielonych katalogów na platformie Windows zaleca się dodatkowe zabezpieczenie archiwum przed niepowołanym dostępem (np. szyfrowanie za pomocą GPG lub archiwizatora z szyfrowaniem AES-256).


## Odtwarzanie sklepu (restore.sh)

Skrypt `deploy/restore.sh` odtwarza sklep z archiwum utworzonego przez `backup.sh`. Przywraca bazę MariaDB, zdjęcia produktów (`img/p`) i pliki konfiguracyjne.

```sh
# Odtworzenie z najnowszego archiwum w deploy/backups
bash deploy/restore.sh --latest

# Odtworzenie ze wskazanego archiwum
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz

# Sama kontrola archiwum, bez Dockera i bez zmian w sklepie
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz --verify-only
```

Na Linuksie i macOS skrypt można też uruchomić bezpośrednio: `./deploy/restore.sh --latest`.

> **Odtwarzaj wyłącznie archiwa z zaufanego źródła.** Sumy SHA-256 z `manifest.json` wykrywają uszkodzenie archiwum, ale nie celową modyfikację, bo manifest jest częścią tego samego archiwum. Skrypt ogranicza skutki spreparowanej kopii:
> - zrzut importuje tymczasowe konto MariaDB z uprawnieniami wyłącznie do bazy tymczasowej, w trybie `--sandbox`; SQL ze zrzutu nie zmieni bazy produkcyjnej ani kont,
> - w `images/p` dopuszczone są tylko obrazy, pliki `fileType` i standardowy `index.php` PrestaShopa (sprawdzany po sumie SHA-256),
> - `parameters.php` jest sprawdzany tokenizerem PHP przed wczytaniem.
>
> Treść odtworzonej bazy (np. opisy produktów, konta) pochodzi jednak w całości z archiwum.

### Przebieg

1. **Walidacja archiwum, zanim cokolwiek się zmieni:**
   - poprawność gzip/tar i dokładnie jeden katalog `backup_*`,
   - brak ścieżek bezwzględnych, `..` i dowiązań,
   - obecność `database.sql`, `manifest.json`, `config/parameters.php` i `images/p/`,
   - zgodność listy plików z manifestem (brakujące i nadmiarowe) oraz sumy SHA-256 każdego pliku,
   - znacznik `Dump completed`, tabele `configuration`, `shop_url` i `product` oraz klucze `cookie_key`, `cookie_iv`, `secret`.
2. **Środowisko:**
   - gdy `db` lub `shop` nie działają, skrypt uruchamia `docker compose up -d`, potem czeka na gotowość bazy i koniec pracy instalatora PrestaShop,
   - wersja PrestaShop w kopii musi być taka sama jak w kontenerze.
3. **Ochrona danych:**
   - skrypt pokazuje stan bieżącego sklepu i prosi o wpisanie nazwy bazy; bez terminala wymaga `--yes`,
   - po potwierdzeniu robi kopię bezpieczeństwa bieżącego stanu przez `backup.sh` do `deploy/backups/pre-restore/`; `--latest` nie bierze kopii z tego katalogu,
   - blokada `deploy/backups/.restore.lock` uniemożliwia dwa odtwarzania naraz,
   - skrypt odmawia działania, gdy zostały dane po poprzednim odtwarzaniu (bazy `_restore_tmp`/`_pre_restore`, `img/p.pre-restore`, `var/restore-previous/`).
4. **Baza:**
   - nginx zostaje zatrzymany na czas odtwarzania,
   - zrzut trafia do bazy tymczasowej `<DB_NAME>_restore_tmp`; po sprawdzeniu liczby tabel jedna instrukcja `RENAME TABLE` podmienia tabele, a poprzednie przenosi do `<DB_NAME>_pre_restore`,
   - jeśli import się nie uda, bieżąca baza zostaje bez zmian,
   - przerwanie (Ctrl+C, `kill`, zamknięcie okna terminala) na dowolnym etapie po imporcie kończy się rollbackiem; gdy przerwanie trafi w podmianę tabel, skrypt czeka, aż MariaDB zakończy `RENAME TABLE`, i dopiero wtedy ustala, czy podmiana nastąpiła.
5. **Zdjęcia i konfiguracja:**
   - `img/p` trafia najpierw do katalogu tymczasowego, a poprzednia wersja zostaje jako `img/p.pre-restore`,
   - z kopii pochodzą `cookie_key`, `cookie_iv`, `secret` i klucze API z `parameters.php`, więc hasła klientów i administratora z kopii działają,
   - dane połączenia z bazą pochodzą z bieżącego środowiska kontenera `shop`,
   - przywracane są też `parameters.yml`, `defines_custom.inc.php` i `.htaccess`,
   - poprzednie wersje tych plików trafiają do `var/restore-previous/` (właściciel root, uprawnienia `0600`).
6. **Dopasowanie do środowiska:**
   - domena z `PS_DOMAIN` jest wpisywana do `ps_shop_url` i `PS_SHOP_DOMAIN(_SSL)`,
   - klucz konta Webservice dostaje wartość `PRESTASHOP_API_KEY` z bieżącego `.env`,
   - motyw i moduły projektu są kopiowane z obrazu (`/opt/shop-themes`, `/opt/shop-modules`), bo kopia ich nie zawiera,
   - skrypt zawsze włącza sklep (`PS_SHOP_ENABLE=1`): kopia z `backup.sh --maintenance` ma ten parametr ustawiony na 0. Jeśli sklep był wyłączony celowo, wyłącz go ponownie w panelu,
   - na końcu skrypt czyści cache, generuje `.htaccess` i restartuje kontener `shop`.
7. **Kontrola:**
   - nginx jest uruchamiany (także gdy wcześniej nie działał),
   - liczba produktów i zdjęć musi zgadzać się z kopią,
   - `https://<PS_DOMAIN>/` musi zwrócić HTTP 200 w czasie `RESTORE_TIMEOUT`, najwyżej 300 s. Skrypt sprawdza to z hosta przez `curl`, a gdy `curl` nie ma lub nie może się połączyć, także z kontenera nginx: `https://127.0.0.1/` z nagłówkiem `Host: <PS_DOMAIN>`, bo nginx nasłuchuje tylko na IPv4,
   - błąd na dowolnym etapie po podmianie przywraca poprzednią bazę, zdjęcia i konfigurację.

### Opcje i zmienne środowiskowe

| Opcja | Znaczenie |
|---|---|
| `--latest` | Najnowsze `backup_*.tar.gz` z katalogu kopii (bez podkatalogu `pre-restore/`) |
| `--verify-only` | Tylko walidacja archiwum |
| `-y`, `--yes` | Bez interaktywnego potwierdzenia (także `RESTORE_ASSUME_YES=1`) |
| `--no-safety-backup` | Bez kopii bezpieczeństwa bieżącego stanu |
| `--use-backup-env` | Tworzy brakujący `.env` z kopii (uprawnienia `0600`) |
| `--keep-previous` | Po udanym odtworzeniu zachowuje `<DB_NAME>_pre_restore`, `img/p.pre-restore` i `var/restore-previous/` |
| `--ignore-version` | Dopuszcza różnicę wersji PrestaShop |

| Zmienna | Domyślnie |
|---|---|
| `ENV_FILE` | `<repo>/.env` |
| `COMPOSE_FILE_PATH` | `<repo>/deploy/compose.yaml` |
| `BACKUP_DIR` | `deploy/backups` (kopie bezpieczeństwa: `BACKUP_DIR/pre-restore`) |
| `RESTORE_TIMEOUT` | `900` sekund oczekiwania na bazę i sklep; kontrola HTTP najwyżej 300 s |
| `SHOP_URL` | `https://<PS_DOMAIN>/` |

- Bieżący `.env` nie jest nadpisywany. Gdy różni się od `.env` z kopii, skrypt wypisuje tylko nazwy różniących się zmiennych, bez wartości.
- Konto administratora i jego hasło pochodzą z kopii, a nie z `ADMIN_PASSWORD` w bieżącym `.env`.
- Kopia bezpieczeństwa wymaga domyślnych `ENV_FILE` i `COMPOSE_FILE_PATH`, bo `backup.sh` używa tych ścieżek.
- **Windows:** skrypt uruchamiaj w PowerShellu poleceniem `bash deploy/restore.sh …` (WSL) lub w Git Bash. W Git Bash ścieżki dla `docker` są zamieniane przez `cygpath -m`. W WSL `TLS_CERT_DIR=C:/…` z `.env` jest przekazywany jako `/mnt/c/…`. Skrypt nie przebudowuje istniejących kontenerów (`start`, `up --no-recreate`), ale polecenia `docker compose up` wykonuj w tym samym środowisku, w którym kontenery zostały utworzone (np. PowerShell), bo inna ścieżka projektu powoduje ich przebudowę.

### Odtworzenie na nowym komputerze

1. Sklonuj repozytorium, utwórz `.env` (albo użyj `--use-backup-env`) i przygotuj certyfikat (`deploy/generate-cert.sh` lub `.ps1`).
2. Skopiuj archiwum do `deploy/backups/`.
3. Uruchom `bash deploy/restore.sh --latest`. Skrypt uruchomi środowisko, poczeka na instalację pustego sklepu i zastąpi go kopią.

Kopia nie zawiera dziennika inicjalizacji `var/import/`. Po odtworzeniu nie uruchamiaj bootstrapu z importem (`run bootstrap`, `init-shop.php --resume`). Na nowym wolumenie dziennika nie ma wcale, a na istniejącym opisuje dane sprzed odtworzenia.

### Ręczne odzyskanie po przerwanym rollbacku

Gdy rollback sam się nie powiedzie (np. Docker przestał odpowiadać), skrypt wypisze, gdzie leżą poprzednie dane:

- **Baza:** poprzednie tabele są w `<DB_NAME>_pre_restore`. Najprościej przywrócić stan kopią bezpieczeństwa: `bash deploy/restore.sh deploy/backups/pre-restore/backup_….tar.gz`. Wcześniej usuń `<DB_NAME>_pre_restore`, gdy jego zawartość nie jest już potrzebna.
- **Zdjęcia:** w kontenerze `shop` wykonaj `cd /var/www/html/img && rm -rf p && mv p.pre-restore p`.
- **Konfiguracja:** poprzednie pliki są w `/var/www/html/var/restore-previous/`. Ścieżka pliku jest zapisana w nazwie, z `/` zamienionym na `_`.

### Ograniczenia

- Kopia obejmuje tylko `img/p`. Zdjęcia kategorii, producentów i logo (`img/c`, `img/m`, `img/*.jpg`) oraz katalogi `upload/` i `download/` nie są przywracane.
- Moduły zainstalowane ręcznie z panelu, spoza obrazu, nie są częścią kopii.
- Po udanym odtworzeniu z `--keep-previous` kolejne odtwarzanie wymaga usunięcia bazy `<DB_NAME>_pre_restore` oraz katalogów `img/p.pre-restore` i `var/restore-previous/` w kontenerze `shop`. Skrypt o tym przypomina.
- **Git Bash na Windows:** `backup.sh` przekazuje do `docker` ścieżki w formacie `/c/...` przy `MSYS_NO_PATHCONV=1`, więc kopia bezpieczeństwa może się nie udać. Nic nie zostaje wtedy zmienione. Do czasu poprawki w `backup.sh` wykonaj kopię ręcznie i uruchom odtwarzanie z `--no-safety-backup`.
