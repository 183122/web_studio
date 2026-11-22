import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { register } from './helpers.ts';

const api = fileURLToPath(new URL('../../../apps/platform-api', import.meta.url));
const grantPlatformAdmin = (email: string) =>
  execFileSync('php', ['artisan', 'studio:platform-admin', email], {
    cwd: api,
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${api}/database/e2e.sqlite` },
  });

// Dummy value assembled at runtime so secret scanners don't flag a fake credential.
const SECRET = ['GOCSPX', 'e2eTestSecretValue1234567890'].join('-');

test('platform admin configures the Google OAuth client; others cannot see the settings', async ({ page }) => {
  const email = await register(page, 'ผู้ดูแลระบบ');
  await expect(page.getByRole('link', { name: 'ผู้ดูแลระบบ' })).toHaveCount(0);
  await page.goto('/app/admin/settings');
  await expect(page.getByText('ไม่พบหน้าที่ต้องการ')).toBeVisible();

  grantPlatformAdmin(email);
  await page.goto('/app');
  await page.getByRole('link', { name: 'ผู้ดูแลระบบ' }).click();
  await page.getByRole('link', { name: 'ตั้งค่าระบบ' }).click();
  await expect(page.getByTestId('google-status')).toHaveText('ยังไม่ได้ตั้งค่า');
  await expect(page.getByText('Authorized redirect URI', { exact: true })).toBeVisible();
  await expect(page.locator('code').filter({ hasText: '/api/v1/integrations/google/callback' })).toBeVisible();

  await page.getByLabel('Client ID').fill('123456789012-abcdefghijk0123456789.apps.googleusercontent.com');
  await page.getByLabel('Client secret').fill(SECRET);
  await page.getByLabel('ยืนยันรหัสผ่านของคุณ').fill('wrong-password-1');
  await page.getByRole('button', { name: 'บันทึก' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'รหัสผ่านไม่ถูกต้อง' })).toBeVisible();

  await page.getByLabel('ยืนยันรหัสผ่านของคุณ').fill('StrongPass123');
  await page.getByRole('button', { name: 'บันทึก' }).click();
  await expect(page.getByTestId('google-status')).toHaveText('ตั้งค่าแล้ว');
  await expect(page.getByText('…7890')).toBeVisible();
  await expect(page.getByLabel('Client secret')).toHaveValue('');

  await page.reload();
  await expect(page.getByTestId('google-status')).toHaveText('ตั้งค่าแล้ว');
  expect(await page.content()).not.toContain(SECRET);
});
