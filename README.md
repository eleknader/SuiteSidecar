# SuiteSidecar

Outlook add-in + connector API for SuiteCRM.

SuiteSidecar gives users CRM context directly in Outlook and lets them create or log CRM activity without leaving the email view.

## What This Is

SuiteSidecar has two runtime parts:

1. Outlook add-in (`addin/`)
2. Connector API in PHP (`connector-php/`) that talks to SuiteCRM

The add-in never calls SuiteCRM directly. It calls the connector, and the connector handles SuiteCRM auth, API shaping, and security controls.

Profile handling:

- Connector may expose one or many profiles.
- If exactly one profile is configured, login can proceed without explicit `profileId`.
- If multiple profiles are configured, profile selection is required.

## What It Is Good For (Daily Use)

In normal email handling, users can:

- Find sender as Contact/Lead automatically when an email is opened
- Create Contact or Lead when no match exists
- Log email to SuiteCRM Notes with deduplication
- Open pre-related `Create Call` and `Create Meeting` forms
- Create follow-up Task from email metadata (idempotent/dedup safe)
- See latest opportunities and jump to CRM records

## Most Useful Admin Workflow Improvement

For a new SuiteCRM administrator, the biggest value is a single end-to-end onboarding runbook:

- one checklist from infrastructure to Microsoft 365 deployment
- one validation path (`smoke.sh` + `e2e-auth.sh`)
- one troubleshooting path keyed by `requestId`

This README is that top-level runbook. Use it first, then use linked docs for deeper details.

## Architecture At A Glance

```text
Outlook (Office.js add-in) -> SuiteSidecar Connector API (PHP) -> SuiteCRM v8 API/OAuth
```

## Prerequisites

- Public HTTPS hostname for connector (same host serves `/addin/*` and API routes)
- Reachable SuiteCRM v8 instance
- SuiteCRM OAuth client credentials (`client_id`, `client_secret`)
- Microsoft 365 admin rights for centralized add-in deployment
- PHP/composer runtime for connector host

## Apache Header Checklist (Important)

If Apache headers are too strict or `Authorization` is stripped, Outlook add-in loading and connector auth will fail.

Required behavior:

- Preserve `Authorization` header to PHP
- Do not block framing for `/addin/*` with global `X-Frame-Options`
- If you set CSP, allow Microsoft host pages in `frame-ancestors`
- Do not override connector CORS headers to something more restrictive than the app expects

Example Apache settings:

```apache
# Pass Authorization to PHP/FPM (required for Bearer auth)
SetEnvIfNoCase Authorization "^(.*)" HTTP_AUTHORIZATION=$1

# Optional baseline hardening
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Add-in pages must be iframe-compatible inside Outlook hosts
<Location "/addin/">
  Header always unset X-Frame-Options
  Header always set Content-Security-Policy "frame-ancestors 'self' https://*.office.com https://*.office365.com https://*.outlook.com;"
</Location>
```

Notes:

- If your global vhost sets `X-Frame-Options DENY` or `SAMEORIGIN`, override/unset it for `/addin/*`.
- If you already set CSP globally, merge `frame-ancestors` there instead of setting a second CSP header.
- Connector CORS headers are currently set by `connector-php/public/index.php`.

## Quick Start (Admin)

### 1) Choose host model

Use either:

- Dedicated virtual domain (recommended), for example `https://suitesidecar.example.com`
- Existing domain/vhost, if you can serve both:
  - Connector API routes from `/`
  - Add-in static files from `/addin/*`

### 2) Configure connector profile(s)

Use `connector-php/config/profiles.example.php` as template and create local `connector-php/config/profiles.php`.

For each profile define:

- `id` (for example `example-dev`)
- `suitecrmBaseUrl` (CRM root URL, not `/legacy`)
- OAuth token URL (normally `https://<crm-host>/legacy/Api/access_token`)

Important:

- `connector-php/config/profiles.php` is local-only and must not be committed.

### 3) Configure secrets

Use environment variables for secrets:

- `SUITESIDECAR_JWT_SECRET`
- `SUITESIDECAR_<PROFILE>_CLIENT_ID`
- `SUITESIDECAR_<PROFILE>_CLIENT_SECRET`

Profile id normalization example:

