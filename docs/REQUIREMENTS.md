# SuiteSidecar Requirements

## Purpose
This document defines minimum SuiteCRM-side requirements for:
- email-log dedup support
- conflict mapping preparation (`409 Conflict` behavior in connector logic)

## SuiteCRM Instance Requirements
- SuiteCRM v8 instance reachable from connector over HTTPS.
- OAuth token flow working for connector integration user.
- Integration user permissions for `Contacts`, `Leads`, and `Notes` (read/create).
- Legacy customization structure available under:
  - `public/legacy/custom/Extension/modules/...`
  - `public/legacy/custom/modules/...`

## Contacts Notes Subpanel Requirement

### Why this is required
- SuiteSidecar logs emails as `Notes` linked to `Contacts` using `parent_type` + `parent_id`.
- For practical use, users must see linked Notes directly in the Contact record.
- In this environment, `Notes` is treated as a core module and cannot be reliably added via Studio subpanel editor.

### Implementing instructions
Apply in `public/legacy`:

- `custom/Extension/modules/Contacts/Ext/Layoutdefs/suitesidecar_notes_subpanel.php`

Content:

```php
<?php

$layout_defs['Contacts']['subpanel_setup']['suitesidecar_notes'] = array(
    'order' => 100,
    'module' => 'Notes',
    'subpanel_name' => 'default',
    'sort_order' => 'desc',
    'sort_by' => 'date_entered',
    'title_key' => 'LBL_NOTES',
    'get_subpanel_data' => 'notes_parent',
    'top_buttons' => array(
        array(
            'widget_class' => 'SubPanelTopCreateNoteButton',
        ),
        array(
            'widget_class' => 'SubPanelTopSelectButton',
            'popup_module' => 'Notes',
        ),
    ),
);
```

### Rebuild / apply steps

1. Add/update extension file:
  - `public/legacy/custom/Extension/modules/Contacts/Ext/Layoutdefs/suitesidecar_notes_subpanel.php`
2. Run Quick Repair and Rebuild in admin UI:
  - `Admin -> Repair -> Quick Repair and Rebuild`
3. Ensure generated file is updated:
  - `public/legacy/custom/modules/Contacts/Ext/Layoutdefs/layoutdefs.ext.php`
4. Clear Suite8 Symfony cache as web user from SuiteCRM project root:

```bash
sudo -u <web-user> php bin/console cache:clear
sudo -u <web-user> php bin/console cache:warmup
```

### Critical permission requirement
- Web server user must be able to read/traverse:
  - `public/legacy/custom/modules/Contacts/Ext/Layoutdefs/`
  - `public/legacy/custom/Extension/modules/Contacts/Ext/Layoutdefs/`
- If this path is not readable, Home dashlets and metadata builds can fail, and subpanel state becomes inconsistent.
- Recommended baseline:
  - directories: `2775`
  - files: `664`
  - group: `<web-user-group>`

### Verification
- Check that generated file exists and contains the new key:
  - `public/legacy/custom/modules/Contacts/Ext/Layoutdefs/layoutdefs.ext.php`
  - key: `suitesidecar_notes`
- Confirm value:
  - `'get_subpanel_data' => 'notes_parent'`
- Opening a Contact record shows a dedicated `Notes` subpanel.
- New email logs from SuiteSidecar appear in this subpanel when linked to that Contact.
- If subpanel is visible but empty while note record exists:
  - verify note links are set in `notes.parent_type='Contacts'` and `notes.parent_id=<contact-id>`
  - verify subpanel uses `notes_parent` (not `notes`)

### Rollback
- Remove:
  - `public/legacy/custom/Extension/modules/Contacts/Ext/Layoutdefs/suitesidecar_notes_subpanel.php`
- Re-run rebuild + QRR.

## Notes Body and Attachment Constraints (v0.2)
- Logged email body is persisted to `Notes.description` as plain text when `storeBody=true`.
- `Notes.description` does not preserve original HTML formatting from Outlook body.
- Rich/HTML body preservation is a future feature and not required for current deployment.
- Attachment persistence is size-limited by:
  - add-in `maxAttachmentBytes` option
  - connector/PHP request limits (`upload_max_filesize`, `post_max_size`)

## Parent Note Child Notes Subpanel Requirement

### Why this is required
- SuiteSidecar stores email attachments as child `Notes`.
- Child notes are linked to the main email note using:
  - `notes.parent_type = 'Notes'`
  - `notes.parent_id = <main-email-note-id>`
- Users must be able to view these related child notes from the main note record.

### Implementing instructions
Apply in `public/legacy`:

- `custom/Extension/modules/Notes/Ext/Vardefs/suitesidecar_note_children.php`
- `custom/Extension/modules/Notes/Ext/Layoutdefs/suitesidecar_child_notes_subpanel.php`
- `custom/modules/Notes/metadata/subpanels/suitesidecar_child_notes.php`

Minimal required behavior:
- Define one self-referencing relationship from `Notes` to `Notes` (`one-to-many`) using `rhs_key = parent_id` and role column `parent_type = 'Notes'`.
- Define two link fields for the same relationship:
  - children link for subpanel data (`suitesidecar_child_notes`)
  - parent-side link with `'side' => 'right'` and `'link_type' => 'one'` (`suitesidecar_parent_note`)
- Configure Notes subpanel setup with:
  - `'get_subpanel_data' => 'suitesidecar_child_notes'`
  - custom `subpanel_name` matching metadata file.

### Rebuild / apply steps
1. Rebuild extensions (at minimum for `Notes`) and run Quick Repair and Rebuild.
2. Ensure generated files include new definitions:
  - `public/legacy/custom/modules/Notes/Ext/Vardefs/vardefs.ext.php`
  - `public/legacy/custom/modules/Notes/Ext/Layoutdefs/layoutdefs.ext.php`
3. Clear Symfony cache as web user.

### Verification
- Open a main email note record.
- Confirm child notes (for attachments) are visible in the Notes subpanel on that note.
- If subpanel exists but shows no rows:
  - verify child rows have `parent_type='Notes'` and correct `parent_id`
  - verify both link fields were generated in Notes vardefs
  - verify `get_subpanel_data` points to `suitesidecar_child_notes`
  - check for relationship runtime error in logs (`One2MRelationship`), which indicates incomplete self-reference link definitions.

## UX Side Effects of Logging Emails as Notes
- Email-as-Note strategy can add visual clutter in SuiteCRM UI (Activities, History, Notes, Calls all populated).
- Current behavior is intentionally preserved for v0.2.
- Attachment child notes are now shown from the parent email note record via a dedicated Notes subpanel.
- Future enhancement direction:
  - reduce clutter by grouping/filtering integration-created notes in relevant subpanels/views

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
Applied in SuiteCRM project root:

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
Run in SuiteCRM project root:

```bash
sudo -u <web-user> php bin/console cache:clear --no-warmup
sudo -u <web-user> php bin/console cache:warmup
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
