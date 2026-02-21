# Configuration & Secrets Policy

- `connector-php/config/profiles.example.php` is versioned and must contain only placeholder values.
- `connector-php/config/profiles.php` is local-only and must never be committed.
- OAuth client credentials must be provided via environment variables, not hardcoded in repository files.
- Never commit `clientId`, `clientSecret`, access tokens, or token cache files.
- If any secret is exposed, rotate it immediately and remove leaked values from history.

## Public-Safe vs Local-Only Split

- Versioned files must stay public-safe and free of secrets.
- `connector-php/config/profiles.example.php` is for placeholders only.
- `connector-php/config/profiles.php` is local-only and ignored by git.
- Keep operational machine-specific notes in `AGENTS.local.md` (ignored).
