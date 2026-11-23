# บันทึกความคืบหน้า (Progress Log)

> เอกสารนี้คือ "จุดเริ่มต้น" สำหรับการพัฒนารอบถัดไป: อ่านสถานะ → ดู backlog ใน `docs/ROADMAP.md` → ทำ Phase ถัดไปเป็น vertical slice
> หลักการ: ไม่อ้างว่าฟีเจอร์ทำงานถ้ายังไม่มีโค้ดและเทสต์จริง

## สถานะรวม (อัปเดต 2026-11-23, หลัง Phase 7)

| Phase | ขอบเขต | สถานะ |
|---|---|---|
| 0 | ADR, threat model, monorepo, Docker, CI, migrations, seed, auth, tenancy tests | ✅ เสร็จ (Docker image ยังไม่ได้ build ทดสอบในสภาพแวดล้อมนี้ — ดู "ข้อจำกัด") |
| 1 | Project dashboard, canonical schema, pages, drag & drop, inspector, assets, responsive preview, save/revisions, templates | ✅ เสร็จ |
| 2 | Data model designer, CRUD, generated admin, app RBAC, forms, workflows, REST API | ✅ เสร็จ (บัญชีผู้ใช้ของแอปส่งมอบใน Phase 4) |
| 3 | AI planner/generator/editor, diff approval, multi-model, budgets | ✅ เสร็จ (Claude + โหมดออฟไลน์; OpenAI/Gemini ยังไม่ทำ, ยังไม่ได้ทดสอบกับ API จริง — ดูข้อจำกัด) |
| 4 | Publishing (wildcard subdomain), immutable releases, rollback, custom domains/SSL | ✅ เสร็จ (ยังไม่ได้ทดสอบกับ DNS/ACME จริงและยังไม่ deploy production — ดูข้อจำกัด) |
| 5 | Google Sheets/Drive OAuth + sync, webhooks, monitoring | ✅ เสร็จ (ทดสอบกับ Google จำลอง ยังไม่ได้ทดสอบกับ Google จริง — ดูข้อจำกัด) |
| 6 | Billing, entitlements/metering, super admin, privacy, hardening | ✅ เสร็จ (billing โหมดทดสอบ — การเปิดใช้ผู้ให้บริการชำระเงินจริงต้องได้รับอนุมัติ) |
| 7 | Marketplace, collaboration, export, sandboxed custom code | ✅ เสร็จ (custom code: ทำ security review แล้ว — ตัดสินใจยังไม่เปิดใช้) |

ยังไม่พร้อมใช้งาน production — gates ใน spec §21 ยังไม่ครบ (J1–J10 มีเทสต์แล้ว แต่ Google, DNS/ACME และผู้ให้บริการชำระเงิน ยังไม่ได้ทดสอบกับระบบจริง และยังไม่ได้ deploy)

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

## Phase 7: สิ่งที่ส่งมอบ (2026-11-23)

- **แกลเลอรีเทมเพลตชุมชน**: ส่งโปรเจกต์เป็นเทมเพลตจากหน้าต่าง "เผยแพร่" → ระบบตัดข้อมูลในตาราง ปิด workflow และนำการส่งอีเมลไปยังที่อยู่คงที่ออก → ผู้ดูแลแพลตฟอร์มดูตัวอย่างและอนุมัติ/ไม่อนุมัติ (แจ้งผลทางอีเมล) → แสดงในหน้าสร้างโปรเจกต์ พร้อมผู้สร้าง สัญญาอนุญาต (ใช้ได้ทุกวัตถุประสงค์ / ใช้ส่วนตัว) เวอร์ชัน และจำนวนการใช้ · ส่งเวอร์ชันใหม่ได้ · รูปภาพถูกคัดลอกเข้า workspace ของผู้ใช้ (ตรวจโควตา) · ผู้สร้างหรือผู้ดูแลนำออกได้ · รายงานเทมเพลตได้
- **แก้ไขพร้อมกัน**: เห็นรูปผู้ที่เปิดโปรเจกต์อยู่ด้วย เห็นกรอบสีที่คนอื่นกำลังเลือก การแก้ไขของคนอื่นแสดงในไม่กี่วินาทีโดยไม่ต้องรีโหลด และถ้าบันทึกชนกัน ระบบรวมให้อัตโนมัติ (แสดงแถบให้เลือกเองเฉพาะกรณีรวมไม่ได้) — ใช้การ polling ทุก 3 วินาที ยังไม่ใช่ WebSocket และการแก้ช่องเดียวกันพร้อมกันคนบันทึกหลังชนะ
- **ส่งออก (ZIP)**: เว็บไซต์แบบ static (ใช้ runtime ตัวเดียวกัน ฟอร์ม/ตารางแสดงข้อความแจ้ง), ข้อมูล CSV/JSON, `schema.sql` สำหรับ MySQL 8 (ทดลองนำเข้าจริงแล้ว), `app.json`, `openapi.json`, README ที่บอกชัดว่าส่วนใดใช้ได้โดยไม่ต้องใช้แพลตฟอร์ม · ไม่มีคีย์ ความลับ หรือรหัสผ่านในไฟล์
- **Custom code**: ทำ security review (`docs/security/custom-code-sandbox-review.md`) — สรุปว่ายังไม่เปิดให้รันโค้ดของลูกค้า พร้อมเงื่อนไขที่ต้องมีก่อนเปิด และเทสต์ป้องกันไม่ให้เพิ่มคอมโพเนนต์/คำสั่งที่รันโค้ดได้โดยไม่ผ่านการทบทวน
- เอกสาร: ADR-0014, threat model (M-1…X-1), OpenAPI 0.7.0

