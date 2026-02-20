# ADR 0001: Use a Connector API (PHP) between the Outlook add-in and SuiteCRM

- Status: Accepted
- Date: 2026-02-20
- Owners: SuiteSidecar maintainers

---

## Context

SuiteSidecar is an Outlook Web Add-in (Office.js) that must work across multiple Outlook hosts
(Desktop/Web/New Outlook/Mobile where supported). The add-in runs in a sandboxed iframe and must
interact with one or more arbitrary SuiteCRM deployments (different domains, SSL, reverse proxies,
versions, and security policies).

Direct browser calls from the add-in to SuiteCRM commonly run into:
- CORS restrictions and inconsistent headers across deployments
- OAuth/token handling challenges in a sandboxed environment
- Version differences and API quirks between SuiteCRM instances
- Higher security risk if CRM credentials or tokens are handled client-side
- Difficult troubleshooting (no centralized logs/correlation)

We want a universal integration that is easy to deploy for SuiteCRM admins and easy to contribute to
for the SuiteCRM community.

---

## Decision

We will implement a **Connector API** that the Outlook add-in talks to exclusively.

The Connector API will be implemented in **PHP** as the first reference implementation.

Key properties:
- The add-in communicates with the connector over HTTPS using a stable contract (`docs/OPENAPI.yaml`).
- The connector communicates with SuiteCRM (prefer v8 JSON:API) and normalizes responses.
- SuiteCRM tokens/credentials are stored server-side by the connector (never in the add-in).
- The connector enforces security, rate limits, request shaping, and deduplication.

---

## Rationale

### Why a connector at all?
- **Universality:** Avoid per-customer CORS and browser auth edge cases.
- **Security:** Keep SuiteCRM tokens server-side; reduce token exfiltration risk.
- **Stability:** Provide a stable API contract even if SuiteCRM versions differ.
- **Observability:** Centralize logging, correlation IDs, and troubleshooting.
- **Policy controls:** Allow admins to enforce retention, module restrictions, and data minimization.

### Why PHP for the connector?
- **SuiteCRM ecosystem alignment:** SuiteCRM developers and admins are typically comfortable with PHP.
- **Deployment familiarity:** Many SuiteCRM deployments already run Apache/PHP; the connector can be
  hosted alongside SuiteCRM with minimal additional infrastructure.
- **Contribution barrier:** Lower for the existing SuiteCRM community.

---

## Consequences

### Positive
- Simplifies add-in implementation and hardens security.
- Minimizes customer-side configuration (no CORS spelunking).
- Enables future compatibility layers (e.g., legacy API support) without changing the add-in.
- Makes it feasible to add alternative connectors later (Node/.NET) without breaking the add-in.

### Negative
- Introduces an extra deployable component (connector) and its operational responsibilities.
- Requires careful connector hardening (rate limits, input validation, auth, logging).

### Neutral / Mitigations
- Contract-first design (OpenAPI) keeps the system modular.
- Provide Docker and Apache deployment options to reduce ops friction.

---

## Alternatives Considered

1) **Direct add-in → SuiteCRM API calls**
- Rejected due to CORS/auth variability, security concerns, and reduced universality.

2) **Node/TypeScript connector as the only implementation**
- Not chosen as default because PHP lowers the adoption barrier for the SuiteCRM community.
- Still allowed later as an additional reference implementation.

3) **SuiteCRM in-process plugin/module only (no connector)**
- Rejected as it would require per-instance installation inside SuiteCRM and can increase upgrade risk.
  A standalone connector is easier to version and operate independently.

---

## Implementation Notes

- Connector implements the public contract defined in `docs/OPENAPI.yaml`.
- Connector stores SuiteCRM tokens server-side with encryption-at-rest where feasible.
- Connector emits correlation IDs (requestId) for troubleshooting and support.
- Connector should be deployable:
  - behind Apache as a vhost/subpath
  - via Docker Compose for quick-start

---