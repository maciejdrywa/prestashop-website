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

Skrypt `deploy/restore.sh` odtwarza sklep z archiwum utworzonego przez `backup.sh`: bazę MariaDB, zdjęcia produktów (`img/p`) i pliki konfiguracyjne.

```sh
# Odtworzenie z najnowszego archiwum w deploy/backups
bash deploy/restore.sh --latest

# Odtworzenie ze wskazanego archiwum
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz

# Sama kontrola archiwum, bez Dockera i bez zmian w sklepie
bash deploy/restore.sh deploy/backups/backup_YYYYMMDD_HHMMSS.tar.gz --verify-only
```

### Przebieg

1. **Walidacja archiwum przed jakąkolwiek zmianą**: poprawność gzip/tar, dokładnie jeden katalog `backup_*`, brak ścieżek bezwzględnych, `..` i dowiązań, obecność `database.sql`, `manifest.json`, `config/parameters.php`, `images/p/`, zgodność listy plików z manifestem (brakujące i nadmiarowe), sumy SHA-256 każdego pliku, znacznik `Dump completed`, obecność tabel `configuration`, `shop_url`, `product` i kluczy `cookie_key`, `cookie_iv`, `secret`.
2. **Środowisko**: gdy `db` lub `shop` nie działają, skrypt uruchamia `docker compose up -d` i czeka na bazę oraz zakończenie instalatora PrestaShop. Wersja PrestaShop kopii musi odpowiadać wersji w kontenerze.
3. **Ochrona danych**: skrypt pokazuje stan bieżącego sklepu i wymaga wpisania nazwy bazy. Bez terminala wymaga `--yes`. Następnie wykonuje kopię bezpieczeństwa bieżącego stanu przez `backup.sh`. Blokada `deploy/backups/.restore.lock` uniemożliwia równoległe odtwarzanie.
4. **Baza**: nginx jest zatrzymywany na czas odtwarzania. Zrzut trafia do bazy tymczasowej `<DB_NAME>_restore_tmp`. Po sprawdzeniu liczby tabel tabele są podmieniane jedną instrukcją `RENAME TABLE`. Błąd importu pozostawia bieżącą bazę bez zmian.
5. **Zdjęcia i konfiguracja**: `img/p` jest przesyłane do katalogu tymczasowego i podmieniane z zachowaniem poprzedniej wersji. `parameters.php` zachowuje z kopii `cookie_key`, `cookie_iv`, `secret` i klucze API, czyli hasła klientów i administratora działają. Dane połączenia z bazą pochodzą z bieżącego środowiska kontenera `shop`. Przywracane są też `parameters.yml`, `defines_custom.inc.php` i `.htaccess`.
6. **Dopasowanie do środowiska**: domena z `PS_DOMAIN` jest ustawiana w `ps_shop_url` i `PS_SHOP_DOMAIN(_SSL)`. Motyw i moduły projektu są synchronizowane z obrazu (`/opt/shop-themes`, `/opt/shop-modules`), bo nie są częścią kopii. Kopia wykonana z `--maintenance` zawiera `PS_SHOP_ENABLE=0`, dlatego skrypt włącza sklep. Na końcu czyszczony jest cache, generowany `.htaccess` i restartowany kontener `shop`.
7. **Kontrola**: liczba produktów i zdjęć musi odpowiadać kopii, a `https://<PS_DOMAIN>/` musi zwrócić HTTP 200. Błąd na dowolnym etapie po podmianie przywraca poprzednią bazę, zdjęcia i konfigurację.

### Opcje i zmienne środowiskowe

| Opcja | Znaczenie |
|---|---|
| `--latest` | Najnowsze `backup_*.tar.gz` z katalogu kopii |
| `--verify-only` | Tylko walidacja archiwum |
| `-y`, `--yes` | Bez interaktywnego potwierdzenia (także `RESTORE_ASSUME_YES=1`) |
| `--no-safety-backup` | Bez kopii bezpieczeństwa bieżącego stanu |
| `--use-backup-env` | Tworzy brakujący `.env` z kopii (uprawnienia `0600`) |
| `--keep-previous` | Zachowuje `<DB_NAME>_pre_restore`, `img/p.pre-restore` i pliki `*.pre-restore` |
| `--ignore-version` | Dopuszcza różnicę wersji PrestaShop |

| Zmienna | Domyślnie |
|---|---|
| `ENV_FILE` | `<repo>/.env` |
| `COMPOSE_FILE_PATH` | `<repo>/deploy/compose.yaml` |
| `BACKUP_DIR` | `deploy/backups` |
| `RESTORE_TIMEOUT` | `900` sekund oczekiwania na bazę i sklep |
| `SHOP_URL` | `https://<PS_DOMAIN>/` |

Bieżący `.env` nie jest nadpisywany. Gdy różni się od `.env` z kopii, skrypt wypisuje tylko nazwy różniących się zmiennych. Kopia bezpieczeństwa wymaga domyślnych `ENV_FILE` i `COMPOSE_FILE_PATH`, ponieważ `backup.sh` korzysta z tych ścieżek.

### Odtworzenie na nowym komputerze

1. Sklonuj repozytorium, utwórz `.env` (lub użyj `--use-backup-env`) i przygotuj certyfikat (`deploy/generate-cert.sh` / `.ps1`).
2. Skopiuj archiwum do `deploy/backups/`.
3. Wykonaj `bash deploy/restore.sh --latest`. Skrypt uruchomi środowisko, poczeka na instalację pustego sklepu i zastąpi go kopią.

Po odtworzeniu na nowym wolumenie nie uruchamiaj bootstrapu z importem (`run bootstrap`), ponieważ kopia nie zawiera dziennika inicjalizacji `var/import/`.

### Ograniczenia

- Kopia obejmuje tylko `img/p`. Zdjęcia kategorii, producentów i logo (`img/c`, `img/m`, `img/*.jpg`) oraz `upload/` i `download/` nie są przywracane.
- Moduły zainstalowane ręcznie z panelu (spoza obrazu) nie są częścią kopii.
- Po nieudanym przebiegu z `--keep-previous` kolejne odtwarzanie wymaga usunięcia bazy `<DB_NAME>_pre_restore`. Skrypt to wskazuje.