## Phase 6: สิ่งที่ส่งมอบ (2026-11-22)

- **การเรียกเก็บเงิน** (`/app/billing`): แพ็กเกจ Free / Pro ฿490 / Business ฿1,490 ต่อเดือน (+VAT 7%) · ชำระผ่านผู้ให้บริการทดสอบ (หน้าชำระเงินจำลอง; ระบบปฏิเสธเมื่อ APP_ENV=production) · แพ็กเกจเปลี่ยนเฉพาะเมื่อได้รับ webhook ที่ตรวจลายเซ็นแล้วเท่านั้น (HMAC + กันส่งซ้ำ 5 นาที + idempotent + ตรวจยอดเงิน) · ใบแจ้งหนี้/ใบกำกับภาษีพิมพ์ได้ เลขที่ `INV-ปี-ลำดับ` ไม่ข้ามเลข · ทดลองใช้ 14 วันครั้งเดียว · ยกเลิกเมื่อสิ้นรอบ · ต่ออายุอัตโนมัติ · ตัดเงินไม่ผ่านลองใหม่ 1/3/7 วัน แล้วปรับเป็น Free โดยไม่ลบข้อมูล
- **Metering**: นับคำขอเว็บไซต์, แบนด์วิดท์, นาทีการเผยแพร่, เครดิต AI แบบ atomic · อีเมลเตือนที่ 80%/100% · เว็บไซต์หยุดชั่วคราวเมื่อเกิน 150% · โควตาสมาชิกและโดเมนของตัวเองตามแพ็กเกจ
- **ผู้ดูแลแพลตฟอร์ม** (`/app/admin`): รายการองค์กร, เปลี่ยนแพ็กเกจ/เพิ่มเครดิต (ต้องระบุเหตุผล บันทึก audit), ระงับองค์กร (เว็บไซต์ออฟไลน์ เผยแพร่ไม่ได้ ข้อมูลยังอยู่), รายงานการละเมิดจากสาธารณะ (`/report-abuse`) · **สิทธิ์ฝ่ายสนับสนุน** ต้องให้เจ้าขององค์กรอนุมัติ อ่านอย่างเดียว มีเวลาจำกัด เพิกถอนได้ ไม่เห็นข้อมูลการเงิน
- **ทีมและความปลอดภัย** (`/app/team`): เชิญสมาชิกทางอีเมล (ผูกกับอีเมลที่เชิญ หมดอายุ 7 วัน จำกัดตามแพ็กเกจ), เปลี่ยนบทบาท/นำออก, สลับ Workspace, 2FA (TOTP + QR + รหัสกู้คืน 10 รหัส), รายการอุปกรณ์ที่เข้าสู่ระบบและสั่งออกจากระบบ
- **PDPA**: บันทึกความยินยอม (เวอร์ชันข้อกำหนด/นโยบาย), ยินยอมรับข่าวสารแบบเลือกเอง, ดาวน์โหลดข้อมูลส่วนบุคคล, ลบบัญชี (ทำให้ระบุตัวตนไม่ได้ทันที องค์กรที่เป็นเจ้าของคนเดียวลบถาวรหลัง 30 วัน), เจ้าของแอปส่งออก/ลบข้อมูลผู้ใช้ปลายทางได้, ลบข้อมูลตามระยะเวลาเก็บรักษาทุกวัน
- **Audit log กันแก้ไข**: hash chain + `php artisan studio:audit:verify` (ตรวจทุกวัน + metric)
- **Observability**: `X-Request-Id` ทุกคำขอ และ `error.requestId` ในข้อความผิดพลาด, health ตรวจ DB/cache/storage + scheduler heartbeat, `/api/internal/metrics` (ต้องตั้ง `METRICS_TOKEN`), log แบบ JSON, CI สแกนความลับ (gitleaks) + SAST (Semgrep), runbooks ใน `docs/runbooks/`
- **แก้บั๊ก**: ลิงก์ GET ที่เปิดตรงหรือบุ๊กมาร์ก (ใบกำกับภาษี, ดาวน์โหลด, ส่งออกข้อมูล) เคยได้ 401 แม้ล็อกอินอยู่; วันหมดอายุคำเชิญเคยใช้นาฬิการะบบแทนนาฬิกาของแอป
- เอกสาร: ADR-0013, threat model (B-1…O-1), OpenAPI 0.6.0

