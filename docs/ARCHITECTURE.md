# SuiteSidecar for Outlook — Architecture

> Universal Outlook add-in + connector for SuiteCRM.  
> This document describes the architecture, key design decisions, and the delivery plan.

> Current implementation direction (Q1 2026): contract-first connector hardening and
> incremental delivery of authenticated profile-scoped endpoints (`/auth/login`,
> `/auth/logout`, `/lookup/by-email`, `/entities/contacts`, `/entities/leads`,
> `/email/log`) before add-in UI expansion.

---

## 1. Goals

### 1.1 Primary goals
- Provide a **universal** Outlook integration for SuiteCRM that works across:
  - Outlook Desktop (classic where supported), Outlook on the web (OWA), and the new Outlook
  - Windows/macOS where applicable (host-dependent)
- Minimize adoption friction:
  - Simple deployment
  - Clear admin controls
  - Strong security posture
- Support **multiple SuiteCRM instances** (user-selectable profiles), without code changes.

### 1.2 Non-goals (initially)
- Deep Exchange-side features (EWS, Graph mailbox operations, server-side rules).
- CRM-specific custom workflows beyond generic SuiteCRM entities.
- Full parity with Dynamics/Salesforce enterprise add-ins in v1.

---

## 2. User Stories (MVP)

### 2.1 Sales / support user
- As a user, when I open an email, I want to instantly see the sender’s CRM context (Contact/Lead/Account).
- As a user, if the sender is not found, I want to create a Contact or Lead with prefilled fields.
- As a user, I want to log the email to SuiteCRM so the interaction history is preserved.

### 2.2 Admin / IT
- As an admin, I want to deploy the add-in centrally to Microsoft 365 users.
- As an admin, I want to configure the SuiteCRM endpoint(s) and security settings.
- As an admin, I want clear auditability and minimal risk to the CRM server.

---

## 3. System Overview

### 3.1 High-level components
- **Outlook Add-in (Office.js)**  
  Task pane UI running inside Outlook (sandboxed iframe).
- **Connector API (PHP)**  
  A small HTTP API that:
  - mediates SuiteCRM API calls
  - handles auth/token storage
  - provides stable contracts for the add-in
- **SuiteCRM** (target system)  
  One or more customer-hosted SuiteCRM instances (v8 preferred; legacy optional later).

### 3.2 Why a connector exists
- Avoid CORS/auth edge cases between sandboxed add-in runtime and arbitrary SuiteCRM deployments.
- Centralize security controls, token storage, and request shaping.
- Provide a stable API contract even if SuiteCRM API differences exist across versions.

---

## 4. Architecture Diagram (logical)

```text
+-----------------------------+
| Outlook (Desktop/Web/Mobile)|
|  - Office.js sandbox        |
|  - SuiteSidecar Add-in      |
+--------------+--------------+
               |
               | HTTPS (Add-in -> Connector API)
               v
+--------------+--------------+
| SuiteSidecar Connector (PHP)|
|  - Auth / tokens            |
|  - SuiteCRM adapters        |
|  - Normalized responses     |
+--------------+--------------+
               |
               | HTTPS (Connector -> SuiteCRM API)
               v
+--------------+--------------+
| SuiteCRM (customer instance)|
|  - v8 JSON:API (preferred)  |
|  - legacy API optional later|
+-----------------------------+
```

## 5. Data Flow

### 5.1 Email selection → CRM lookup

Add-in detects current item (initial load and on changes).

Add-in extracts:

sender email

message id (internetMessageId if available)

subject, time, minimal body preview (optional)

Add-in calls Connector:

GET /v1/lookup/by-email?email=...

Connector queries SuiteCRM:

Contacts by email

Leads by email (fallback)

Accounts (via related contact/lead where possible)

Connector returns a normalized object:

person summary + related entities summary

### 5.2 Log email to SuiteCRM

Add-in posts email metadata to connector:

message id, subject, from/to/cc, timestamp, body preview or full body (policy-controlled)

Connector creates log record(s) in SuiteCRM:

SuiteCRM Notes in MVP; SuiteCRM Emails may be added later as an optional strategy

Connector links the record to Contact/Lead/Account via relationships.

Current MVP decision:

- Email logging is implemented to SuiteCRM **Notes** records.
- SuiteCRM **Emails** module integration is out of MVP scope and treated as a later option.

### 5.3 Create Contact/Lead

Add-in opens a “Create” form prefilled from Outlook item.

Add-in posts to connector:

POST /v1/entities/contacts or POST /v1/entities/leads

Connector creates the record in SuiteCRM and returns created entity summary.

## 6. Key Design Decisions

### 6.1 Outlook add-in type

Task pane add-in with optional pinning for reading view.

Uses Office.js and supports multiple hosts where available.

### 6.2 Manifest format

Start with XML manifest for broad compatibility.

Roadmap: migrate or dual-support Unified manifest later if beneficial.

### 6.3 Connector language/runtime

