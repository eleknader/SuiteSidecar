# SuiteSidecar Requirements

## Purpose
This document defines minimum SuiteCRM-side requirements for:
- email-log dedup support
- conflict mapping preparation (`409 Conflict` behavior in connector logic)

## SuiteCRM Instance Requirements
- SuiteCRM v8 instance reachable from connector over HTTPS.
- OAuth token flow working for connector integration user.
- Integration user permissions for `Contacts`, `Leads`, and `Notes` (read/create).
- Legacy customizations path available at `public/legacy/custom/Extension/modules/...`.

## Notes Body and Attachment Constraints (v0.2)
- Logged email body is persisted to `Notes.description` as plain text when `storeBody=true`.
- `Notes.description` does not preserve original HTML formatting from Outlook body.
- Rich/HTML body preservation is a future feature and not required for current deployment.
- Attachment persistence is size-limited by:
  - add-in `maxAttachmentBytes` option
  - connector/PHP request limits (`upload_max_filesize`, `post_max_size`)

## Dedup Fields (DEV baseline)

### Notes module
- `suitesidecar_message_id_c` (varchar 191): stores `EmailMessage.internetMessageId`.
- `suitesidecar_profile_id_c` (varchar 64): stores connector profile id for scoped dedup checks.
- Indexes:
  - `idx_note_ssidecar_msgid`
  - `idx_note_ssidecar_profile`

### Contacts module
- `suitesidecar_email_norm_c` (varchar 255): normalized email candidate key.
- Index:
  - `idx_contact_ssidecar_email_norm`

### Leads module
- `suitesidecar_email_norm_c` (varchar 255): normalized email candidate key.
- Index:
  - `idx_lead_ssidecar_email_norm`

## SuiteCRM DEV Vardef Files
Applied in `/var/www/suitecrm_dev/current`:

- `public/legacy/custom/Extension/modules/Notes/Ext/Vardefs/suitesidecar_dedup_fields.php`
- `public/legacy/custom/Extension/modules/Contacts/Ext/Vardefs/suitesidecar_dedup_fields.php`
- `public/legacy/custom/Extension/modules/Leads/Ext/Vardefs/suitesidecar_dedup_fields.php`

## Apply Changes: QRR + Metadata/Cache Refresh (DEV)

### 1) Run Quick Repair and Rebuild (UI)
1. Login to SuiteCRM DEV as admin.
2. Go to `Admin -> Repair -> Quick Repair and Rebuild`.
3. Click `Repair`.
4. If SQL is shown, execute it against the DEV database.

### 2) Refresh Symfony cache (server)
Run in SuiteCRM DEV repo:

```bash
cd /var/www/suitecrm_dev/current
sudo -u www-data php bin/console cache:clear --no-warmup
sudo -u www-data php bin/console cache:warmup
```

### 3) Optional web tier reload (if needed)
```bash
sudo systemctl reload apache2
```

## Verification Checklist
- New custom fields are visible in Studio/metadata.
- `public/legacy/custom/modules/*/Ext/Vardefs/vardefs.ext.php` includes new definitions after QRR.
- Connector requests still pass smoke checks (`ops/scripts/smoke.sh`).

## Connector-Side Checklist (Dedup + Conflict Mapping)
- Normalize keys before lookup/create:
  - email-based keys -> lowercase + trim
  - message-id key -> trim + lowercase + remove wrapping `< >` from `internetMessageId`
- `/email/log` dedup pre-check:
  - query `Notes` by `suitesidecar_message_id_c` + `suitesidecar_profile_id_c`
  - if existing record found, return `409 conflict` (or `200 deduplicated=true` if policy chooses reuse)
- `/entities/contacts` and `/entities/leads` dedup pre-check:
  - query by normalized email key (`suitesidecar_email_norm_c`)
  - if duplicate candidate found, return `409 conflict`
- On successful create/log, persist dedup fields in created record payload.
- Map SuiteCRM duplicate/constraint failures to connector `409 conflict` response.
- Keep non-duplicate upstream failures mapped as:
  - auth -> `401` (`suitecrm_auth_failed`)
  - bad payload from upstream -> `502` (`suitecrm_bad_response`)
  - transport/upstream unavailable -> `502` (`suitecrm_unreachable`)
- Include `requestId` in every conflict response.
- Add test cases:
  - first create/log -> success
  - second same dedup key -> `409`
  - profile mismatch on same message id does not collide when scoped by profile id.
