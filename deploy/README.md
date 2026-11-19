# Środowisko Docker

`compose.yaml` jest jedynym plikiem Compose projektu. Definiuje bazę, PrestaShop, Nginx, Mailpit, przygotowanie certyfikatu i bootstrap. Zwykłe `up` uruchamia usługi i przygotowuje TLS. Usługa `bootstrap` ma profil `init` i uruchamia się po jawnym `run bootstrap`, wykonując bezpieczną inicjalizację.

Sklep i testy korzystają z tej samej definicji, z różnymi plikami `--env-file`, portami i nazwami projektów. Wzór testowy to `api-test.env.example`.

```sh
docker compose --env-file .env -f deploy/compose.yaml run --build --rm bootstrap
```

Pełną instrukcję `.env`, HTTPS, platform i pracy ręcznej zawiera [wdrożenie](../docs/DEPLOYMENT.md). Etapy i aktualizacje opisuje [inicjalizacja](../docs/INITIALIZATION.md), a reguły sklepu [konfiguracja](../docs/CONFIGURATION.md).

`bootstrap.php` jest wspólnym punktem wejścia kontenerów. `generate-cert.ps1`, `generate-cert.sh` i `start-macos.sh` udostępniają zgodne wywołania pomocnicze. `nginx/default.conf` obsługuje lokalne HTTPS. W `init/` znajdują się importery i konfiguracja, a w `init/tests/` testy klas PHP oraz JavaScript.
