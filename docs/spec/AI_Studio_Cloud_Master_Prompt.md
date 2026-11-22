# AI STUDIO CLOUD — MASTER PROMPT FOR CLAUDE CODE
## Hybrid AI Full-Stack Generator × Visual Drag-and-Drop Builder × SaaS Deployment
Version: 1.0 | Production-oriented blueprint | Language: Thai UI, English code identifiers

> ROLE: You are the lead architect and a coordinated team of senior Laravel, React/TypeScript, infrastructure, security, AI, and QA engineers. Build a functioning multi-tenant SaaS website and application builder, not a static prototype. Follow this specification, implement in vertical slices, run tests, and report actual results. Never claim unimplemented features are working.

## 0. Non-negotiable outcomes
1. User signs up, creates workspace/project, describes an app in Thai, AI proposes a validated app schema, and the system renders editable pages.
2. Drag-and-drop changes the SAME canonical schema as AI edits; changes persist, support undo/redo, version history, preview, and rollback.
3. Generated apps include working public pages, admin CRUD, data models, permissions, forms, validations, API endpoints and declarative workflows.
4. Publishing creates a live isolated tenant-aware application at `slug.<PLATFORM_BASE_DOMAIN>` with HTTPS and safe rollback. Users may connect verified custom domains.
5. Google Sheets/Drive integrations use OAuth with least privilege, token encryption, queued synchronization, rate-limit handling, and disconnect/revoke.
6. Platform admin controls billing, plans, credits, quotas, abuse protection, tenant support and operations.
7. Generated user content and AI output are untrusted. Never execute arbitrary generated PHP/JS in platform process. MVP uses schema-driven runtime with whitelisted components/actions.
8. Every feature must have acceptance tests, access checks, loading/empty/error states, audit logs where appropriate, and responsive UI.

## 1. Product positioning and personas
Personas: solo creator, teacher/school, small business, agency, developer, enterprise admin. Thai-first interface with i18n. Apps: landing pages, institutional websites, booking, training registration, certificate portal, membership portal, dashboards, inventory, internal CRM, school administration, e-commerce catalog and custom CRUD apps. Support accessibility WCAG 2.2 AA as target, mobile responsive and desktop canvas.

## 2. Architecture / boundaries
A. CONTROL PLANE: Laravel API, auth, users, organizations, workspaces, plans, projects, AI orchestration, billing, domain registry, audit and usage.
B. STUDIO: React/TS editor with component tree, visual canvas, properties inspector, pages/routes, database builder, logic graph, API designer, AI chat, preview, asset library and publishing panel.
C. SCHEMA REGISTRY: canonical versioned JSON documents for app definitions; JSON Schema validation; migration/upcasters; immutable release snapshots; content hashing.
D. APP DATA PLANE: per-tenant logical isolation with tenant_id enforcement, runtime query service, access-policy evaluation, app-scoped sessions and secrets; plan database-per-tenant option for high tiers.
E. RUNTIME: render published release from immutable validated schema; server-executed allowlisted actions, data queries and workflows; no direct SQL or arbitrary server code from tenant input.
F. DEPLOYMENT: build/release queue, artifact store, wildcard routing, host validation, HTTPS, staged preview, health check, atomic switch, rollback and deploy logs.
G. WORKERS: isolated queues for AI, build, email, Google sync, imports, exports, thumbnails, virus scans, billing webhooks and scheduled workflows.
H. OBSERVABILITY: traces, metrics, structured logs, error monitoring, per-tenant usage and audit.

Request path: DNS/TLS -> edge/WAF -> tenant hostname resolver -> signed release lookup -> runtime -> policy/data service -> tenant-scoped database. Studio path: auth -> project authorization -> editor schema mutation -> validation -> version save -> preview -> publish pipeline.

## 3. Stack
Laravel supported stable version at implementation time, PHP compatible supported release, MySQL 8, Redis/Horizon, React, TypeScript, Vite, Tailwind, accessible component library, GrapesJS adapter for HTML-oriented canvas and dnd-kit for custom structured editor interactions, TanStack Query, Zustand or equivalent, Zod, JSON Schema/Ajv, React Hook Form, Playwright, PHPUnit/Pest, Vitest. Docker containers, Nginx/Traefik, S3-compatible storage, Cloudflare for SaaS or compatible provider abstraction. Verify actual provider API and plan limits from current official docs before implementing. Pin dependencies and maintain lockfiles.