## Phase 5: สิ่งที่ส่งมอบ (2026-11-22)

- **การเชื่อมต่อ Google** (`/app/integrations`): หนึ่งบัญชีต่อ Workspace, ผู้ดูแล Workspace เลือกฟีเจอร์เอง (Sheets → `spreadsheets`, Drive → `drive.file` เท่านั้น), OAuth + PKCE, state ใช้ครั้งเดียวและผูกกับผู้ใช้, โทเค็นเข้ารหัส, refresh อัตโนมัติ, ยกเลิกการเชื่อมต่อ = เพิกถอนที่ Google + ลบโทเค็น + หยุดการซิงก์
- **Google Sheets** (แท็บใหม่ในหน้า ข้อมูล): วางลิงก์ → เลือกแท็บ → ดูตัวอย่าง → จับคู่คอลัมน์ (เดาให้อัตโนมัติ) → คีย์กันข้อมูลซ้ำ → ทิศทาง นำเข้า/ส่งออก/สองทาง + นโยบายเมื่อขัดแย้ง → ความถี่ · dry run · นำเข้าตรวจทุกแถวด้วยกฎเดียวกับฟอร์ม (แถวผิดถูกรายงาน ไม่ทำให้ทั้งงานล้ม) · ส่งออกแบบ RAW แก้เฉพาะเซลล์ที่จับคู่และเพิ่มคอลัมน์ `_studio_id` · ประวัติงาน, ลองใหม่อัตโนมัติ (30 วิ → 30 นาที) แล้วเข้าสถานะ dead ให้กดลองใหม่ได้
- **Google Drive**: ไฟล์แนบในหน้าต่างแก้ไขข้อมูล, โฟลเดอร์ที่ระบบสร้าง, ตรวจชนิดไฟล์จากเนื้อหา ≤ 10 MB + สแกนมัลแวร์, ตรวจสิทธิ์ที่ Google ทุกครั้งที่ดาวน์โหลด, แสดงสถานะเมื่อไฟล์ถูกลบหรือสิทธิ์ถูกเพิกถอน
- **Webhooks** (แท็บใหม่): ส่งเหตุการณ์ สร้าง/แก้ไข/ลบ ข้อมูล, ลายเซ็น HMAC-SHA256, กัน SSRF (https + IP สาธารณะเท่านั้น, ล็อก IP ที่ตรวจแล้ว, ไม่ตาม redirect), ลองใหม่ 6 ครั้ง, dead letter, ส่งใหม่ได้, ปิดอัตโนมัติเมื่อปลายทางพัง
- **แก้บั๊กที่พบระหว่างทาง**: คำขอ API ที่ไม่ได้ล็อกอินแบบไม่ใช่ JSON เคยตอบ 500 → ตอนนี้ 401; ข้อผิดพลาดที่คาดไว้ (ApiException) ไม่ถูกบันทึกเป็น error อีก
- **การทดสอบ**: PHPUnit ใช้ Google จำลองในหน่วยความจำ (Http::fake), E2E ใช้เซิร์ฟเวอร์ Google จำลอง `tests/e2e/fake-google` (ตรวจ PKCE เหมือน Google จริง) ผ่าน `GOOGLE_FAKE_BASE` ซึ่งระบบปฏิเสธใน production
- เอกสาร: ADR-0012, threat model (I-1…I-12), OpenAPI 0.5.0, `docs/runbooks/google-oauth-review.md`

