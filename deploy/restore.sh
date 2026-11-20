#!/usr/bin/env bash
# Skrypt odtwarzania sklepu PrestaShop z archiwum utworzonego przez deploy/backup.sh.
# Wywołanie z katalogu repozytorium lub dowolnego miejsca:
#   bash deploy/restore.sh <archiwum.tar.gz> [OPCJE]
#   bash deploy/restore.sh --latest [OPCJE]
#
# Kolejność działań:
#   1. Walidacja archiwum bez dotykania środowiska (struktura, ścieżki, manifest, SHA-256, zrzut SQL).
#   2. Przygotowanie środowiska Docker (start usług, oczekiwanie na bazę i instalację PrestaShop).
#   3. Potwierdzenie nadpisania danych i kopia bezpieczeństwa bieżącego stanu (backup.sh).
#   4. Import bazy do bazy tymczasowej, następnie atomowa podmiana tabel (RENAME TABLE).
#   5. Podmiana zdjęć produktów (img/p) i plików konfiguracyjnych z kopią poprzednich wersji.
#   6. Dopasowanie do bieżącego środowiska: domena, dane dostępowe bazy, motyw, moduły, cache.
#   7. Kontrola działania sklepu. Błąd na dowolnym etapie podmiany cofa wprowadzone zmiany.
#
# Bezpieczeństwo:
#   - umask 077, archiwum jest rozpakowywane do katalogu tymczasowego usuwanego po zakończeniu
#   - archiwum z bezwzględnymi ścieżkami, "..", dowiązaniami lub urządzeniami jest odrzucane
#   - hasła bazy nie są przekazywane jako argumenty CLI (MYSQL_PWD w środowisku procesu w kontenerze)
#   - bieżący .env nie jest nadpisywany; .env z kopii jest używany tylko na wyraźne żądanie
set -Eeuo pipefail
umask 077

# Wyłączenie automatycznej konwersji ścieżek POSIX->Windows w Git Bash (MSYS2)
export MSYS_NO_PATHCONV=1
export LC_ALL=C
# macOS bsdtar: bez plików AppleDouble/xattr w strumieniu wysyłanym do kontenera
export COPYFILE_DISABLE=1

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

error_already_logged=false

log_info() { echo -e "${COLOR_INFO}[INFO]${COLOR_RESET} $*"; }
log_success() { echo -e "${COLOR_SUCCESS}[SUCCESS]${COLOR_RESET} $*"; }
log_warn() { echo -e "${COLOR_WARN}[WARN]${COLOR_RESET} $*" >&2; }
log_error() {
    error_already_logged=true
    echo -e "${COLOR_ERROR}[ERROR]${COLOR_RESET} $*" >&2
}
die() { log_error "$*"; exit 1; }

print_help() {
    cat << 'EOF'
Użycie: deploy/restore.sh <ARCHIWUM.tar.gz> [OPCJE]
        deploy/restore.sh --latest [OPCJE]

Odtwarza sklep PrestaShop z archiwum utworzonego przez deploy/backup.sh:
bazę MariaDB, zdjęcia produktów (img/p) oraz pliki konfiguracyjne.
Przed podmianą danych sprawdza kompletność archiwum i sumy SHA-256,
wykonuje kopię bezpieczeństwa bieżącego stanu i prosi o potwierdzenie.

Opcje:
  --latest              Używa najnowszego archiwum backup_*.tar.gz z katalogu kopii
  --verify-only         Sprawdza tylko archiwum (bez Dockera i bez zmian w sklepie)
  -y, --yes             Pomija interaktywne potwierdzenie nadpisania danych
  --no-safety-backup    Pomija kopię bezpieczeństwa bieżącego stanu przed odtworzeniem
  --use-backup-env      Gdy brak pliku .env, tworzy go z .env zapisanego w archiwum
  --keep-previous       Zachowuje poprzednie dane (baza <DB>_pre_restore, img/p.pre-restore,
                        var/restore-previous/) po udanym odtworzeniu
  --ignore-version      Pozwala odtworzyć kopię z inną wersją PrestaShop niż w kontenerze
  -h, --help            Wyświetla tę pomoc i kończy działanie

Zmienne środowiskowe:
  ENV_FILE              Plik zmiennych Compose (domyślnie: <repo>/.env)
  COMPOSE_FILE_PATH     Plik Compose (domyślnie: <repo>/deploy/compose.yaml)
  BACKUP_DIR            Katalog kopii dla --latest (domyślnie: deploy/backups);
                        kopie bezpieczeństwa trafiają do BACKUP_DIR/pre-restore
  RESTORE_TIMEOUT       Limit oczekiwania na bazę i sklep w sekundach (domyślnie: 900)
  SHOP_URL              Adres kontroli działania sklepu (domyślnie: https://<PS_DOMAIN>/)
  RESTORE_ASSUME_YES=1  Odpowiednik --yes (np. w CI)

Przykłady:
  bash deploy/restore.sh --latest
  bash deploy/restore.sh deploy/backups/backup_20261119_182142.tar.gz
  bash deploy/restore.sh deploy/backups/backup_20261119_182142.tar.gz --verify-only
  ENV_FILE=.env.test bash deploy/restore.sh --latest --yes --no-safety-backup
EOF
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "$script_dir/.." && pwd)"

archive=""
use_latest=false
verify_only=false
assume_yes=false
[[ "${RESTORE_ASSUME_YES:-0}" == "1" ]] && assume_yes=true
safety_backup=true
use_backup_env=false
keep_previous=false
ignore_version=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --latest) use_latest=true; shift ;;
        --verify-only) verify_only=true; shift ;;
        -y|--yes) assume_yes=true; shift ;;
        --no-safety-backup) safety_backup=false; shift ;;
        --use-backup-env) use_backup_env=true; shift ;;
        --keep-previous) keep_previous=true; shift ;;
        --ignore-version) ignore_version=true; shift ;;
        -h|--help) print_help; exit 0 ;;
        -*)
            log_error "Nieznana opcja: $1"
            print_help >&2
            exit 1
            ;;
        *)
            [[ -z "$archive" ]] || die "Podano więcej niż jedno archiwum: '$archive' i '$1'."
            archive="$1"
            shift
            ;;
    esac
done