## 4. Modules (32)
M01 Identity: registration, login, email verification, password reset, optional OAuth, passkeys/2FA, session revocation, throttling.
M02 Organizations and Workspaces: owners, members, invitations, roles, tenant isolation, workspace switch.
M03 Plans/Billing: trial, free/paid tiers, Thai-compatible payment adapter, recurring billing where provider supports, tax invoices configuration, payment webhooks, dunning, cancellation.
M04 Entitlements/Quotas: project limits, AI credits, storage, build minutes, requests, bandwidth, domains, seats; atomic metering.
M05 Super Admin: users, tenants, abuse flags, plans, subscriptions, support impersonation with approval/audit, system status.
M06 Project Dashboard: create, clone, archive, delete/recover, search, filter, favorites, templates.
M07 Page/Route Builder: multi-page, nested routes, SEO metadata, redirects, 404, draft/published states.
M08 Visual Canvas: drag/drop, snap, responsive breakpoints, selection, resize, layers, keyboard shortcuts, copy/paste, undo/redo.
M09 Component Library: containers, grid, typography, images, video, nav, forms, inputs, buttons, modals, tables, charts, lists, tabs, cards, maps as safe integrations.
M10 Theme/Design System: color tokens, typography, spacing, global styles, dark/light, accessibility checks, reusable blocks.
M11 Asset Manager: images/files, folders, deduplication, transformations, image optimization, signed URLs, quotas, virus scanning.
M12 Template Marketplace: curated templates, category, preview, version, ownership, licensing, moderation.
M13 AI Prompt-to-App: Thai natural-language requirements -> plan -> app schema -> generated pages/data/workflows -> tests/preview.
M14 AI Conversational Editor: scoped edit requests, diffs, human review/accept/reject, regenerate component, token budget.
M15 AI Provider Gateway: OpenAI/Anthropic/Gemini adapters, BYOK optional, encrypted keys, timeout, retries, spend caps, model capabilities, logging with redaction.
M16 Data Model Designer: visual entities, fields, types, constraints, relationships, indexes, migration preview, safe evolution.
M17 CRUD/Data Views: tables, filters, sorting, pagination, validation, import/export, optimistic concurrency, bulk actions.
M18 Auth/RBAC for Generated Apps: public/admin/member roles, permission matrices, row-level authorization, tenant-specific app user identities.
M19 API Builder: allowlisted REST endpoints, versioning, schema validation, auth scopes, rate limits, pagination, webhook signatures.
M20 Workflow/Logic Builder: event-trigger-condition-action graph; branching, retries, idempotency, schedule, approval, notifications, dry run.
M21 Forms: conditional fields, server validation, uploads, spam controls, submissions, notifications, CAPTCHA integration.
M22 Google OAuth/Connections: separate workspace credentials and scopes, secure token refresh, revocation, reconnection UX.
M23 Google Sheets: mapping, import/export, append/update, one-way or bidirectional sync with conflict policy, job history.
M24 Google Drive: picker/selected files, folders, upload, attach to records, permission checks, sync state, disconnect.
M25 External Connectors: generic REST/webhooks, email, SMTP/transactional email, SMS optional, custom provider adapters.
M26 Preview/Staging: preview URL, private access, test data, shareable review links with expiration.
M27 Release/Deployment: validation, compile, build artifact, deploy status, health checks, atomic publish, rollback.
M28 Domains/SSL: platform wildcard subdomains, custom hostname ownership verification, DNS instructions, SSL provisioning status, renewal and removal.
M29 Code Export: safe static export in MVP; later full project export with generated server code, migrations, README and deployment recipe, clearly labeled by capability.
M30 Analytics: visits, forms, app errors, project metrics, consent-aware analytics, export and retention.
M31 Security/Compliance: CSRF, CSP, XSS sanitization, SSRF protection, malware scanning, tenant boundaries, PDPA-oriented data controls, audit, backups.
M32 Operations/Developer Experience: logs, queues, migrations, health, feature flags, API docs, seeders, local Docker, CI/CD, disaster recovery.

## 5. Studio information architecture
Left vertical rail: Projects, Pages, Components, Layers, Assets, Theme, Database, Logic, API, Integrations, AI, Deploy. Center: canvas with responsive viewports desktop/tablet/mobile, zoom, selection overlay, live preview. Right: Design / Data / Events / Permissions / SEO properties. Top: breadcrumb, save state, undo/redo, version, preview, publish. Bottom: validation errors, job console, network/action debug. AI panel docked, accepts Thai prompts and shows diff before applying. Keyboard accessible controls and mobile-friendly simplified editor.

