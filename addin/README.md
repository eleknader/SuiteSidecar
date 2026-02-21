# SuiteSidecar Add-in Scaffold

This folder contains a vertical-slice scaffold for the Outlook task pane.

## Scope in this scaffold

- Load connector profiles (`GET /profiles`)
- Login (`POST /auth/login`)
- Lookup sender (`GET /lookup/by-email`)
- Create Contact (`POST /entities/contacts`)
- Create Lead (`POST /entities/leads`)
- Log Email (`POST /email/log`)
- Render requestId-aware error/status messages

## Run local static host

```bash
cd addin
npm run serve
```

Open:

- `http://localhost:3000/taskpane.html`

## Notes

- `manifest/suitesidecar.xml` is a starter manifest and uses localhost placeholder URLs.
- Update manifest URLs and icon assets before sideloading in Outlook.
- For Office runtime testing, sideload the manifest into a mailbox-enabled Outlook host.
