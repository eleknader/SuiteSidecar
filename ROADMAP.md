# SuiteSidecar Roadmap

## v0.2 - Implemented (validated 2026-02-21)
- Request-scoped profile selection and stabilized `/auth/login` + `/auth/logout` session handling.
- Add-in auto-refresh on Outlook item change and form reset on context switch.
- `/email/log` to SuiteCRM Notes with deduplication (`profileId + internetMessageId`).
- Attachment support with policy flags + size guardrail (`maxAttachmentBytes`) and sent/skipped status reporting.
- Attachment persistence linked to the logged email Note (with fallback to target entity if Notes parenting is rejected).
- Timeline lookup (`include=timeline`) with working SuiteCRM deep links in format `/#/module/record/{id}`.
- Cache-busting/versioned add-in asset URLs and no-cache hosting guidance.

## v0.3 - Implemented (validated 2026-02-22)
- Taskpane UI streamlined for Outlook classic workflow: automatic lookup path, compact panel order, and reduced non-essential controls.
- Details panel moved below top actions and kept collapsed by default when a CRM match is found.
- Connector/Login cards hidden when authenticated; business panels hidden while not authenticated.
- Session/settings restore between Outlook launches, including startup auth-state messaging fix to avoid false “session restored” state.
- Create Contact / Create Lead shown only when lookup returns `notFound=true`.
- Attachment defaults updated to store attachments enabled and `maxAttachmentBytes=5242880` (5 MB).
- Release/version hygiene completed in manifests and taskpane cache-busting URLs.

## v0.4 - Packaging/Deployment Hardening
- Consolidate deployment workflow for connector + add-in.
- Improve operational diagnostics and support runbooks.
- Expand automated smoke/e2e coverage for attachment and timeline scenarios.
- Document and validate alignment between add-in attachment max size and server PHP upload limits.

## v0.5 - UI/UX Refinements
- Resolve remaining taskpane UX issues and improve status clarity.
- Keep host behavior consistent across Outlook Desktop/Web.

## v1.x+ - Distant Future
- Rich formatted email body persistence and rendering strategy (HTML-safe storage + policy controls).
- Optional SuiteCRM Emails-module logging strategy (`notes | emails`) per profile.
