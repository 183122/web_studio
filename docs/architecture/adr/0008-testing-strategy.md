# ADR-0008: Testing strategy

- Status: Accepted (Phase 0)
- Layers:
  1. **Schema parity** (Vitest + PHPUnit) on shared fixtures — guarantees client and server agree on every command and validation rule.
  2. **API feature tests** (PHPUnit) on SQLite and MySQL 8 — auth, tenancy isolation, quotas, concurrency, idempotency, assets.
  3. **Unit tests** for renderer and editor store (Vitest + jsdom).
  4. **E2E** (Playwright) against real Laravel + the production build of the studio — user journeys J1 (Phase 1 scope), dashboard, drag-and-drop, assets, conflict merge, J8 isolation.
- Rule: every new phase adds its journeys (J2–J10) before being marked done; no test is skipped to get green.
