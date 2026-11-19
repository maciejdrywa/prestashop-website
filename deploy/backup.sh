#!/bin/bash
# Skrypt tworzenia kompletnej kopii zapasowej sklepu PrestaShop.
# Wywołanie z katalogu repozytorium lub dowolnego miejsca:
#   bash deploy/backup.sh [--output-dir <katalog>] [--maintenance]
#
# Zawartość tworzonego archiwum:
#   - database.sql: zrzut bazy MariaDB (InnoDB --single-transaction, UTF-8)
#   - images/p/: drzewo zdjęć produktów (oryginały i miniatury)
#   - config/: pliki konfiguracyjne (parameters.php, .htaccess, .env)
#   - manifest.json: metadane, wersja PrestaShop, sumy kontrolne SHA-256 i rozmiary
#
# Bezpieczeństwo:
#   - umask 077, katalog docelowy 0700, plik archiwum 0600
#   - hasła bazy nie są przekazywane jako argumenty CLI na hoście
#   - katalog deploy/backups jest zabezpieczony regułami .gitignore
set -euo pipefail
umask 077

# Kolorowanie wyjścia dla terminala interaktywnego
if [[ -t 1 ]]; then
    COLOR_INFO="\033[1;34m"
    COLOR_SUCCESS="\033[1;32m"
    COLOR_WARN="\033[1;33m"
    COLOR_ERROR="\033[1;31m"
    COLOR_RESET="\033[0m"
else
    COLOR_INFO=""
    COLOR_SUCCESS=""
    COLOR_WARN=""
    COLOR_ERROR=""
    COLOR_RESET=""
fi

log_info() { echo -e "${COLOR_INFO}[INFO]${COLOR_RESET} $*"; }
log_success() { echo -e "${COLOR_SUCCESS}[SUCCESS]${COLOR_RESET} $*"; }
log_warn() { echo -e "${COLOR_WARN}[WARN]${COLOR_RESET} $*" >&2; }
log_error() { echo -e "${COLOR_ERROR}[ERROR]${COLOR_RESET} $*" >&2; }

print_help() {
    cat << 'EOF'
Użycie: deploy/backup.sh [OPCJE]

Tworzy kompletną, spójną i bezpieczną kopię zapasową sklepu PrestaShop.
Kopia obejmuje zrzut bazy MariaDB (InnoDB --single-transaction), zdjęcia
produktów (img/p), pliki konfiguracyjne (parameters.php, .htaccess, .env)
oraz manifest ze strukturą i sumami SHA-256.

Opcje:
  --output-dir <KATALOG>  Ścieżka do katalogu docelowego dla archiwów
                          (domyślnie: deploy/backups)
  --maintenance           Włącza tryb konserwacji PrestaShop na czas tworzenia
                          kopii zapasowej i przywraca poprzedni stan po zakończeniu
  -h, --help              Wyświetla tę pomoc i kończy działanie

Przykłady:
  bash deploy/backup.sh
  bash deploy/backup.sh --maintenance
  bash deploy/backup.sh --output-dir /var/backups/prestashop
EOF
}

# Ustalenie katalogu repozytorium
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "$script_dir/.." && pwd)"

# Parametry domyślne
output_dir="$repo_dir/deploy/backups"
maintenance_mode=false

# Przetwarzanie argumentów wiersza poleceń
while [[ $# -gt 0 ]]; do
    case "$1" in
        --output-dir)
            [[ $# -ge 2 ]] || { log_error "Opcja --output-dir wymaga podania ścieżki katalogu."; exit 1; }
            output_dir="$2"
            shift 2
            ;;
        --maintenance)
            maintenance_mode=true
            shift
            ;;
        -h|--help)
            print_help
            exit 0
            ;;
        *)
            log_error "Nieznana opcja: $1"
            print_help >&2
            exit 1
            ;;
    esac
done

