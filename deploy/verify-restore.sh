#!/usr/bin/env bash
# Weryfikacja odtwarzania sklepu z kopii zapasowej na czystym, odizolowanym środowisku.
# Wywołanie z katalogu repozytorium lub dowolnego miejsca:
#   bash deploy/verify-restore.sh [OPCJE]
#   bash deploy/verify-restore.sh --cleanup
#
# Przebieg:
#   1. Kopia zapasowa działającego sklepu (deploy/backup.sh) albo archiwum wskazane przez --archive.
#   2. Odcisk danych sklepu źródłowego: liczby obiektów, sumy CHECKSUM TABLE, zdjęcia, eksport konfiguracji.
#   3. Czyste środowisko: osobny projekt Compose, nowe wolumeny, porty, hasła, certyfikat i klucz API.
#   4. Odtworzenie skryptem deploy/restore.sh z pomiarem czasu (limit domyślnie 600 s).
#   5. Porównanie odtworzonych danych, zdjęć i konfiguracji ze źródłem.
#   6. Kontrola funkcjonalności (deploy/verify/restore-smoke.php): strony, zdjęcia, promocje, warianty,
#      wyszukiwarka, koszyk, pierwszy krok zamówienia, panel i API.
#   7. Raport Markdown (domyślnie docs/RESTORE_VERIFICATION.md) i log w deploy/backups/verify/.
#
# Środowisko weryfikacyjne nie współdzieli z głównym sklepem wolumenów, sieci, portów, haseł, certyfikatu
# ani konfiguracji nginx. Wspólny jest tylko obraz prestashop-website:8.2.8 (budowany, gdy go brak)
# i katalog scraper-results, którego wymaga usługa certificates.
# Po weryfikacji zostaje uruchomione do pokazania (https://localhost:19443); usuwa je --cleanup.
set -Eeuo pipefail
umask 077

# Zmienne powłoki mają w Compose pierwszeństwo przed --env-file. Wyeksportowana nazwa projektu,
# wolumenów lub portów mogłaby skierować odtwarzanie na sklep źródłowy, więc są usuwane.
unset COMPOSE_PROJECT_NAME COMPOSE_FILE COMPOSE_PROFILES DB_VOLUME_NAME SHOP_VOLUME_NAME PS_DOMAIN PS_ENABLE_SSL \
    HTTP_PORT HTTPS_PORT MAILPIT_PORT NGINX_CONFIG TLS_CERT_DIR SCRAPER_RESULTS_DIR DB_NAME DB_USER DB_PASSWORD \
    DB_ROOT_PASSWORD ADMIN_EMAIL ADMIN_PASSWORD PRESTASHOP_API_KEY SHOP_URL BACKUP_DIR COMPOSE_FILE_PATH

export MSYS_NO_PATHCONV=1
export LC_ALL=C

if [[ -t 1 ]]; then
    C_INFO="\033[1;34m"; C_OK="\033[1;32m"; C_WARN="\033[1;33m"; C_ERR="\033[1;31m"; C_RESET="\033[0m"
else
    C_INFO=""; C_OK=""; C_WARN=""; C_ERR=""; C_RESET=""
fi
log_info() { echo -e "${C_INFO}[INFO]${C_RESET} $*"; }
log_ok() { echo -e "${C_OK}[OK]${C_RESET} $*"; }
log_warn() { echo -e "${C_WARN}[WARN]${C_RESET} $*" >&2; }
log_error() { echo -e "${C_ERR}[ERROR]${C_RESET} $*" >&2; }
die() { log_error "$*"; exit 1; }

print_help() {
    cat << 'EOF'
Użycie: deploy/verify-restore.sh [OPCJE]

Tworzy kopię zapasową działającego sklepu, odtwarza ją na czystym, odizolowanym
środowisku Docker (osobny projekt Compose, wolumeny, porty i hasła), mierzy czas
odtwarzania, porównuje dane, zdjęcia i konfigurację ze źródłem, sprawdza podstawowe
funkcjonalności sklepu i zapisuje raport.

Opcje:
  --archive <PLIK>      Odtwarza wskazane archiwum zamiast tworzyć nową kopię.
                        Porównanie ze sklepem źródłowym jest wtedy pomijane, bo sklep
                        mógł zmienić się po wykonaniu kopii.
  --recreate            Usuwa istniejące środowisko weryfikacyjne przed startem
                        (bez tej opcji skrypt odmawia pracy na nieczystym środowisku)
  --cleanup             Tylko usuwa środowisko weryfikacyjne (kontenery, wolumeny, verify.env,
                        certyfikat i archiwum weryfikacji) i kończy
  --down-after          Usuwa środowisko weryfikacyjne po udanej weryfikacji
  --report <PLIK>       Ścieżka raportu (domyślnie: docs/RESTORE_VERIFICATION.md)
  -h, --help            Wyświetla tę pomoc

Zmienne środowiskowe:
  VERIFY_PROJECT        Nazwa projektu Compose (domyślnie: prestashop-restore-verify)
  VERIFY_HTTPS_PORT     Port HTTPS (domyślnie: 19443), także VERIFY_HTTP_PORT (19080)
                        i VERIFY_MAILPIT_PORT (19025)
  RESTORE_TIME_LIMIT    Limit czasu odtwarzania w sekundach (domyślnie: 600)

Przykłady:
  bash deploy/verify-restore.sh
  bash deploy/verify-restore.sh --recreate --down-after
  bash deploy/verify-restore.sh --archive deploy/backups/backup_20261120_085522.tar.gz
  bash deploy/verify-restore.sh --cleanup
EOF
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "$script_dir/.." && pwd)"

archive=""
recreate=false
cleanup_only=false
down_after=false
report_file="$repo_dir/docs/RESTORE_VERIFICATION.md"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            [[ $# -ge 2 ]] || die "Opcja --archive wymaga ścieżki archiwum."
            archive="$2"; shift 2 ;;
        --recreate) recreate=true; shift ;;
        --cleanup) cleanup_only=true; shift ;;
        --down-after) down_after=true; shift ;;
        --report)
            [[ $# -ge 2 ]] || die "Opcja --report wymaga ścieżki pliku."
            report_file="$2"; shift 2 ;;
        -h|--help) print_help; exit 0 ;;
        *) log_error "Nieznana opcja: $1"; print_help >&2; exit 1 ;;
    esac