## ตั้งค่าระบบสำหรับผู้ดูแลแพลตฟอร์ม (2026-11-22)

- หน้า **ตั้งค่าระบบ** (`/app/admin/settings`, ลิงก์บนแดชบอร์ดเห็นเฉพาะผู้ดูแลแพลตฟอร์ม): ตั้งค่า Google OAuth client (Client ID + secret) พร้อมค่า Authorized origin / redirect URI ให้คัดลอกไปใส่ใน Google Cloud Console
- ผู้ดูแลแพลตฟอร์มมอบสิทธิ์ได้จาก shell เท่านั้น: `php artisan studio:platform-admin <email>` (`--revoke` เพื่อถอน) · ผู้อื่นได้ 404
- secret เข้ารหัสด้วย app key (`platform_settings`), ไม่ถูกส่งกลับ (แสดงแค่ 4 ตัวท้าย), ต้องยืนยันรหัสผ่านทุกครั้งที่บันทึก/ลบ, บันทึก audit log (ไม่มีค่า secret)
- ถ้าตั้ง `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` ใน environment ค่านั้นมีผลก่อนและฟอร์มจะเป็นแบบอ่านอย่างเดียว
- ค่าที่บันทึกจะถูกใช้โดยขั้นตอนเชื่อมต่อ Google ใน Phase 5 (ยังไม่ได้สร้าง callback/OAuth flow)

## Phase 4: สิ่งที่ส่งมอบ (2026-11-22)

- **Release & pipeline** (`app/Domain/Publishing/PublishService.php`): ตรึง revision ที่บันทึกแล้วเป็น release ที่แก้ไขไม่ได้ → validate → security scan (รูปถูกลบ/ไม่ผ่านสแกน = ล้มเหลว, ลิงก์เสีย/สิทธิ์อ่านสาธารณะ = คำเตือน) → manifest → stage → smoke test → สลับแบบ atomic (compare-and-set) → health check (ล้มเหลว = คืนค่าอัตโนมัติ) · rollback, หยุดเผยแพร่ · คิว `build`, ทีละ 1 deployment ต่อโปรเจกต์ · ต้องยืนยันอีเมลก่อนเผยแพร่
- **Runtime** (`RuntimeController` + `apps/runtime-web`): Laravel แยกเว็บไซต์จาก Host → ส่ง HTML (title/description/robots ต่อหน้า, 404 จริง, redirects, CSP เข้มงวด) + เอกสาร release เป็น JSON ที่ไม่ถูก execute → React runtime เรนเดอร์ด้วย `@studio/renderer` โหมด live · ตัด workflows/projectId ออกจากเอกสารสาธารณะ · รูปผ่าน `/_assets/{id}` เฉพาะที่อยู่ใน manifest
- **ข้อมูลจริง (production)**: ฟอร์มบนเว็บไซต์จริงบันทึกลง environment `production` ตาม schema/สิทธิ์ของ release · workflows รันจาก release · หน้า "ข้อมูล" สลับ preview/production ได้ (ค้นหา, แก้ไข, ส่งออก/นำเข้า CSV, ประวัติ)
- **บัญชีผู้ใช้ของแอป**: สมัคร/เข้าสู่ระบบบนเว็บไซต์ที่เผยแพร่ (แถบบัญชีแสดงเมื่อสิทธิ์ให้ member/admin), cookie `__site_session` HttpOnly + host-only + SameSite=Lax, token hash, ผูกกับโปรเจกต์, ระงับ/เปลี่ยนบทบาทแล้ว session ถูกเพิกถอนทันที · แท็บ "ผู้ใช้แอป" ในหน้า ข้อมูล · คำขอเขียนต้องมี `X-Runtime-Request` (กัน CSRF)
- **โดเมน**: โดเมนย่อย `<slug>.PLATFORM_BASE_DOMAIN` (ชื่อสงวน, ห้ามซ้ำ, พัก 30 วันก่อนให้องค์กรอื่นใช้) · โดเมนของตัวเอง: คำแนะนำ TXT + CNAME/ALIAS, ตรวจความเป็นเจ้าของ + การชี้ + ใบรับรองก่อนให้บริการ, ตรวจซ้ำทุก 10 นาที (`studio:domains:check`), ลบแล้วเพิ่มใหม่ต้องยืนยันใหม่ · `GET /api/internal/tls/ask` สำหรับ on-demand TLS · `infra/edge/Caddyfile` + nginx แยก server ของ studio กับเว็บไซต์ลูกค้า
- **ลิงก์แชร์ฉบับร่าง** (`/share/:token`): หมดอายุ 1–30 วัน, ยกเลิกได้, noindex, ฟอร์มปิดใช้งาน
- **Studio**: ปุ่ม "เผยแพร่" → dialog (โดเมนย่อย, สถานะ + log ทีละขั้น, ประวัติ release + ย้อนกลับ, หยุดเผยแพร่, โดเมนของตัวเอง, ลิงก์แชร์)
- เอกสาร: ADR-0011, threat model (P-1…P-11), OpenAPI 0.4.0, `.env.example`

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
| PHPUnit บน SQLite | `cd apps/platform-api && php artisan test` | **208/208 ผ่าน** (1,703 assertions) |
| PHPUnit บน MySQL 8.0.46 | `DB_CONNECTION=mysql … php artisan test` | **208/208 ผ่าน** |
| Vitest app-schema / renderer / studio / runtime | `npm test` | **80/80, 10/10, 9/9, 5/5 ผ่าน** |
| Template validation | `npm test -w packages/templates` | 3/3 valid |
| Typecheck + production build | `npm run typecheck && npm run build` | ผ่าน |
| Playwright E2E | `npm run test:e2e` | **21/21 ผ่าน** (รอบหนึ่งมี J2 timeout ครั้งเดียว รันซ้ำผ่านทั้งเดี่ยวและทั้งชุด) |
| Pint (code style) | `vendor/bin/pint --test` | ผ่าน |

