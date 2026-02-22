# SuiteSidecar Roadmap

## v0.2 - Implemented (validated 2026-02-21)
- Request-scoped profile selection and stabilized `/auth/login` + `/auth/logout` session handling.
- Add-in auto-refresh on Outlook item change and form reset on context switch.
- `/email/log` to SuiteCRM Notes with deduplication (`profileId + internetMessageId`).
- Attachment support with policy flags + size guardrail (`maxAttachmentBytes`) and sent/skipped status reporting.
- Attachment persistence linked to the logged email Note (with fallback to target entity if Notes parenting is rejected).
- Timeline lookup (`include=timeline`) with working SuiteCRM deep links in format `/#/module/record/{id}`.
- Cache-busting/versioned add-in asset URLs and no-cache hosting guidance.

## v0.3 - Stabilization and Test Coverage
- Fix UI look & feel for cleaner
- Keep settings between sessions, restore connection automatically when opening Outlook / SuiteSidecar panel
- Hide Connector and Login -parts when logged in and session valid
- Hide all other panels exept Connector, Login and Status when not logged in
- Expand automated smoke/e2e coverage for attachment and timeline scenarios.
- Finalize release checklist for manifest/version/cache updates.
- Complete remaining UI polish and host-specific compatibility checks.

## v0.4 - Packaging/Deployment Hardening
- Consolidate deployment workflow for connector + add-in.
- Improve operational diagnostics and support runbooks.

## v0.5 - UI/UX Refinements
- Resolve remaining taskpane UX issues and improve status clarity.
- Keep host behavior consistent across Outlook Desktop/Web.

## v1.x+ - Distant Future
- Rich formatted email body persistence and rendering strategy (HTML-safe storage + policy controls).
- Optional SuiteCRM Emails-module logging strategy (`notes | emails`) per profile.
