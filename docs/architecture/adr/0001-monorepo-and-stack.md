# ADR-0001: Monorepo, stack and version pinning

- Status: Accepted (Phase 0)
- Context: spec §3 and §16 require Laravel + React/TS with shared schema packages and pinned dependencies.
- Decision:
  - npm workspaces monorepo: `apps/platform-api` (Laravel 13.x, PHP 8.3), `apps/studio-web` (React 19, Vite 7, Tailwind 4, TanStack Query 5, Zustand 5, Zod 4, dnd-kit 6, Radix), `packages/*` (app-schema, component-registry, renderer, templates), `tests/e2e` (Playwright 1.63).
  - Exact versions in `package.json`; `package-lock.json` and `composer.lock` committed.
  - Chosen majors are the latest stable lines with an established ecosystem (e.g. TypeScript 5.9 rather than the brand-new 7.x native compiler; React Router 7 rather than 8) to limit migration risk; upgrades are tracked in `docs/ROADMAP.md`.
  - `apps/runtime-web`, `apps/deploy-worker`, `packages/ai-adapters`, `packages/integration-contracts` are created when their phases start (4, 4, 3, 5) instead of as empty placeholders.
- Consequences: TS packages are consumed as source (no build step) by Vite/Vitest; PHP reads the same JSON files at runtime via `STUDIO_PACKAGES_PATH`.
