# ADR-0007: Studio authentication

- Status: Accepted (Phase 0)
- Decision:
  - Laravel Sanctum **SPA cookie sessions** (HttpOnly session cookie + XSRF token), the SPA and API share one origin through a reverse proxy (`vite` in dev, nginx in prod). No bearer tokens in browser storage.
  - Registration provisions organization + default workspace in one transaction; e-mail verification via signed links (required before publishing in Phase 4); password reset revokes all sessions; login throttled per email+IP and per IP; generic responses prevent account enumeration.
  - Passkeys/2FA, OAuth login and session management UI are scheduled in the M01 backlog (Phase 6 hardening).
