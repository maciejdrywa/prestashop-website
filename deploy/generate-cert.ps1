
$ErrorActionPreference = "Stop"

Get-Command openssl -ErrorAction Stop | Out-Null

$certDir = Join-Path $env:USERPROFILE ".prestashop-website/certs"
$certPath = Join-Path $certDir "localhost.crt"
$keyPath = Join-Path $certDir "localhost.key"

if ((Test-Path $certPath) -or (Test-Path $keyPath)) {
    throw "Certyfikat lub klucz już istnieje. Nie nadpisuję plików."
}

New-Item -ItemType Directory -Force -Path $certDir | Out-Null

openssl req -x509 -newkey rsa:2048 -sha256 -nodes `
    -days 365 `
    -keyout $keyPath `
    -out $certPath `
    -subj "/CN=localhost" `
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" `
    -addext "extendedKeyUsage=serverAuth"

if ($LASTEXITCODE -ne 0) {
    throw "Generowanie certyfikatu nie powiodło się."
}

Write-Host "Certyfikat: $certPath"
Write-Host "Klucz: $keyPath"