# SuiteSidecar Changelog

## v0.5 feature snapshot (2026-02-23)

### Connector
- Added lookup action deeplinks for pre-related Calls/Meetings create forms.
- Added `POST /tasks/from-email` with idempotent dedup (`message ids`) and task provenance metadata.
- Added `GET /opportunities/by-context` for lightweight latest-opportunities panel data.
- Added `/version` metadata fields for release diagnostics (`apiVersion`, optional add-in manifest/asset versions).

### Add-in
- Added quick actions: `Create Call`, `Create Meeting`, `Create Task`.
- Added opportunities panel rendering (latest 5 + `View all` link).
- Added task-create result UX (`Task created` / `Task already exists` + `Open Task`).
- Added recipient-first lookup target selection for sent-mail context (fallback to sender).

### Ops
- Added consolidated release packaging script `ops/scripts/package-release.sh` (connector + add-in artifacts).
- Expanded smoke/e2e coverage for timeline include, task dedup, opportunities endpoint, and attachment/`413` behaviors.

## v0.4 hardening snapshot (2026-02-22)

### Connector
- Added deterministic `413 payload_too_large` handling for oversized `/email/log` requests.
- Added runtime request/attachment limits to `/version` response (`limits.*`).
- Added optional env guardrails `SUITESIDECAR_MAX_REQUEST_BYTES` and `SUITESIDECAR_MAX_ATTACHMENT_BYTES`.

### Add-in
- Added connector runtime-limit sync from `/version`.
- Added client-side payload-size preflight before `/email/log`.
- Added explicit `413` user-facing handling with size guidance.

## v0.2 implementation snapshot (2026-02-21)

### Connector
- Implemented authenticated profile-scoped flows for lookup, create, and log operations.
- Implemented timeline aggregation for Notes, Calls, Meetings, and Tasks in lookup responses.
- Implemented attachment persistence with size guardrails and policy options.
- Updated deep links to SuiteCRM route format `/#/module/record/{id}`.
- Attachment records are linked to the logged email Note; fallback to target entity is used if Notes-parenting is rejected by target CRM.
- Email deduplication is enforced by `profileId + internetMessageId` with `409 conflict` mapping.

### Add-in
- Added timeline rendering in taskpane lookup results.
- Added attachment logging options (`storeAttachments`, `maxAttachmentBytes`) and sent/skipped status feedback.
- Added `storeBody` behavior aligned with plain-text Note description.
- Added Outlook item-change handling with context refresh and auto-lookup while authenticated.
- Added cache-busting version query strings for taskpane assets and manifest taskpane URL.

### Known constraints
- Note description stores plain text only; rich HTML formatting is not preserved.
- `message.bodyHtml` is reserved for future rich-body support and is not persisted in current Notes flow.

### Deferred to distant future
- Rich formatted body persistence/rendering strategy.
- Optional per-profile `notes | emails` logging strategy.
