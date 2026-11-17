# Środowisko Docker

`compose.yaml` definiuje bazę, PrestaShop, Nginx i Mailpit. `compose.bootstrap.yaml` dodaje przygotowanie certyfikatu, kontrolę gotowości i bezpieczną inicjalizację.

```sh
docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.bootstrap.yaml run --build --rm bootstrap
```

Pełną instrukcję `.env`, HTTPS, platform i pracy ręcznej zawiera [wdrożenie](../docs/DEPLOYMENT.md). Etapy i aktualizacje opisuje [inicjalizacja](../docs/INITIALIZATION.md), a reguły sklepu [konfiguracja](../docs/CONFIGURATION.md).

`bootstrap.php` jest wspólnym punktem wejścia kontenerów. `generate-cert.ps1`, `generate-cert.sh` i `start-macos.sh` udostępniają zgodne wywołania pomocnicze. `nginx/default.conf` obsługuje lokalne HTTPS. W `init/` znajdują się importery i konfiguracja, a w `init/tests/` testy klas PHP oraz JavaScript.
