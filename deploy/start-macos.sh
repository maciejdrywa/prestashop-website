#!/bin/bash
# Pełny start lokalnego sklepu na macOS z opcjonalnym zaufaniem certyfikatowi.
# Wywołanie z katalogu repozytorium
# bash deploy/start-macos.sh [--trust]
# Wymaga Docker Desktop, .env i przygotowanych źródeł. Wywołuje wspólny bootstrap,
# a --trust dodaje wygenerowany certyfikat do pęku kluczy bieżącego użytkownika.
# Katalog TLS jest domyślnie poza repozytorium i może być wskazany przez środowisko.
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
# Tablica zachowuje granice argumentów także przy spacjach w ścieżce projektu.
# Eksport TLS_CERT_DIR ma pierwszeństwo przed wartością zapisaną w .env.
compose=(docker compose --env-file "$repo_dir/.env" -f "$repo_dir/deploy/compose.yaml" -f "$repo_dir/deploy/compose.bootstrap.yaml")
"${compose[@]}" run --build --rm bootstrap
if "$trust"; then
    # Zaufanie dotyczy konta bieżącego użytkownika i wymaga natywnego pęku kluczy.
    security add-trusted-cert -r trustRoot -k "$HOME/Library/Keychains/login.keychain-db" \
        "$TLS_CERT_DIR/localhost.crt"
fi
