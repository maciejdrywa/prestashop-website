#!/bin/bash
set -euo pipefail

trust=false
case "${1:-}" in
    --trust) trust=true ;;
    '') ;;
    *) echo "Użycie: $0 [--trust]" >&2; exit 1 ;;
esac
[[ $# -le 1 ]] || { echo "Użycie: $0 [--trust]" >&2; exit 1; }
[[ "$(uname -s)" = Darwin ]] || { echo 'Ten skrypt jest przeznaczony dla macOS.' >&2; exit 1; }
repo_dir=$(cd "$(dirname "$0")/.." && pwd)
cd "$repo_dir"
command -v docker >/dev/null || { echo 'Zainstaluj i uruchom Docker Desktop.' >&2; exit 1; }
docker compose version >/dev/null
docker info >/dev/null
[[ -f .env ]] || { echo 'Skopiuj .env.example do .env i uzupełnij hasła.' >&2; exit 1; }
# Eksport ma pierwszeństwo nad windowsową lub pustą ścieżką w .env.
export TLS_CERT_DIR="${TLS_CERT_DIR:-$HOME/.prestashop-website/certs}"
[[ "$TLS_CERT_DIR" = /* ]] || { echo 'TLS_CERT_DIR musi być ścieżką bezwzględną.' >&2; exit 1; }
compose=(docker compose --env-file "$repo_dir/.env" -f "$repo_dir/deploy/compose.yaml")
"${compose[@]}" config --quiet
if [[ ! -e "$TLS_CERT_DIR/localhost.crt" && ! -e "$TLS_CERT_DIR/localhost.key" ]]; then
    bash "$repo_dir/deploy/generate-cert.sh"
fi
[[ -s "$TLS_CERT_DIR/localhost.crt" && -s "$TLS_CERT_DIR/localhost.key" ]] || {
    echo 'Niekompletna para certyfikatu i klucza. Sprawdź TLS_CERT_DIR.' >&2; exit 1;
}
openssl x509 -in "$TLS_CERT_DIR/localhost.crt" -checkend 0 -noout
if "$trust"; then
    # Zaufanie dotyczy tylko konta bieżącego użytkownika.
    security add-trusted-cert -r trustRoot -k "$HOME/Library/Keychains/login.keychain-db" \
        "$TLS_CERT_DIR/localhost.crt"
fi
"${compose[@]}" up -d --build
printf '\nSklep: https://localhost\nLogi: docker compose --env-file .env -f deploy/compose.yaml logs -f shop\n'
