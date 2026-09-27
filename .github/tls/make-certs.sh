#!/usr/bin/env bash
# Throwaway PKI for the TLS matrix (PRD-connection-tls §10.1). Valid for two days, never reused.
# Usage: make-certs.sh <dir>
set -euo pipefail

dir="${1:?usage: make-certs.sh <dir>}"
mkdir -p "$dir"
cd "$dir"

openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Clonio Test CA" \
  -keyout ca-key.pem -out ca.pem
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Clonio Wrong CA" \
  -keyout wrong-ca-key.pem -out wrong-ca.pem

openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout server-key.pem -out server.csr
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' > server.ext
openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 2 \
  -extfile server.ext -out server.pem

openssl req -newkey rsa:2048 -nodes -subj "/CN=clonio-client" -keyout client-key.pem -out client.csr
printf 'extendedKeyUsage=clientAuth\n' > client.ext
openssl x509 -req -in client.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 2 \
  -extfile client.ext -out client.pem

rm -f ./*.csr ./*.ext ./*.srl
# Server processes in the containers (mysql uid 999, mssql uid 10001) must read the server key.
chmod 644 ./*.pem
# Clonio warns about group/world-readable client keys (§7); keep the matrix output free of that noise.
chmod 600 client-key.pem
