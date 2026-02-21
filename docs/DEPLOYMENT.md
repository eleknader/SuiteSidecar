# SuiteSidecar Connector PHP: Deployment and Verification

## Composer clean-state verification

Run from `connector-php`:

```bash
cd connector-php
rm -rf vendor
composer install
composer dump-autoload
```

## Local secrets setup

Install server-local secrets file and permissions:

```bash
sudo ops/scripts/env-install.sh
```

Run one-command smoke checks against local Apache (with `--resolve`):

```bash
ops/scripts/smoke.sh
```

## Runtime storage (`connector-php/var`)

`connector-php/var` is runtime-only storage (tokens, sessions, temporary runtime data).
It is intentionally excluded from git and must not contain committed files.

Create and secure it on the target host:

```bash
mkdir -p connector-php/var/tokens connector-php/var/sessions
sudo chown -R www-data:www-data connector-php/var
sudo chmod -R 750 connector-php/var
```

## Profile OAuth secrets via environment variables

Profile id `example-dev` is normalized to `EXAMPLE_DEV` for env keys.

```bash
export SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID="your-client-id"
export SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET="your-client-secret"
```

General pattern:

```bash
SUITESIDECAR_<PROFILEID_NORMALIZED>_CLIENT_ID
SUITESIDECAR_<PROFILEID_NORMALIZED>_CLIENT_SECRET
```

## Connector JWT secret

`/auth/login` and authenticated endpoints require:

```bash
export SUITESIDECAR_JWT_SECRET="replace-with-long-random-secret"
```

Optional token lifetime override (default: 8h):

```bash
export SUITESIDECAR_JWT_TTL_SECONDS="28800"
```

## API smoke tests (normal DNS path)

```bash
BASE_URL="https://connector.example.com"
curl -sS "${BASE_URL}/health"
curl -sS "${BASE_URL}/version"
curl -sS "${BASE_URL}/profiles"

LOGIN_RESPONSE=$(curl -sS -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "profileId":"example-dev",
    "username":"user@example.com",
    "password":"your-password"
  }')

TOKEN=$(printf "%s" "${LOGIN_RESPONSE}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["token"] ?? "";')

curl -sS "${BASE_URL}/lookup/by-email?profileId=example-dev&email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}"

curl -sS "${BASE_URL}/lookup/by-email?email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-SuiteSidecar-Profile: example-dev"
```

## How to test before DNS propagation

Use `--resolve` to force hostname + TLS SNI to this server IP:

```bash
SERVER_IP=127.0.0.1
HOSTNAME="connector.example.com"

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/health

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/version

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/profiles

LOGIN_RESPONSE=$(curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -X POST "https://${HOSTNAME}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "profileId":"example-dev",
    "username":"user@example.com",
    "password":"your-password"
  }')

TOKEN=$(printf "%s" "${LOGIN_RESPONSE}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["token"] ?? "";')

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  "https://${HOSTNAME}/lookup/by-email?profileId=example-dev&email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}"

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  "https://${HOSTNAME}/lookup/by-email?email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-SuiteSidecar-Profile: example-dev"
```
