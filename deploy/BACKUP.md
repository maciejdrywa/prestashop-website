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

