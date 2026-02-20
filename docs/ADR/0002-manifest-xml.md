# ADR 0002: Start with XML manifest for the Outlook add-in (Unified manifest later)

- Status: Accepted
- Date: 2026-02-20
- Owners: SuiteSidecar maintainers

---

## Context

SuiteSidecar is an Outlook Web Add-in. The add-in must be deployable in real customer environments
with minimal friction and maximum compatibility across Outlook hosts.

Microsoft supports:
- A traditional **XML add-in manifest** (Outlook add-in manifest)
- A newer **Unified manifest** (JSON) for Microsoft 365, which is evolving and has broader
  cross-host ambitions.

We want an initial release path that:
- works broadly in production deployments
- matches common admin workflows (manifest upload, centralized deployment)
- has predictable tooling and validation

---

## Decision

We will start with the **XML manifest** for v0.x releases.

We may add Unified manifest support later when:
- it provides clear benefits for Outlook deployment and lifecycle
- tooling and required schemas are stable for our use cases
- we have bandwidth to test across relevant Outlook clients

---

## Rationale

- **Compatibility:** XML manifests are widely used and supported for Outlook add-ins in production.
- **Tooling maturity:** Scaffolding tools and examples are abundant and stable.
- **Operational predictability:** Centralized deployment with XML manifests is a known path for M365 admins.
- **Focus:** Avoid spending early engineering time on manifest format churn.

---

## Consequences

### Positive
- Faster MVP delivery with fewer platform uncertainties.
- Easier onboarding for organizations that already deploy Outlook add-ins via XML manifest.

### Negative
- We may need migration work later if Unified manifest becomes the preferred path for certain scenarios.

### Mitigations
- Keep manifest-related decisions isolated:
  - Add-in code should not depend on manifest format.
  - Document requirements and constraints in `docs/DEPLOYMENT.md`.

---

## Alternatives Considered

1) **Unified manifest (JSON) from day one**
- Not chosen for MVP to avoid early dependency on evolving schema/tooling and to reduce
  cross-client surprises.

2) **Support both from the beginning**
- Rejected for MVP due to increased test matrix and maintenance cost.

---

## Implementation Notes

- Store manifests under `addin/manifest/` with versioned filenames if needed.
- Ensure all add-in resources are served over HTTPS.
- Ensure icons and static assets are cache-friendly (avoid `no-cache` headers on required assets).

---