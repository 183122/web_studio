# คู่มือติดตั้ง AI Studio Cloud บน DirectAdmin (เช่น Hostatom)

คู่มือนี้ใช้กับโฮสติ้งแบบ shared ที่ใช้ DirectAdmin และ **ไม่จำเป็นต้องมี SSH**: ทุกขั้นตอนทำผ่านหน้าเว็บ DirectAdmin (File Manager + Cron Jobs) ถ้าแพ็กเกจของคุณมี SSH ใช้คำสั่งในกรอบ "ถ้ามี SSH" แทนได้ ซึ่งเร็วกว่า

> ⚠️ **เหมาะสำหรับเดโม่ ทดลองใช้ หรือทีมเล็ก** — ระบบเต็มรูปแบบสำหรับลูกค้าจำนวนมากควรใช้ VPS (ดูหัวข้อ "ข้อจำกัดของ shared hosting" ท้ายคู่มือ)
> 🔒 **ห้ามส่งรหัสผ่านใด ๆ (DirectAdmin, ฐานข้อมูล, อีเมล) ทางแชทหรืออีเมล** — กรอกเองในไฟล์ `.env` บนเซิร์ฟเวอร์เท่านั้น

ในคู่มือนี้สมมติว่า
- ชื่อผู้ใช้ DirectAdmin คือ `USER`
- โดเมนคือ `studio.example.com` (แทนที่ด้วยโดเมนจริงของคุณทุกที่)

---

## 0. ตรวจสอบก่อนเริ่ม

