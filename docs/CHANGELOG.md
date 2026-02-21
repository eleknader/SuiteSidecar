# SuiteSidecar Changelog

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
