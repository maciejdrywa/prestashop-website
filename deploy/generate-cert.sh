#!/bin/bash
# Przygotowanie lokalnego certyfikatu dla Linux/macOS przez wspólny entrypoint PHP.
# Wywołanie z katalogu repozytorium
# bash deploy/generate-cert.sh
# Wymaga Dockera, .env, danych scrapera i TLS_CERT_DIR. Usługa certificates
# waliduje wejście i zapisuje parę TLS na hoście bez uruchamiania bazy ani sklepu.
set -euo pipefail
cd "$(dirname "$0")/.."
# --no-deps ogranicza wykonanie do przygotowania wejścia i TLS. Odrębna komenda
# bootstrap odpowiada za uruchomienie całego środowiska oraz inicjalizację sklepu.
docker compose --env-file .env -f deploy/compose.yaml \
    run --build --rm --no-deps certificates
