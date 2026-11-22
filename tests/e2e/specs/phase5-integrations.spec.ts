import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { createProject, register, waitSaved } from './helpers.ts';

const api = fileURLToPath(new URL('../../../apps/platform-api', import.meta.url));
const FAKE = 'http://127.0.0.1:8002';
const SHEET_URL = 'https://docs.google.com/spreadsheets/d/e2eSheet00000000000000000000000001/edit';

async function becomePlatformAdminAndConfigureGoogle(page: Page, email: string) {
  execFileSync('php', ['artisan', 'studio:platform-admin', email], { cwd: api, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${api}/database/e2e.sqlite` } });
  await page.goto('/app/admin/settings');
  await page.getByLabel('Client ID').fill('123456789012-e2efakeclient00000000.apps.googleusercontent.com');
  await page.getByLabel('Client secret').fill(['e2e', 'fake', 'client', 'secret', 'value'].join('-'));
  await page.getByLabel('ยืนยันรหัสผ่านของคุณ').fill('StrongPass123');
  await page.getByRole('button', { name: 'บันทึก' }).click();
  await expect(page.getByTestId('google-status')).toHaveText('ตั้งค่าแล้ว');
}

async function connectGoogle(page: Page, features: Array<'Google Sheets' | 'Google Drive'>) {
  await page.goto('/app/integrations');
  for (const name of ['Google Sheets', 'Google Drive'] as const) {
    const box = page.getByRole('checkbox', { name: new RegExp(name) });
    if (features.includes(name)) await box.check(); else await box.uncheck();
  }
  await page.getByRole('button', { name: /^เชื่อมต่อ(ใหม่)?กับ Google$/ }).click();
  // Fake consent screen redirects straight back through the real callback.
  await expect(page).toHaveURL(/\/app\/integrations$/);
  await expect(page.getByText('เชื่อมต่อ Google สำเร็จ')).toBeVisible();
  await expect(page.getByTestId('google-connection-status')).toHaveText('เชื่อมต่อแล้ว');
}

async function fake(path: string, init?: RequestInit) {
  return (await fetch(FAKE + path, init)).json();
}

test.beforeEach(async () => {
  await fetch(`${FAKE}/__reset`, { method: 'POST' });
});

/** J4: Google connect → choose Sheet → map columns → import → sync → disconnect → no residual access. */
test('J4: Google Sheets import and export, then disconnect revokes access', async ({ page }) => {
  const email = await register(page, 'เจ้าของศูนย์อบรม');
  const projectId = await createProject(page, 'ศูนย์อบรม Sheets');
  await waitSaved(page);
  await becomePlatformAdminAndConfigureGoogle(page, email);
  await connectGoogle(page, ['Google Sheets']);
  await expect(page.getByText('e2e.owner@gmail.example')).toBeVisible();

  // Import: choose the sheet, preview, map (auto-matched by column labels), dry run, run.
  await page.goto(`/app/projects/${projectId}/data`);
  await page.getByRole('tab', { name: 'Google Sheets' }).click();
  await page.getByRole('button', { name: 'เชื่อมกับ Google Sheet' }).click();
  const dlg = page.getByRole('dialog', { name: /เชื่อม .* กับ Google Sheet/ });
  await dlg.getByLabel('ลิงก์ Google Sheet').fill(SHEET_URL);
  await dlg.getByRole('button', { name: 'ตรวจสอบ' }).click();
  await expect(dlg.getByText('ผู้สมัครจากแบบฟอร์มเดิม')).toBeVisible();
  await dlg.getByRole('button', { name: 'ดูตัวอย่างคอลัมน์' }).click();
  await expect(dlg.getByRole('cell', { name: 'kamol@example.com' })).toBeVisible();
  await expect(dlg.getByLabel('คอลัมน์สำหรับ อีเมล')).toHaveValue('อีเมล');
  await expect(dlg.getByLabel('คอลัมน์สำหรับ หลักสูตร')).toHaveValue('หลักสูตร');
  await dlg.getByRole('button', { name: 'บันทึกการซิงก์' }).click();
  await expect(dlg).toBeHidden();

  const sync = page.getByTestId('sheet-sync');
  await sync.getByRole('button', { name: 'ทดลอง (dry run)' }).click();
  await expect(page.getByText(/ทดลองนำเข้า: 3 แถว · ใหม่ 2 .* ผิดพลาด 1 \(แถว 4\)/)).toBeVisible();
  await sync.getByRole('button', { name: 'ซิงก์ตอนนี้' }).click();
  await expect(sync.getByTestId('sync-job-status')).toHaveText('สำเร็จบางส่วน');
  await sync.getByRole('button', { name: 'ประวัติ' }).click();
  const history = page.getByRole('dialog', { name: /ประวัติการซิงก์/ });
  await history.getByText(/แถวที่นำเข้าไม่ได้ \(1\)/).click();
  await expect(history.getByText(/แถว 4 · หลักสูตร/)).toBeVisible();
  await page.keyboard.press('Escape');

  await page.getByRole('tab', { name: 'ข้อมูล', exact: true }).click();
  await expect(page.getByRole('table')).toContainText('kamol@example.com');
  await expect(page.getByRole('table')).toContainText('dara@example.com');
  await expect(page.getByRole('table')).not.toContainText('nocourse@example.com');

  // Running again is idempotent (matched by e-mail key): still two records.
  await page.getByRole('tab', { name: 'Google Sheets' }).click();
  await sync.getByRole('button', { name: 'ซิงก์ตอนนี้' }).click();
  await expect(sync.getByText(/เหมือนเดิม 2/)).toBeVisible();

  // Export to an empty tab: header with _studio_id is created and rows appended.
  await page.getByRole('button', { name: 'เชื่อมกับ Google Sheet' }).click();
  await dlg.getByLabel('ลิงก์ Google Sheet').fill(SHEET_URL);
  await dlg.getByRole('button', { name: 'ตรวจสอบ' }).click();
  await dlg.getByLabel('แท็บ').selectOption('รายงาน');
  await dlg.getByRole('button', { name: 'ดูตัวอย่างคอลัมน์' }).click();
  await expect(dlg.getByText('แท็บนี้ว่าง')).toBeVisible();
  await expect(dlg.getByRole('radio', { name: /ส่งออกจากแอป/ })).toBeChecked();
  for (const f of ['เบอร์โทรศัพท์', 'หน่วยงาน', 'หมายเหตุ', 'สถานะ']) await dlg.getByLabel(`คอลัมน์สำหรับ ${f}`).fill('');
  await dlg.getByRole('button', { name: 'บันทึกการซิงก์' }).click();
  await expect(dlg).toBeHidden();
  const exportSync = page.getByTestId('sheet-sync').filter({ hasText: 'รายงาน' });
  await exportSync.getByRole('button', { name: 'ซิงก์ตอนนี้' }).click();
  await expect(exportSync.getByTestId('sync-job-status')).toHaveText('สำเร็จ');
  const report = (await fake('/__state')).spreadsheets.e2eSheet00000000000000000000000001.tabs['รายงาน'];
  expect(report[0]).toEqual(['_studio_id', 'ชื่อ–นามสกุล', 'อีเมล', 'หลักสูตร']);
  expect(report).toHaveLength(3);
  expect(report.map((r: string[]) => r[2])).toContain('kamol@example.com');

  // Disconnect: tokens revoked at Google, syncs stop, nothing can reach Google any more.
  await page.goto('/app/integrations');
  await page.getByRole('button', { name: 'ยกเลิกการเชื่อมต่อ' }).click();
  await page.getByRole('button', { name: 'ยืนยันยกเลิกการเชื่อมต่อ' }).click();
  await expect(page.getByText('ยกเลิกการเชื่อมต่อและเพิกถอนสิทธิ์ที่ Google แล้ว')).toBeVisible();
  await expect(page.getByTestId('google-connection-status')).toHaveCount(0);
  expect((await fake('/__state')).revoked).toBe(1);
  await page.goto(`/app/projects/${projectId}/data`);
  await page.getByRole('tab', { name: 'Google Sheets' }).click();
  await expect(page.getByText('การเชื่อมต่อ Google ถูกยกเลิก').first()).toBeVisible();
  await expect(page.getByTestId('sheet-sync').first().getByRole('button', { name: 'ซิงก์ตอนนี้' })).toBeDisabled();
});

/** J5: Drive connect → choose folder → upload attachment → see file → revoke and handle the error. Plus a signed webhook. */
test('J5: Drive attachments with revoke handling, and signed webhooks', async ({ page }) => {
  const email = await register(page, 'ผู้ดูแลไฟล์');
  const projectId = await createProject(page, 'ศูนย์อบรม Drive');
  await waitSaved(page);
  await becomePlatformAdminAndConfigureGoogle(page, email);
  await connectGoogle(page, ['Google Drive']);

  // Webhook first, so the record we create below is delivered.
  await page.goto(`/app/projects/${projectId}/data`);
  await page.getByRole('tab', { name: 'Webhooks' }).click();
  await page.getByLabel('URL ปลายทาง').fill(`${FAKE}/hook`);
  await page.getByRole('button', { name: 'เพิ่ม webhook' }).click();
  const secret = (await page.getByTestId('webhook-secret').textContent())!.trim();
  expect(secret).toMatch(/^whsec_/);

  await page.getByRole('tab', { name: 'ข้อมูล', exact: true }).click();
  await page.getByRole('button', { name: 'เพิ่มข้อมูล' }).click();
  let rec = page.getByRole('dialog', { name: /เพิ่มข้อมูลใน/ });
  await rec.getByLabel(/ชื่อ–นามสกุล/).fill('ปกรณ์ แนบไฟล์');
  await rec.getByLabel(/อีเมล/).fill('pakorn@example.com');
  await rec.getByLabel(/หลักสูตร/).selectOption('การใช้ AI เพื่อการทำงาน');
  await rec.getByRole('button', { name: 'บันทึก' }).click();
  await expect(rec).toBeHidden();

  const hooks = await fake('/__hooks');
  expect(hooks).toHaveLength(1);
  expect(hooks[0].headers.event).toBe('record.created');
  expect(JSON.parse(hooks[0].body).record.fields.email).toBe('pakorn@example.com');
  expect((await fake('/hook/verify', { method: 'POST', body: JSON.stringify({ secret, index: 0 }) })).valid).toBe(true);

  // Attach a file to the record in Google Drive.
  await page.getByRole('button', { name: 'ปกรณ์ แนบไฟล์' }).click();
  rec = page.getByRole('dialog', { name: /แก้ไขข้อมูล/ });
  await rec.getByLabel(/สร้างโฟลเดอร์ใน Google Drive/).fill('ไฟล์แนบผู้สมัคร');
  await rec.getByRole('button', { name: 'สร้างโฟลเดอร์' }).click();
  await expect(rec.getByText('โฟลเดอร์: ไฟล์แนบผู้สมัคร')).toBeVisible();
  await rec.getByLabel('แนบไฟล์').setInputFiles({ name: 'ใบรับรอง.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n% e2e\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') });
  await expect(page.getByText('อัปโหลดไปยัง Google Drive แล้ว')).toBeVisible();
  await expect(rec.getByTestId('attachment')).toContainText('ใบรับรอง.pdf');
  const files = Object.values((await fake('/__state')).files) as Array<{ name: string }>;
  expect(files.map((f) => f.name)).toEqual(expect.arrayContaining(['ไฟล์แนบผู้สมัคร', 'ใบรับรอง.pdf']));

  const download = page.waitForEvent('download');
  await rec.getByRole('button', { name: 'ใบรับรอง.pdf', exact: true }).click();
  expect((await download).suggestedFilename()).toBe('ใบรับรอง.pdf');

  // The user revokes the app in their Google account: access fails clearly, nothing hangs.
  await fake('/__revoke-all', { method: 'POST' });
  await rec.getByRole('button', { name: 'ใบรับรอง.pdf', exact: true }).click();
  await expect(page.getByText('การเชื่อมต่อ Google ถูกเพิกถอนหรือหมดอายุ กรุณาเชื่อมต่อใหม่')).toBeVisible();
  await expect(rec.getByText('เข้าถึงไม่ได้ (การเชื่อมต่อ Google ถูกเพิกถอน)')).toBeVisible();
  await page.goto('/app/integrations');
  await expect(page.getByTestId('google-connection-status')).toHaveText('การเชื่อมต่อใช้ไม่ได้ — กรุณาเชื่อมต่อใหม่');
});
