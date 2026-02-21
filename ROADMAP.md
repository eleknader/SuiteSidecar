# SuiteSidecar Roadmap

## v0.2 - Auth/Login + Profile Selection
- Finalize request-scoped profile selection behavior.
- Stabilize `/auth/login` and token/session handling.
- Validate error mapping and request tracing in responses.

## v0.3 - Email Logging MVP
- Introduce `/email/log` MVP flow with profile-aware routing.
- Add minimal persistence/audit strategy and API contract updates.
- Add smoke coverage for primary logging scenarios.

## v0.4 - Add-in Scaffolding (Office.js) + Basic UI
- Establish Office.js add-in scaffold integrated with connector endpoints.
- Implement basic authenticated UI flow and lookup interaction.
- Document local developer run/test path for add-in + connector.

## v0.5 - Packaging/Deployment + Centralized Deployment Notes
- Consolidate packaging and deployment workflow for connector and add-in.
- Standardize release checklist and environment rollout steps.
- Centralize deployment notes and operational troubleshooting guidance.
