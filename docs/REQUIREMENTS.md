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