E2E ที่ครอบคลุม: แก้ไขพร้อมกัน 3 ผู้ใช้, แกลเลอรีเทมเพลต (ส่ง → ตรวจ → ติดตั้ง), ส่งออก ZIP, J7 (อัปเกรด → ชำระเงินจำลอง → webhook → โควตาเพิ่ม → ใบกำกับภาษี), บัตรถูกปฏิเสธ, 2FA, J4 (Sheets นำเข้า/ส่งออก/ยกเลิกการเชื่อมต่อ), J5 (Drive + การเพิกถอน + webhook ที่ตรวจลายเซ็น), J1 (ครบ: เผยแพร่ → เปิด `<slug>.sites.localhost` → ส่งฟอร์มจริง → สมัครสมาชิก → เห็นข้อมูลจริงใน studio → เผยแพร่ใหม่ → ย้อนกลับ → ลิงก์แชร์), J6 (DNS แบบ static file), J1 (ขอบเขต Phase 1), J2 (ครบรวมขั้น AI — ใช้โหมดออฟไลน์), J3, แก้ไขด้วย AI, dashboard, drag & drop + keyboard, upload asset, แก้ไขพร้อมกัน, J8 · J10 ทดสอบใน PHPUnit (ผู้ให้บริการล่ม → fallback, JSON เสีย, ปฏิเสธคำขอ, key ผิด, คำตอบถูกตัด)