Connector is PHP to maximize SuiteCRM community accessibility and “same stack” deployments.

Contract-first design (OpenAPI) allows alternate implementations later (Node/.NET).

### 6.4 SuiteCRM API compatibility

Prefer SuiteCRM v8 JSON:API.

Roadmap: optional adapter layer for legacy API (v4_1 / v7 style) if demand exists.

### 6.5 Normalized contract

Add-in never speaks SuiteCRM directly.

Add-in consumes a stable normalized schema (see docs/OPENAPI.yaml).

## 7. Security Model

### 7.1 Threat model (summary)

Add-in runs in a sandbox but handles sensitive customer data.

Primary risks:

token theft

data exfiltration

CSRF / request forgery against connector

insecure storage of CRM credentials

### 7.2 Auth between add-in and connector

Connector uses one of:

Signed session token (short-lived) issued after login

Optional: Microsoft identity sign-in later (future)

All traffic HTTPS only.

### 7.3 Auth between connector and SuiteCRM

SuiteCRM v8 OAuth2 token flow handled by connector.

Token storage:

encrypted at rest (file or DB; initial: filesystem with strict perms)

No SuiteCRM credentials stored in the add-in.

Current implementation (Q1 2026):

- Connector session state is stored as JSON files under `connector-php/var/sessions/<subjectId>.json`.
- Each session stores connector subject/profile plus SuiteCRM `access_token`, `refresh_token`, and token expiry.
- OAuth client-credential tokens are cached under `connector-php/var/tokens/<profileId>.json`.
- Runtime storage is server-local, excluded from git, and must be protected with strict filesystem permissions.

### 7.4 Permissions

Respect SuiteCRM user permissions:

connector acts on behalf of the user (user tokens), not as a global admin.

### 7.5 Logging and privacy

Configurable logging levels.

Avoid storing full email bodies unless explicitly enabled.

Clear retention policy for stored artifacts.

## 8. Deployment & Operations

### 8.1 Add-in hosting

Static hosting over HTTPS:

nginx/apache, CDN, or dedicated host

Versioning strategy:

semantic versioning

cache-busting assets

### 8.2 Connector hosting options

Apache vhost / subpath:

/suitesidecar/ (example)

Optional Docker-compose for easy deployment.

Health endpoints for monitoring:

/health

/version

### 8.3 Microsoft 365 distribution

Centralized deployment through M365 Admin Center (manifest upload).

AppSource publication later (requires additional compliance and processes).

## 9. Observability

### 9.1 Logs

Structured logs (JSON recommended).

Correlation id per request chain (add-in -> connector -> SuiteCRM).

### 9.2 Metrics (future)

Request latency

Lookup success rate

Log-email success rate

Error categories (auth, network, SuiteCRM validation)

## 10. Testing Strategy

### 10.1 Add-in tests

Unit tests for state and UI logic.

Mock connector calls.

### 10.2 Connector tests

Unit tests for:

auth/token logic

SuiteCRM adapter mapping

normalization layer

Integration tests against a SuiteCRM dev instance (optional, CI-gated).

### 10.3 End-to-end tests (future)

Playwright-based testing for the task pane where feasible.

## 11. MVP Scope and Phases

### 11.1 v0.1 (MVP)

Lookup by sender email (Contacts → Leads fallback)

Display person card + minimal related info

Create Contact/Lead (prefill)

Log email metadata as SuiteCRM Notes (without attachments initially)

Multi-instance profiles (basic)

### 11.2 v0.2

Attachments support (policy + size limits)

Timeline view (Activities/History)

Link logged email to selected CRM entity (manual selection)

### 11.3 v0.3+

Dynamic fields via SuiteCRM metadata (optional)

Admin policies (restrict modules, fields, retention)

Optional SSO / Microsoft identity integration

## 12. Open Questions

Which SuiteCRM module to use for email logging by default (Notes vs Emails)?

Minimum supported Outlook clients (new Outlook vs classic vs Mac differences).

## 13. References

Office Add-ins documentation (Office.js, Outlook add-ins, pinning task pane)

SuiteCRM developer docs (v8 JSON:API, OAuth2)

Project docs:

docs/OPENAPI.yaml

