# บันทึกความคืบหน้า (Progress Log)

> เอกสารนี้คือ "จุดเริ่มต้น" สำหรับการพัฒนารอบถัดไป: อ่านสถานะ → ดู backlog ใน `docs/ROADMAP.md` → ทำ Phase ถัดไปเป็น vertical slice
> หลักการ: ไม่อ้างว่าฟีเจอร์ทำงานถ้ายังไม่มีโค้ดและเทสต์จริง

## สถานะรวม (อัปเดต 2026-11-22, หลัง Phase 3)

| Phase | ขอบเขต | สถานะ |
|---|---|---|
| 0 | ADR, threat model, monorepo, Docker, CI, migrations, seed, auth, tenancy tests | ✅ เสร็จ (Docker image ยังไม่ได้ build ทดสอบในสภาพแวดล้อมนี้ — ดู "ข้อจำกัด") |
| 1 | Project dashboard, canonical schema, pages, drag & drop, inspector, assets, responsive preview, save/revisions, templates | ✅ เสร็จ |
| 2 | Data model designer, CRUD, generated admin, app RBAC, forms, workflows, REST API | ✅ เสร็จ (บัญชีผู้ใช้ของแอปที่สร้าง ย้ายไป Phase 4 — ดูข้อจำกัด) |
| 3 | AI planner/generator/editor, diff approval, multi-model, budgets | ✅ เสร็จ (Claude + โหมดออฟไลน์; OpenAI/Gemini ยังไม่ทำ, ยังไม่ได้ทดสอบกับ API จริง — ดูข้อจำกัด) |
| 4 | Publishing (wildcard subdomain), immutable releases, rollback, custom domains/SSL | ⏳ ถัดไป |
| 5 | Google Sheets/Drive OAuth + sync, webhooks, monitoring | ⏳ |
| 6 | Billing, entitlements/metering, super admin, privacy, hardening | ⏳ (มี plans/entitlements พื้นฐานแล้ว) |
| 7 | Marketplace, collaboration, export, sandboxed custom code | ⏳ |

ยังไม่พร้อมใช้งาน production — gates ใน spec §21 ยังไม่ครบ (J4–J7, J9 อยู่ใน phase ถัดไป)

## Phase 0–1: สิ่งที่ส่งมอบ

### Control plane — `apps/platform-api` (Laravel 13.43, PHP 8.3)
- **M01 Identity**: register (สร้าง org + workspace อัตโนมัติ), login/logout, `me`, e-mail verification (signed link), forgot/reset password (เพิกถอนทุก session), rate limit, ไม่เปิดเผยว่ามีบัญชีหรือไม่
- **M02 Org/Workspace**: roles `owner/admin/billing_admin/member` และ `admin/editor/developer/viewer`, policies (ไม่ใช่สมาชิก = 404), members list, audit log (admin)
- **M04 Entitlements (พื้นฐาน)**: ตาราง `plans` (free/pro) แบบ data-driven, จำกัดจำนวนโปรเจกต์และพื้นที่จัดเก็บ (lock แถว organization กัน race)
- **M06 Projects**: create (blank/template), list + search + status filter + favorites + sort, rename, archive (อ่านอย่างเดียว), duplicate, trash/restore
- **§6 Schema registry**: `GET/PUT schema` (If-Match), `POST commands` (baseRevision, 409 conflict), checkpoints, append-only change events, content hash, named versions, restore → revision ใหม่, Idempotency-Key
- **M11 Assets**: content sniffing, JPEG/PNG/WebP, re-encode (ลบ EXIF), จำกัด 16MP, ClamAV adapter, quota, dedup, tenant prefix, signed URL 30 นาที
- **M12 Templates (curated)**: ระบบลงทะเบียนอบรม (3 หน้า), เว็บไซต์โรงเรียน (4 หน้า), Landing page ธุรกิจ — versioned, validated ตอน seed
- Error envelope, security headers, health probe

