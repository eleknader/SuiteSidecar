# SuiteSidecar Connector PHP: Deployment and Verification

## Composer clean-state verification

Run from project root:

```bash
cd /opt/evomax/suitesidecar/connector-php
rm -rf vendor
composer install
composer dump-autoload
```

## API smoke tests (normal DNS path)

```bash
curl -sS https://suitesidecar.example.com/health
curl -sS https://suitesidecar.example.com/version
curl -sS https://suitesidecar.example.com/profiles
curl -sS "https://suitesidecar.example.com/lookup/by-email?email=test%2Bfound@example.com&include=account,timeline"
```

## How to test before DNS propagation

Use `--resolve` to force hostname + TLS SNI to this server IP:

```bash
SERVER_IP=127.0.0.1

curl -sS --resolve suitesidecar.example.com:443:${SERVER_IP} \
  https://suitesidecar.example.com/health

curl -sS --resolve suitesidecar.example.com:443:${SERVER_IP} \
  https://suitesidecar.example.com/version

curl -sS --resolve suitesidecar.example.com:443:${SERVER_IP} \
  https://suitesidecar.example.com/profiles

curl -sS --resolve suitesidecar.example.com:443:${SERVER_IP} \
  "https://suitesidecar.example.com/lookup/by-email?email=test%2Bfound@example.com&include=account,timeline"
```