# Ścieżka hosta w formacie POSIX dla narzędzi Bash (Git Bash: C:/x -> /c/x).
abs_path() {
    local path="$1"
    if [[ "$path" =~ ^[A-Za-z]:[/\\] ]]; then
        command -v cygpath >/dev/null 2>&1 || die "Ścieżka z literą dysku wymaga cygpath (Git Bash): $path"
        path=$(cygpath -u "$path")
    fi
    if [[ "$path" == /* ]]; then echo "$path"; else echo "$PWD/$path"; fi
}

# Ścieżka hosta przekazywana do docker.exe. MSYS_NO_PATHCONV wyłącza automatyczną
# konwersję, więc w Git Bash /c/Users/... jest jawnie zamieniane na C:/Users/...
host_path() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi
}

env_file="$(abs_path "${ENV_FILE:-$repo_dir/.env}")"
compose_file="$(abs_path "${COMPOSE_FILE_PATH:-$repo_dir/deploy/compose.yaml}")"
backup_dir="$(abs_path "${BACKUP_DIR:-$repo_dir/deploy/backups}")"
# Kopie bezpieczeństwa trafiają do podkatalogu, więc --latest nigdy ich nie wybierze.
safety_dir="$backup_dir/pre-restore"
restore_timeout="${RESTORE_TIMEOUT:-900}"
[[ "$restore_timeout" =~ ^[0-9]+$ ]] || die "RESTORE_TIMEOUT musi być liczbą sekund."

if [[ "$use_latest" == "true" ]]; then
    [[ -z "$archive" ]] || die "Użyj albo --latest, albo ścieżki archiwum."
    archive=$(find "$backup_dir" -maxdepth 1 -type f -name 'backup_*.tar.gz' 2>/dev/null | sort | tail -n 1)
    [[ -n "$archive" ]] || die "Brak archiwów backup_*.tar.gz w katalogu $backup_dir."
fi
[[ -n "$archive" ]] || { log_error "Nie podano archiwum kopii zapasowej."; print_help >&2; exit 1; }
archive="$(abs_path "$archive")"

# Stan potrzebny do sprzątania i wycofania zmian
temp_dir=""
lock_dir=""
nginx_stopped_by_us=false
db_swapped=false
images_swapped=false
config_swapped=false
restore_finished=false
compose=()

compute_sha256_check() {
    # Sprawdza listę "<sha256>  <ścieżka>" względem bieżącego katalogu.
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum --quiet -c "$1"
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 --quiet -c "$1"
    else
        die "Brak narzędzia do weryfikacji SHA-256 (sha256sum lub shasum)."
    fi
}

dc() {
    [[ ${#compose[@]} -gt 0 ]] || return 1
    "${compose[@]}" "$@"
}

# Polecenie SQL jako root bazy. Hasło pochodzi ze środowiska kontenera db.
db_sql() {
    dc exec -T db sh -c 'exec env MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot --default-character-set=utf8mb4 -N -B "$@"' sh "$@"
}

# Import zrzutu tymczasowym kontem z uprawnieniami wyłącznie do bazy tymczasowej:
# SQL ze zrzutu nie może zmienić bazy produkcyjnej ani kont MariaDB.
# --sandbox dodatkowo blokuje polecenia klienta (\\!, system, source).
import_user="restore_import"
db_import() {
    local password
    password=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    # SQL przez stdin: bez cudzysłowów i nowych linii w argumentach (WSL, Git Bash).
    if ! db_sql << SQL
DROP USER IF EXISTS '$import_user'@'localhost';
CREATE USER '$import_user'@'localhost' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON \`$1\`.* TO '$import_user'@'localhost';
SQL
    then
        log_error "Nie udało się utworzyć tymczasowego konta importu w MariaDB."
        return 1
    fi
    local rc=0
    dc exec -T -e "RESTORE_IMPORT_PWD=$password" -e "RESTORE_IMPORT_USER=$import_user" -e "RESTORE_IMPORT_DB=$1" db sh -c \
        'exec env MYSQL_PWD="$RESTORE_IMPORT_PWD" mariadb -u "$RESTORE_IMPORT_USER" --sandbox --default-character-set=utf8mb4 "$RESTORE_IMPORT_DB"' || rc=$?
    drop_import_user
    [[ $rc -eq 0 ]] || log_error "Klient MariaDB przerwał import zrzutu (kod $rc)."
    return $rc
}
drop_import_user() {
    db_sql -e "DROP USER IF EXISTS '$import_user'@'localhost';" >/dev/null 2>&1 || true
}

shop_sh() { dc exec -T shop sh -c "$1"; }

# Atomowo przenosi tabele: <a> -> <b> oraz <c> -> <a> w jednej instrukcji RENAME TABLE.
atomic_move_tables() {
    local a="$1" b="$2" c="$3" statement
    db_sql -e "CREATE DATABASE IF NOT EXISTS \`$b\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" || return 1
    statement=$(db_sql << SQL
SET SESSION group_concat_max_len = 4194304;
SELECT CONCAT('RENAME TABLE ', GROUP_CONCAT(x ORDER BY ord, x SEPARATOR ', '))
FROM (
    SELECT 1 AS ord, CONCAT('\`$a\`.\`', table_name, '\` TO \`$b\`.\`', table_name, '\`') AS x
    FROM information_schema.tables WHERE table_schema = '$a'
    UNION ALL
    SELECT 2, CONCAT('\`$c\`.\`', table_name, '\` TO \`$a\`.\`', table_name, '\`')
    FROM information_schema.tables WHERE table_schema = '$c'
) moves;
SQL
)
    if [[ -z "$statement" || "$statement" == "NULL" ]]; then
        log_error "Brak tabel do przeniesienia między bazami $a, $b, $c."
        return 1
    fi
    # Instrukcja ma kilkadziesiąt tysięcy znaków: stdin zamiast argumentu (limit CLI w Windows).
    # Komunikat błędu klienta zawiera całą instrukcję, więc wypisujemy tylko jego początek.
    local err_file rc=0
    err_file=$(mktemp)
    printf 'SET SESSION lock_wait_timeout = 60;\n%s;\n' "$statement" | db_sql 2> "$err_file" || rc=$?
    [[ $rc -eq 0 ]] || head -c 300 "$err_file" >&2
    rm -f "$err_file"
    return $rc
}

# Klient (docker compose exec) mógł zginąć od Ctrl+C, a serwer nadal wykonywać RENAME.
# Stan bazy oceniamy dopiero, gdy żadne RENAME TABLE nie jest w toku.
wait_rename_done() {
    for _ in $(seq 1 60); do
        [[ "$(db_sql -e "SELECT COUNT(*) FROM information_schema.processlist WHERE info LIKE 'RENAME TABLE%' AND id <> CONNECTION_ID();" 2>/dev/null | tr -d '\r')" == "0" ]] && return 0
        sleep 1
    done
    return 1
}

# Podmiana nastąpiła, gdy baza tymczasowa jest pusta, a baza na poprzednie tabele nie.
swap_happened() {
    [[ "$(table_count "$old_db" 2>/dev/null)" =~ ^[1-9] && "$(table_count "$tmp_db" 2>/dev/null)" == "0" ]]
}

table_count() {
    db_sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$1';" | tr -d '\r'
}

# Pliki konfiguracyjne i katalog z ich poprzednimi wersjami (poza katalogami publicznymi, 0600, root).
config_files_sh='CONFIG_FILES="app/config/parameters.php app/config/parameters.yml config/defines_custom.inc.php .htaccess"; PREV=/var/www/html/var/restore-previous;'

# Usuwanie cache odporne na równoległe tworzenie plików (healthcheck bootstrapu co 5 s).
clear_cache() {
    shop_sh 'cd /var/www/html
        for d in var/cache/prod var/cache/dev; do
            if [ -d "$d" ]; then mv "$d" "$d.old-$$" && rm -rf "$d.old-$$" || rm -rf "$d" || true; fi
        done
        find img/tmp -mindepth 1 ! -name index.php -delete 2>/dev/null || true
        mkdir -p var/cache && chown -R www-data:www-data var/cache'
}

rollback() {
    # Sygnał mógł przerwać skrypt w trakcie lub tuż po RENAME, przed ustawieniem flagi: stan ustalany z bazy.
    if [[ "$db_swapped" != "true" && "${db_import_started:-false}" == "true" ]]; then
        drop_import_user
        wait_rename_done || log_warn "RENAME TABLE nadal trwa w MariaDB. Stan bazy może być niepewny."
        swap_happened && db_swapped=true
    fi
    if [[ "$config_swapped" != "true" && "$images_swapped" != "true" && "$db_swapped" != "true" ]]; then
        if [[ "${db_import_started:-false}" == "true" ]]; then
            log_info "Bieżąca baza, zdjęcia i konfiguracja sklepu nie zostały zmienione."
        fi
    else
        log_warn "Wycofywanie zmian wprowadzonych przez odtwarzanie..."
    fi
    if [[ "$config_swapped" == "true" || "$images_swapped" == "true" || "${images_upload_started:-false}" == "true" ]]; then
        # Operacje na plikach wymagają działającego kontenera shop.
        if ! shop_sh 'true' >/dev/null 2>&1; then
            dc start shop >/dev/null 2>&1 || true
            for _ in $(seq 1 30); do shop_sh 'true' >/dev/null 2>&1 && break; sleep 2; done
        fi
    fi
    if [[ "$config_swapped" == "true" ]]; then
        shop_sh "cd /var/www/html; $config_files_sh"'
            for f in $CONFIG_FILES; do
                saved="$PREV/$(echo "$f" | tr / _)"
                if [ -f "$saved" ]; then cp -p "$saved" "$f" && chown www-data:www-data "$f"
                elif [ -f "$saved.absent" ]; then rm -f "$f"; fi
            done' && log_info "Przywrócono poprzednie pliki konfiguracyjne." \
            || log_error "Nie udało się przywrócić plików konfiguracyjnych (kopie w var/restore-previous w kontenerze shop)."
    fi
    if [[ "${images_upload_started:-false}" == "true" ]]; then
        shop_sh 'rm -rf /var/www/html/img/p.restore-new' >/dev/null 2>&1 || true
    fi
    if [[ "$images_swapped" == "true" ]]; then
        shop_sh 'cd /var/www/html/img && if [ -d p.pre-restore ]; then rm -rf p && mv p.pre-restore p; fi' \
            && log_info "Przywrócono poprzedni katalog img/p." \
            || log_error "Nie udało się przywrócić img/p (katalog img/p.pre-restore w kontenerze shop)."
    fi
    if [[ "$db_swapped" != "true" && -n "${tmp_db:-}" && "${db_import_started:-false}" == "true" ]]; then
        # Podmiana nie nastąpiła: usuwamy tylko bazę tymczasową i pustą bazę na poprzednie tabele.
        db_sql -e "DROP DATABASE IF EXISTS \`$tmp_db\`;" || true
        old_tables=$(db_sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$old_db';" | tr -d '\r')
        [[ "$old_tables" != "0" ]] || db_sql -e "DROP DATABASE IF EXISTS \`$old_db\`;" || true
    fi
    if [[ "$db_swapped" == "true" ]]; then
        if db_sql -e "DROP DATABASE IF EXISTS \`$tmp_db\`;" && atomic_move_tables "$live_db" "$tmp_db" "$old_db"; then
            db_sql -e "DROP DATABASE IF EXISTS \`$tmp_db\`; DROP DATABASE IF EXISTS \`$old_db\`;" || true
            log_info "Przywrócono poprzednią bazę danych."
        else
            log_error "Nie udało się przywrócić bazy. Poprzednie tabele mogą znajdować się w bazie $old_db."
        fi
    fi
    if [[ "$config_swapped" == "true" ]]; then
        shop_sh 'rm -rf /var/www/html/var/restore-previous' || true
    fi
    if [[ "$config_swapped" == "true" || "$images_swapped" == "true" || "$db_swapped" == "true" ]]; then
        clear_cache || true
        dc restart shop >/dev/null 2>&1 || true
    fi
}

cleanup() {
    local exit_code=${1:-$?}
    trap - EXIT INT TERM ERR
    set +e
    # Katalog tymczasowy zawiera zrzut bazy i sekrety: usuwany jako pierwszy.
    [[ -n "$temp_dir" && -d "$temp_dir" ]] && rm -rf "$temp_dir"
    # Rollback zawsze, gdy odtwarzanie nie zostało ukończone, także przy kodzie 0 (np. sygnał bez pułapki).
    if [[ "$restore_finished" != "true" ]]; then
        [[ $exit_code -ne 0 ]] || exit_code=1
        [[ "$error_already_logged" == "true" ]] || log_error "Odtwarzanie nie powiodło się (kod błędu: $exit_code)."
        rollback
        if [[ -n "${safety_archive:-}" ]]; then
            log_warn "Kopia bezpieczeństwa stanu sprzed odtwarzania: $safety_archive"
        fi
    fi
    if [[ "$nginx_stopped_by_us" == "true" ]]; then
        log_info "Ponowne uruchamianie usługi nginx..."
        dc start nginx >/dev/null 2>&1 || log_error "Nie udało się uruchomić nginx. Uruchom: docker compose ... start nginx"
    fi
    shop_sh 'rm -rf /tmp/restore-config' >/dev/null 2>&1 || true
    [[ -n "$lock_dir" && -d "$lock_dir" ]] && rmdir "$lock_dir" 2>/dev/null
    exit "$exit_code"
}
trap cleanup EXIT
trap 'cleanup 130' INT
trap 'cleanup 143' TERM
# Zamknięcie okna terminala (Git Bash) wysyła SIGHUP.
trap 'cleanup 129' HUP
trap 'cleanup 131' QUIT

start_time=$(date +%s)

# ---------------------------------------------------------------------------
# 1. Walidacja archiwum (bez zmian w środowisku)
# ---------------------------------------------------------------------------
log_info "Sprawdzanie archiwum: $archive"
for tool in tar gzip awk find sort comm; do
    command -v "$tool" >/dev/null 2>&1 || die "Brak wymaganego narzędzia: $tool"
done

[[ -f "$archive" ]] || die "Archiwum nie istnieje: $archive"
[[ -r "$archive" ]] || die "Brak uprawnień do odczytu archiwum: $archive"
[[ -s "$archive" ]] || die "Archiwum jest puste: $archive"
[[ "$archive" == *.tar.gz ]] || die "Oczekiwano archiwum .tar.gz utworzonego przez deploy/backup.sh."
gzip -t "$archive" 2>/dev/null || die "Archiwum jest uszkodzone (błąd kompresji gzip)."

temp_dir=$(mktemp -d)
listing="$temp_dir/listing.txt"
# Archiwum czytane ze stdin: GNU tar traktuje "C:" w ścieżce jak zdalny host.
tar -tzvf - < "$archive" > "$listing" 2>/dev/null || die "Nie można odczytać listy plików archiwum (uszkodzony tar)."
tar -tzf - < "$archive" > "$temp_dir/names.txt" 2>/dev/null || die "Nie można odczytać listy plików archiwum."

# Dozwolone są wyłącznie zwykłe pliki i katalogi (bez dowiązań i urządzeń).
if awk '{ t = substr($1, 1, 1); if (t != "-" && t != "d") bad = 1 } END { exit bad ? 0 : 1 }' "$listing"; then
    die "Archiwum zawiera dowiązania lub pliki specjalne. Odtwarzanie przerwane."
fi
if grep -qE '(^/|^\.\./|/\.\./|/\.\.$|^\.\.$|\\)' "$temp_dir/names.txt"; then
    die "Archiwum zawiera niebezpieczne ścieżki (bezwzględne lub z ..). Odtwarzanie przerwane."
fi
top_dirs=$(cut -d/ -f1 "$temp_dir/names.txt" | sort -u)
[[ $(printf '%s\n' "$top_dirs" | wc -l | tr -d ' ') -eq 1 ]] || die "Archiwum musi zawierać dokładnie jeden katalog główny backup_*."
backup_name="$top_dirs"
[[ "$backup_name" =~ ^backup_[0-9]{8}_[0-9]{6}$ ]] || die "Nieoczekiwany katalog główny archiwum: $backup_name"

for required in database.sql manifest.json config/parameters.php images/p/; do
    grep -qxF "$backup_name/$required" "$temp_dir/names.txt" \
        || die "Archiwum jest niekompletne: brak $required."
done

log_info "Rozpakowywanie archiwum do katalogu tymczasowego..."
extract_root="$temp_dir/extract"
mkdir -p "$extract_root"
tar -xzf - -C "$extract_root" --no-same-owner < "$archive" 2>/dev/null || die "Rozpakowanie archiwum nie powiodło się (brak miejsca lub uszkodzone dane)."
bdir="$extract_root/$backup_name"

# Manifest: lista "<sha256>  <ścieżka>" z sekcji "files" (format backup.sh, Python i wariant Bash).
manifest="$bdir/manifest.json"
grep -q '"files"' "$manifest" || die "manifest.json nie zawiera sekcji files."
awk '
    /^  "files": \{/ { in_files = 1; next }
    in_files && /^  \}/ { in_files = 0 }
    in_files && /^    "/ {
        line = $0
        sub(/^    "/, "", line); sub(/": \{[[:space:]]*$/, "", line)
        path = line
        next
    }
    in_files && /"sha256":/ {
        line = $0
        sub(/.*"sha256": "/, "", line); sub(/".*/, "", line)
        if (path != "" && line ~ /^[0-9a-f]{64}$/) print line "  " path
        else { print "BAD " path > "/dev/stderr"; bad = 1 }
        path = ""
    }
    END { exit bad }
' "$manifest" > "$temp_dir/checksums.txt" || die "manifest.json ma niepoprawny format."

manifest_count=$(wc -l < "$temp_dir/checksums.txt" | tr -d ' ')
declared_total=$(grep -m1 -oE '"total_files": *[0-9]+' "$manifest" | grep -oE '[0-9]+$' || true)
declared_images=$(grep -m1 -oE '"images_count": *[0-9]+' "$manifest" | grep -oE '[0-9]+$' || true)
backup_ps_version=$(grep -m1 -oE '"prestashop_version": *"[^"]*"' "$manifest" | sed -E 's/.*"([^"]*)"$/\1/' || true)
[[ "$manifest_count" -gt 0 ]] || die "manifest.json nie zawiera listy plików."
[[ -z "$declared_total" || "$declared_total" == "$manifest_count" ]] \
    || die "Niezgodna liczba plików: manifest deklaruje $declared_total, lista zawiera $manifest_count."

# Pliki wymagane muszą figurować w manifeście
for required in database.sql config/parameters.php; do
    grep -qE "^[0-9a-f]{64}  ${required//./\\.}\$" "$temp_dir/checksums.txt" || die "Manifest nie obejmuje pliku $required."
done

# Porównanie listy plików: brakujące i nadmiarowe
(cd "$bdir" && find . -type f ! -name manifest.json | sed 's|^\./||' | sort) > "$temp_dir/actual.txt"
cut -c67- "$temp_dir/checksums.txt" | sort > "$temp_dir/expected.txt"
comm -23 "$temp_dir/expected.txt" "$temp_dir/actual.txt" > "$temp_dir/missing.txt"
missing=$(sed -n '1,5p' "$temp_dir/missing.txt")
[[ -z "$missing" ]] || die "Brak plików wymienionych w manifeście, np.: $(echo "$missing" | tr '\n' ' ')"
comm -13 "$temp_dir/expected.txt" "$temp_dir/actual.txt" > "$temp_dir/extra.txt"
extra=$(sed -n '1,5p' "$temp_dir/extra.txt")
[[ -z "$extra" ]] || die "Archiwum zawiera pliki spoza manifestu, np.: $(echo "$extra" | tr '\n' ' ')"

log_info "Weryfikacja sum SHA-256 ($manifest_count plików)..."
(cd "$bdir" && compute_sha256_check "$temp_dir/checksums.txt" > "$temp_dir/sha-errors.txt" 2>&1) || {
    sed -n '1,5p' "$temp_dir/sha-errors.txt" >&2
    die "Sumy kontrolne nie zgadzają się z manifestem. Archiwum jest uszkodzone."
}

# images/p trafia do katalogu publicznego sklepu: dozwolone wyłącznie obrazy, pliki fileType
# i standardowy index.php PrestaShop (przekierowanie), rozpoznawany po sumie SHA-256.
ps_index_sha="47a20a732cc9087e6a9a1a15f67c9b5fb03db7e3ce2a840c612538476f477bf9"
bad_images=$(grep '^images/p/' "$temp_dir/expected.txt" \
    | grep -viE '\.(jpe?g|png|gif|webp|avif)$|/fileType$|/index\.php$' | sed -n '1,5p' || true)
[[ -z "$bad_images" ]] || die "Niedozwolone pliki w images/p, np.: $(echo "$bad_images" | tr '\n' ' ')"
bad_index=$(grep -E '  images/p/(.*/)?index\.php$' "$temp_dir/checksums.txt" | grep -v "^$ps_index_sha " | sed -n '1,3p' | cut -c67- || true)
[[ -z "$bad_index" ]] || die "Niestandardowy index.php w images/p: $(echo "$bad_index" | tr '\n' ' ')"

images_count=$(grep -c '^images/p/' "$temp_dir/expected.txt" || true)
[[ -z "$declared_images" || "$declared_images" == "$images_count" ]] \
    || die "Niezgodna liczba zdjęć: manifest deklaruje $declared_images, lista zawiera $images_count."
[[ "$images_count" -gt 0 ]] || die "Archiwum nie zawiera zdjęć produktów (images/p jest pusty)."

# Zrzut SQL: kompletność, brak przełączania bazy, oczekiwany prefiks tabel
dump="$bdir/database.sql"
[[ -s "$dump" ]] || die "Zrzut bazy database.sql jest pusty."
tail -n 25 "$dump" | grep -q "Dump completed" || die "Zrzut bazy jest niekompletny (brak znacznika Dump completed)."
if grep -qiE '^(CREATE DATABASE|USE )' "$dump"; then
    die "Zrzut bazy zawiera CREATE DATABASE/USE. Obsługiwane są wyłącznie zrzuty z backup.sh."
fi
backup_prefix=$(grep -m1 -oE "'database_prefix' => '[^']*'" "$bdir/config/parameters.php" | sed -E "s/.*=> '([^']*)'/\1/" || true)
[[ "$backup_prefix" =~ ^[A-Za-z0-9_]+$ ]] || die "Nie można odczytać prefiksu tabel z config/parameters.php."
for table in configuration shop_url product; do
    grep -q "^CREATE TABLE \`${backup_prefix}${table}\`" "$dump" \
        || die "Zrzut bazy nie zawiera tabeli ${backup_prefix}${table}. To nie jest pełna kopia sklepu PrestaShop."
done
for key in cookie_key cookie_iv secret; do
    grep -qE "'$key' => '[^']+'" "$bdir/config/parameters.php" || die "config/parameters.php nie zawiera klucza $key."
done

log_success "Archiwum $backup_name jest kompletne i poprawne (PrestaShop ${backup_ps_version:-?}, $images_count zdjęć)."
if [[ "$verify_only" == "true" ]]; then
    restore_finished=true
    exit 0
fi

# ---------------------------------------------------------------------------
# 2. Środowisko Docker
# ---------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 || die "Zainstaluj i uruchom narzędzie Docker."
docker compose version >/dev/null 2>&1 || die "Narzędzie docker compose nie jest dostępne."
docker info >/dev/null 2>&1 || die "Demon Docker nie jest uruchomiony."
[[ -f "$compose_file" ]] || die "Brak pliku compose: $compose_file"

if [[ ! -f "$env_file" ]]; then
    if [[ "$use_backup_env" == "true" && -f "$bdir/config/.env" ]]; then
        cp "$bdir/config/.env" "$env_file"
        chmod 0600 "$env_file"
        log_warn "Utworzono $env_file na podstawie .env z kopii. Sprawdź TLS_CERT_DIR dla tego komputera."
    else
        die "Brak pliku $env_file. Utwórz go z .env.example albo użyj --use-backup-env."
    fi
fi

# Porównanie nazw zmiennych o różnych wartościach (bez wypisywania wartości)
if [[ -f "$bdir/config/.env" ]]; then
    diff_keys=$(awk -F= '
        /^[[:space:]]*#/ || !/=/ { next }
        { key = $1; sub(/^[[:space:]]+/, "", key); val = substr($0, index($0, "=") + 1); sub(/\r$/, "", val) }
        FNR == NR { a[key] = val; next }
        { if (!(key in a) || a[key] != val) d[key] = 1; seen[key] = 1 }
        END { for (k in a) if (!(k in seen)) d[k] = 1; for (k in d) printf "%s ", k }
    ' "$bdir/config/.env" "$env_file")
    if [[ -n "$diff_keys" ]]; then
        log_warn "Bieżący .env różni się od .env z kopii w zmiennych: $diff_keys"
        log_warn "Używane są wartości bieżącego środowiska (domena, dane dostępowe bazy)."
    fi
fi

compose=(docker compose --env-file "$(host_path "$env_file")" -f "$(host_path "$compose_file")")

# WSL z linuksowym klientem Dockera: TLS_CERT_DIR=C:/... z .env (przygotowanego pod Windows)
# nie jest dla niego ścieżką bezwzględną. Zmienna środowiskowa ma pierwszeństwo przed .env.
if grep -qi microsoft /proc/version 2>/dev/null && command -v wslpath >/dev/null 2>&1; then
    tls_dir=$(grep -m1 -E '^TLS_CERT_DIR=' "$env_file" | cut -d= -f2- | tr -d '\r"' || true)
    if [[ -z "${TLS_CERT_DIR:-}" && "$tls_dir" =~ ^[A-Za-z]:[/\\] ]]; then
        TLS_CERT_DIR=$(wslpath -u "$tls_dir")
        export TLS_CERT_DIR
        log_info "WSL: TLS_CERT_DIR przekazywany jako $TLS_CERT_DIR."
    fi
fi

# backup.sh korzysta zawsze z <repo>/.env i <repo>/deploy/compose.yaml
if [[ "$safety_backup" == "true" && ( "$env_file" != "$repo_dir/.env" || "$compose_file" != "$repo_dir/deploy/compose.yaml" ) ]]; then
    die "Kopia bezpieczeństwa (backup.sh) obsługuje tylko domyślne ENV_FILE i COMPOSE_FILE_PATH. Użyj domyślnych ścieżek albo --no-safety-backup."
fi

if [[ -z "$(dc ps --status running -q db 2>/dev/null || true)" || -z "$(dc ps --status running -q shop 2>/dev/null || true)" ]]; then
    log_info "Usługi db/shop nie działają. Uruchamianie środowiska (docker compose up -d)..."
    dc up -d --no-recreate || die "Nie udało się uruchomić środowiska. Sprawdź .env i certyfikaty (deploy/generate-cert.sh)."
fi

wait_for() {
    local description="$1" check="$2" deadline=$(( $(date +%s) + restore_timeout ))
    until eval "$check" >/dev/null 2>&1; do
        if [[ $(date +%s) -ge $deadline ]]; then
            die "Przekroczono limit ${restore_timeout}s oczekiwania: $description."
        fi
        sleep 5
    done
}

log_info "Oczekiwanie na gotowość bazy danych..."
wait_for "baza danych" 'dc exec -T db healthcheck.sh --connect --innodb_initialized'
log_info "Oczekiwanie na zakończenie instalacji PrestaShop w kontenerze shop..."
wait_for "instalacja PrestaShop" "shop_sh 'test ! -f /var/www/html/install.lock && test -f /var/www/html/app/config/parameters.php'"

live_db=$(dc exec -T db printenv MARIADB_DATABASE | tr -d '\r')
shop_domain=$(dc exec -T shop printenv PS_DOMAIN | tr -d '\r')
shop_prefix=$(dc exec -T shop printenv DB_PREFIX | tr -d '\r')
[[ "$live_db" =~ ^[A-Za-z0-9_]{1,40}$ ]] || die "Niepoprawna nazwa bazy danych: '$live_db'."
[[ "$shop_domain" =~ ^[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || die "Niepoprawna wartość PS_DOMAIN: '$shop_domain'."
[[ "$shop_prefix" == "$backup_prefix" ]] || die "Prefiks tabel kopii ($backup_prefix) różni się od środowiska ($shop_prefix)."
if grep -qF "\`$live_db\`." "$dump"; then
    die "Zrzut odwołuje się bezpośrednio do bazy $live_db. Obsługiwane są wyłącznie zrzuty z backup.sh."
fi
tmp_db="${live_db}_restore_tmp"
old_db="${live_db}_pre_restore"
p="$backup_prefix"

shop_version=$(shop_sh "grep -oE \"VERSION = '[^']+'\" /var/www/html/src/Core/Version.php | head -n 1 | cut -d\"'\" -f2" | tr -d '\r' || true)
if [[ -z "$backup_ps_version" || "$backup_ps_version" == "unknown" || -z "$shop_version" ]]; then
    log_warn "Nie ustalono wersji PrestaShop (kopia: ${backup_ps_version:-brak}, sklep: ${shop_version:-brak}). Pominięto porównanie wersji."
elif [[ "$backup_ps_version" != "$shop_version" ]]; then
    if [[ "$ignore_version" == "true" ]]; then
        log_warn "Wersja kopii ($backup_ps_version) różni się od sklepu ($shop_version). Kontynuacja (--ignore-version)."
    else
        die "Wersja kopii ($backup_ps_version) różni się od sklepu ($shop_version). Użyj --ignore-version, jeśli to zamierzone."
    fi
fi

# ---------------------------------------------------------------------------
# 3. Ochrona przed przypadkowym nadpisaniem
# ---------------------------------------------------------------------------
lock_dir="$backup_dir/.restore.lock"
mkdir -p "$backup_dir" 2>/dev/null && [[ -w "$backup_dir" ]] \
    || { lock_dir=""; die "Brak uprawnień do zapisu w katalogu kopii: $backup_dir"; }
if ! mkdir "$lock_dir" 2>/dev/null; then
    lock_dir=""
    die "Inne odtwarzanie jest w toku (blokada $backup_dir/.restore.lock). Jeśli nie, usuń ten katalog."
fi

current_tables=$(db_sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$live_db';" | tr -d '\r')
current_products=$(db_sql "$live_db" -e "SELECT COUNT(*) FROM \`${p}product\`;" 2>/dev/null | tr -d '\r' || echo 0)
current_images=$(shop_sh 'find /var/www/html/img/p -type f 2>/dev/null | wc -l' | tr -d ' \r' || echo 0)
leftovers=$(db_sql -e "SELECT GROUP_CONCAT(schema_name) FROM information_schema.schemata WHERE schema_name IN ('$tmp_db', '$old_db');" | tr -d '\r')
[[ -z "$leftovers" || "$leftovers" == "NULL" ]] \
    || die "Istnieją bazy po poprzednim odtwarzaniu ($leftovers). Sprawdź je i usuń przed ponowieniem."
leftover_files=$(shop_sh 'cd /var/www/html; for d in img/p.pre-restore var/restore-previous; do [ ! -e "$d" ] || printf "%s " "$d"; done' | tr -d '\r')
[[ -z "$leftover_files" ]] \
    || die "W kontenerze shop są dane po poprzednim odtwarzaniu: $leftover_files. Przenieś lub usuń je przed ponowieniem."

echo ""
echo "--------------------------------------------------------------------------------"
echo "  Archiwum:           $(basename "$archive")"
echo "  Sklep docelowy:     https://$shop_domain (baza $live_db)"
echo "  Obecny stan:        $current_tables tabel, $current_products produktów, $current_images plików img/p"
echo "  Kopia bezpieczeństwa: $([[ "$safety_backup" == "true" ]] && echo "tak, w $safety_dir" || echo "NIE (--no-safety-backup)")"
echo "--------------------------------------------------------------------------------"
if [[ "$current_tables" -gt 0 || "$current_images" -gt 0 ]]; then
    log_warn "Odtworzenie ZASTĄPI bieżącą bazę, zdjęcia produktów i konfigurację sklepu."
    if [[ "$assume_yes" != "true" ]]; then
        [[ -t 0 ]] || die "Brak terminala do potwierdzenia. Użyj --yes, aby świadomie nadpisać dane."
        read -r -p "Aby kontynuować, wpisz nazwę bazy ($live_db): " answer
        [[ "$answer" == "$live_db" ]] || die "Odtwarzanie anulowane przez użytkownika. Nic nie zmieniono."
    fi
fi

if [[ "$safety_backup" == "true" ]]; then
    log_info "Tworzenie kopii bezpieczeństwa bieżącego stanu (deploy/backup.sh)..."
    mkdir -p "$safety_dir"
    before=$(find "$safety_dir" -maxdepth 1 -name 'backup_*.tar.gz' | sort)
    bash "$script_dir/backup.sh" --output-dir "$safety_dir" > "$temp_dir/safety-backup.log" 2>&1 || {
        tail -n 15 "$temp_dir/safety-backup.log" >&2
        die "Kopia bezpieczeństwa nie powiodła się. Nic nie zmieniono. Jeśli to Git Bash na Windows, zob. BACKUP.md (ograniczenia); pominięcie: --no-safety-backup"
    }
    safety_archive=$(comm -13 <(printf '%s\n' "$before") <(find "$safety_dir" -maxdepth 1 -name 'backup_*.tar.gz' | sort) | tail -n 1)
    log_info "Kopia bezpieczeństwa: ${safety_archive:-zob. $safety_dir}"
fi

# ---------------------------------------------------------------------------
# 4. Baza danych: import do bazy tymczasowej i atomowa podmiana
# ---------------------------------------------------------------------------
log_info "Zatrzymywanie nginx na czas odtwarzania (sklep niedostępny dla klientów)..."
if [[ -n "$(dc ps --status running -q nginx 2>/dev/null || true)" ]]; then
    dc stop nginx >/dev/null
    nginx_stopped_by_us=true
fi

log_info "Import zrzutu bazy do bazy tymczasowej $tmp_db..."
db_import_started=true
db_sql -e "DROP DATABASE IF EXISTS \`$tmp_db\`; CREATE DATABASE \`$tmp_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
db_import "$tmp_db" < "$dump" || die "Import zrzutu bazy nie powiódł się. Bieżąca baza pozostała bez zmian."

restored_tables=$(db_sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$tmp_db';" | tr -d '\r')
dump_tables=$(grep -c '^CREATE TABLE ' "$dump" || true)
[[ "$restored_tables" == "$dump_tables" ]] \
    || die "Zaimportowano $restored_tables tabel, zrzut zawiera $dump_tables. Bieżąca baza pozostała bez zmian."
restored_products=$(db_sql "$tmp_db" -e "SELECT COUNT(*) FROM \`${p}product\`;" | tr -d '\r')
log_info "Zaimportowano $restored_tables tabel, $restored_products produktów."

log_info "Atomowa podmiana tabel bazy $live_db..."
# RENAME TABLE jest atomowe: przy błędzie bieżąca baza pozostaje nietknięta.
# Sygnał w tej sekcji jest odraczany, aby podmiana i flaga stanu były nierozdzielne.
pending_signal=""
trap 'pending_signal=130' INT
trap 'pending_signal=143' TERM
trap 'pending_signal=129' HUP
trap 'pending_signal=131' QUIT
if ! atomic_move_tables "$live_db" "$old_db" "$tmp_db"; then
    wait_rename_done || true
    if swap_happened; then
        db_swapped=true
        die "Połączenie z bazą przerwane po wykonaniu podmiany tabel."
    fi
    die "Podmiana tabel nie powiodła się. Bieżąca baza pozostała bez zmian."
fi
db_swapped=true
trap 'cleanup 130' INT
trap 'cleanup 143' TERM
trap 'cleanup 129' HUP
trap 'cleanup 131' QUIT
if [[ -n "$pending_signal" ]]; then
    log_warn "Przerwano przez użytkownika podczas podmiany bazy."
    exit "$pending_signal"
fi
db_sql -e "DROP DATABASE IF EXISTS \`$tmp_db\`;"

log_info "Ustawianie domeny sklepu na $shop_domain..."
db_sql "$live_db" << SQL
UPDATE \`${p}shop_url\` SET domain = '$shop_domain', domain_ssl = '$shop_domain';
UPDATE \`${p}configuration\` SET value = '$shop_domain' WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL');
SQL

# backup.sh --maintenance wyłącza sklep przed zrzutem, więc kopia może zawierać PS_SHOP_ENABLE=0.
shop_enabled=$(db_sql "$live_db" -e "SELECT value FROM \`${p}configuration\` WHERE name = 'PS_SHOP_ENABLE' LIMIT 1;" | tr -d '\r')
if [[ "$shop_enabled" == "0" ]]; then
    log_warn "Kopia zawiera wyłączony sklep (PS_SHOP_ENABLE=0, np. backup.sh --maintenance). Włączanie sklepu."
    db_sql "$live_db" -e "UPDATE \`${p}configuration\` SET value = '1' WHERE name = 'PS_SHOP_ENABLE';"
fi

# Klucz Webservice w kopii pochodzi z .env środowiska źródłowego. Narzędzia (scraper/API) używają
# PRESTASHOP_API_KEY z bieżącego .env, więc konto z kluczem z kopii dostaje klucz bieżący.
backup_api_key=$(grep -m1 -E '^PRESTASHOP_API_KEY=' "$bdir/config/.env" 2>/dev/null | cut -d= -f2- | tr -d '\r"' || true)
current_api_key=$(dc exec -T shop printenv PRESTASHOP_API_KEY 2>/dev/null | tr -d '\r' || true)
if [[ "$backup_api_key" =~ ^[A-Za-z0-9]{32}$ && "$current_api_key" =~ ^[A-Za-z0-9]{32}$ && "$backup_api_key" != "$current_api_key" ]]; then
    if db_sql "$live_db" -e "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${p}webservice_account';" | grep -q 1; then
        log_info "Ustawianie klucza Webservice z bieżącego .env (PRESTASHOP_API_KEY)..."
        db_sql "$live_db" -e "UPDATE \`${p}webservice_account\` SET \`key\` = '$current_api_key' WHERE \`key\` = '$backup_api_key';"
    fi
fi

# ---------------------------------------------------------------------------
# 5. Zdjęcia produktów
# ---------------------------------------------------------------------------
log_info "Przesyłanie zdjęć produktów do kontenera shop ($images_count plików)..."
images_upload_started=true
tar -C "$bdir/images" -cf - p | dc exec -T shop sh -c '
    set -e
    rm -rf /var/www/html/img/p.restore-new
    mkdir -p /var/www/html/img/p.restore-new
    tar -C /var/www/html/img/p.restore-new --strip-components=1 --no-same-owner -xf -'
uploaded=$(shop_sh 'find /var/www/html/img/p.restore-new -type f | wc -l' | tr -d ' \r')
[[ "$uploaded" == "$images_count" ]] || die "Przesłano $uploaded z $images_count zdjęć."

images_swapped=true
shop_sh 'set -e; cd /var/www/html/img
    rm -rf p.pre-restore
    if [ -d p ]; then mv p p.pre-restore; fi
    mv p.restore-new p
    chown -R www-data:www-data p'

# ---------------------------------------------------------------------------
# 6. Konfiguracja (klucze z kopii, dane dostępowe bazy z bieżącego środowiska)
# ---------------------------------------------------------------------------
log_info "Przywracanie plików konfiguracyjnych..."
# .env nie trafia do kontenera
tar -C "$bdir/config" --exclude=.env -cf - . | dc exec -T shop sh -c '
    set -e; rm -rf /tmp/restore-config; mkdir -p /tmp/restore-config
    tar -C /tmp/restore-config --no-same-owner -xf -'

config_swapped=true
shop_sh "set -e; cd /var/www/html; $config_files_sh"'
    rm -rf "$PREV"; mkdir -p "$PREV"; chmod 0700 "$PREV"
    for f in $CONFIG_FILES; do
        saved="$PREV/$(echo "$f" | tr / _)"
        if [ -f "$f" ]; then cp "$f" "$saved"; chown root:root "$saved"; chmod 0600 "$saved"; else : > "$saved.absent"; fi
    done'

# parameters.php: cookie_key, cookie_iv, secret i klucze API z kopii, połączenie z bazą z env kontenera.
# Plik z archiwum jest sprawdzany tokenizerem przed require: dozwolona wyłącznie literalna tablica.
dc exec -T shop php -r '
    $file = "/tmp/restore-config/parameters.php";
    $allowed = [T_OPEN_TAG, T_RETURN, T_ARRAY, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER,
                T_DOUBLE_ARROW, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    foreach (token_get_all(file_get_contents($file)) as $t) {
        if (is_string($t)) {
            if (!in_array($t, ["(", ")", "[", "]", ",", ";", "-"], true)) { fwrite(STDERR, "Niedozwolony element w parameters.php: $t\n"); exit(1); }
        } elseif ($t[0] === T_STRING) {
            if (!in_array(strtolower($t[1]), ["true", "false", "null"], true)) { fwrite(STDERR, "Niedozwolony identyfikator w parameters.php: $t[1]\n"); exit(1); }
        } elseif (!in_array($t[0], $allowed, true)) {
            fwrite(STDERR, "Niedozwolony element w parameters.php: " . token_name($t[0]) . "\n"); exit(1);
        }
    }
    $p = require $file;
    if (!is_array($p) || !isset($p["parameters"]) || !is_array($p["parameters"])) {
        fwrite(STDERR, "Niepoprawny parameters.php w kopii.\n"); exit(1);
    }
    $map = ["database_host" => "DB_SERVER", "database_name" => "DB_NAME", "database_user" => "DB_USER",
            "database_password" => "DB_PASSWD", "database_prefix" => "DB_PREFIX"];
    foreach ($map as $key => $env) {
        $value = getenv($env);
        if ($value === false || $value === "") { fwrite(STDERR, "Brak zmiennej $env w kontenerze shop.\n"); exit(1); }
        $p["parameters"][$key] = $value;
    }
    $target = "/var/www/html/app/config/parameters.php.restore-new";
    if (file_put_contents($target, "<?php return " . var_export($p, true) . ";\n") === false) exit(1);
'
shop_sh 'set -e; cd /var/www/html
    php -l app/config/parameters.php.restore-new >/dev/null
    mv -f app/config/parameters.php.restore-new app/config/parameters.php
    [ ! -f /tmp/restore-config/parameters.yml ] || cp /tmp/restore-config/parameters.yml app/config/parameters.yml
    [ ! -f /tmp/restore-config/defines_custom.inc.php ] || cp /tmp/restore-config/defines_custom.inc.php config/defines_custom.inc.php
    [ ! -f /tmp/restore-config/.htaccess ] || cp /tmp/restore-config/.htaccess .htaccess
    chown www-data:www-data app/config/parameters.php app/config/parameters.yml config/defines_custom.inc.php .htaccess 2>/dev/null || true
    rm -rf /tmp/restore-config'

# Motyw i moduły projektu nie są częścią kopii. Źródłem jest zbudowany obraz sklepu.
log_info "Synchronizacja motywu i modułów projektu z obrazu sklepu..."
shop_sh 'set -e; cd /var/www/html
    for src in /opt/shop-themes/*/; do [ -d "$src" ] || continue; n=$(basename "$src")
        mkdir -p "themes/$n"; cp -R "$src." "themes/$n/"; chown -R www-data:www-data "themes/$n"; done
    for src in /opt/shop-modules/*/; do [ -d "$src" ] || continue; n=$(basename "$src")
        mkdir -p "modules/$n"; cp -R "$src." "modules/$n/"; chown -R www-data:www-data "modules/$n"; done'

# ---------------------------------------------------------------------------
# 7. Cache, restart i kontrola działania
# ---------------------------------------------------------------------------
log_info "Czyszczenie pamięci podręcznej PrestaShop..."
clear_cache

dc exec -T --user www-data shop php -r '
    require "/var/www/html/config/config.inc.php";
    exit(Tools::generateHtaccess() ? 0 : 1);' >/dev/null 2>&1 \
    || log_warn "Nie udało się wygenerować .htaccess. Pozostawiono plik z kopii."

log_info "Restart kontenera shop..."
dc restart shop >/dev/null
wait_for "uruchomienie Apache w kontenerze shop" \
    "dc exec -T shop php -r 'exit(@fsockopen(\"127.0.0.1\", 80) ? 0 : 1);'"

# nginx jest potrzebny do kontroli HTTP także wtedy, gdy nie działał przed odtwarzaniem.
# start nie przebudowuje kontenera; up --no-recreate tylko gdy kontenera nie ma.
dc start nginx >/dev/null 2>&1 || dc up -d --no-deps --no-recreate nginx >/dev/null \
    || die "Nie udało się uruchomić nginx."
nginx_stopped_by_us=false

log_info "Kontrola poprawności odtworzenia..."
live_products=$(db_sql "$live_db" -e "SELECT COUNT(*) FROM \`${p}product\`;" | tr -d '\r')
[[ "$live_products" == "$restored_products" ]] || die "Liczba produktów po podmianie ($live_products) różni się od kopii ($restored_products)."
live_images=$(shop_sh 'find /var/www/html/img/p -type f | wc -l' | tr -d ' \r')
[[ "$live_images" == "$images_count" ]] || die "Liczba zdjęć po podmianie ($live_images) różni się od kopii ($images_count)."

shop_url="${SHOP_URL:-https://$shop_domain/}"

# Kontrola z wnętrza kontenera nginx. 127.0.0.1 zamiast localhost: w Alpine localhost rozwiązuje się
# najpierw na ::1, a nginx nasłuchuje tylko na IPv4. Nagłówek Host = domena sklepu, aby PrestaShop
# nie przekierował na inny adres. Wynik: ostatni kod HTTP z nagłówków lub 000 przy braku połączenia.
probe_nginx() {
    dc exec -T nginx wget -S -q --no-check-certificate --header "Host: $shop_domain" -O /dev/null "https://127.0.0.1/" 2>&1 \
        | awk '/^ *HTTP\// { code = $2 } END { print (code == "" ? "000" : code) }'
}

# Osobny, krótszy limit na kontrolę HTTP: zatrzymany sklep nie powinien czekać 15 minut na rollback.
http_timeout=$(( restore_timeout < 300 ? restore_timeout : 300 ))
http_code=""
deadline=$(( $(date +%s) + http_timeout ))
while :; do
    host_code=""
    if command -v curl >/dev/null 2>&1; then
        host_code=$(curl -k -s -o /dev/null -w '%{http_code}' --max-time 60 "$shop_url" || true)
    fi
    http_code="$host_code"
    if [[ "$host_code" != "200" ]]; then
        nginx_code=$(probe_nginx || true)
        if [[ "$nginx_code" == "200" && -n "$host_code" && "$host_code" != "000" ]]; then
            # Host dostaje odpowiedź inną niż 200 (np. 500): to błąd sklepu, nie sieci.
            http_code="$host_code"
        elif [[ "$nginx_code" == "200" ]]; then
            [[ -z "$host_code" ]] || log_warn "Brak połączenia z $shop_url z hosta, ale nginx w kontenerze zwraca HTTP 200. Sprawdź mapowanie portów."
            http_code=200
        elif [[ -z "$host_code" || "$host_code" == "000" ]]; then
            http_code="$nginx_code"
        fi
    fi
    [[ "$http_code" == "200" ]] && break
    [[ $(date +%s) -ge $deadline ]] && die "Sklep nie odpowiada poprawnie pod $shop_url (HTTP ${http_code:-000}) w ciągu ${http_timeout}s."
    sleep 5
done
log_info "Sklep odpowiada pod $shop_url (HTTP 200)."

restore_finished=true

if [[ "$keep_previous" == "true" ]]; then
    log_info "Poprzednie dane zachowano: baza $old_db, img/p.pre-restore, var/restore-previous/."
    log_info "Kolejne odtwarzanie wymaga wcześniejszego usunięcia bazy $old_db."
else
    db_sql -e "DROP DATABASE IF EXISTS \`$old_db\`;" || log_warn "Nie usunięto bazy $old_db."
    shop_sh 'cd /var/www/html && rm -rf img/p.pre-restore var/restore-previous' \
        || log_warn "Nie usunięto img/p.pre-restore lub var/restore-previous."
fi

duration=$(( $(date +%s) - start_time ))
echo ""
log_success "Sklep został odtworzony z kopii zapasowej!"
echo "--------------------------------------------------------------------------------"
echo "  Archiwum:           $(basename "$archive")"
echo "  Wersja PrestaShop:  ${backup_ps_version:-?}"
echo "  Baza danych:        $live_db ($restored_tables tabel, $live_products produktów)"
echo "  Zdjęcia produktów:  $live_images plików"
echo "  Adres sklepu:       $shop_url"
[[ -n "${safety_archive:-}" ]] && echo "  Kopia sprzed odtw.: $safety_archive"
echo "  Czas wykonania:     ${duration}s"
echo "--------------------------------------------------------------------------------"
exit 0
