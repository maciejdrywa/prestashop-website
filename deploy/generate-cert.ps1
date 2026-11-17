<#
Przygotowanie certyfikatu przez wspólny entrypoint PHP, uruchamiane na Windows.
Wywołanie z PowerShell
  .\deploy\generate-cert.ps1
Wymaga Docker Engine, .env, obu JSON-ów, lokalnych zdjęć i TLS_CERT_DIR.
Uruchamia jednorazową usługę certificates bez startowania jej zależności.
Zapis pary TLS i walidację wejścia wykonuje bootstrap.php --certificates.
#>
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path $PSScriptRoot -Parent
Push-Location $projectDirectory
try {
    docker compose --env-file .env -f deploy/compose.yaml -f deploy/compose.bootstrap.yaml run --build --rm --no-deps certificates
    # Błędy procesów natywnych są odczytywane z kodu wyjścia Dockera.
    # ErrorActionPreference obejmuje błędy PowerShell, więc kod sprawdzamy jawnie.
    if ($LASTEXITCODE -ne 0) {
        throw 'Przygotowanie certyfikatu nie powiodło się. Sprawdź komunikat kontenera.'
    }
} finally {
    # Przywrócenie katalogu wywołującego obowiązuje także po nieudanym poleceniu.
    Pop-Location
}