## 6. Canonical schema and compatibility
Define versioned `AppDefinition` JSON with `schemaVersion`, `projectId`, `pages[]`, `routes[]`, `components` (stable IDs, tree, props, styles, responsive variants), `designTokens`, `dataModels[]`, `permissions[]`, `actions[]`, `workflows[]`, `integrations[]`, `assets[]`, `seo`, `settings`. All changes use commands (add/move/update/delete component, create field, connect event). Validate referential integrity, disallow unsupported action types, sanitize content and URLs, detect cycles, enforce maximum depth and size. Store snapshots plus append-only changes; optimistic concurrency with revision checks. AI returns typed patches, NEVER raw arbitrary execution instructions. Preserve user edits across regeneration; conflict resolution with preview.

## 7. Database model (minimum)
Control plane: users, auth_identities, sessions, organizations, organization_members, invitations, workspaces, workspace_members, projects, project_members, app_definitions, app_revisions, app_change_events, releases, deployments, deployment_jobs, deployment_logs, domains, domain_verifications, tls_status, templates, template_versions, assets, asset_variants, ai_providers, ai_models, ai_requests, ai_usage, secrets, connections, google_accounts, integration_sync_jobs, plans, subscriptions, invoices, payments, usage_counters, usage_events, notifications, audit_logs, feature_flags, support_tickets, webhook_events, idempotency_keys.
App data: app_users, app_roles, app_permissions, app_user_roles, app_data_models, app_records (MVP typed JSON records with indexed materialized fields OR generated physical tables under a safe migration system), app_record_relations, app_files, app_form_submissions, workflow_runs, workflow_steps, app_api_keys, app_audit_logs. Define foreign keys, unique indexes, tenant_id/workspace_id/project_id boundaries and soft-delete policies. For each query explicitly enforce tenant/project scoping; avoid accepting tenant ID from client as authorization.

## 8. Runtime and publishing rules
MVP must not execute tenant-authored PHP, SQL, shell, arbitrary JavaScript, eval, or arbitrary package installs. Render from approved components and run allowlisted server actions. Publish pipeline: freeze revision -> validate schema and permissions -> security scan -> generate immutable artifact/manifest -> stage -> smoke test -> provision routing -> atomic release switch -> health verify -> record deployment. Failed publish keeps previous release live. Support unpublish and rollback. User preview is private and separate from public production data. For custom code in future: sandbox per build and per runtime, strict egress allowlist, CPU/memory/time limits, read-only FS, ephemeral credentials, network isolation, policy enforcement and billing.

## 9. Domain workflow
Platform wildcard domain configured once with wildcard DNS and TLS. Reserve slug transactionally, prohibit system names, route based on validated Host header, never trust X-Forwarded-Host except trusted proxies. Custom domain: collect hostname -> check ownership via TXT/CNAME or provider-supported validation -> present DNS record instructions -> poll provider -> verify active hostname AND certificate -> publish route -> renew and monitor -> support remove/reassign with ownership revalidation. Handle apex separately from subdomain; do not claim all registrars support identical records. Ensure tenant takeover prevention, no cross-tenant hostname reuse without fresh verification. Domain errors must be actionable.

## 10. Google Sheets / Drive integration
Google OAuth consent, per-user or workspace connection with explicit grant, PKCE where appropriate, encrypted refresh tokens, scope minimization, token rotation and revoke. Sheets: choose spreadsheet and tab, preview columns, map data types, import with dry run, scheduled push/pull, conflict detection, exponential backoff, quotas, retry dead-letter queue, idempotent row mapping and error reports. Drive: choose file/folder, upload with metadata, attach file references, check permission on each access, support revoked tokens and deleted files. Do not expose tokens or use Google Sheets as the canonical transactional database. Audit external data transfers and provide deletion controls.

## 11. AI generation pipeline
Collect goal, pages, roles, entities, fields, relationships, integrations, branding, deployment intent. AI planner outputs structured plan with missing requirements and confidence; deterministic validators check scope and security. Generate AppDefinition using constrained structured outputs, run schema validator, security validator, data model validator, route conflict detector, preview renderer, synthetic tests. AI repair loop limited by token/time budget; no silent destructive changes. Display plan, changes, estimated credit cost, permission impact and user approval. Support partial regeneration (screen, form, action, data model). Record model, version, token usage, cost, prompts redacted and user consent for sending sensitive content. Provide safe fallback when provider unavailable.

