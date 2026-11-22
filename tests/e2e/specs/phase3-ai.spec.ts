import { expect, test } from '@playwright/test';
import { register, waitSaved } from './helpers.ts';

/**
 * J2 (complete): Thai prompt → structured plan → approval → generated pages + data + form + workflow
 * → review/apply → public form submission → record in admin.
 * The e2e environment has no AI key, so the offline planner (safe fallback) is exercised; the
 * Claude request path is covered by PHPUnit with a fake transport.
 */
test('J2: describe an app in Thai, approve the plan and the change set, then use the generated form', async ({ page }) => {
  await register(page);
  const dialog = page.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' });
  await dialog.getByLabel('ชื่อโปรเจกต์').fill('อบรมครู AI');
  await dialog.getByRole('radio', { name: /อธิบายด้วยคำพูด/ }).check();
  await dialog.getByLabel('อธิบายแอปที่ต้องการ').fill('ระบบลงทะเบียนอบรมครู ชื่อ "อบรมครูยุคใหม่" ให้ครูกรอกชื่อ อีเมล และส่งอีเมลยืนยัน');
  await dialog.getByRole('button', { name: 'สร้างโปรเจกต์' }).click();
  await expect(page).toHaveURL(/\/app\/projects\/[0-9a-z]{26}$/);
  const projectId = page.url().split('/').pop()!;

  // AI panel opens prefilled; offline mode is disclosed.
  await expect(page.getByTestId('ai-provider')).toContainText('โหมดออฟไลน์');
  await expect(page.getByLabel('อธิบายแอปที่ต้องการ')).toHaveValue(/อบรมครูยุคใหม่/);
  await page.getByRole('button', { name: 'วางแผนแอป' }).click();

  const plan = page.getByTestId('ai-plan');
  await expect(plan).toContainText('แผน: อบรมครูยุคใหม่');
  await expect(plan).toContainText('ผู้ลงทะเบียน (registrations)');
  await expect(plan).toContainText('ข้อมูลที่ยังขาด');
  await expect(page.getByTestId('canvas-frame').locator('h1')).toHaveCount(0); // nothing applied yet

  await plan.getByRole('button', { name: /สร้างตามแผนนี้/ }).click();
  const proposal = page.getByTestId('ai-proposal');
  await expect(proposal).toContainText('ตารางข้อมูลใหม่');
  await expect(proposal).toContainText('ผลต่อสิทธิ์การเข้าถึง');
  await proposal.getByRole('button', { name: 'นำไปใช้' }).click();
  await expect(page.getByText('นำการเปลี่ยนแปลงจาก AI ไปใช้แล้ว')).toBeVisible();
  await expect(page.getByTestId('canvas-frame').locator('h1').first()).toHaveText('อบรมครูยุคใหม่');
  await waitSaved(page);

  // The generated public form saves real records; the workflow sets the status.
  await page.goto(`/app/projects/${projectId}/preview?page=/register&role=public`);
  await page.locator('main').getByLabel(/ชื่อ–นามสกุล/).fill('ครูสมศรี มีสุข');
  await page.locator('main').getByLabel(/อีเมล/).fill('somsri@example.com');
  await page.waitForTimeout(1600);
  await page.locator('main').getByRole('button', { name: 'ส่งใบสมัคร' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'ส่งข้อมูลเรียบร้อย' })).toBeVisible();

  await page.goto(`/app/projects/${projectId}/data`);
  await expect(page.getByRole('table')).toContainText('ครูสมศรี มีสุข');
  await expect(page.getByRole('table')).toContainText('รอตรวจสอบ');
});

test('AI edit: scoped change with before/after review, and an unsupported request fails safely', async ({ page }) => {
  await register(page);
  const dialog = page.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' });
  await dialog.getByLabel('ชื่อโปรเจกต์').fill('แก้ไขด้วย AI');
  await dialog.getByRole('radio', { name: /Landing page/ }).check();
  await dialog.getByRole('button', { name: 'สร้างโปรเจกต์' }).click();
  await expect(page.getByTestId('canvas-frame')).toBeVisible();

  await page.getByTestId('canvas-frame').locator('h1').first().click();
  await page.getByRole('button', { name: 'AI', exact: true }).click();
  await page.getByRole('tab', { name: 'แก้ไขด้วย AI' }).click();
  await expect(page.getByText('ขอบเขต: คอมโพเนนต์ที่เลือก')).toBeVisible();
  await page.getByLabel('ต้องการให้แก้อะไร').fill('เปลี่ยนข้อความเป็น "บริการครบวงจรสำหรับ SME" และเปลี่ยนสีหลักเป็น #2563eb');
  await page.getByRole('button', { name: 'เสนอการแก้ไข' }).click();
  const proposal = page.getByTestId('ai-proposal');
  await expect(proposal).toContainText('"ยกระดับธุรกิจของคุณด้วยบริการที่ใช่" → "บริการครบวงจรสำหรับ SME"');
  await expect(proposal).toContainText('สีธีม primary');
  await proposal.getByRole('button', { name: 'นำไปใช้' }).click();
  await expect(page.getByTestId('canvas-frame').locator('h1').first()).toHaveText('บริการครบวงจรสำหรับ SME');

  await page.getByLabel('ต้องการให้แก้อะไร').fill('ทำให้ดูทันสมัยขึ้น');
  await page.getByRole('button', { name: 'เสนอการแก้ไข' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'AI ไม่พบการเปลี่ยนแปลงที่ทำได้' })).toBeVisible();
  await expect(page.getByTestId('canvas-frame').locator('h1').first()).toHaveText('บริการครบวงจรสำหรับ SME');
});
