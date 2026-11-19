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
- `config/`: kluczowe pliki konfiguracyjne (`parameters.php` z kluczami cookie/szyfrowania, `.htaccess`, `.env`, `env.backup` oraz opcjonalne `parameters.yml` i `defines_custom.inc.php`).
- `manifest.json`: metadane kopii (wersja PrestaShop, data ISO 8601, podsumowanie rozmiarów i sumy kontrolne SHA-256 dla każdego pliku).

### Bezpieczeństwo i uprawnienia

- **Poufność poświadczeń**: hasła bazy danych nie są przekazywane jako argumenty CLI na hoście (`ps aux`), lecz odczytywane bezpiecznie ze środowiska kontenera `db`.
- **Restrykcyjne uprawnienia**: katalog docelowy `deploy/backups` tworzony jest z uprawnieniami `0700` (`drwx------`), a plik archiwum `0600` (`-rw-------`).
- **Ochrona przed wyciekiem do Git**: katalog `deploy/backups/` jest wykluczony w głównym `.gitignore` oraz zawiera lokalny plik `.gitignore` blokujący przypadkowe śledzenie archiwów.
- **Sprzątanie zasobów**: wszelkie operacje pośrednie realizowane są w katalogu tymczasowym `mktemp -d` usuwanym przez pułapkę sygnałów (`trap ... EXIT INT TERM ERR`).
- **Gwarancja przywrócenia**: przy użyciu opcji `--maintenance` sklep jest automatycznie przywracany do stanu aktywnego nawet w przypadku przerwania działania skryptu błędem lub sygnałem SIGINT.