### Shared packages
- `app-schema`: JSON Schema v1 (AppDefinition + Commands), semantic validator, command reducer + inverse, precompiled Ajv validators (ไม่ต้องใช้ eval), canonical JSON, parity fixtures
- `component-registry`: 14 คอมโพเนนต์ (Page, Section, Container, Columns, Card, Heading, Text, Button, Image, Video, Navbar, List, Divider, Spacer) + style allowlist 28 รายการ
- `renderer`: safe React renderer (edit/preview/live), tokens, breakpoints desktop/tablet/mobile
- `templates`: builder + validation + dist JSON

### Studio — `apps/studio-web`
- Auth pages (TH), dashboard ตาม M06, dialog สร้างโปรเจกต์พร้อมพรีวิวเทมเพลตสด
- Editor: left rail (หน้า, คอมโพเนนต์, เลเยอร์, คลังภาพ, ธีม, เวอร์ชัน), canvas จุด + zoom + viewport 3 ขนาด, selection overlay + toolbar, drag & drop (palette → canvas, ย้ายบน canvas, ลากในเลเยอร์) พร้อมเส้นบอกตำแหน่ง, ทางเลือกคีย์บอร์ด (คลิก/Enter เพิ่ม, Alt+↑/↓ ย้าย, Delete, Ctrl+Z/Y/C/V/D, Esc)
- Inspector: props ตามชนิด (ตรวจสอบก่อนส่ง), styles ต่อ breakpoint พร้อมค่า "สืบทอด", page SEO, site settings, 404 page
- Theme tokens + ตรวจ contrast WCAG AA, versions (ตั้งชื่อ/ดูตัวอย่าง/กู้คืน)
- Autosave (debounce, batch, idempotency, backoff), conflict banner (รวมการแก้ไข/โหลดใหม่), beforeunload guard
- Private preview (ทุกหน้า, ทุก breakpoint, นำทางลิงก์ภายใน, ดู revision เก่า)
- Dark/light mode, Kanit/Sarabun/Prompt/Noto Sans Thai แบบ self-host

## Phase 3: สิ่งที่ส่งมอบ (2026-11-22)

- **AI gateway** (`app/Domain/Ai`): Claude Opus 5.5 ผ่าน Anthropic PHP SDK อย่างเป็นทางการ (structured outputs, effort `medium`, server-side refusal fallback), แปลงข้อผิดพลาดเป็นข้อความที่ทำต่อได้, BYOK เข้ารหัสต่อองค์กร, ต้องได้รับความยินยอมก่อนส่งข้อความออกภายนอก, เก็บ prompt แบบปิดบังข้อมูลส่วนบุคคล, เครดิต AI รายเดือนตามแผน (จองก่อน → ตัดตามจริง), คิว `ai` แยก, จำกัด 6 ครั้ง/นาที/ผู้ใช้
- **โหมดออฟไลน์**: ถ้าไม่มี API key หรือผู้ให้บริการล่ม ระบบใช้ตัววางแผนจากแม่แบบ (ระบุชัดว่าไม่ใช่ AI) — รองรับ ลงทะเบียนอบรม / จองคิว / ร้านค้า / เว็บไซต์ทั่วไป และคำสั่งแก้ไขพื้นฐาน
- **ไปป์ไลน์**: คำอธิบายภาษาไทย → แผน (หน้า, ตาราง, สิทธิ์, workflow, ข้อมูลที่ขาด, สมมติฐาน, ความครบถ้วน) → ผู้ใช้อนุมัติ → compiler แปลงเป็น typed commands เท่านั้น → ทดลอง apply + validate → ข้อเสนอให้ตรวจ (หน้า, ตาราง, ผลต่อสิทธิ์, workflow, ก่อน/หลัง, คำเตือน) → นำไปใช้/ไม่ใช้ · ย้อนกลับได้จากแผงเวอร์ชัน
- **แก้ไขด้วย AI**: จำกัดขอบเขตตามคอมโพเนนต์ที่เลือกหรือหน้าปัจจุบัน (partial regeneration)
- **Studio**: แผง AI ในตัวแก้ไข + ตัวเลือก "อธิบายด้วยคำพูด (AI)" ตอนสร้างโปรเจกต์
- เอกสาร: ADR-0010, threat model (A-1…A-7), OpenAPI 0.3.0