## ข้อจำกัดที่ทราบ (Known limitations)
0. **AI ยังไม่ได้ทดสอบกับ Claude API จริง** — สภาพแวดล้อมนี้ไม่มี API key; เส้นทางเรียก SDK ทดสอบผ่าน fake HTTP transport (ตรวจรูปแบบคำขอ, การแปลงผลลัพธ์, ข้อผิดพลาด) · ต้องตั้ง `ANTHROPIC_API_KEY` (หรือ BYOK) แล้วทดลองจริงก่อนเปิดใช้ · OpenAI/Gemini adapter ยังไม่ทำ
1. **Docker images** (`infra/docker/*`) และ CI workflow ยังไม่ได้ build/รันจริง (ไม่มี Docker daemon / ยังไม่มีรัน GitHub Actions)
2. **ClamAV** adapter ยังไม่ได้ทดสอบกับ clamd จริง; ถ้าไม่ตั้งค่า สถานะไฟล์คือ `not_scanned`
3. **บัญชีผู้ใช้ของแอป**: มีสมัคร/เข้าสู่ระบบแล้ว แต่ยังไม่มีรีเซ็ตรหัสผ่าน/ยืนยันอีเมลของผู้ใช้ปลายทาง และสิทธิ์แบบ "เฉพาะข้อมูลของตัวเอง"
9. **การเผยแพร่ยังไม่ได้ทดสอบกับ DNS/ACME จริง** — ตรวจ DNS/TLS ผ่าน interface (ทดสอบด้วย fake และไฟล์ static ใน E2E); `infra/edge/Caddyfile` และ nginx tenant server เป็น reference ที่ยังไม่ได้รันจริง · การแก้ DNS และ deploy production ต้องได้รับอนุมัติก่อน
12. **Billing อยู่ในโหมดทดสอบ** — ยังไม่มี adapter ของผู้ให้บริการชำระเงินจริง (ต้องได้รับอนุมัติก่อนเปิดใช้), รูปแบบใบกำกับภาษีต้องให้นักบัญชีตรวจ, ไม่มี proration และหน้าจัดการบัตร
13. CI security scans (gitleaks/Semgrep) ถูกเพิ่มใน workflow แต่ยังไม่ได้รันจริง (ไม่มี GitHub Actions run ในสภาพแวดล้อมนี้); ยังไม่มี DAST, passkeys, UI โอนความเป็นเจ้าของ
11. **Google ยังไม่ได้ทดสอบกับของจริง** — ไม่มีการเข้าถึง Google จากสภาพแวดล้อมนี้; โค้ดทดสอบกับ Google จำลองที่ทำตาม API จริง · ก่อนเปิดใช้ต้องทำตาม `docs/runbooks/google-oauth-review.md` (รวมการขอ verify scope `spreadsheets` กับ Google) · ยังไม่มี Google Picker (ลิงก์ชีตด้วย URL, โฟลเดอร์ Drive ต้องสร้างผ่านระบบ)
10. หน้าเว็บที่เผยแพร่เรนเดอร์ฝั่ง client (server ส่ง title/description ให้ search engine แล้ว แต่เนื้อหายังไม่ SSR), ยังไม่มี sitemap.xml/robots.txt ต่อเว็บไซต์
4. **ฟิลด์ไฟล์/อัปโหลด และความสัมพันธ์ระหว่างตาราง** ยังไม่มี (backlog)
5. CAPTCHA ยังไม่มี (มี honeypot + เวลากรอกขั้นต่ำ + rate limit)
6. การค้นหาใช้ LIKE บน JSON — เหมาะกับข้อมูลระดับหมื่นแถวต่อตาราง; ข้อมูลใหญ่กว่านี้ต้องเพิ่ม materialized index (Phase 6)
7. อีเมลจาก workflow ใช้ mail driver ของ Laravel (dev = log) — ต้องตั้งค่า SMTP/ผู้ให้บริการจริงก่อนใช้งาน
8. Workspace switcher/การเชิญสมาชิก, 2FA/passkeys, UI แก้ redirects, inline text editing บนแคนวาส ยังไม่ทำ (backlog เดิม)

## วิธีทำงานต่อ (สำหรับรอบถัดไป)
1. อ่าน `docs/ROADMAP.md` → Phase 2 backlog
2. ก่อนใช้งานจริง: ทดสอบกับ Google/DNS จริง, เลือกและอนุมัติผู้ให้บริการชำระเงิน, ทำ restore drill และ DAST ตาม `docs/runbooks/`
3. งานที่เหลือ: M30 Analytics, เทมเพลตแบบเสียเงิน, collaboration แบบ WebSocket/CRDT, prototype custom code แบบ Wasm (หลัง external review)
4. runtime: build `npm run build:runtime` ก่อนรัน API (E2E ทำให้อัตโนมัติ)
5. ทุก slice: migration → API contract (อัปเดต `docs/api/openapi.yaml`) → policy → service → UI → tests (PHPUnit + Vitest + E2E) → อัปเดตไฟล์นี้
