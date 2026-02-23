# SuiteSidecar Add-in

This folder contains the Outlook taskpane MVP vertical slice.

## Implemented flow

- Load profiles from connector: `GET /profiles`
- Login: `POST /auth/login`
- Logout: `POST /auth/logout`
- Lookup sender: `GET /lookup/by-email`
- Lookup timeline view: `GET /lookup/by-email?include=timeline`
- Lookup action deeplinks: `match.actions.createCallLink`, `match.actions.createMeetingLink`
- Create Contact: `POST /entities/contacts`
- Create Lead: `POST /entities/leads`
- Log Email (SuiteCRM Notes): `POST /email/log`
- Create Task from email: `POST /tasks/from-email`
- Opportunities panel: `GET /opportunities/by-context`
- Log Email options: `storeBody`, `storeAttachments`, `maxAttachmentBytes`
- Keep requestId out of visible status text; expose `Copy Debug Info` for support triage payload export
- Add-in aligns attachment size control to connector `/version` runtime limits when available
- Add-in preflights oversized `/email/log` payloads and handles server `413 payload_too_large` explicitly
- Auto-lookup on item change via `Office.EventType.ItemChanged` (after login)
- Profile/connector changes clear local session and require re-login

## Manifest split (public-safe + local-only)

- Versioned manifest: `addin/manifest/suitesidecar.xml`
  - Uses placeholder host `https://suitesidecar.example.com`
  - Safe to publish in git
- Local-only manifest: `addin/manifest/suitesidecar.local.xml`
  - Contains your real host URL
  - Gitignored via `addin/.gitignore`

Create local manifest:

```bash
cd addin
./scripts/make-local-manifest.sh https://your-real-host.example
```

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
2. Create your local manifest:
   - `cd addin && ./scripts/make-local-manifest.sh https://your-real-host.example`
3. Ensure `https://your-real-host.example/addin/taskpane.html` is reachable from the Outlook client.
4. In Outlook on the web:
   - `Get Add-ins` -> `My add-ins` -> `Add a custom add-in` -> `Add from file`.
   - Upload `addin/manifest/suitesidecar.local.xml`.
5. Open an email in read mode and launch SuiteSidecar from the ribbon.
6. Load profiles, login, then run lookup/create/log actions.

Outlook Desktop note:
- If the task pane disappears when selecting another email, pin the pane from the add-in title bar (pushpin icon).
- The manifest already enables pinning; without pinning, Outlook can close the pane on item change by design.

## Microsoft 365 propagation note

- Microsoft 365 admin center may show stale icon/metadata for a while after an update.
- For predictable updates:
  1. bump `<Version>` in the uploaded manifest
  2. upload the new manifest
  3. wait 5-30 minutes (sometimes longer)
  4. hard refresh the admin center view
- If icons look broken briefly but URLs return `200`, this is usually propagation/cache delay.
- For taskpane cache refresh after UI changes:
  1. bump add-in manifest `<Version>`
  2. bump `?v=` in `manifest/suitesidecar.xml` taskpane URL
  3. bump `?v=` in `addin/public/taskpane.html` for JS/CSS
  4. run `bash ops/scripts/publish-addin.sh`

## Validation checklist

1. `GET /profiles` returns at least one profile.
2. `POST /auth/login` returns connector JWT.
3. Lookup works for both found and not-found emails.
4. `Create Contact`, `Create Lead`, and `Log Email` return success or structured errors with `requestId`.

## If add-in is not visible in Outlook

1. Confirm deployment target:
   - Your mailbox user is included in the Microsoft 365 admin center deployment scope.
2. Wait for central deployment propagation:
   - It can take from minutes up to 24 hours.
3. Confirm host compatibility:
   - Open a message in **Read** mode (manifest is Message Read surface).
   - Check the add-ins panel/ribbon in that view.
4. Confirm manifest URLs are reachable publicly over HTTPS:
   - `https://your-real-host.example/addin/taskpane.html`
   - `https://your-real-host.example/addin/assets/icon-64.png`
5. Confirm the uploaded manifest uses your real URL:
   - Upload `suitesidecar.local.xml`, not the placeholder `suitesidecar.xml`.
6. If still missing:
   - remove and re-add the add-in for the mailbox
   - sign out/in to Outlook Web/Desktop
   - test in Outlook on the web first (fastest feedback loop)
