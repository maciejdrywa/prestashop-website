#!/bin/bash
set -euo pipefail
umask 077

command -v openssl >/dev/null || { echo 'Brak OpenSSL w PATH.' >&2; exit 1; }
cert_dir="${TLS_CERT_DIR:-$HOME/.prestashop-website/certs}"
[[ "$cert_dir" = /* ]] || { echo 'TLS_CERT_DIR musi być ścieżką bezwzględną.' >&2; exit 1; }
if [[ -e "$cert_dir/localhost.crt" || -e "$cert_dir/localhost.key" ]]; then
    echo 'Certyfikat lub klucz już istnieje. Nie nadpisuję plików.' >&2
    exit 1
fi
mkdir -p "$cert_dir"
# Plik konfiguracyjny działa również z systemowym LibreSSL na macOS.
work_dir=$(mktemp -d "$cert_dir/.generate.XXXXXX")
trap 'rm -rf "$work_dir"' EXIT
cat > "$work_dir/openssl.cnf" <<'CONFIG'
[req]
prompt = no
distinguished_name = dn
x509_extensions = extensions
[dn]
CN = localhost
[extensions]
subjectAltName = DNS:localhost,IP:127.0.0.1
extendedKeyUsage = serverAuth
keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
basicConstraints = critical,CA:TRUE
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CONFIG
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 365 \
    -config "$work_dir/openssl.cnf" \
    -keyout "$work_dir/localhost.key" -out "$work_dir/localhost.crt"
mv "$work_dir/localhost.key" "$cert_dir/localhost.key"
mv "$work_dir/localhost.crt" "$cert_dir/localhost.crt"
chmod 644 "$cert_dir/localhost.crt"
printf 'Certyfikat: %s\nKlucz: %s\n' "$cert_dir/localhost.crt" "$cert_dir/localhost.key"
