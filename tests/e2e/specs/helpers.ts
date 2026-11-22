import { expect, type Page } from '@playwright/test';

export async function register(page: Page, name = 'ผู้ทดสอบ'): Promise<string> {
  const email = `e2e-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.com`;
  await page.goto('/register');
  await page.getByLabel('ชื่อ–นามสกุล').fill(name);
  await page.getByLabel('อีเมล').fill(email);
  await page.getByLabel('รหัสผ่าน', { exact: true }).fill('StrongPass123');
  await page.getByLabel('ยืนยันรหัสผ่าน').fill('StrongPass123');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'สร้างบัญชี' }).click();
  await expect(page).toHaveURL(/\/app$/);
  return email;
}

export async function login(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('อีเมล').fill(email);
  await page.getByLabel('รหัสผ่าน').fill('StrongPass123');
  await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
  await expect(page).toHaveURL(/\/app/);
}

/** Creates a project via the dialog (opens automatically after sign-up). Returns its id. */
export async function createProject(page: Page, name: string, template: RegExp | 'blank' = /ระบบลงทะเบียนอบรม/): Promise<string> {
  const dialog = page.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' });
  if (!(await dialog.isVisible())) await page.getByRole('button', { name: 'สร้างโปรเจกต์', exact: true }).click();
  await dialog.getByLabel('ชื่อโปรเจกต์').fill(name);
  if (template === 'blank') await dialog.getByRole('radio', { name: /หน้าว่าง/ }).check();
  else await dialog.getByRole('radio', { name: template }).check();
  await dialog.getByRole('button', { name: 'สร้างโปรเจกต์' }).click();
  await expect(page).toHaveURL(/\/app\/projects\/[0-9a-z]{26}$/);
  await expect(page.getByTestId('canvas-frame')).toBeVisible();
  return page.url().split('/').pop()!;
}

export async function waitSaved(page: Page) {
  await expect(page.getByTestId('save-state')).toHaveAttribute('data-state', 'saved', { timeout: 15_000 });
}

export function inspector(page: Page) {
  return page.getByRole('complementary', { name: 'แผงคุณสมบัติ' });
}
