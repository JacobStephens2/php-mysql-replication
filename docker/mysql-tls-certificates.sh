#!/bin/sh
# Create a CA and a server certificate that names 127.0.0.1.
# The integration tests connect to that host and trust ca.pem.
set -eu

destination="${1:?usage: mysql-tls-certificates.sh DIRECTORY}"
mkdir -p "$destination"

if [ -s "$destination/ca.pem" ] && [ -s "$destination/server-cert.pem" ] && [ -s "$destination/server-key.pem" ]; then
    exit 0
fi

if ! command -v openssl >/dev/null 2>&1; then
    apk add --no-cache openssl
fi

config="$destination/openssl.cnf"
cat > "$config" <<'EOF'
[req]
distinguished_name = dn
prompt = no
[dn]
CN = 127.0.0.1
[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[server_cert]
basicConstraints = CA:FALSE
keyUsage = digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = IP:127.0.0.1
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
EOF

openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout "$destination/ca-key.pem" \
    -out "$destination/ca.pem" \
    -config "$config" \
    -extensions v3_ca \
    -subj "/CN=MySQL test authority"

openssl req -newkey rsa:2048 -nodes \
    -keyout "$destination/server-key.pem" \
    -out "$destination/server.csr" \
    -config "$config" \
    -subj "/CN=127.0.0.1"

openssl x509 -req \
    -in "$destination/server.csr" \
    -CA "$destination/ca.pem" \
    -CAkey "$destination/ca-key.pem" \
    -CAcreateserial \
    -out "$destination/server-cert.pem" \
    -days 3650 \
    -extfile "$config" \
    -extensions server_cert

chmod 644 "$destination/ca.pem" "$destination/server-cert.pem"
chmod 600 "$destination/server-key.pem" "$destination/ca-key.pem"
rm -f "$destination/server.csr" "$destination/ca.srl" "$config"
