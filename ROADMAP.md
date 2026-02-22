# SuiteSidecar Roadmap

## Guiding Direction
- Chosen track: **Version A (security-first / stability-first)**.
- Roadmap language stays product-generic and organization-neutral.
- Enterprise readiness is prioritized before major feature expansion.

## v0.2 - Implemented (validated 2026-02-21)
- Request-scoped profile selection and stabilized `/auth/login` + `/auth/logout` session handling.
- Add-in auto-refresh on Outlook item change and form reset on context switch.
- `/email/log` to SuiteCRM Notes with deduplication (`profileId + internetMessageId`).
- Attachment support with policy flags + size guardrail (`maxAttachmentBytes`) and sent/skipped status reporting.
- Attachment persistence linked to the logged email Note (with fallback to target entity if Notes parenting is rejected).
- Timeline lookup (`include=timeline`) with SuiteCRM deep links in format `/#/module/record/{id}`.
- Cache-busting/versioned add-in asset URLs and no-cache hosting guidance.

## v0.3 - Implemented (validated 2026-02-22)
- Taskpane UI streamlined for Outlook classic workflow: automatic lookup path, compact panel order, and reduced non-essential controls.
- Details panel moved below top actions and collapsed by default when a CRM match is found.
- Connector/Login cards hidden when authenticated; business panels hidden while unauthenticated.
- Session/settings restore between Outlook launches, including startup auth-state messaging fix.
- `Create Contact` / `Create Lead` shown only when lookup returns `notFound=true`.
- Attachment defaults updated to `storeAttachments=true` and `maxAttachmentBytes=5242880` (5 MB).
- Release/version hygiene completed in manifests and taskpane cache-busting URLs.

## v0.4 - Release Hardening and Operability
- Consolidate repeatable release flow for connector + add-in artifacts.
- Improve support diagnostics and runbooks around `requestId`-based triage.
- Expand smoke/e2e coverage for timeline + attachments (including skip/limit scenarios).
- Align add-in attachment limits with server PHP limits and define `413` behavior.
- Standardize version hygiene (manifest/taskpane/API product version visibility).

## v0.5 - Host Compatibility and UX Reliability
- Finalize supported Outlook host matrix and host-specific fallback behavior.
- Stabilize auto-lookup and item-change handling across Outlook Desktop/Web hosts.
- Improve user-facing status clarity for auth, lookup, and attachment outcomes.
- Keep workflow compact while preserving operational transparency for support.

## v0.6 - Security Baseline (Enterprise Foundation)
- Harden session/token lifecycle (expiry, cleanup, storage policy, invalidation path).
- Implement CRM token refresh or deterministic re-auth flow with clear UI signaling.
- Add rate limiting on high-risk endpoints (`/auth/login`, `/email/log`, create endpoints).
- Introduce structured audit events for login/logout/create/log/conflict/error flows.
- Define key rotation procedure and incident-response minimum runbook.

## v0.7 - Governance and Policy Controls
- Add server-enforced policy controls for logging behavior (body, attachments, limits).
- Add readiness checks beyond liveness (config/env/profile/runtime prerequisites).
- Improve supportability with sanitized diagnostic bundle and incident hooks.
- Strengthen admin/deployment documentation for controlled tenant rollout.

## v0.8 - Enterprise Identity and Access
- Add optional enterprise SSO path (OIDC/SAML-compatible) with secure fallback login.
- Introduce RBAC for administrative/configuration operations.
- Add session governance improvements (revocation strategy, session visibility controls).
- Expand audit coverage for identity and admin actions.

## v0.9 - Compatibility and SLO Readiness
- Define and enforce API compatibility/deprecation policy for v1.0 stabilization.
- Separate product version and API contract version semantics explicitly.
- Add contract-diff test gates and expanded CI quality gates.
- Define baseline service metrics and alerting targets (latency, success/error rates).

## v1.0 - Enterprise Minimum Complete Product
- Ship a security-hardened, auditable, supportable Outlook-to-CRM integration baseline.
- Publish clear supported-host/client matrix and deployment model.
- Finalize operational acceptance criteria: monitoring, alerts, runbooks, rollback.
- Freeze stable API contract and provide upgrade/migration notes.

## Deferred Beyond v1.0
- Rich HTML body persistence/rendering strategy with policy controls.
- Optional per-profile logging backend strategy (`notes | emails`) if risk and compatibility are acceptable.