## 12. Auth and permissions
Platform roles: super_admin, support_admin, org_owner, workspace_admin, editor, developer, viewer, billing_admin. Generated app roles are separate and scoped to app. Use policies and middleware on every API, secure app login and sessions, verified redirects, password hashing, optional 2FA, rate limiting, session invalidation, role-based UI plus backend enforcement. Guard horizontal privilege escalation, IDOR, CSRF and token theft. No cross-tenant file access.

## 13. Billing, metering and limits
Plans defined as entitlements rather than hard-coded if statements. Meter AI tokens, build seconds, storage bytes, bandwidth, requests, projects, team seats, custom domains, app database size and background jobs. Reserve/commit credits atomically, prevent race conditions, allow admin adjustments with audit. Webhook signature verification, idempotent processing, retry and reconciliation; do not treat redirect to payment success as payment confirmation. Trial expiry and downgrade preserve data under retention policy, prevent new usage above quota without destructive deletion. Provide cost dashboard and warnings.

## 14. Security and privacy acceptance criteria
TLS, secure cookies, CSP, CORS allowlist, CSRF, prepared queries, output encoding, rich text sanitization, upload MIME validation + AV scan, signed URLs, per-tenant storage prefixes and authorization, SSRF allowlist/deny private network metadata endpoints, egress controls, secret encryption/KMS option, least privilege IAM, webhook HMAC, replay prevention, backups with restore drills, encryption at rest, audit log integrity, log redaction, PDPA-oriented consent/privacy/export/delete flows, retention schedules. Run dependency scanning, SAST and DAST in CI. Publish tenant isolation penetration tests.

## 15. API contracts (illustrative)
POST /api/v1/projects
GET /api/v1/projects/{id}
GET/PUT /api/v1/projects/{id}/schema (If-Match revision)
POST /api/v1/projects/{id}/commands
POST /api/v1/projects/{id}/ai/plan
POST /api/v1/projects/{id}/ai/generate
POST /api/v1/projects/{id}/ai/patch
GET /api/v1/projects/{id}/revisions
POST /api/v1/projects/{id}/preview
POST /api/v1/projects/{id}/publish
GET /api/v1/projects/{id}/deployments
POST /api/v1/projects/{id}/rollback
POST /api/v1/projects/{id}/domains
GET /api/v1/domains/{id}/status
POST /api/v1/integrations/google/connect
POST /api/v1/integrations/google/disconnect
POST /api/v1/projects/{id}/sheets/sync
GET /api/v1/projects/{id}/usage
POST /api/v1/billing/webhooks/{provider}
Define OpenAPI 3.1 schemas, response envelopes, error codes, pagination, idempotency keys and request limits. Generated app endpoints must be isolated under app hostname and app auth.

## 16. Monorepo skeleton
/apps/platform-api (Laravel)
/apps/studio-web (React/TS)
/apps/runtime-web (safe renderer)
/apps/deploy-worker
/packages/app-schema
/packages/component-registry
/packages/ai-adapters
/packages/integration-contracts
/packages/ui
/infra/docker
/infra/terraform (optional)
/docs/architecture
/docs/security
/docs/api
/tests/e2e
/scripts
README.md, .env.example, docker-compose.yml, CI workflow, LICENSE and CHANGELOG.

## 17. Required end-to-end journeys
J1 Register -> verify -> workspace -> project -> template -> edit -> preview -> publish -> open live subdomain.
J2 Thai prompt -> structured plan -> approval -> generated pages+DB+admin+API -> edit canvas -> publish -> submit public form -> view saved record in admin.
J3 Create data model -> form binding -> permission checks -> CRUD -> pagination/filter -> audit.
J4 Google connect -> choose Sheet -> map columns -> import -> sync -> disconnect -> no residual access.
J5 Google Drive connect -> choose folder -> upload app attachment -> see file -> revoke and handle error.
J6 Custom domain -> DNS instructions -> verify ownership -> certificate active -> route -> rollback -> remove domain.
J7 Subscription payment webhook -> quota update -> usage enforcement -> downgrade behavior.
J8 Cross-tenant attempts: IDs, files, API, host headers, preview tokens, cache keys -> blocked.
J9 Failed build -> previous release stays live -> error logs -> retry.
J10 AI invalid output or provider outage -> no corrupted schema -> actionable error.

