# SuiteSidecar Connector PHP: Deployment and Verification

## Composer clean-state verification

Run from `connector-php`:

```bash
cd connector-php
rm -rf vendor
composer install
composer dump-autoload
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

## API smoke tests (normal DNS path)

```bash
BASE_URL="https://connector.example.com"
curl -sS "${BASE_URL}/health"
curl -sS "${BASE_URL}/version"
curl -sS "${BASE_URL}/profiles"
curl -sS "${BASE_URL}/lookup/by-email?email=known.user@example.com&include=account"
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

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  "https://${HOSTNAME}/lookup/by-email?email=known.user@example.com&include=account"
```
