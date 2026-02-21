# SuiteSidecar Add-in

This folder contains the Outlook taskpane MVP vertical slice.

## Implemented flow

- Load profiles from connector: `GET /profiles`
- Login: `POST /auth/login`
- Lookup sender: `GET /lookup/by-email`
- Create Contact: `POST /entities/contacts`
- Create Lead: `POST /entities/leads`
- Log Email (SuiteCRM Notes): `POST /email/log`
- Show requestId in UI errors for support
- Auto-lookup on item change via `Office.EventType.ItemChanged` (after login)

## Configured production endpoint

- Add-in host URL: `https://suitesidecar.example.com`
- Manifest file: `addin/manifest/suitesidecar.xml`
- Taskpane URL in manifest: `https://suitesidecar.example.com/addin/taskpane.html`

## Local static preview (browser only)

```bash
cd addin
npm run serve
```

Open `http://localhost:3000/taskpane.html`.
This preview is useful for UI checks, but Outlook host APIs require sideloading.

## Build release artifacts

Run from repo root:

```bash
bash ops/scripts/package-addin.sh
bash ops/scripts/publish-addin.sh
```

Output:

- `dist/addin/stage/sideload/suitesidecar.xml`
- `dist/addin/stage/static/addin/`
- `dist/addin/suitesidecar-manifest.zip` (or `.tar.gz`)
- `dist/addin/suitesidecar-static.zip` (or `.tar.gz`)

Published web files:

- `connector-php/public/addin/*`
- `connector-php/public/addin/manifest/suitesidecar.xml`

## Install to Outlook (sideload)

1. Publish add-in assets with `bash ops/scripts/publish-addin.sh`.
2. Ensure `https://suitesidecar.example.com/addin/taskpane.html` is reachable from the Outlook client.
3. In Outlook on the web:
   - `Get Add-ins` -> `My add-ins` -> `Add a custom add-in` -> `Add from file`.
   - Upload `dist/addin/stage/sideload/suitesidecar.xml`.
4. Open an email in read mode and launch SuiteSidecar from the ribbon.
5. Load profiles, login, then run lookup/create/log actions.

## Validation checklist

1. `GET /profiles` returns at least one profile.
2. `POST /auth/login` returns connector JWT.
3. Lookup works for both found and not-found emails.
4. `Create Contact`, `Create Lead`, and `Log Email` return success or structured errors with `requestId`.