## 18. Phased delivery and definition of done
PHASE 0: architecture decision records, threat model, repo, Docker, CI, migration baseline, seed, auth and tenancy tests.
PHASE 1: project dashboard, schema, pages, drag/drop, inspector, assets, responsive preview, save/revisions, templates.
PHASE 2: data model designer, safe CRUD, generated admin, auth/RBAC, forms, actions/workflows, REST API.
PHASE 3: AI planner/generator/editor, diff approval, multi-model adapters, budgets, retries, validation.
PHASE 4: wildcard domain publishing, immutable releases, deployment status, rollback, custom domain verification/SSL.
PHASE 5: Google Sheets/Drive OAuth and robust sync, external webhooks, monitoring.
PHASE 6: SaaS billing, entitlements, metering, super admin, audit, privacy and production hardening.
PHASE 7: marketplace, collaboration, advanced export and custom-code sandbox only after security review.
Each phase must include real code, migrations, API/UI integration, tests, screenshots or browser-based verification, setup instructions and known limitations. No placeholder buttons or fake success responses.

## 19. UI/UX visual direction
Inspired by professional canvas editors: modern clean SaaS, teal/navy brand, subtle glass effects only where legibility remains high, Kanit for Thai UI with accessible fallback, compact left icon rail, secondary components panel, central dotted canvas, right inspector, floating AI assistant, project card dashboard, dark/light mode, responsive layouts. Use real functional components and keyboard accessible drag/drop alternatives. Never copy branding or proprietary assets of reference products.

## 20. Production operations
Separate local/staging/production; managed MySQL/Redis/object storage when possible; immutable images, non-root containers, health/readiness probes, zero-downtime migrations, encrypted backups, tested restore, SLOs, alerts, queue DLQs, error budgets, audit retention, cost controls. Define deployment runbook, rollback runbook, incident response, Google OAuth review checklist, domain setup checklist and billing reconciliation checklist. No hard-coded API keys. cPanel shared hosting is not sufficient for full multi-tenant build workers and isolated runtimes; require VPS/container infrastructure for production.

## 21. Acceptance gates and quality targets
Automated unit, integration, contract, E2E and security tests. Required: 100% of critical user journeys J1-J10 tested; zero known critical/high vulnerabilities at launch; all data access tenant-scoped; publish rollback verified; no silent data loss; accessibility checks on core screens; Thai UTF-8 rendering; graceful rate limit handling; reliable async job retry and idempotency. Establish measured performance budgets in staging (do not invent benchmark results).

## 22. Claude Code execution instructions
1. Inspect repository and existing files before editing; never overwrite user work without permission.
2. Present a concise implementation plan, assumptions, architecture diagram, migration strategy, and phase backlog.
3. Implement PHASE 0 and PHASE 1 first as working vertical slices. Do not pretend to complete all phases in one pass.
4. For each slice: create migrations -> API contracts -> authorization -> services -> UI -> tests -> docs -> verify actual commands and output.
5. Use TODO only for explicitly deferred future phases, never for an advertised MVP feature.
6. Prefer reusable modules, strict types, schema validation, clean boundaries and feature flags.
7. Provide commands to install, configure, migrate, seed, test, build and run locally and in staging.
8. Before each external integration, verify current official docs, credentials, pricing, limits and app-review requirements. Ask for secrets only through secure environment setup, never in chat or code.
9. After each phase, report files changed, tests run with pass/fail counts, operational instructions, remaining risks and next actions.
10. Continue phase-by-phase with explicit approval for destructive migrations, production deployment, billing activation and DNS changes.

## 23. MVP showcase seed project
Build an example 'ระบบลงทะเบียนอบรม' with public home, training catalog, registration form, admin login, dashboard, registrant CRUD, file attachments, status workflow and Google Sheets export integration stub disabled until OAuth configured. Demo must use real database records and policies. Include a second template for a school website and a third for a business landing page.

## 24. Success criteria
A nontechnical Thai-speaking user can create a project, generate a functioning app from a prompt, edit it visually, bind a form to real records, manage submissions in the generated backend, preview, publish to a secure platform subdomain, and later verify a custom domain; all while tenant isolation, quotas and deployment safety remain enforced.

START NOW: first inspect repository and produce architecture decision records and phase plan, then implement PHASE 0 and PHASE 1 in verifiable vertical slices. Never claim production readiness until all gates are satisfied.
