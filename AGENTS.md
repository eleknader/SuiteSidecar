# SuiteSidecar Agent Steering

## Project Goals
- Build a secure connector service that exposes a stable API for the SuiteSidecar add-in.
- Keep API behavior predictable through explicit contracts and consistent error handling.
- Enable safe local development, testing, and deployment workflows.

## Non-Goals
- No direct changes to SuiteCRM instance code in this repository.
- No infrastructure-wide changes unless explicitly requested for the task.
- No embedding of environment-specific or secret values in versioned files.

## Repo Layout Overview
- `connector-php/`: PHP connector backend (public entrypoint, controllers, adapters, auth, config).
- `addin/`: Office add-in client code and package metadata.
- `docs/`: architecture, API, deployment, security, privacy, ADRs.
- `ops/`: scripts and operational helpers for local/server workflows.

## Coding Conventions
- Target PHP `8.1+`.
- Use `declare(strict_types=1);` in PHP source files.
- Follow PSR-4 autoloading and namespace-to-path mapping from `composer.json`.
- Keep framework-free components minimal, testable, and explicit.

## API Contract Rules
- `docs/OPENAPI.yaml` is the source of truth for request/response shapes.
- Any endpoint behavior change must update OpenAPI in the same change set.
- Error responses must follow the shared error schema and include `requestId`.

## Security Rules
- Never commit secrets, tokens, credentials, or internal-only infrastructure values.
- Use environment variables for sensitive values.
- Keep `connector-php/config/profiles.example.php` as placeholders only.
- Keep local runtime config in ignored files (for example `connector-php/config/profiles.php`).

## Definition of Done
- Code change is implemented and follows project conventions.
- Relevant docs are updated (`OPENAPI`, deployment, architecture, or security as needed).
- Smoke checks run successfully (for example `ops/scripts/smoke.sh`).
- Changes are committed with a clear message.

## Do-Not-Break Rules
- Do not modify Apache vhosts, Apache global config, or SuiteCRM instance configuration unless explicitly requested in the task.
- Do not perform destructive operations or unrelated refactors while addressing scoped work.
