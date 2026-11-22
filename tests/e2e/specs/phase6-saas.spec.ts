import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { createProject, register, upgradeTo } from './helpers.ts';

/** RFC 6238 TOTP for the authenticator step (base32 secret). */
function totp(secret: string, step = Math.floor(Date.now() / 30000)): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of secret) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g)!.map((b) => parseInt(b, 2)));
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(step));
  const h = createHmac('sha1', key).update(msg).digest();
  const o = h[19] & 0xf;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1_000_000).padStart(6, '0');
}

/** J7: payment webhook → quota update → usage enforcement (dunning/downgrade are covered in PHPUnit with time travel). */
test('J7: upgrade through checkout, webhook confirms payment, quota is raised, tax invoice issued', async ({ page }) => {
  await register(page);
  for (const name of ['หนึ่ง', 'สอง', 'สาม']) {
    await createProject(page, `โปรเจกต์${name}`, 'blank');
    await page.goto('/app');
  }
  // Free plan: the 4th project is blocked by quota.
  await page.getByRole('button', { name: 'สร้างโปรเจกต์', exact: true }).click();
  const dlg = page.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' });
  await dlg.getByLabel('ชื่อโปรเจกต์').fill('โปรเจกต์สี่');
  await dlg.getByRole('radio', { name: /หน้าว่าง/ }).check();
  await dlg.getByRole('button', { name: 'สร้างโปรเจกต์' }).click();
  await expect(page.getByText(/จำกัด จำนวนโปรเจกต์ ไว้ที่ 3/)).toBeVisible();

  await page.goto('/app/billing');
  await expect(page.getByText('โหมดทดสอบ: การชำระเงินเป็นการจำลอง')).toBeVisible();
  await expect(page.getByTestId('plan-pro')).toContainText('฿524.30');
  await upgradeTo(page, 'Pro');
  await expect(page.getByTestId('subscription-status')).toHaveText('ใช้งานอยู่');
  const row = page.getByTestId('invoice-row').first();
  await expect(row).toContainText(/INV-\d{4}-\d{6}/);
  await expect(row).toContainText('ชำระแล้ว');
  const printUrl = await row.getByRole('link', { name: 'ใบกำกับภาษี' }).getAttribute('href');
  const html = await (await page.request.get(printUrl!)).text();
  expect(html).toContain('ใบเสร็จรับเงิน / ใบกำกับภาษี');
  expect(html).toContain('524.30');

  // Quota raised: the 4th project can now be created.
  await page.goto('/app');
  await createProject(page, 'โปรเจกต์สี่', 'blank');
});

test('declined card leaves the plan unchanged', async ({ page }) => {
  await register(page);
  await page.goto('/app/billing');
  await page.getByRole('button', { name: 'อัปเกรดเป็น Pro' }).click();
  await page.getByRole('button', { name: 'บัตรถูกปฏิเสธ' }).click();
  await expect(page).toHaveURL(/\/app\/billing/);
  await expect(page.getByTestId('current-plan')).toHaveText('Free');
  await expect(page.getByTestId('invoice-row').first()).toContainText('รอชำระ');
});

test('2FA: enrol with an authenticator, then sign-in requires the code', async ({ page }) => {
  const email = await register(page, 'ผู้ใช้ปลอดภัย');
  await page.goto('/app/team');
  const tfa = page.getByRole('region', { name: /การยืนยันตัวตนสองขั้นตอน/ });
  await tfa.getByLabel('รหัสผ่านปัจจุบัน').fill('StrongPass123');
  await tfa.getByRole('button', { name: 'เริ่มตั้งค่า 2FA' }).click();
  await expect(tfa.getByRole('img', { name: /QR code/ })).toBeVisible();
  const secret = (await tfa.getByTestId('tfa-secret').textContent())!.trim();
  await tfa.getByLabel('รหัสยืนยัน').fill(totp(secret));
  await tfa.getByRole('button', { name: 'ยืนยันและเปิดใช้' }).click();
  await expect(tfa.getByTestId('recovery-codes').locator('li')).toHaveCount(10);
  await tfa.getByRole('button', { name: 'บันทึกแล้ว' }).click();
  await expect(tfa.getByTestId('tfa-status')).toBeVisible();

  await page.goto('/app');
  await page.getByRole('button', { name: 'ออกจากระบบ' }).click();
  await page.getByLabel('อีเมล').fill(email);
  await page.getByLabel('รหัสผ่าน').fill('StrongPass123');
  await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
  await expect(page.getByRole('heading', { name: 'ยืนยันตัวตนสองขั้นตอน' })).toBeVisible();
  await page.getByLabel('รหัสยืนยัน').fill('000000');
  await page.getByRole('button', { name: 'ยืนยัน' }).click();
  await expect(page.getByText('รหัสไม่ถูกต้อง')).toBeVisible();
  // Next time step (the enrolment step can't be replayed).
  await page.getByLabel('รหัสยืนยัน').fill(totp(secret, Math.floor(Date.now() / 30000) + 1));
  await page.getByRole('button', { name: 'ยืนยัน' }).click();
  await expect(page).toHaveURL(/\/app$/);
  await page.goto('/app/team');
  await expect(page.getByRole('region', { name: /อุปกรณ์ที่เข้าสู่ระบบ/ }).getByText('อุปกรณ์นี้')).toBeVisible();
});
