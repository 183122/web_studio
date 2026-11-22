# Roadmap / Phase backlog

แต่ละ Phase ส่งมอบเป็น vertical slice พร้อม migrations, API, authorization, UI, tests, docs (spec §18, §22)
การเปลี่ยนแปลงที่ทำลายข้อมูล, deploy production, เปิดใช้ billing และแก้ DNS ต้องได้รับอนุมัติก่อนเสมอ

## Phase 2 — Data, CRUD, app auth, forms, workflows ✅ (2026-11-22)
- [x] Schema v2 + upcaster v1→v2 (TS/PHP) + parity fixtures: `dataModels`, `permissions`, `workflows`
- [x] M16 Data model designer: 11 field types, required/unique/options/min/max/maxLength, display field, migration impact preview, safe evolution
- [x] App data plane: typed JSON records, preview environment, atomic unique keys, optimistic concurrency, app audit log, record quota
- [x] M17 Generated admin: search, filters, numeric-aware sort, pagination, create/edit/delete, bulk delete, CSV export/import (dry run, all-or-nothing)
- [x] M18 App RBAC: explicit public/member/admin × CRUD matrix, default deny, enforced for preview runtime, forms and API keys
- [x] M21 Forms: Form/FormField components bound to models, client + server validation, honeypot, min fill time, rate limit, success state
- [x] DataTable component (paginated, searchable, permission-aware)
- [x] M20 Workflows: record.created/updated triggers, conditions, setField + queued e-mail, idempotent runs, no recursion, dry run, run history
- [x] M19 REST API: per-model endpoints with hashed, scoped, revocable API keys, rate limit, pagination
- [x] Showcase template: registration form → records → confirmation e-mail workflow → admin-only registrant list
- [x] Journeys: J2 (without AI), J3
- [x] App end-user accounts → delivered in Phase 4 (row-level "own" rules still deferred)
- [ ] Deferred: file/upload fields and relations between models → Phase 2.5 backlog (needs per-record file ACL and relation integrity rules)
- [ ] Deferred: CAPTCHA adapter (honeypot + timing + rate limit are in place)

## Phase 3 — AI ✅ (2026-11-22)
- [x] Provider gateway: Claude Opus 5.5 via the official Anthropic PHP SDK (structured outputs, effort, server-side refusal fallback), typed error mapping, BYOK (encrypted), timeouts/retries, credit budgets, redacted logging
- [x] Offline rules provider as safe fallback (no key / outage), clearly labelled
- [x] Planner → structured plan (missing requirements, assumptions, confidence) → approval
- [x] Deterministic compiler → typed commands only → dry-run validation → bounded repair loop
- [x] Proposal review (pages, models, permission impact, workflows, before/after, warnings, cost) → apply/reject; `ai` checkpoints for rollback
- [x] Conversational editor with scoped (selection/page) edits = partial regeneration
- [x] Journeys: J2 (full, with offline provider in E2E), J10 (PHPUnit: outage, invalid JSON, refusal, auth, truncation)
- [ ] Deferred: OpenAI and Gemini adapters (interface ready; not implemented)
- [ ] Deferred: live verification against the real Claude API (no key in this environment — SDK path tested with a fake transport)
- [ ] Deferred: streaming progress for long generations, image/brand-asset understanding, AI-generated sample data

## Phase 4 — Publishing & domains ✅ (2026-11-22)
- [x] App end-user accounts and member sessions for generated apps (from Phase 2); app-user admin
- [x] `apps/runtime-web` using `packages/renderer` in `live` mode; immutable release manifests; publish pipeline (freeze → validate → scan → manifest → stage → smoke → switch → health); rollback; unpublish
- [x] Slug reservation (system names blocked, cooldown), Host-based routing with validated Host, trusted proxies only
- [x] M26 shareable preview links with expiry; M28 custom domains (TXT/CNAME verification, DNS/TLS provider abstraction, certificate status, removal/reassignment with revalidation, scheduled re-check)
- [x] Require verified e-mail to publish
- [x] Journeys: J1 (full), J6, J9 (+ J8 for published hosts)
- [ ] Deferred: server-side rendering of page content for crawlers (title/description are server-rendered now), sitemap.xml/robots.txt per site, CDN/static pre-render
- [ ] Deferred: real DNS + ACME issuance verified against a live edge (needs approval for DNS changes and production deployment)
- [ ] Deferred: row-level "own records" rules, password reset/e-mail verification for end users, CAPTCHA

## Phase 5 — Integrations ✅ (2026-11-22)
- [x] Platform admin settings for the Google OAuth client (encrypted; env takes precedence)
- [x] M22 Google OAuth per workspace: PKCE, least-privilege features, encrypted refresh tokens, refresh under lock, revoke/disconnect, reconnection UX
- [x] M23 Sheets: link by URL, tab preview, mapping, dry run, import/export (append/update), two-way with conflict policy, schedules, job history, backoff + dead letter + retry
- [x] M24 Drive: app-created folder (`drive.file`), upload with checks, attach to records, access re-check per download, deleted/revoked handling
- [x] M25 Outbound webhooks with SSRF allowlist, HMAC signatures, retries/DLQ, redelivery
- [x] Journeys: J4, J5 (against a fake Google server; PHPUnit with an HTTP fake)
- [ ] Deferred: verification against real Google + OAuth app verification (`docs/runbooks/google-oauth-review.md`)
- [ ] Deferred: Google Picker (choose files/folders not created by the app), incremental authorization, per-organization BYOK OAuth clients
- [ ] Deferred: workflow action "call webhook / send to connector", SMS, custom provider adapters; SMTP settings UI (mail is configured via env)
- [ ] Deferred: file fields in data models (attachments are record-level for now)

## Phase 6 — SaaS & hardening
- [ ] Billing provider adapter (Thai-compatible), webhooks (HMAC, replay protection, idempotency), dunning, invoices, downgrade policy
- [ ] Metering (AI tokens, build seconds, storage, bandwidth, requests) with atomic reserve/commit
- [ ] Super admin, support impersonation with approval + audit, abuse flags
- [ ] Workspace invitations/switcher, 2FA/passkeys, session management UI
- [ ] PDPA: consent records, export/delete, retention schedules; audit log hash chain
- [ ] Observability (tracing, metrics, error monitoring), SAST/DAST in CI, backup/restore drills, runbooks
- [ ] Journey: J7

## Phase 7
- [ ] Template marketplace (moderation, licensing), real-time collaboration, full code export, sandboxed custom code (after security review)

## Dependency upgrades to evaluate
- TypeScript 7 (native compiler), React Router 8, Vite 8, Vitest 5 — currently on the previous stable majors (ADR-0001).