- `example-dev` -> `EXAMPLE_DEV`
- env var names:
  - `SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID`
  - `SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET`

For local development, copy `connector-php/.env.example` to `connector-php/.env` and set values.

### 4) Create OAuth client in SuiteCRM

In SuiteCRM admin UI:

1. Create an OAuth client for connector use.
2. Copy `client_id` and `client_secret`.
3. Ensure password grant authentication works for SuiteCRM users (`/auth/login` path in connector uses `grant_type=password`).
4. Verify token endpoint is `https://<crm-host>/legacy/Api/access_token`.

Quick verification:

```bash
curl -sS -i -X POST "https://crm.example.com/legacy/Api/access_token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=password" \
  --data-urlencode "client_id=<client-id>" \
  --data-urlencode "client_secret=<client-secret>" \
  --data-urlencode "username=<suitecrm-user>" \
  --data-urlencode "password=<suitecrm-password>"
```

Expected: HTTP `200` with `access_token`.

### 5) Deploy and verify connector

From repo root:

```bash
ops/scripts/smoke.sh
E2E_USERNAME="admin" E2E_PASSWORD="<password>" ops/scripts/e2e-auth.sh
```

### 6) Build/publish add-in assets

```bash
bash ops/scripts/package-addin.sh
bash ops/scripts/publish-addin.sh
```

This publishes taskpane assets under `connector-php/public/addin/`.

### 7) Prepare manifest for your real host

```bash
cd addin
./scripts/make-local-manifest.sh https://your-real-host.example
```

This creates `addin/manifest/suitesidecar.local.xml` (gitignored).

Optional split-host mode:

```bash
cd addin
./scripts/make-local-manifest.sh https://addin.example.com https://api.example.com
```

This keeps add-in assets on the first host and injects connector startup URL (`connectorBaseUrl`) for the second host.

Startup defaults:

- Connector Base URL defaults to the taskpane host (`window.location.origin`), so single-site deployments usually need no manual connector URL input.
- Optional override: append `connectorBaseUrl` query parameter to taskpane URL in the manifest.
  Example: `.../addin/taskpane.html?v=0.4.2&connectorBaseUrl=https%3A%2F%2Fapi.example.com`
- If an older session snapshot still contains placeholder `https://suitesidecar.example.com`, it is auto-migrated to current host on startup.

### 8) Deploy add-in in Microsoft 365 admin center

Use centralized deployment (Integrated apps / custom Office add-in upload) and upload:

- `addin/manifest/suitesidecar.local.xml`

Assign users/groups, then wait for propagation.

### 9) Validate with a mailbox user

1. Open email in read mode.
2. Open SuiteSidecar taskpane.
3. Login and run lookup.
4. If connector has multiple profiles, select profile from dropdown first. If connector has one profile, dropdown is hidden and profile is auto-selected.
5. Validate Create Contact/Lead, Log Email, Create Task, and deeplinks.

## Security Basics

- Never commit secrets or local runtime files.
- Keep OAuth client credentials in env vars, not in git-tracked config.
- Keep `connector-php/var` writable only to runtime user.
- Use HTTPS only for connector and CRM endpoints.
- Use `requestId` for support diagnostics (see `docs/SUPPORT.md`).

## Common First-Time Issues

- `401 invalid_client`:
  - wrong client id/secret, wrong env var names, or env not loaded by runtime
- `404` on token URL:
  - wrong CRM base URL or token endpoint path
- Add-in not visible in Outlook:
  - deployment scope missing user, propagation delay, or wrong manifest URL host
- Outlook UI stale after update:
  - bump manifest `<Version>` and taskpane `?v=` cache-busting query

## Documentation Map

- Admin deployment and deep verification: `docs/DEPLOYMENT.md`
- API contract: `docs/OPENAPI.yaml`
- System architecture: `docs/ARCHITECTURE.md`
- Support triage (`requestId`): `docs/SUPPORT.md`
- Security notes: `docs/SECURITY.md`
- Add-in packaging and sideload details: `addin/README.md`

## Repository Layout

- `connector-php/` connector backend
- `addin/` Outlook add-in UI and manifest
- `docs/` architecture, API, deployment, support docs
- `ops/scripts/` smoke, e2e, packaging scripts
