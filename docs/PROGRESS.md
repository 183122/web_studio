# บันทึกความคืบหน้า (Progress Log)

> เอกสารนี้คือ "จุดเริ่มต้น" สำหรับการพัฒนารอบถัดไป: อ่านสถานะ → ดู backlog ใน `docs/ROADMAP.md` → ทำ Phase ถัดไปเป็น vertical slice
> หลักการ: ไม่อ้างว่าฟีเจอร์ทำงานถ้ายังไม่มีโค้ดและเทสต์จริง

## สถานะรวม (อัปเดต 2026-11-22)

| Phase | ขอบเขต | สถานะ |
|---|---|---|
| 0 | ADR, threat model, monorepo, Docker, CI, migrations, seed, auth, tenancy tests | ✅ เสร็จ (Docker image ยังไม่ได้ build ทดสอบในสภาพแวดล้อมนี้ — ดู "ข้อจำกัด") |
| 1 | Project dashboard, canonical schema, pages, drag & drop, inspector, assets, responsive preview, save/revisions, templates | ✅ เสร็จ |
| 2 | Data model designer, CRUD, generated admin, app RBAC, forms, actions/workflows, REST API | ⏳ ถัดไป |
| 3 | AI planner/generator/editor, diff approval, multi-model, budgets | ⏳ |
| 4 | Publishing (wildcard subdomain), immutable releases, rollback, custom domains/SSL | ⏳ |
| 5 | Google Sheets/Drive OAuth + sync, webhooks, monitoring | ⏳ |
| 6 | Billing, entitlements/metering, super admin, privacy, hardening | ⏳ (มี plans/entitlements พื้นฐานแล้ว) |
| 7 | Marketplace, collaboration, export, sandboxed custom code | ⏳ |

ยังไม่พร้อมใช้งาน production — gates ใน spec §21 ยังไม่ครบ (J2–J7, J9, J10 อยู่ใน phase ถัดไป)

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

## ผลการทดสอบล่าสุด (รันจริงในสภาพแวดล้อมนี้)

| ชุดทดสอบ | คำสั่ง | ผล |
|---|---|---|
| PHPUnit บน SQLite | `cd apps/platform-api && php artisan test` | **73/73 ผ่าน** (337 assertions) |
| PHPUnit บน MySQL 8.0.46 | `DB_CONNECTION=mysql … php artisan test` | **73/73 ผ่าน** (337 assertions) |
| Vitest app-schema | `npm test -w packages/app-schema` | **57/57 ผ่าน** |
| Vitest renderer | `npm test -w packages/renderer` | **7/7 ผ่าน** |
| Vitest studio | `npm test -w apps/studio-web` | **9/9 ผ่าน** |
| Template validation | `npm test -w packages/templates` | 3/3 valid |
| Typecheck | `npm run typecheck` | ผ่าน |
| Playwright E2E | `npm run test:e2e` | **6/6 ผ่าน** |
| Pint (code style) | `vendor/bin/pint --test` | ผ่าน |
| `npm audit --omit=dev` / `composer audit` | | 0 vulnerabilities |

E2E ที่ครอบคลุม: J1 (ขอบเขต Phase 1: สมัคร → workspace → เทมเพลต → แก้ไข → บันทึก → reload → preview), dashboard, drag & drop + keyboard, upload asset, แก้ไขพร้อมกัน (409 → merge), J8 (เข้าถึงข้าม tenant ถูกบล็อก)

## ข้อจำกัดที่ทราบ (Known limitations)
1. **Docker images** (`infra/docker/*`) เขียนไว้แต่ยังไม่ได้ build ในสภาพแวดล้อมนี้ (ไม่มี Docker daemon) — ต้องทดสอบ `docker compose up --build` ก่อนใช้
2. **ClamAV** adapter ยังไม่ได้ทดสอบกับ clamd จริง; ถ้าไม่ตั้งค่า สถานะไฟล์จะเป็น `not_scanned` (แสดงตามจริง)
3. การสแกนไฟล์เป็นแบบ synchronous (ยังไม่ใช้ queue), ยังไม่มี image variants/folders
4. Workspace switcher/การเชิญสมาชิก (invitations) ยังไม่มี UI/API — ผู้ใช้เห็น workspace แรก (M02 backlog)
5. 2FA/passkeys, OAuth login, หน้าจัดการ session ยังไม่ทำ (M01 backlog → Phase 6)
6. Redirects (`routes[]`) มีใน schema + validator แต่ยังไม่มี UI แก้ไข
7. ยังไม่มี inline text editing บน canvas (แก้ผ่าน inspector)
8. Template marketplace (moderation/licensing) ยังไม่ทำ — มีเฉพาะ curated templates
9. Mobile-friendly simplified editor ยังไม่ทำ (editor ใช้บนจอ ≥ 1024px; dashboard/preview responsive)

## วิธีทำงานต่อ (สำหรับรอบถัดไป)
1. อ่าน `docs/ROADMAP.md` → Phase 2 backlog
2. ขยาย schema → v1 ยังห้ามข้อมูลใน `dataModels/permissions/actions/workflows`; Phase 2 จะออก **schemaVersion 2** พร้อม upcaster v1→v2 ทั้ง TS และ PHP และ fixtures ใหม่
3. ทุก slice: migration → API contract (อัปเดต `docs/api/openapi.yaml`) → policy → service → UI → tests (PHPUnit + Vitest + E2E) → อัปเดตไฟล์นี้