done

# Ścieżki: POSIX dla Basha, natywne dla docker.exe (Git Bash: C:/...).
abs_path() {
    local path="$1"
    if [[ "$path" =~ ^[A-Za-z]:[/\\] ]] && command -v cygpath >/dev/null 2>&1; then
        path=$(cygpath -u "$path")
    fi
    if [[ "$path" == /* ]]; then echo "$path"; else echo "$PWD/$path"; fi
}
host_path() {
    if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi
}

[[ -z "$archive" ]] || archive="$(abs_path "$archive")"
report_file="$(abs_path "$report_file")"
# backup.sh zawsze kopiuje sklep z <repo>/.env, więc odcisk musi pochodzić z tego samego sklepu.
src_env="$repo_dir/.env"
[[ -z "${ENV_FILE:-}" || "$(abs_path "$ENV_FILE")" == "$src_env" ]] \
    || die "ENV_FILE nie jest obsługiwany: backup.sh kopiuje zawsze sklep z $src_env."
unset ENV_FILE
compose_file="$repo_dir/deploy/compose.yaml"
project="${VERIFY_PROJECT:-prestashop-restore-verify}"
https_port="${VERIFY_HTTPS_PORT:-19443}"
http_port="${VERIFY_HTTP_PORT:-19080}"
mailpit_port="${VERIFY_MAILPIT_PORT:-19025}"
time_limit="${RESTORE_TIME_LIMIT:-600}"
[[ "$project" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "VERIFY_PROJECT może zawierać tylko małe litery, cyfry, - i _."
for value in "$https_port" "$http_port" "$mailpit_port" "$time_limit"; do
    [[ "$value" =~ ^[0-9]+$ ]] || die "Porty i RESTORE_TIME_LIMIT muszą być liczbami."
done

src_project=$(grep -m1 -E '^COMPOSE_PROJECT_NAME=' "$src_env" 2>/dev/null | cut -d= -f2- | tr -d '\r"' || true)
[[ "$project" != "${src_project:-prestashop-website}" ]] \
    || die "VERIFY_PROJECT nie może być równy projektowi sklepu źródłowego ($project)."

work_dir="$repo_dir/deploy/backups/verify"
verify_env="$work_dir/verify.env"
log_file="$work_dir/verify-$(date +%Y%m%d_%H%M%S).log"
mkdir -p "$work_dir/archive" "$work_dir/certs"
chmod 0700 "$work_dir"
exec > >(tee -a "$log_file") 2>&1

command -v docker >/dev/null 2>&1 || die "Zainstaluj i uruchom Docker."
docker compose version >/dev/null 2>&1 || die "Narzędzie docker compose nie jest dostępne."
docker info >/dev/null 2>&1 || die "Demon Docker nie jest uruchomiony."

src=(docker compose -p "${src_project:-prestashop-website}" --env-file "$(host_path "$src_env")" -f "$(host_path "$compose_file")")
dst=(docker compose -p "$project" --env-file "$(host_path "$verify_env")" -f "$(host_path "$compose_file")")
dst_down=(docker compose -p "$project" -f "$(host_path "$compose_file")")

verify_down() {
    # down nie potrzebuje sekretów; puste wartości wymagane przez compose.yaml zastępują atrapy.
    if [[ -f "$verify_env" ]]; then
        "${dst[@]}" --profile init down -v --remove-orphans
    else
        DB_NAME=x DB_USER=x DB_PASSWORD=x DB_ROOT_PASSWORD=x ADMIN_EMAIL=x ADMIN_PASSWORD=x PS_DOMAIN=localhost \
            PRESTASHOP_API_KEY=x TLS_CERT_DIR="$(host_path "$work_dir/certs")" COMPOSE_PROJECT_NAME="$project" \
            "${dst_down[@]}" --profile init down -v --remove-orphans
    fi
}

if [[ "$cleanup_only" == "true" ]]; then
    log_info "Usuwanie środowiska weryfikacyjnego $project (kontenery, sieć, wolumeny)..."
    verify_down
    rm -f "$verify_env"
    rm -rf "$work_dir/certs" "$work_dir/archive" "$work_dir/nginx.conf"
    log_ok "Środowisko $project zostało usunięte. Sklep źródłowy nie był zmieniany."
    exit 0
fi

# --- Wyniki kontroli do raportu ------------------------------------------------------------------
results=()        # "STATUS|nazwa|szczegóły"
overall=PASS
record() {
    results+=("$1|$2|$3")
    case "$1" in
        PASS) log_ok "$2: $3" ;;
        SKIP) log_warn "$2: pominięto ($3)" ;;
        INFO) log_info "$2: $3" ;;
        *) overall=FAIL; log_error "$2: $3" ;;
    esac
}

stage_names=()
stage_secs=()
stage() { stage_names+=("$1"); stage_secs+=("$2"); }

# SQL jako root bazy w kontenerze db wybranego środowiska; zapytanie przez stdin.
sql() {
    local target="$1"; shift
    local -a cmd
    if [[ "$target" == "src" ]]; then cmd=("${src[@]}"); else cmd=("${dst[@]}"); fi
    printf '%s\n' "$*" | "${cmd[@]}" exec -T db sh -c \
        'exec env MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot --default-character-set=utf8mb4 -N -B "$MARIADB_DATABASE"' \
        | tr -d '\r'
}

shop_exec() {
    local target="$1"; shift
    if [[ "$target" == "src" ]]; then "${src[@]}" exec -T "$@"; else "${dst[@]}" exec -T "$@"; fi
}

# Liczby obiektów porównywane między źródłem a kopią i opisywane w raporcie.
metric_labels=(
    "Kategorie"
    "Kategorie aktywne"
    "Produkty"
    "Produkty aktywne"
    "Powiązania produkt–kategoria"
    "Kombinacje (warianty)"
    "Atrybuty wariantów"
    "Cechy produktów"
    "Zdjęcia w bazie"
    "Ceny promocyjne (specific_price)"
    "Reguły cen katalogowych"
    "Reguły koszyka"
    "Stany magazynowe (suma)"
    "Przewoźnicy aktywni"
    "Moduły aktywne"
    "Strony CMS"
    "Widoki bazy"
    "Klienci"
    "Zamówienia"
)
metric_sql=(
    "SELECT COUNT(*) FROM ps_category"
    "SELECT COUNT(*) FROM ps_category WHERE active = 1"
    "SELECT COUNT(*) FROM ps_product"
    "SELECT COUNT(*) FROM ps_product_shop WHERE active = 1 AND id_shop = 1"
    "SELECT COUNT(*) FROM ps_category_product"
    "SELECT COUNT(*) FROM ps_product_attribute"
    "SELECT COUNT(*) FROM ps_attribute"
    "SELECT COUNT(*) FROM ps_feature_product"
    "SELECT COUNT(*) FROM ps_image"
    "SELECT COUNT(*) FROM ps_specific_price"
    "SELECT COUNT(*) FROM ps_specific_price_rule"
    "SELECT COUNT(*) FROM ps_cart_rule"
    "SELECT COALESCE(SUM(quantity), 0) FROM ps_stock_available"
    "SELECT COUNT(*) FROM ps_carrier WHERE deleted = 0 AND active = 1"
    "SELECT COUNT(*) FROM ps_module WHERE active = 1"
    "SELECT COUNT(*) FROM ps_cms"
    "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
    "SELECT COUNT(*) FROM ps_customer"
    "SELECT COUNT(*) FROM ps_orders"
)

collect_metrics() {
    local target="$1" out="$2" query="" i
    for i in "${!metric_sql[@]}"; do query+="${metric_sql[$i]};"$'\n'; done
    sql "$target" "$query" > "$out"
    [[ $(wc -l < "$out" | tr -d ' ') -eq ${#metric_sql[@]} ]] || die "Nie udało się odczytać liczników z bazy ($target)."
}

# Tabele z danymi sklepu, które muszą być identyczne po odtworzeniu (CHECKSUM TABLE).
critical_tables="category category_lang category_shop category_group category_product product product_lang product_shop
product_attribute product_attribute_shop product_attribute_combination product_attribute_image attribute attribute_lang
attribute_group attribute_group_lang feature feature_lang feature_product feature_value feature_value_lang image image_lang
image_shop stock_available specific_price specific_price_rule cart_rule cart_rule_lang carrier carrier_lang delivery
range_price range_weight tax tax_rule tax_rules_group module module_shop hook_module cms meta_lang lang currency
country customer address orders order_detail order_invoice employee"
# Tabele zmieniane celowo przez restore.sh lub przez sam ruch w sklepie (raportowane, bez błędu).
# cms_lang, configuration_lang i psreassurance: restore.sh przepisuje w nich odnośniki na domenę środowiska;
# treść stron CMS porównuje dodatkowo eksport konfiguracji (adresy zastąpione znacznikami).
expected_diff_tables="configuration configuration_lang cms_lang psreassurance shop_url webservice_account connections connections_source connections_page guest log
cart cart_product page_viewed date_range statssearch smarty_cache smarty_lazy_cache"

collect_checksums() {
    local target="$1" out="$2" tables
    tables=$(sql "$target" "SELECT GROUP_CONCAT(CONCAT('\`', table_name, '\`') ORDER BY table_name) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';")
    [[ -n "$tables" && "$tables" != "NULL" ]] || die "Brak tabel w bazie ($target)."
    sql "$target" "CHECKSUM TABLE $tables EXTENDED;" | awk -F'\t' '{ sub(/^[^.]*\./, "", $1); print $1 "\t" $2 }' | sort > "$out"
}

# Suma kontrolna listy zdjęć img/p (ścieżki i zawartość).
collect_images() {
    local target="$1"
    shop_exec "$target" shop sh -c 'cd /var/www/html/img/p && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum | cut -c1-32; find . -type f | wc -l' | tr -d '\r '
}

collect_config_export() {
    local target="$1" out="$2"
    shop_exec "$target" --user www-data shop php /opt/shop/init/export-config.php > "$out" 2> "$out.err"
}

# Wybrane ustawienia sklepu porównywane jawnie w raporcie.
config_keys="PS_SHOP_NAME PS_LANG_DEFAULT PS_CURRENCY_DEFAULT PS_COUNTRY_DEFAULT PS_TIMEZONE PS_SSL_ENABLED
PS_SSL_ENABLED_EVERYWHERE PS_REWRITING_SETTINGS PS_TAX PS_TAX_DISPLAY PS_ORDER_PROCESS_TYPE PS_GUEST_CHECKOUT_ENABLED
PS_INVOICE PS_INVOICE_PREFIX PS_STOCK_MANAGEMENT PS_ORDER_OUT_OF_STOCK PS_MAIL_METHOD PS_WEBSERVICE"
collect_config_keys() {
    local target="$1" out="$2" in_list=""
    for key in $config_keys; do in_list+="'$key',"; done
    sql "$target" "SELECT name, COALESCE(value, 'NULL') FROM ps_configuration WHERE name IN (${in_list%,}) AND id_shop IS NULL AND id_shop_group IS NULL ORDER BY name;" > "$out"
}
# Wszystkie wartości konfiguracji do listy różnic (bez kluczy zmienianych przez restore.sh).
collect_config_all() {
    local target="$1" out="$2"
    sql "$target" "SELECT CONCAT_WS('=', name, IFNULL(id_shop, '-'), MD5(IFNULL(value, '<NULL>'))) FROM ps_configuration WHERE name NOT IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL', 'PS_SHOP_ENABLE', 'PS_CCCJS_VERSION', 'PS_CCCCSS_VERSION') ORDER BY name, id_shop;" | sort > "$out"
}

# Tekst błędu do jednego wiersza tabeli Markdown (bez nowych linii i separatora |).
one_line() { head -c 300 "$1" 2>/dev/null | tr '\n\r|' '  /' || true; }

# Szczegóły warunku etapu Webservice (configure-webservice.php), gdy eksport konfiguracji go odrzuca.
diagnose_init_state() {
    local out
    out=$(shop_exec dst --user www-data shop php -r '
        require "/opt/shop/init/lib/initialization-state.php";
        ShopInit\boot();
        $key = (string) getenv("PRESTASHOP_API_KEY");
        $db = Db::getInstance();
        $id = (int) $db->getValue("SELECT id_webservice_account FROM " . _DB_PREFIX_ . "webservice_account WHERE `key` = \"" . pSQL($key) . "\" AND active = 1");
        $perms = $id ? (int) $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "webservice_permission WHERE id_webservice_account = $id") : 0;
        $expected = 0; foreach (ShopInit\webservicePermissions() as $m) $expected += count(array_filter($m));
        $shops = $id ? (int) $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "webservice_account_shop WHERE id_webservice_account = $id") : 0;
        $ht = _PS_ROOT_DIR_ . "/.htaccess";
        echo "konto API z kluczem z .env: " . ($id ?: "brak") . "; uprawnienia $perms/$expected; sklepy $shops; PS_WEBSERVICE=" . Configuration::get("PS_WEBSERVICE")
            . "; CGI_HOST=" . (int) Configuration::get("PS_WEBSERVICE_CGI_HOST") . "; .htaccess czytelny: " . (is_readable($ht) ? "tak" : "nie")
            . "; blok tożsamości API zgodny: " . (str_contains((string) @file_get_contents($ht), ShopInit\webserviceIdentityBlock()) ? "tak" : "nie")
            . "; blok obecny: " . (str_contains((string) @file_get_contents($ht), "catalog-import-identity") ? "tak" : "nie");
    ' 2>&1 | tr '\n|' ' /' | cut -c1-600) || true
    record INFO "Diagnostyka etapu Webservice" "${out:-brak danych}"
}

fmt_secs() { printf '%d min %02d s' $(( $1 / 60 )) $(( $1 % 60 )); }

# Raport Markdown; wywoływany na końcu i przez pułapkę EXIT po przerwaniu.
write_report() {
    local total_secs host_desc docker_desc r st name detail icon i
    total_secs=$(( $(date +%s) - total_start ))
    host_desc="$(uname -s)"
    grep -qi microsoft /proc/version 2>/dev/null && host_desc="WSL ($host_desc)"
    docker_desc=$(docker version --format '{{.Server.Version}}' 2>/dev/null | tr -d '\r\n' || true)
    docker_desc="${docker_desc:-?}"

    {
        echo "# Weryfikacja odtwarzania sklepu z kopii zapasowej"
        echo ""
        echo "Raport wygenerowany przez \`deploy/verify-restore.sh\`. Instrukcję tworzenia i przywracania kopii opisuje [dokumentacja backupu](BACKUP.md)."
        echo ""
        echo "## Wynik: $( [[ "$overall" == "PASS" ]] && echo "✅ POZYTYWNY" || echo "❌ NEGATYWNY")"
        echo ""
        echo "| Parametr | Wartość |"
        echo "|---|---|"
        echo "| Data | $(date '+%Y-%m-%d %H:%M:%S %z') |"
        echo "| Wersja repozytorium | \`$git_branch\` @ \`$git_rev\` |"
        echo "| Archiwum | ${archive:+\`$(basename "$archive")\`, }$(( archive_size / 1048576 )) MiB |"
        echo "| SHA-256 archiwum | \`$archive_sha\` |"
        echo "| Źródło danych do porównania | $( [[ "$compare_source" == "true" ]] && echo "działający sklep, odczyt zaraz po kopii" || echo "brak (użyto --archive); kontrola manifestu i \`shop-config.json\`") |"
        echo "| Środowisko docelowe | projekt Compose \`$project\`: nowe wolumeny, sieć, hasła, certyfikat i konfiguracja nginx, \`https://localhost:$https_port\`; wspólne ze źródłem są tylko obraz \`prestashop-website:8.2.8\` i katalog \`scraper-results\` (wymagany przez usługę \`certificates\`) |"
        echo "| Host | $host_desc, Docker $docker_desc |"
        if [[ -n "$restore_secs" ]]; then
            echo "| **Czas odtwarzania** | **$(fmt_secs "$restore_secs")** (limit $(fmt_secs "$time_limit")) |"
            echo "| Czas od zera (przygotowanie obrazów i środowiska + odtworzenie) | $(fmt_secs $(( prep_secs + restore_secs ))) |"
        else
            echo "| **Czas odtwarzania** | nie zmierzono (weryfikacja przerwana przed odtworzeniem) |"
        fi
        echo ""
        echo "## Czas etapów"
        echo ""
        echo "| Etap | Czas |"
        echo "|---|---|"
        if [[ ${#stage_names[@]} -gt 0 ]]; then
            for i in "${!stage_names[@]}"; do echo "| ${stage_names[$i]} | $(fmt_secs "${stage_secs[$i]}") |"; done
        fi
        echo "| Cała weryfikacja | $(fmt_secs "$total_secs") |"
        echo ""
        echo "Czas odtwarzania mierzony jest od uruchomienia \`restore.sh\` na pustym środowisku (bez kontenerów i wolumenów) do odpowiedzi HTTP 200 sklepu. Obejmuje start usług, instalację pustego PrestaShop, walidację archiwum, import bazy, zdjęć i konfiguracji. Nie obejmuje budowy lub pobrania obrazów Docker, które należą do przygotowania środowiska; łączny czas od zera podaje tabela powyżej."
        echo ""
        echo "## Kontrole"
        echo ""
        echo "| Wynik | Kontrola | Szczegóły |"
        echo "|---|---|---|"
        for r in "${results[@]}"; do
            IFS='|' read -r st name detail <<< "$r"
            case "$st" in PASS) icon="✅";; SKIP) icon="⏭️";; INFO) icon="ℹ️";; *) icon="❌";; esac
            echo "| $icon $st | $name | $detail |"
        done
        if [[ -n "$metrics_table" ]]; then
            echo ""
            echo "## Kompletność danych"
            echo ""
            echo "| Obiekt | Źródło | Po odtworzeniu | Zgodność |"
            echo "|---|---:|---:|---|"
            printf '%s' "$metrics_table"
            echo ""
            echo "Zdjęcia produktów w \`img/p\`: $(sed -n 2p "$work_dir/dst.images" 2>/dev/null) plików (manifest archiwum: ${manifest_images:-?})."
        fi
        if [[ -n "$checksum_summary" ]]; then
            echo ""
            echo "## Zawartość bazy"
            echo ""
            printf '%s' "$checksum_summary"
        fi
        if [[ -n "$config_table" ]]; then
            echo ""
            echo "## Konfiguracja (wybrane ustawienia)"
            echo ""
            echo "| Klucz | Źródło | Po odtworzeniu | Zgodność |"
            echo "|---|---|---|---|"
            printf '%s' "$config_table"
        fi
        echo ""
        echo "## Powtórzenie weryfikacji"
        echo ""
        echo '```sh'
        echo "bash deploy/verify-restore.sh --recreate"
        echo "bash deploy/verify-restore.sh --cleanup   # usunięcie środowiska weryfikacyjnego"
        echo '```'
    } > "$report_file.tmp"
    mv "$report_file.tmp" "$report_file"
    chmod 0644 "$report_file"
}

# =================================================================================================
total_start=$(date +%s)
log_info "Log weryfikacji: $log_file"
git_rev=$(git -C "$repo_dir" rev-parse --short HEAD 2>/dev/null || echo "?")
git_branch=$(git -C "$repo_dir" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "?")
compare_source=true
[[ -z "$archive" ]] || compare_source=false
archive_size=0
archive_sha="?"
restore_secs=""
prep_secs=0
manifest_images="?"
metrics_table=""
checksum_summary=""
config_table=""
smoke_lines=()
report_written=false

# Czystość środowiska sprawdzana przed kilkuminutową kopią: bez --recreate nic nie jest usuwane.
existing=$(docker ps -aq --filter "label=com.docker.compose.project=$project"; \
    docker volume ls -q --filter "label=com.docker.compose.project=$project"; \
    docker volume ls -q --filter "name=^${project}_")
if [[ -n "$existing" && "$recreate" != "true" ]]; then
    die "Środowisko $project już istnieje (kontenery lub wolumeny). Użyj --recreate albo --cleanup."
fi
for port in "$http_port" "$https_port" "$mailpit_port"; do
    busy=$(docker ps --filter "publish=$port" --format '{{.Names}}' | grep -v "^${project}[-_]" || true)
    [[ -z "$busy" ]] || die "Port $port jest zajęty przez kontener $busy. Ustaw inny VERIFY_*_PORT."
done

# Raport powstaje także po przerwaniu (die, błąd polecenia, Ctrl+C) w dowolnym etapie.
on_exit() {
    local rc=$?
    trap - EXIT
    if [[ "$report_written" != "true" ]]; then
        record FAIL "Przerwanie weryfikacji" "skrypt zakończył się kodem $rc przed końcem kontroli; zob. log $(basename "$log_file")"
        write_report || true
    fi
    exit "$rc"
}

# --- 1. Kopia zapasowa ---------------------------------------------------------------------------
if [[ -z "$archive" ]]; then
    [[ -f "$src_env" ]] || die "Brak $src_env sklepu źródłowego."
    [[ -n "$("${src[@]}" ps --status running -q db 2>/dev/null || true)" && -n "$("${src[@]}" ps --status running -q shop 2>/dev/null || true)" ]] \
        || die "Sklep źródłowy nie działa. Uruchom go albo wskaż gotowe archiwum (--archive)."
    src_containers=$("${src[@]}" ps -q | sort)
    trap on_exit EXIT
    # Archiwa poprzednich weryfikacji zawierają .env i klucze źródła: zostaje tylko nowe.
    find "$work_dir/archive" -maxdepth 1 -name 'backup_*.tar.gz' -exec rm -f {} + 2>/dev/null || true
    log_info "[1/7] Odcisk sklepu źródłowego przed kopią..."
    collect_checksums src "$work_dir/src.checksums.before"
    log_info "[1/7] Kopia zapasowa sklepu źródłowego (deploy/backup.sh)..."
    t=$(date +%s)
    bash "$script_dir/backup.sh" --output-dir "$work_dir/archive" || die "Kopia zapasowa nie powiodła się."
    archive=$(find "$work_dir/archive" -maxdepth 1 -name 'backup_*.tar.gz' | sort | tail -n 1)
    [[ -n "$archive" ]] || die "Nie znaleziono archiwum utworzonego przez backup.sh."
    stage "Kopia zapasowa (backup.sh)" $(( $(date +%s) - t ))

    # Odcisk źródła zaraz po kopii; sklep źródłowy jest tylko czytany.
    log_info "[2/7] Odcisk danych sklepu źródłowego..."
    collect_metrics src "$work_dir/src.metrics"
    collect_checksums src "$work_dir/src.checksums"
    collect_images src > "$work_dir/src.images"
    collect_config_keys src "$work_dir/src.config"
    collect_config_all src "$work_dir/src.config-all"
    collect_config_export src "$work_dir/src.config-export.json" || log_warn "Eksport konfiguracji źródła nie powiódł się: $(one_line "$work_dir/src.config-export.json.err")"
    # Zmiana w sklepie w trakcie kopii (zamówienie, edycja w panelu) dałaby fałszywe różnice.
    source_changed=$(comm -3 "$work_dir/src.checksums.before" "$work_dir/src.checksums" | awk -F'\t' '{ t = ($1 == "" ? $2 : $1); sub(/^ps_/, "", t); print t }' | sort -u | tr '\n' ' ')
    [[ -z "$source_changed" ]] || log_warn "Sklep źródłowy zmienił się w trakcie kopii (tabele: $source_changed). Różnice w tych tabelach mogą być fałszywe."
else
    [[ -f "$archive" ]] || die "Archiwum nie istnieje: $archive"
    trap on_exit EXIT
    log_warn "[1-2/7] Użyto gotowego archiwum: porównanie ze sklepem źródłowym zostanie pominięte."
fi
archive_size=$(wc -c < "$archive" | tr -d ' ')
archive_sha=$( (sha256sum "$archive" 2>/dev/null || shasum -a 256 "$archive") | awk '{print $1}')
# Manifest czytany do końca strumienia (grep -m1 przerwałby tar sygnałem SIGPIPE).
archive_root=$(tar -tzf - < "$archive" 2>/dev/null | awk -F/ 'NR == 1 { print $1 } { }' || true)
manifest_images=$(tar -xzOf - "$archive_root/manifest.json" < "$archive" 2>/dev/null \
    | awk 'match($0, /"images_count": *[0-9]+/) { v = substr($0, RSTART, RLENGTH); sub(/.*: */, "", v) } END { print (v == "" ? "?" : v) }' || true)
log_info "Archiwum: $archive ($(( archive_size / 1048576 )) MiB, $manifest_images zdjęć w manifeście)"

# --- 3. Czyste środowisko ------------------------------------------------------------------------
log_info "[3/7] Przygotowanie czystego środowiska $project..."
t=$(date +%s)
if [[ -n "$existing" ]]; then
    log_info "Usuwanie poprzedniego środowiska weryfikacyjnego (--recreate)..."
    verify_down
    rmdir "$work_dir/archive/.restore.lock" 2>/dev/null || true
fi
rm -rf "$work_dir/certs"; mkdir -p "$work_dir/certs"
# Domyślny default.conf przekazuje "Host: $host" (bez portu) i stały X-Forwarded-Port 443, a sklep po
# odtworzeniu ma domenę localhost:<port>: PrestaShop nie znalazłby sklepu i zapętlał przekierowania.
nginx_conf="$work_dir/nginx.conf"
cat > "$nginx_conf" << NGINX
# Wygenerowane przez deploy/verify-restore.sh dla środowiska $project (porty $http_port/$https_port).
server {
    listen 80;
    server_name localhost;
    return 301 https://localhost:$https_port\$request_uri;
}
server {
    listen 443 ssl;
    server_name localhost;
    ssl_certificate /etc/nginx/certs/localhost.crt;
    ssl_certificate_key /etc/nginx/certs/localhost.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 32M;
    location / {
        proxy_pass http://shop:80;
        proxy_set_header Host localhost:$https_port;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port $https_port;
    }
}
NGINX
chmod 0644 "$nginx_conf"
rand() { od -An -N16 -tx1 /dev/urandom | tr -d ' \n'; }
api_key=$(rand)
{
    echo "# Wygenerowane przez deploy/verify-restore.sh. Nie zawiera sekretów sklepu źródłowego."
    echo "COMPOSE_PROJECT_NAME=$project"
    echo "PS_DOMAIN=localhost:$https_port"
    echo "TLS_CERT_DIR=$(host_path "$work_dir/certs")"
    echo "NGINX_CONFIG=$(host_path "$nginx_conf")"
    echo "SCRAPER_RESULTS_DIR=$(host_path "$repo_dir/scraper-results")"
    echo "DB_NAME=prestashop"
    echo "DB_USER=prestashop"
    echo "DB_PASSWORD=$(rand)"
    echo "DB_ROOT_PASSWORD=$(rand)"
    echo "ADMIN_EMAIL=restore-verify@example.test"
    echo "ADMIN_PASSWORD=$(rand)"
    echo "PRESTASHOP_API_KEY=$api_key"
    echo "PRESTASHOP_API_URL=https://localhost:$https_port/api"
    echo "PRESTASHOP_API_VERIFY_SSL=false"
    echo "HTTP_PORT=$http_port"
    echo "HTTPS_PORT=$https_port"
    echo "MAILPIT_PORT=$mailpit_port"
} > "$verify_env"
chmod 0600 "$verify_env"
# Obraz sklepu jest wspólny ze sklepem źródłowym (ten sam tag). Gdy istnieje, nie jest przebudowywany,
# żeby weryfikacja nie podmieniła obrazu działającego sklepu. Budowa i pobieranie obrazów to
# przygotowanie środowiska, mierzone osobno od odtwarzania.
docker image inspect prestashop-website:8.2.8 >/dev/null 2>&1 || "${dst[@]}" build shop
for image in mariadb:10.11.14 nginx:stable-alpine axllent/mailpit:v1.33; do
    docker image inspect "$image" >/dev/null 2>&1 || docker pull "$image"
done
prep_secs=$(( $(date +%s) - t ))
stage "Przygotowanie czystego środowiska (obrazy, .env, nginx)" "$prep_secs"

# --- 4. Odtworzenie ------------------------------------------------------------------------------
log_info "[4/7] Odtwarzanie sklepu skryptem deploy/restore.sh (pomiar czasu)..."
restore_start=$(date +%s)
restore_rc=0
COMPOSE_PROJECT_NAME="$project" ENV_FILE="$verify_env" BACKUP_DIR="$work_dir/archive" SHOP_URL="https://localhost:$https_port/" \
    bash "$script_dir/restore.sh" "$archive" --yes --no-safety-backup || restore_rc=$?
restore_secs=$(( $(date +%s) - restore_start ))
stage "Odtworzenie sklepu (restore.sh, od pustego środowiska do HTTP 200)" "$restore_secs"
if [[ $restore_rc -eq 0 ]]; then
    record PASS "Odtworzenie skryptem restore.sh" "kod 0, $(fmt_secs "$restore_secs")"
else
    record FAIL "Odtworzenie skryptem restore.sh" "kod $restore_rc po $(fmt_secs "$restore_secs")"
fi
if [[ -n "${src_containers:-}" ]]; then
    if [[ "$("${src[@]}" ps -q | sort)" == "$src_containers" ]]; then
        record PASS "Izolacja od sklepu źródłowego" "kontenery źródła ($(echo "$src_containers" | wc -l | tr -d ' ')) te same i działające przez całe odtwarzanie"
    else
        record FAIL "Izolacja od sklepu źródłowego" "zestaw kontenerów źródła zmienił się podczas odtwarzania"
    fi
fi
if [[ $restore_secs -le $time_limit ]]; then
    record PASS "Czas odtwarzania" "$(fmt_secs "$restore_secs") (limit $(fmt_secs "$time_limit"))"
else
    record FAIL "Czas odtwarzania" "$(fmt_secs "$restore_secs") przekracza limit $(fmt_secs "$time_limit")"
fi

if [[ $restore_rc -eq 0 ]]; then
    # --- 5. Kompletność danych, zdjęć i konfiguracji (przed ruchem HTTP testów) -------------------
    log_info "[5/7] Porównanie danych, zdjęć i konfiguracji..."
    t=$(date +%s)
    collect_metrics dst "$work_dir/dst.metrics"
    collect_checksums dst "$work_dir/dst.checksums"
    collect_images dst > "$work_dir/dst.images"
    collect_config_keys dst "$work_dir/dst.config"
    collect_config_all dst "$work_dir/dst.config-all"

    metrics_ok=true
    for i in "${!metric_labels[@]}"; do
        d=$(sed -n "$((i + 1))p" "$work_dir/dst.metrics")
        if [[ "$compare_source" == "true" ]]; then
            s=$(sed -n "$((i + 1))p" "$work_dir/src.metrics")
            mark="✅"; [[ "$s" == "$d" ]] || { mark="❌"; metrics_ok=false; }
            metrics_table+="| ${metric_labels[$i]} | $s | $d | $mark |"$'\n'
        else
            metrics_table+="| ${metric_labels[$i]} | – | $d | – |"$'\n'
        fi
    done
    for i in 0 2 4 5 8; do
        [[ "$(sed -n "$((i + 1))p" "$work_dir/dst.metrics")" -gt 0 ]] || { metrics_ok=false; log_error "Brak danych: ${metric_labels[$i]} = 0"; }
    done
    if [[ "$metrics_ok" == "true" ]]; then
        record PASS "Kompletność kategorii, produktów, wariantów i promocji" "$( [[ "$compare_source" == "true" ]] && echo "${#metric_labels[@]} liczników zgodnych ze źródłem" || echo "dane obecne (bez porównania ze źródłem)")"
    else
        record FAIL "Kompletność kategorii, produktów, wariantów i promocji" "liczniki różnią się od źródła lub są zerowe (tabela w raporcie)"
    fi

    dst_img_sum=$(sed -n 1p "$work_dir/dst.images"); dst_img_count=$(sed -n 2p "$work_dir/dst.images")
    if [[ "$compare_source" == "true" ]]; then
        src_img_sum=$(sed -n 1p "$work_dir/src.images"); src_img_count=$(sed -n 2p "$work_dir/src.images")
        if [[ "$src_img_sum" == "$dst_img_sum" && "$src_img_count" == "$dst_img_count" ]]; then
            record PASS "Zdjęcia produktów (img/p)" "$dst_img_count plików, identyczne ścieżki i zawartość (MD5) jak w źródle"
        else
            record FAIL "Zdjęcia produktów (img/p)" "źródło $src_img_count plików, kopia $dst_img_count; sumy $( [[ "$src_img_sum" == "$dst_img_sum" ]] && echo zgodne || echo różne)"
        fi
    elif [[ "$manifest_images" == "$dst_img_count" ]]; then
        record PASS "Zdjęcia produktów (img/p)" "$dst_img_count plików, zgodnie z manifestem archiwum"
    else
        record FAIL "Zdjęcia produktów (img/p)" "manifest $manifest_images, sklep $dst_img_count"
    fi

    if [[ "$compare_source" == "true" ]]; then
        crit_bad=(); other_bad=(); expected_bad=(); changed_bad=()
        while IFS=$'\t' read -r table sum; do
            dsum=$(awk -F'\t' -v t="$table" '$1 == t { print $2 }' "$work_dir/dst.checksums")
            [[ "$sum" == "$dsum" ]] && continue
            short="${table#ps_}"
            if [[ " ${source_changed:-} " == *" $short "* ]]; then changed_bad+=("$short"); continue; fi
            if [[ " ${critical_tables//$'\n'/ } " == *" $short "* ]]; then crit_bad+=("$short")
            elif [[ " ${expected_diff_tables//$'\n'/ } " == *" $short "* ]]; then expected_bad+=("$short")
            else other_bad+=("$short"); fi
        done < "$work_dir/src.checksums"
        missing=$(comm -23 <(cut -f1 "$work_dir/src.checksums") <(cut -f1 "$work_dir/dst.checksums") | tr '\n' ' ')
        total_tables=$(wc -l < "$work_dir/src.checksums" | tr -d ' ')
        same=$(( total_tables - ${#crit_bad[@]} - ${#other_bad[@]} - ${#expected_bad[@]} - ${#changed_bad[@]} ))
        checksum_summary="- Tabel w bazie źródłowej: $total_tables, identycznych po odtworzeniu (CHECKSUM TABLE): $same."$'\n'
        checksum_summary+="- Różnice oczekiwane (domena i odnośniki w treściach, klucz API, statystyki ruchu): ${expected_bad[*]:-brak}."$'\n'
        checksum_summary+="- Różnice w pozostałych tabelach (do oceny): ${other_bad[*]:-brak}."$'\n'
        checksum_summary+="- Tabele zmienione w sklepie źródłowym w trakcie kopii (nieporównywalne): ${changed_bad[*]:-brak}."$'\n'
        checksum_summary+="- Różnice w tabelach danych sklepu: ${crit_bad[*]:-brak}. Brakujące tabele: ${missing:-brak}."$'\n'
        if [[ ${#crit_bad[@]} -eq 0 && -z "$missing" ]]; then
            record PASS "Zawartość bazy (CHECKSUM TABLE)" "$same/$total_tables tabel identycznych, tabele danych sklepu bez różnic"
        else
            record FAIL "Zawartość bazy (CHECKSUM TABLE)" "różnice: ${crit_bad[*]:-} brak tabel: ${missing:-}"
        fi
        [[ ${#other_bad[@]} -eq 0 ]] || log_warn "Inne tabele różniące się od źródła: ${other_bad[*]}"

        config_ok=true
        while IFS=$'\t' read -r key value; do
            dvalue=$(awk -F'\t' -v k="$key" '$1 == k { print $2 }' "$work_dir/dst.config")
            mark="✅"; [[ "$value" == "$dvalue" ]] || { mark="❌"; config_ok=false; }
            config_table+="| \`$key\` | $value | $dvalue | $mark |"$'\n'
        done < "$work_dir/src.config"
        config_diff=$(comm -3 "$work_dir/src.config-all" "$work_dir/dst.config-all" | tr -d '\t' | sed -E 's/=[^=]*=[0-9a-f]{32}$//' | sort -u | tr '\n' ' ')
        if [[ "$config_ok" == "true" && -z "$config_diff" ]]; then
            record PASS "Konfiguracja (ps_configuration)" "wszystkie wartości zgodne poza domeną (PS_SHOP_DOMAIN*) i włączeniem sklepu"
        else
            record FAIL "Konfiguracja (ps_configuration)" "różne klucze: ${config_diff:-tylko wybrane w tabeli}"
        fi
        if [[ ! -s "$work_dir/src.config-export.json" ]]; then
            record SKIP "Eksport konfiguracji (export-config.php)" "eksport w sklepie źródłowym nie powiódł się"
        elif collect_config_export dst "$work_dir/dst.config-export.json"; then
            if cmp -s "$work_dir/src.config-export.json" "$work_dir/dst.config-export.json"; then
                record PASS "Eksport konfiguracji (export-config.php)" "identyczny w źródle i po odtworzeniu (motyw, moduły, przewoźnicy, płatności, podatki, treści)"
            else
                record FAIL "Eksport konfiguracji (export-config.php)" "różni się; zob. diff $work_dir/*.config-export.json"
            fi
        else
            record FAIL "Eksport konfiguracji (export-config.php)" "$(one_line "$work_dir/dst.config-export.json.err")"
            diagnose_init_state
        fi
    else
        while IFS=$'\t' read -r key value; do config_table+="| \`$key\` | – | $value | – |"$'\n'; done < "$work_dir/dst.config"
        if shop_exec dst --user www-data shop php /opt/shop/init/export-config.php --check > /dev/null 2> "$work_dir/dst.config-check.err"; then
            record PASS "Konfiguracja zgodna z deploy/config/shop-config.json" "export-config.php --check: brak różnic"
        else
            record FAIL "Konfiguracja zgodna z deploy/config/shop-config.json" "$(one_line "$work_dir/dst.config-check.err")"
        fi
    fi
    domain=$(sql dst "SELECT CONCAT(domain_ssl, physical_uri) FROM ps_shop_url WHERE main = 1 LIMIT 1;")
    enabled=$(sql dst "SELECT value FROM ps_configuration WHERE name = 'PS_SHOP_ENABLE' AND id_shop IS NULL LIMIT 1;")
    if [[ "$domain" == "localhost:$https_port/" && "$enabled" == "1" ]]; then
        record PASS "Dopasowanie do środowiska" "domena $domain, sklep włączony"
    else
        record FAIL "Dopasowanie do środowiska" "domena '$domain', PS_SHOP_ENABLE=$enabled"
    fi
    stage "Porównanie danych, zdjęć i konfiguracji" $(( $(date +%s) - t ))

    # --- 6. Funkcjonalności ------------------------------------------------------------------------
    log_info "[6/7] Kontrola podstawowych funkcjonalności sklepu..."
    t=$(date +%s)
    smoke_rc=0
    smoke_out=$(shop_exec dst -e SMOKE_BASE_URL="https://localhost:$https_port" -e SMOKE_CONNECT_TO="nginx:443" \
        -e SMOKE_API_KEY="$api_key" shop php /opt/shop/deploy/verify/restore-smoke.php 2>&1) || smoke_rc=$?
    while IFS= read -r line; do
        line="${line%$'\r'}"
        case "$line" in
            PASS\|*|FAIL\|*|SKIP\|*|INFO\|*)
                IFS='|' read -r st name detail <<< "$line"
                record "$st" "$name" "$detail"
                smoke_lines+=("$line") ;;
            *) [[ -z "$line" ]] || echo "$line" ;;
        esac
    done <<< "$smoke_out"
    [[ ${#smoke_lines[@]} -gt 0 ]] || record FAIL "Kontrola funkcjonalności" "restore-smoke.php nie zwrócił wyników (kod $smoke_rc)"
    [[ $smoke_rc -eq 0 || $smoke_rc -eq 1 ]] || record FAIL "Kontrola funkcjonalności" "restore-smoke.php zakończony kodem $smoke_rc"

    stage "Kontrola funkcjonalności" $(( $(date +%s) - t ))
fi

# --- 7. Raport -----------------------------------------------------------------------------------
write_report
report_written=true

echo ""
echo "--------------------------------------------------------------------------------"
echo "  Wynik weryfikacji:  $overall"
echo "  Czas odtwarzania:   $(fmt_secs "$restore_secs") (limit $(fmt_secs "$time_limit"))"
echo "  Raport:             $report_file"
echo "  Log:                $log_file"
if [[ "$down_after" == "true" && "$overall" == "PASS" ]]; then
    verify_down >/dev/null 2>&1 && echo "  Środowisko:         usunięte (--down-after)"
else
    echo "  Odtworzony sklep:   https://localhost:$https_port  (panel: /admin-dev/, dane logowania z kopii)"
    echo "  Usunięcie:          bash deploy/verify-restore.sh --cleanup"
fi
echo "--------------------------------------------------------------------------------"
[[ "$overall" == "PASS" ]]
