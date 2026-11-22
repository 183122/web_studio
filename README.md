# AI Studio Cloud

แพลตฟอร์ม SaaS แบบ multi-tenant สำหรับสร้างเว็บไซต์และแอปด้วย AI ร่วมกับเครื่องมือลากวาง (Thai-first)
สเปกทั้งหมด: [`AI_Studio_Cloud_Master_Prompt.md`](docs/spec/AI_Studio_Cloud_Master_Prompt.md) · สถานะ: **Phase 0–1 เสร็จ** — ดู [`docs/PROGRESS.md`](docs/PROGRESS.md)

> ⚠️ ยังไม่พร้อมใช้งาน production: publishing, data/CRUD, AI, integrations และ billing อยู่ใน Phase 2–6 ([`docs/ROADMAP.md`](docs/ROADMAP.md))

## โครงสร้าง

```
apps/platform-api      Laravel 13 control plane (auth, tenancy, projects, schema registry, assets, audit)
apps/studio-web        React 19 + TS studio (dashboard, visual editor, preview)
packages/app-schema    Canonical AppDefinition JSON Schema, typed commands, validators, parity fixtures
packages/component-registry  Allowlisted components + style properties (shared by TS and PHP)
packages/renderer      Safe React renderer (editor canvas, preview, future runtime)
packages/templates     Curated templates (training registration, school website, business landing)
tests/e2e              Playwright journeys against real servers
infra/docker           API (php-fpm) and web (nginx + SPA) images
docs/                  Architecture, ADRs, threat model, OpenAPI, progress, roadmap
```

## เริ่มต้นใช้งาน (local)

ต้องมี: PHP 8.3 (ext: pdo_sqlite/pdo_mysql, gd, intl, mbstring, zip), Composer 2, Node 22

```bash
# 1) Dependencies
npm ci
cd apps/platform-api && composer install && cp .env.example .env && php artisan key:generate

# 2) Database (SQLite for quick start; MySQL 8: set DB_* in .env)
touch database/database.sqlite
php artisan migrate --seed          # plans, templates, demo user demo@example.com / DemoPassword123

# 3) Run (two terminals)
php artisan serve --port=8000       # API
npm run dev:studio                  # Studio at http://localhost:5173 (proxies /api → :8000)
```

E-mails (verification, password reset) are written to `storage/logs/laravel.log` with `MAIL_MAILER=log`.

### Docker (staging-like)
```bash
cp .env.docker.example .env.docker   # set APP_KEY
docker compose up --build            # http://localhost:8080
docker compose exec api php artisan migrate --seed --force
```
(Images are defined but have not yet been built in CI — see known limitations.)

## คำสั่งทดสอบ

```bash
npm test                                   # Vitest: app-schema, renderer, studio, template validation
npm run typecheck
cd apps/platform-api && php artisan test   # PHPUnit (SQLite by default; set DB_CONNECTION=mysql for MySQL)
npm run test:e2e                           # Playwright (starts Laravel + built studio itself)
npm run generate -w packages/app-schema    # after editing JSON Schemas (precompiled validators)
npm run fixtures -w packages/app-schema    # after changing command/validation semantics (TS ↔ PHP parity)
npm run build -w packages/templates        # after editing templates
```

## เอกสาร
- สถาปัตยกรรม: [`docs/architecture/overview.md`](docs/architecture/overview.md) และ ADR ใน [`docs/architecture/adr`](docs/architecture/adr)
- ความปลอดภัย: [`docs/security/threat-model.md`](docs/security/threat-model.md)
- API: [`docs/api/openapi.yaml`](docs/api/openapi.yaml)
- ความคืบหน้าและผลทดสอบ: [`docs/PROGRESS.md`](docs/PROGRESS.md)
