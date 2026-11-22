# Changelog

## [0.2.0] — 2026-11-22 — Phase 2
### Added
- AppDefinition schema v2 (data models, permissions, workflows) with v1→v2 upcasting and new typed commands.
- App data plane: typed JSON records per environment, atomic unique fields, optimistic concurrency, app audit log, record quota.
- Generated admin: search/filter/sort/pagination, CRUD, bulk delete, CSV import/export, migration impact preview.
- App RBAC (public/member/admin × CRUD), Form/FormField/DataTable components with spam controls, preview role switcher.
- Workflow engine (setField, queued e-mail, idempotent runs, dry run) and generated REST API with scoped API keys.
- Training-registration showcase now saves real registrations and sends confirmation e-mails.

## [0.1.0] — 2026-11-22 — Phase 0 + Phase 1
### Added
- Monorepo (npm workspaces), Laravel 13 platform API, React 19 studio, shared schema/registry/renderer/templates packages.
- Auth (register, login, e-mail verification, password reset, rate limits), organizations/workspaces with roles, tenant-isolating policies.
- Projects: create from blank/templates, search/filter/favorites, archive, duplicate, trash/restore, plan quotas.
- Canonical `AppDefinition` v1 with typed commands, dual (TS/PHP) validation and parity fixtures; optimistic concurrency, checkpoints, append-only change log, named versions and restore.
- Assets: sniffed/re-encoded image uploads, quotas, de-duplication, signed URLs, ClamAV adapter.
- Visual editor: drag & drop, layers, pages, inspector with responsive styles, theme tokens, undo/redo, autosave with idempotency and conflict merge, private preview.
- Three templates: training registration, school website, business landing page.
- CI workflow, Docker definitions, ADRs, threat model, OpenAPI, progress log.