## Phase 2: สิ่งที่ส่งมอบ (2026-11-22)

- **Schema v2** (`packages/app-schema`): `dataModels`, `permissions`, `workflows` + upcaster v1→v2 ทั้ง TS/PHP (เอกสาร v1 เดิมอ่านได้และถูกยกระดับเมื่อบันทึก) · commands ใหม่ `model.*`, `permission.*`, `workflow.*` พร้อม inverse · กฎตรวจสอบใหม่ (ชนิดฟิลด์, ชื่อสงวน, สิทธิ์ต้องระบุทุกตาราง, workflow, การผูกฟอร์ม/ตาราง) · parity fixtures 19/9/27/19 กรณี
- **Data plane** (`app/Domain/Data`): `app_records` แยก environment preview/production, unique key แบบ atomic, optimistic concurrency, `app_audit_logs`, โควตาจำนวน record ตามแผน
- **RBAC ของแอป**: ตารางสิทธิ์ public/member/admin × create/read/update/delete, ค่าเริ่มต้นปฏิเสธ — ใช้กับ preview runtime (จำลองบทบาท), ฟอร์ม และ API key
- **Generated admin** (`/app/projects/:id/data`): ค้นหา (ภาษาไทยได้), กรอง, เรียง (ตัวเลขเรียงถูกต้อง), แบ่งหน้า, เพิ่ม/แก้ไข/ลบ, ลบหลายรายการ, ส่งออก CSV (กัน formula injection, มี BOM สำหรับ Excel), นำเข้า CSV (dry run, ผิดแถวเดียวยกเลิกทั้งไฟล์), ประวัติการแก้ไข, workflow runs, API keys
- **Data model designer** (แผง "ฐานข้อมูล"): 11 ชนิดฟิลด์, จำเป็น/ห้ามซ้ำ/ตัวเลือก/min/max/ความยาว, ฟิลด์แสดงชื่อ, ตารางสิทธิ์, **migration impact preview** ก่อนบันทึก (ไม่ลบหรือแก้ข้อมูลอัตโนมัติ)
- **Forms & DataTable**: คอมโพเนนต์ Form, FormField, DataTable · ตรวจสอบทั้งฝั่ง client/server · honeypot + เวลากรอกขั้นต่ำ + rate limit · ปุ่ม "เพิ่มช่องกรอกที่ยังไม่มี"
- **Workflows** (แผง "Workflow"): trigger สร้าง/แก้ไข → เงื่อนไข → ตั้งค่าฟิลด์ / ส่งอีเมล (ข้อความล้วน, queue + retry) · รันครั้งเดียวต่อเหตุการณ์ (idempotent) · ไม่วนซ้ำ · dry run · ประวัติการรัน
- **REST API** (`/api/v1/apps/{project}/models/{name}/records`): API key แบบ hash, scopes, บทบาท, เพิกถอนได้, rate limit, ใช้ชื่อฟิลด์
- **Showcase**: เทมเพลตระบบลงทะเบียนอบรมมีฟอร์มบันทึกลงฐานข้อมูลจริง, workflow ตั้งสถานะ "รอตรวจสอบ" + อีเมลยืนยันผู้สมัคร + แจ้งเจ้าหน้าที่, หน้ารายชื่อผู้สมัครสำหรับผู้ดูแลเท่านั้น
- เอกสาร: ADR-0009

## ผลการทดสอบล่าสุด (รันจริงในสภาพแวดล้อมนี้)