| รายการ | ต้องการ | ดู/ตั้งค่าที่ |
|---|---|---|
| PHP | **8.3** ขึ้นไป | DirectAdmin → *Select PHP version* (หรือ *PHP Version Manager*) |
| PHP extensions | `pdo_mysql`, `gd` (รองรับ WebP), `fileinfo`, `curl`, `zip`, `openssl`, `mbstring`, `intl` | หน้าเดียวกัน → แท็บ *Extensions* |
| ฐานข้อมูล | MySQL 8 / MariaDB 10.6+ | *MySQL Management* |
| SSL | ใบรับรองสำหรับโดเมน (Let's Encrypt ฟรี) | *SSL Certificates* |
| Cron Jobs | ใช้ได้ (ทุก 1 นาที) | *Cron Jobs* |
| พื้นที่ | ประมาณ 300 MB + ข้อมูล/รูปภาพของผู้ใช้ | — |

ถ้าไม่แน่ใจว่าแพ็กเกจรองรับหรือไม่ ส่งตารางนี้ให้ฝ่ายซัพพอร์ตของ Hostatom ตรวจสอบได้

---

## 1. เตรียมโดเมนและ SSL
1. DirectAdmin → *Domain Setup* → เพิ่มโดเมน (หรือ subdomain เช่น `studio.example.com`)
2. *SSL Certificates* → เลือก **Free & automatic certificate from Let's Encrypt** → ติ๊กโดเมน (และ `www.` ถ้ามี) → Save
3. เปิด **Force SSL with https redirect** (ในหน้า *Domain Setup* ของโดเมนนั้น)

## 2. เลือก PHP 8.3
*Select PHP version* → เลือก **8.3** → ในแท็บ Extensions ติ๊กตามตารางข้อ 0 → Save

## 3. สร้างฐานข้อมูล
*MySQL Management* → **Create new Database**
- ตั้งชื่อฐานข้อมูลและผู้ใช้ (DirectAdmin จะเติม `USER_` ข้างหน้า เช่น `USER_studio`)
- ใช้รหัสผ่านที่สุ่มยาว ๆ — **จดไว้กับตัวเอง ไม่ต้องส่งให้ใคร**

## 4. สร้างบัญชีอีเมลสำหรับส่งอีเมลจากระบบ
*E-mail Accounts* → สร้าง เช่น `no-reply@studio.example.com` (ใช้สำหรับอีเมลยืนยันตัวตน, รีเซ็ตรหัสผ่าน, แจ้งเตือน)

## 5. อัปโหลดไฟล์
1. *File Manager* → ไปที่ `/domains/studio.example.com/`
   (จะเห็นโฟลเดอร์ `public_html` อยู่แล้ว)
2. **สำรองของเดิม**: เปลี่ยนชื่อ `public_html` เป็น `public_html_old` (ถ้ามีเว็บเดิมอยู่)
3. อัปโหลดไฟล์ `ai-studio-cloud-directadmin-XXXX.zip` มาไว้ที่ `/domains/studio.example.com/`
4. คลิกขวาที่ไฟล์ ZIP → **Extract** → จะได้โฟลเดอร์
   ```
   /domains/studio.example.com/
   ├── ai-studio/       ← ตัวระบบ (เข้าจากเว็บไม่ได้)
   ├── public_html/     ← หน้าเว็บ
   └── DEPLOY-TH.md
   ```
5. ตรวจว่าใน `public_html` มีไฟล์ `.htaccess` (ใน File Manager อาจต้องเปิด *Show hidden files*)
6. ลบไฟล์ ZIP ออกได้หลังแตกไฟล์เสร็จ

> ถ้า DirectAdmin ของคุณใช้ `private_html` แยกสำหรับ HTTPS: ตั้งค่าให้ `private_html` ชี้ไปที่ `public_html` (*Domain Setup* → *Use a symbolic link from private_html to public_html*)

## 6. ตั้งค่า `.env`
1. ใน `ai-studio/` คัดลอกไฟล์ `.env.directadmin` → ตั้งชื่อใหม่เป็น **`.env`**
2. คลิกแก้ไข `.env` แล้วเปลี่ยนทุกค่าที่มีคำว่า `CHANGE`:
   - `APP_URL` และ `FRONTEND_URL` = `https://studio.example.com`
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` = จากข้อ 3
   - `SANCTUM_STATEFUL_DOMAINS` และ `PLATFORM_HOSTS` = `studio.example.com` (ไม่มี https://)
   - `MAIL_*` = จากข้อ 4 (`MAIL_HOST` มักเป็น `mail.โดเมนของคุณ`)
   - เว้น `APP_KEY=` ว่างไว้ — ตัวติดตั้งจะสร้างให้
3. บันทึก แล้วตั้งสิทธิ์ไฟล์ `.env` เป็น **600** (คลิกขวา → *Set Permission*)

## 7. รันตัวติดตั้ง (ครั้งเดียว)

**ถ้ามี SSH**
```bash
cd ~/domains/studio.example.com/ai-studio
php artisan studio:install
```

**ถ้าไม่มี SSH — ใช้ Cron Job ชั่วคราว**
1. *Cron Jobs* → **Create Cron Job** → ตั้งเวลา `* * * * *` (ทุกนาที)
2. Command (บรรทัดเดียว):
   ```
   /usr/local/bin/php /home/USER/domains/studio.example.com/ai-studio/artisan studio:install >> /home/USER/domains/studio.example.com/ai-studio/storage/logs/install-cron.log 2>&1
   ```
   > ถ้าโฮสต์ใช้ CloudLinux ตำแหน่ง PHP อาจเป็น `/opt/alt/php83/usr/bin/php` (สอบถามซัพพอร์ตได้ว่า "PHP 8.3 CLI path")
3. รอ 1–2 นาที แล้วเปิดไฟล์ `ai-studio/storage/logs/install-cron.log` ใน File Manager
   - ต้องเห็นบรรทัดสุดท้าย **"ติดตั้งเสร็จ"**
   - ถ้าเห็น `ERROR:` ให้แก้ตามข้อความ (เช่น extension ขาด, รหัสฐานข้อมูลผิด) แล้วรอรอบถัดไป
4. **ลบ cron job นี้ทันทีเมื่อติดตั้งเสร็จ**

ตัวติดตั้งจะ: ตรวจ PHP/extension/สิทธิ์โฟลเดอร์ → สร้าง `APP_KEY` → สร้างตารางในฐานข้อมูล → ใส่แพ็กเกจและเทมเพลตเริ่มต้น → ตั้งชื่อโดเมนใน `.htaccess`
รันซ้ำได้อย่างปลอดภัย (ใช้ตอนอัปเกรดด้วย)

> 🔐 หลังติดตั้ง **ดาวน์โหลดไฟล์ `.env` เก็บสำรองไว้ในที่ปลอดภัย** — ถ้า `APP_KEY` หาย ข้อมูลที่เข้ารหัส (โทเค็น Google, 2FA, คีย์ต่าง ๆ) จะอ่านไม่ได้

## 8. ตั้ง Cron Jobs ถาวร (จำเป็น)
สร้าง 2 รายการ ทั้งคู่ตั้งเวลา `* * * * *`:

**ตัวจัดตารางงาน** (ต่ออายุแพ็กเกจ, ตรวจโดเมน, ลบข้อมูลตามระยะเวลา, ตรวจ audit log ฯลฯ)
```
/usr/local/bin/php /home/USER/domains/studio.example.com/ai-studio/artisan schedule:run >> /dev/null 2>&1
```

**คิวงานเบื้องหลัง** (เผยแพร่เว็บไซต์, ส่งอีเมล, AI, Google Sheets, webhooks)
```
/usr/local/bin/php /home/USER/domains/studio.example.com/ai-studio/artisan queue:work database --queue=build,ai,email,google,webhooks,default --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```
> บน shared hosting คิวจะทำงานรอบละไม่เกิน 1 นาที งานจึงอาจช้ากว่าบน VPS เล็กน้อย (เช่น อีเมลถึงภายใน 1 นาที)

## 9. เปิดใช้งาน
1. เปิด `https://studio.example.com` → **สมัครใช้งาน** บัญชีแรก → ยืนยันอีเมล
2. ตั้งบัญชีนี้เป็นผู้ดูแลแพลตฟอร์ม:
   - SSH: `php artisan studio:platform-admin you@example.com`
   - ไม่มี SSH: สร้าง cron ชั่วคราวเหมือนข้อ 7 ด้วยคำสั่ง
     `/usr/local/bin/php /home/USER/domains/studio.example.com/ai-studio/artisan studio:platform-admin you@example.com`
     รอ 1 นาทีแล้ว **ลบ cron ทิ้ง**
3. รีเฟรชหน้าเว็บ → จะเห็นเมนู **ผู้ดูแลระบบ** (ตั้งค่า Google OAuth, ดูองค์กร, ตรวจเทมเพลต)

## 10. ตรวจสอบหลังติดตั้ง
- [ ] `https://studio.example.com/api/health` แสดง `"status":"ok"`
- [ ] สมัคร/เข้าสู่ระบบได้ และได้รับอีเมลยืนยัน
- [ ] สร้างโปรเจกต์จากเทมเพลต แก้ไข และกด "ดูตัวอย่าง" ได้
- [ ] อัปโหลดรูปภาพได้
- [ ] หน้า "ข้อมูล" เพิ่ม/ส่งออก CSV ได้
- [ ] ส่งออก ZIP (หน้าต่างเผยแพร่) ดาวน์โหลดได้
- [ ] หลังรอ 2–3 นาที `api/health` แสดง `"scheduler":"ok"` (cron ทำงาน)

---

## อัปเกรดเป็นเวอร์ชันใหม่
1. สำรองฐานข้อมูล (*MySQL Management* → *Backup*) และโฟลเดอร์ `ai-studio/storage`
2. แตก ZIP ใหม่ไว้ในโฟลเดอร์ชั่วคราว
3. แทนที่ `ai-studio/` **ยกเว้น** `.env` และ `storage/` · แทนที่ไฟล์ทั้งหมดใน `public_html/`
4. รัน `studio:install` อีกครั้ง (ข้อ 7) เพื่ออัปเดตฐานข้อมูล

## ปัญหาที่พบบ่อย
| อาการ | สาเหตุ/วิธีแก้ |
|---|---|
| หน้าขาว / Error 500 | เปิด `ai-studio/storage/logs/laravel-*.log` · ตรวจ PHP 8.3 · ตรวจสิทธิ์ `storage/` และ `bootstrap/cache/` เป็น 755 |
| หน้าเว็บโหลดได้แต่เข้าสู่ระบบแล้วเด้งออก | `SANCTUM_STATEFUL_DOMAINS` / `APP_URL` ไม่ตรงกับโดเมนจริง หรือยังไม่ได้ใช้ https |
| 404 ทุกหน้ายกเว้นหน้าแรก | ไฟล์ `.htaccess` ไม่ได้อัปโหลด หรือโฮสต์ปิด `mod_rewrite` |
| อีเมลไม่ส่ง | ตรวจ `MAIL_*` และ cron คิวงาน (ข้อ 8) |
| อัปโหลดรูปไม่ได้ | ต้องเปิด extension `gd` (WebP) และ `fileinfo` |
| แก้ `.env` แล้วไม่มีผล | ระบบ cache ค่าไว้ — รัน `studio:install` อีกครั้ง (หรือ `php artisan config:cache`) |

## ข้อจำกัดของ shared hosting
| ฟีเจอร์ | บน shared DirectAdmin |
|---|---|
| Studio, แก้ไขเว็บ, ข้อมูล/ฟอร์มในหน้าตัวอย่าง, AI (โหมดออฟไลน์ หรือใส่ ANTHROPIC_API_KEY), ส่งออก ZIP, ทีม/2FA/PDPA | ✅ ใช้ได้ |
| เผยแพร่เว็บไซต์ลูกค้าเป็น `ชื่อ.sites.โดเมน` | ⚠️ ต้องมี **wildcard DNS** (`*.sites.โดเมน`) + ให้โฮสต์ตั้ง **wildcard subdomain ชี้มาที่ public_html** + **wildcard SSL** — สอบถาม Hostatom ก่อน ถ้าทำไม่ได้ ให้ใช้ "ส่งออก ZIP" แล้วอัปโหลดเว็บไซต์ลูกค้าแยกแทน |
| โดเมนของลูกค้าเอง (custom domain) + SSL อัตโนมัติ | ❌ ต้องใช้ VPS (on-demand TLS) — หรือเพิ่มเป็น domain pointer ใน DirectAdmin ทีละโดเมนด้วยตนเอง |
| Google Sheets/Drive | ✅ ถ้าตั้งค่า Google OAuth client (เมนูผู้ดูแลระบบ) — งานซิงก์รันผ่าน cron |
| การชำระเงินจริง | ⏸ ยังปิดอยู่จนกว่าจะเลือกและอนุมัติผู้ให้บริการชำระเงิน |
| ลูกค้าจำนวนมาก / โหลดสูง | ❌ แนะนำ VPS (ใช้ Docker ตาม `infra/` ใน repository) |

## ความปลอดภัย (ทำทุกข้อ)
- `APP_DEBUG=false` เสมอ
- โฟลเดอร์ `ai-studio/` ต้องอยู่ **นอก** `public_html` (ตามโครงสร้างในข้อ 5)
- ไฟล์ `.env` สิทธิ์ 600 และสำรองไว้นอกเซิร์ฟเวอร์
- สำรองฐานข้อมูลอัตโนมัติ (*Create/Restore Backups* ของ DirectAdmin) อย่างน้อยวันละครั้ง
- ลบ cron ชั่วคราว (ข้อ 7 และ 9) ทันทีหลังใช้งาน
