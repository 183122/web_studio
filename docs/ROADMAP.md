# Roadmap / Phase backlog

แต่ละ Phase ส่งมอบเป็น vertical slice พร้อม migrations, API, authorization, UI, tests, docs (spec §18, §22)
การเปลี่ยนแปลงที่ทำลายข้อมูล, deploy production, เปิดใช้ billing และแก้ DNS ต้องได้รับอนุมัติก่อนเสมอ

## Phase 2 — Data, CRUD, app auth, forms, workflows (ถัดไป)
- [ ] Schema v2 + upcaster v1→v2 (TS/PHP) + parity fixtures: `dataModels`, `permissions`, `actions`, `workflows`
- [ ] M16 Data model designer: entities, field types (text, longtext, number, decimal, boolean, date, datetime, enum, email, phone, file, relation), constraints, indexes, migration preview, safe evolution (no destructive change without approval)
- [ ] App data plane: `app_data_models`, `app_records` (typed JSON + materialized indexed columns), `app_record_relations`, tenant/project scoping on every query, optimistic concurrency
- [ ] M17 CRUD/data views: table, filters, sort, pagination, validation, import/export CSV, bulk actions
- [ ] M18 App auth/RBAC: `app_users`, `app_roles`, `app_permissions`, row-level rules, separate app sessions
- [ ] M21 Forms: Form/Input/Select/Checkbox/Upload components bound to a model, server validation, spam control (honeypot + rate limit, CAPTCHA adapter), submissions, notifications
- [ ] M20 Workflows: event → condition → action graph (allowlisted actions: create/update record, send email, set status, webhook), idempotency, retries, dry run
- [ ] M19 REST API builder: allowlisted endpoints per model, scopes, rate limits, pagination, API keys
- [ ] Showcase: training registration template gains real registration form, admin dashboard, registrant CRUD, file attachments, status workflow; Google Sheets export stub disabled until OAuth configured
- [ ] Journeys: J2 (without AI part), J3

## Phase 3 — AI
- [ ] `packages/ai-adapters`: OpenAI/Anthropic/Gemini adapters, BYOK (encrypted), timeouts, retries, spend caps, redacted logging
- [ ] Planner → structured plan (missing requirements, confidence) → generator emitting **typed commands only** → validators → preview → user approval with diff, credit estimate, permission impact
- [ ] Conversational editor with scoped patches, partial regeneration, repair loop with budget
- [ ] Journeys: J2 (full), J10

## Phase 4 — Publishing & domains
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