| ชุดทดสอบ | คำสั่ง | ผล |
|---|---|---|
| PHPUnit บน SQLite | `cd apps/platform-api && php artisan test` | **117/117 ผ่าน** (688 assertions) |
| PHPUnit บน MySQL 8.0.46 | `DB_CONNECTION=mysql … php artisan test` | **117/117 ผ่าน** |
| Vitest app-schema / renderer / studio | `npm test` | **80/80, 10/10, 9/9 ผ่าน** |
| Template validation | `npm test -w packages/templates` | 3/3 valid |
| Typecheck + production build | `npm run typecheck && npm run build` | ผ่าน |
| Playwright E2E | `npm run test:e2e` | **10/10 ผ่าน** |
| Pint (code style) | `vendor/bin/pint --test` | ผ่าน |

E2E ที่ครอบคลุม: J1 (ขอบเขต Phase 1), J2 (ครบรวมขั้น AI — ใช้โหมดออฟไลน์), J3, แก้ไขด้วย AI, dashboard, drag & drop + keyboard, upload asset, แก้ไขพร้อมกัน, J8 · J10 ทดสอบใน PHPUnit (ผู้ให้บริการล่ม → fallback, JSON เสีย, ปฏิเสธคำขอ, key ผิด, คำตอบถูกตัด)

## ข้อจำกัดที่ทราบ (Known limitations)
0. **AI ยังไม่ได้ทดสอบกับ Claude API จริง** — สภาพแวดล้อมนี้ไม่มี API key; เส้นทางเรียก SDK ทดสอบผ่าน fake HTTP transport (ตรวจรูปแบบคำขอ, การแปลงผลลัพธ์, ข้อผิดพลาด) · ต้องตั้ง `ANTHROPIC_API_KEY` (หรือ BYOK) แล้วทดลองจริงก่อนเปิดใช้ · OpenAI/Gemini adapter ยังไม่ทำ
1. **Docker images** (`infra/docker/*`) และ CI workflow ยังไม่ได้ build/รันจริง (ไม่มี Docker daemon / ยังไม่มีรัน GitHub Actions)
2. **ClamAV** adapter ยังไม่ได้ทดสอบกับ clamd จริง; ถ้าไม่ตั้งค่า สถานะไฟล์คือ `not_scanned`
3. **บัญชีผู้ใช้ของแอปที่สร้าง** (สมัคร/เข้าสู่ระบบในแอป, member session, สิทธิ์แบบ "ของตัวเอง") ยังไม่มี — บทบาท member/admin ตอนนี้ใช้ได้ผ่านการจำลองบทบาทใน preview และ API key; จะทำพร้อม runtime ใน Phase 4
4. **ฟิลด์ไฟล์/อัปโหลด และความสัมพันธ์ระหว่างตาราง** ยังไม่มี (backlog)
5. CAPTCHA ยังไม่มี (มี honeypot + เวลากรอกขั้นต่ำ + rate limit)
6. การค้นหาใช้ LIKE บน JSON — เหมาะกับข้อมูลระดับหมื่นแถวต่อตาราง; ข้อมูลใหญ่กว่านี้ต้องเพิ่ม materialized index (Phase 6)
7. อีเมลจาก workflow ใช้ mail driver ของ Laravel (dev = log) — ต้องตั้งค่า SMTP/ผู้ให้บริการจริงก่อนใช้งาน
8. Workspace switcher/การเชิญสมาชิก, 2FA/passkeys, UI แก้ redirects, inline text editing บนแคนวาส ยังไม่ทำ (backlog เดิม)

## วิธีทำงานต่อ (สำหรับรอบถัดไป)
1. อ่าน `docs/ROADMAP.md` → Phase 2 backlog
2. Phase 3 (AI): ตัวสร้างต้องส่งออกเป็น typed commands เท่านั้น (รวม `model.upsert`/`permission.set`/`workflow.upsert`) แล้วผ่าน validator เดียวกัน; ถ้าต้องเปลี่ยน schema ให้ออก v3 + upcaster + fixtures
3. ทุก slice: migration → API contract (อัปเดต `docs/api/openapi.yaml`) → policy → service → UI → tests (PHPUnit + Vitest + E2E) → อัปเดตไฟล์นี้
