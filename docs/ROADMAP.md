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
- [ ] Deferred: app end-user accounts (sign-up/login inside generated apps, "member" sessions, row-level "own" rules) → with the published runtime in Phase 4
- [ ] Deferred: file/upload fields and relations between models → Phase 2.5 backlog (needs per-record file ACL and relation integrity rules)
- [ ] Deferred: CAPTCHA adapter (honeypot + timing + rate limit are in place)

## Phase 3 — AI
- [ ] `packages/ai-adapters`: OpenAI/Anthropic/Gemini adapters, BYOK (encrypted), timeouts, retries, spend caps, redacted logging
- [ ] Planner → structured plan (missing requirements, confidence) → generator emitting **typed commands only** → validators → preview → user approval with diff, credit estimate, permission impact
- [ ] Conversational editor with scoped patches, partial regeneration, repair loop with budget
- [ ] Journeys: J2 (full), J10

## Phase 4 — Publishing & domains
- [ ] App end-user accounts and member sessions for generated apps (from Phase 2)
- [ ] `apps/runtime-web` using `packages/renderer` in `live` mode; immutable release manifests; publish pipeline (freeze → validate → scan → artifact → stage → smoke → switch → health); rollback; unpublish
- [ ] Slug reservation (system names blocked), wildcard routing with validated Host, trusted proxies only
- [ ] M26 shareable preview links with expiry; M28 custom domains (TXT/CNAME verification, provider abstraction, certificate status, removal/reassignment with revalidation)
- [ ] Require verified e-mail to publish
- [ ] Journeys: J1 (full), J6, J9

## Phase 5 — Integrations
- [ ] Google OAuth (least privilege, PKCE, encrypted refresh tokens, revoke), Sheets mapping/import/sync with conflict policy + DLQ, Drive picker/attachments
- [ ] Generic REST/webhook connectors with SSRF allowlist; transactional e-mail
- [ ] Journeys: J4, J5

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