# Rozwinięcie względnej ścieżki docelowej względem bieżącego katalogu
if [[ "$output_dir" != /* ]]; then
    output_dir="$PWD/$output_dir"
fi

# Formatowanie rozmiaru w bajtach do czytelnej jednostki
format_bytes() {
    local bytes="$1"
    if [[ $bytes -ge 1073741824 ]]; then
        awk -v b="$bytes" 'BEGIN { printf "%.2f GiB", b/1073741824 }'
    elif [[ $bytes -ge 1048576 ]]; then
        awk -v b="$bytes" 'BEGIN { printf "%.2f MiB", b/1048576 }'
    elif [[ $bytes -ge 1024 ]]; then
        awk -v b="$bytes" 'BEGIN { printf "%.2f KiB", b/1024 }'
    else
        echo "${bytes} B"
    fi
}

# Obliczenie sumy SHA-256 w sposób przenośny (Linux / macOS)
compute_sha256() {
    local file="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$file" | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$file" | awk '{print $1}'
    elif command -v openssl >/dev/null 2>&1; then
        openssl dgst -sha256 "$file" | awk '{print $NF}'
    else
        log_error "Brak dostępnego narzędzia do wyliczenia SHA-256 (sha256sum, shasum lub openssl)."
        exit 1
    fi
}

start_time=$(date +%s)
log_info "Rozpoczynanie procedury tworzenia kopii zapasowej PrestaShop..."

# Weryfikacja wymagań wstępnych
command -v docker >/dev/null 2>&1 || { log_error "Zainstaluj i uruchom narzędzie Docker."; exit 1; }
docker compose version >/dev/null 2>&1 || { log_error "Narzędzie docker compose nie jest dostępne."; exit 1; }
docker info >/dev/null 2>&1 || { log_error "Demon Docker nie jest uruchomiony."; exit 1; }

env_file="$repo_dir/.env"
[[ -f "$env_file" ]] || { log_error "Brak pliku .env w katalogu $repo_dir. Utwórz .env przed wykonaniem kopii."; exit 1; }
compose_file="$repo_dir/deploy/compose.yaml"
[[ -f "$compose_file" ]] || { log_error "Brak pliku compose: $compose_file"; exit 1; }

compose=(docker compose --env-file "$env_file" -f "$compose_file")

# Weryfikacja działania wymaganych kontenerów
db_running=$("${compose[@]}" ps --status running -q db 2>/dev/null || true)
if [[ -z "$db_running" ]]; then
    log_error "Kontener bazy danych 'db' nie jest uruchomiony. Uruchom środowisko przed wykonaniem kopii."
    exit 1
fi

shop_running=$("${compose[@]}" ps --status running -q shop 2>/dev/null || true)
if [[ -z "$shop_running" ]]; then
    log_error "Kontener sklepu 'shop' nie jest uruchomiony. Uruchom środowisko przed wykonaniem kopii."
    exit 1
fi

# Bezpieczny katalog tymczasowy
temp_dir=$(mktemp -d)
maintenance_enabled_by_us=false

cleanup() {
    local exit_code=$?
    if [[ "$maintenance_enabled_by_us" == "true" ]]; then
        log_info "Przywracanie sklepu z trybu konserwacji..."
        "${compose[@]}" exec -T shop php bin/console prestashop:config set PS_SHOP_ENABLE --value=1 >/dev/null 2>&1 || true
    fi
    if [[ -n "${temp_dir:-}" && -d "$temp_dir" ]]; then
        rm -rf "$temp_dir"
    fi
    exit "$exit_code"
}
trap cleanup EXIT INT TERM ERR

timestamp=$(date +"%Y%m%d_%H%M%S")
iso_created_at=$(date +"%Y-%m-%dT%H:%M:%S%z")
backup_name="backup_${timestamp}"
backup_dir="$temp_dir/$backup_name"

mkdir -p "$backup_dir/config"
mkdir -p "$backup_dir/images"

# Opcjonalne włączenie trybu konserwacji
if [[ "$maintenance_mode" == "true" ]]; then
    current_status=$("${compose[@]}" exec -T shop php bin/console prestashop:config get PS_SHOP_ENABLE 2>/dev/null | grep -o 'PS_SHOP_ENABLE=[0-9]' | cut -d= -f2 || echo "1")
    if [[ "$current_status" == "1" ]]; then
        log_info "Włączanie trybu konserwacji w PrestaShop (PS_SHOP_ENABLE=0)..."
        "${compose[@]}" exec -T shop php bin/console prestashop:config set PS_SHOP_ENABLE --value=0 >/dev/null
        maintenance_enabled_by_us=true
    else
        log_info "Sklep jest już w trybie konserwacji."
    fi
fi

# 1. Zrzut bazy MariaDB
log_info "Wykonywanie transakcyjnego zrzutu bazy MariaDB..."
"${compose[@]}" exec -T db sh -c 'exec mariadb-dump -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" --single-transaction --quick --default-character-set=utf8mb4' > "$backup_dir/database.sql"

if [[ ! -s "$backup_dir/database.sql" ]]; then
    log_error "Zrzut bazy danych jest pusty!"
    exit 1
fi
if ! tail -n 25 "$backup_dir/database.sql" | grep -q "Dump completed"; then
    log_error "Zrzut bazy danych nie zawiera znacznika zakończenia (Dump completed). Możliwe przerwanie transferu."
    exit 1
fi
db_size=$(wc -c < "$backup_dir/database.sql" | tr -d ' ')
log_info "Zrzut bazy MariaDB zakończony pomyślnie ($(format_bytes "$db_size"))."

# 2. Zdjęcia produktów
log_info "Kopiowanie zdjęć produktów z kontenera sklepu (/var/www/html/img/p)..."
"${compose[@]}" cp shop:/var/www/html/img/p "$backup_dir/images"
if [[ ! -d "$backup_dir/images/p" ]]; then
    log_error "Nie udało się skopiować katalogu zdjęć produktów img/p!"
    exit 1
fi
images_count=$(find "$backup_dir/images/p" -type f | wc -l | tr -d ' ')
log_info "Skopiowano zdjęcia produktów: $images_count plików."

# 3. Pliki konfiguracyjne
log_info "Kopiowanie plików konfiguracyjnych..."
# parameters.php jest plikiem krytycznym
if ! "${compose[@]}" exec -T shop test -f /var/www/html/app/config/parameters.php; then
    log_error "Brak wymaganego pliku /var/www/html/app/config/parameters.php w kontenerze!"
    exit 1
fi
"${compose[@]}" cp shop:/var/www/html/app/config/parameters.php "$backup_dir/config/parameters.php"

# parameters.yml (opcjonalny)
if "${compose[@]}" exec -T shop test -f /var/www/html/app/config/parameters.yml; then
    "${compose[@]}" cp shop:/var/www/html/app/config/parameters.yml "$backup_dir/config/parameters.yml"
fi

# defines_custom.inc.php (opcjonalny)
if "${compose[@]}" exec -T shop test -f /var/www/html/config/defines_custom.inc.php; then
    "${compose[@]}" cp shop:/var/www/html/config/defines_custom.inc.php "$backup_dir/config/defines_custom.inc.php"
fi

# .htaccess (opcjonalny)
if "${compose[@]}" exec -T shop test -f /var/www/html/.htaccess; then
    "${compose[@]}" cp shop:/var/www/html/.htaccess "$backup_dir/config/.htaccess"
fi

# .env oraz env.backup
cp "$env_file" "$backup_dir/config/.env"
cp "$env_file" "$backup_dir/config/env.backup"

# Odczyt wersji PrestaShop
ps_version=$("${compose[@]}" exec -T shop php -r '
    if (file_exists("/var/www/html/src/Core/Version.php")) {
        require_once "/var/www/html/src/Core/Version.php";
        if (class_exists("\\PrestaShop\\PrestaShop\\Core\\Version") && defined("\\PrestaShop\\PrestaShop\\Core\\Version::VERSION")) {
            echo \PrestaShop\PrestaShop\Core\Version::VERSION;
            exit(0);
        }
    }
    if (defined("_PS_VERSION_")) {
        echo _PS_VERSION_;
        exit(0);
    }
    echo "unknown";
' 2>/dev/null || echo "8.2.8")

# 4. Generowanie manifestu JSON ze strukturą, sumami SHA-256 i metadanymi
log_info "Generowanie manifestu metadanych (manifest.json)..."

if command -v python3 >/dev/null 2>&1; then
    python3 - "$backup_dir" "$backup_name" "$iso_created_at" "$ps_version" << 'PYEOF'
import hashlib
import json
import os
import sys

backup_dir = sys.argv[1]
backup_name = sys.argv[2]
created_at = sys.argv[3]
ps_version = sys.argv[4]

files_dict = {}
total_files = 0
total_bytes = 0
images_count = 0

for root, _, filenames in os.walk(backup_dir):
    for fn in sorted(filenames):
        if fn == "manifest.json":
            continue
        full_path = os.path.join(root, fn)
        rel_path = os.path.relpath(full_path, backup_dir)
        size = os.path.getsize(full_path)
        
        hasher = hashlib.sha256()
        with open(full_path, "rb") as f:
            while chunk := f.read(65536):
                hasher.update(chunk)
        digest = hasher.hexdigest()
        
        files_dict[rel_path] = {
            "size_bytes": size,
            "sha256": digest
        }
        total_files += 1
        total_bytes += size
        if rel_path.startswith("images/p/"):
            images_count += 1

db_info = files_dict.get("database.sql", {})

manifest_data = {
    "backup_name": backup_name,
    "created_at": created_at,
    "prestashop_version": ps_version,
    "database": {
        "file": "database.sql",
        "size_bytes": db_info.get("size_bytes", 0),
        "sha256": db_info.get("sha256", "")
    },
    "summary": {
        "total_files": total_files,
        "total_uncompressed_bytes": total_bytes,
        "images_count": images_count
    },
    "files": files_dict
}

with open(os.path.join(backup_dir, "manifest.json"), "w", encoding="utf-8") as f:
    json.dump(manifest_data, f, indent=2, ensure_ascii=False)
    f.write("\n")
PYEOF
else
    # Rezerwowy generator manifestu w czystym Bash / awk
    manifest_tmp="$backup_dir/manifest.json.tmp"
    total_files=0
    total_bytes=0
    images_count=0
    
    printf '{\n  "backup_name": "%s",\n  "created_at": "%s",\n  "prestashop_version": "%s",\n  "database": {\n' \
        "$backup_name" "$iso_created_at" "$ps_version" > "$manifest_tmp"
    
    db_sha=$(compute_sha256 "$backup_dir/database.sql")
    printf '    "file": "database.sql",\n    "size_bytes": %s,\n    "sha256": "%s"\n  },\n  "files": {\n' \
        "$db_size" "$db_sha" >> "$manifest_tmp"
    
    first=true
    while IFS= read -r -d '' full_path; do
        rel_path="${full_path#$backup_dir/}"
        [[ "$rel_path" == "manifest.json" || "$rel_path" == "manifest.json.tmp" ]] && continue
        f_size=$(wc -c < "$full_path" | tr -d ' ')
        f_sha=$(compute_sha256 "$full_path")
        total_files=$((total_files + 1))
        total_bytes=$((total_bytes + f_size))
        if [[ "$rel_path" == images/p/* ]]; then
            images_count=$((images_count + 1))
        fi
        
        if [ "$first" = true ]; then
            first=false
        else
            printf ',\n' >> "$manifest_tmp"
        fi
        printf '    "%s": {\n      "size_bytes": %s,\n      "sha256": "%s"\n    }' "$rel_path" "$f_size" "$f_sha" >> "$manifest_tmp"
    done < <(find "$backup_dir" -type f -print0 | sort -z)
    
    printf '\n  },\n  "summary": {\n    "total_files": %d,\n    "total_uncompressed_bytes": %d,\n    "images_count": %d\n  }\n}\n' \
        "$total_files" "$total_bytes" "$images_count" >> "$manifest_tmp"
    mv "$manifest_tmp" "$backup_dir/manifest.json"
fi

# 5. Pakowanie archiwum .tar.gz
archive_filename="${backup_name}.tar.gz"
temp_archive="$temp_dir/$archive_filename"

log_info "Tworzenie skompresowanego archiwum $archive_filename..."
tar -czf "$temp_archive" -C "$temp_dir" "$backup_name"

# 6. Weryfikacja integralności archiwum
log_info "Weryfikacja integralności archiwum..."
tar -tzf "$temp_archive" >/dev/null

# 7. Zapis do katalogu docelowego z restrykcyjnymi uprawnieniami
mkdir -p "$output_dir"
chmod 0700 "$output_dir"

# Zabezpieczenie katalogu kopii przed dodaniem do Gita
if [[ ! -f "$output_dir/.gitignore" ]]; then
    printf "*\n!.gitignore\n" > "$output_dir/.gitignore"
    chmod 0644 "$output_dir/.gitignore"
fi

final_archive="$output_dir/$archive_filename"
chmod 0600 "$temp_archive"
mv "$temp_archive" "$final_archive"

archive_size=$(wc -c < "$final_archive" | tr -d ' ')
archive_sha256=$(compute_sha256 "$final_archive")
total_files_count=$(find "$backup_dir" -type f | wc -l | tr -d ' ')

# Jeśli tryb konserwacji był włączony przez skrypt, przywracamy sklep teraz
if [[ "$maintenance_enabled_by_us" == "true" ]]; then
    log_info "Przywracanie dostępności sklepu (PS_SHOP_ENABLE=1)..."
    "${compose[@]}" exec -T shop php bin/console prestashop:config set PS_SHOP_ENABLE --value=1 >/dev/null
    maintenance_enabled_by_us=false
fi

end_time=$(date +%s)
duration=$((end_time - start_time))

echo ""
log_success "Kopia zapasowa została pomyślnie utworzona i zweryfikowana!"
echo "--------------------------------------------------------------------------------"
echo "  Plik archiwum:      $final_archive"
echo "  Uprawnienia:        $(ls -l "$final_archive" | awk '{print $1}') (0600)"
echo "  Rozmiar archiwum:   $(format_bytes "$archive_size") ($archive_size bajtów)"
echo "  Suma SHA-256:       $archive_sha256"
echo "  Wersja PrestaShop:  $ps_version"
echo "  Liczba plików:      $total_files_count (w tym $images_count zdjęć produktów)"
echo "  Czas wykonania:     ${duration}s"
echo "--------------------------------------------------------------------------------"
exit 0