docs/ADR/*

docs/SECURITY.md

docs/DEPLOYMENT.md

## 14. Email Logging Strategy (SuiteCRM Mapping & Deduplication)

Email logging is one of the core value propositions of SuiteSidecar.
This section defines how Outlook emails are mapped into SuiteCRM entities,
how relationships are created, and how duplicate logging is prevented.

---

### 14.1 Default Module Strategy (MVP)

For v0.1, the connector will log emails using the **Notes** module in SuiteCRM.

#### Why Notes (not Emails) for MVP?

- The SuiteCRM Emails module has complex internal logic and dependencies.
- Notes are simpler, stable, and widely used for manual logging.
- Easier cross-version compatibility.
- Lower implementation risk for first public release.

Later versions may optionally support:
- Emails module (if API support is reliable across v8 installations)
- Custom module (configurable)

---

### 14.2 What Gets Stored

For each logged email, the connector will create a Note record with:

| Field | Source | Notes |
|-------|--------|-------|
| name | Email subject | Required |
| description | Plain text body (optional) | Policy-controlled |
| created_by | SuiteCRM user | From OAuth context |
| assigned_user_id | SuiteCRM user | From OAuth context |
| suitesidecar_message_id_c (custom field) | internetMessageId | Used for deduplication |
| suitesidecar_profile_id_c (custom field) | profileId | Scopes deduplication per connector profile |

Custom fields required (created during connector installation if missing):
- `suitesidecar_message_id_c` (varchar, indexed)
- `suitesidecar_profile_id_c` (varchar, indexed)

---

### 14.3 Relationships

The connector stores the target entity reference directly on the Note via:

- `parent_type`
- `parent_id`

Supported link targets (MVP):

- Contacts
- Leads
- Accounts
- Opportunities
- Cases

Connector flow:

1. Build payload from incoming email metadata.
2. Create Note in SuiteCRM v8 with `parent_type` and `parent_id`.
3. Return normalized `loggedRecord` response.

---

### 14.4 Deduplication Strategy

Duplicate prevention is critical because:
- Outlook add-ins may reload.
- ItemChanged event may fire multiple times.
- Users may click "Log Email" repeatedly.

#### Current uniqueness key strategy

Primary key (implemented now):

- `profileId + internetMessageId`
- Persisted in:
  - `suitesidecar_message_id_c`
  - `suitesidecar_profile_id_c`

Connector logic:

1. On log request, normalize incoming message identifiers.
   Current normalization trims whitespace, lowercases, and removes wrapping `< >`.
2. Query existing logs for the same `profileId + internetMessageId`.
3. If found, return `409 conflict` (`error.code = "conflict"`).
4. Otherwise create a new Note record.

#### Fallback when Message-ID is unavailable (planned)

If `internetMessageId` is missing on a host/client:

- Build a deterministic fingerprint from:
  - normalized sender
  - normalized recipients
  - normalized subject
  - sentAt (rounded to minute precision)
- Store fingerprint in the same uniqueness fields and apply the same lookup-first flow.

#### Indexing plan

- Index uniqueness fields in SuiteCRM for performance and race-risk reduction.
- If feasible in a target deployment, enforce uniqueness at DB/index level in addition to connector checks.

---

### 14.5 Attachments (v0.2+)

Attachments are NOT stored in v0.1.

Planned approach (v0.2):

- Each attachment becomes a separate Note or Document record.
- File content stored using:
  - `filecontents` attribute (base64)
- Relationship:
  - Link Document to main Note or directly to target entity.

Guardrails:
- Max file size configurable
- Attachment count limit configurable

---

### 14.6 Privacy & Data Minimization

Connector must support policies:

- `storeBody` (default: false)
- `storeAttachments` (default: false)

If disabled:
- Only metadata stored:
  - subject
  - participants
  - timestamps
  - message id

This enables compliance-friendly deployments.

---

### 14.7 Timeline Representation

Timeline entries shown in the add-in are normalized from:

- Notes (email logs)
- Calls
- Meetings
- Tasks

Sorted by `date_entered` or module-appropriate timestamp.

Connector returns a unified structure:

```json
{
  "type": "Note",
  "occurredAt": "2026-02-20T10:15:00Z",
  "title": "Re: Offer request",
  "summary": "Email logged from Outlook",
  "link": "https://crm.example.com/#Notes/123"
}
```

The add-in does not need to know which SuiteCRM module produced the entry.

### 14.8 Future: Emails Module Support

A future version may support logging into SuiteCRM's Emails module if:

v8 API stability is confirmed across real-world installations

Relationship handling is consistent

Threading support becomes relevant

This would likely be implemented as:

Config flag per profile:

emailLoggingStrategy: notes | emails

### 14.9 Operational Considerations

suitesidecar_message_id_c must be indexed for performance.

Connector must log:

create attempts

dedup hits

relationship failures

All log operations must emit a correlation requestId.

## 15. CRM Custom Field Requirements (Connector Installation Checklist)

The connector installer or setup script must ensure:

suitesidecar_message_id_c exists in Notes

Field is indexed

Field length >= 255

suitesidecar_profile_id_c exists in Notes

If missing:

Connector should fail gracefully with clear error message

Or provide CLI script to create them

## 16. Summary of Email Logging Design
Aspect	Decision
Module	Notes (MVP)
Dedup key	profileId + internetMessageId (stored in suitesidecar_message_id_c + suitesidecar_profile_id_c)
Attachments	Not in v0.1
Relationships	parent_type + parent_id on Note
Body storage	Policy-controlled
Timeline	Normalized structure

This approach prioritizes:

Stability

Cross-version compatibility

Low implementation risk

Clean upgrade path to more advanced email handling later
