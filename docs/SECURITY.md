# Configuration & Secrets Policy

- `connector-php/config/profiles.example.php` is versioned and must contain only placeholder values.
- `connector-php/config/profiles.php` is local-only and must never be committed.
- OAuth client credentials must be provided via environment variables, not hardcoded in repository files.
- Never commit `clientId`, `clientSecret`, access tokens, or token cache files.
- If any secret is exposed, rotate it immediately and remove leaked values from history.
- `connector-php/.env` is local-only (gitignored) for local dev; production should use server-owned env files (for example `/etc/suitesidecar/suitesidecar.env`).

## Public-Safe vs Local-Only Split

- Versioned files must stay public-safe and free of secrets.
- `connector-php/config/profiles.example.php` is for placeholders only.
- `connector-php/config/profiles.php` is local-only and ignored by git.
- `connector-php/var/` is runtime-only and ignored by git (tokens/sessions must never be committed).
- Keep operational machine-specific notes in `AGENTS.local.md` (ignored).

## Tenant Routing Hardening

- For multi-tenant host/subdomain routing, prefer strict mode:
  - strict mode auto-enables when host mappings are configured.
  - optional override: `SUITESIDECAR_REQUIRE_HOST_ROUTING=true|false`
  - when strict mode is active, unmapped request hosts are rejected.
- Do not trust `X-Forwarded-Host` on direct/public connector traffic.
  - Keep `SUITESIDECAR_TRUST_X_FORWARDED_HOST=false` unless connector is behind a controlled reverse proxy.
- If `SUITESIDECAR_TRUST_X_FORWARDED_HOST=true`, restrict sources with:
  - `SUITESIDECAR_TRUSTED_PROXY_IPS=<comma-separated proxy IPs/CIDRs>`
  - forwarded host is ignored when the trusted proxy list is empty
- Avoid overlapping profile host patterns that could route a host to multiple profiles.
  - startup rejects ambiguous cross-profile host mappings (exact-vs-exact, exact-vs-wildcard, wildcard-vs-wildcard overlap)
- Restrict browser origins for API access in production:
  - set `SUITESIDECAR_ALLOWED_ORIGINS=<comma-separated origins>`
  - disallowed browser origins are rejected with `403 forbidden`
  - leaving the variable unset keeps development-friendly wildcard CORS (`*`)
