import { expect, test } from '@playwright/test';
import { createProject, inspector, register, waitSaved } from './helpers.ts';

/**
 * J1 (Phase 1 scope): register → workspace → project from template → edit → save → reload → preview.
 * Publishing to a live subdomain is Phase 4.
 */
test('register, create from template, edit visually, persist and preview', async ({ page }) => {
  await register(page);
  await expect(page.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' })).toBeVisible();
  const projectId = await createProject(page, 'ศูนย์อบรม E2E');

  const canvas = page.getByTestId('canvas-frame');
  await expect(canvas.locator('h1')).toContainText('พัฒนาทักษะใหม่');

  // Select the hero heading on the canvas and edit it in the inspector.
  await canvas.locator('h1').first().click();
  const text = inspector(page).getByLabel('ข้อความ', { exact: true });
  await text.fill('อบรมฟรี ปี 2569');
  await expect(canvas.locator('h1').first()).toHaveText('อบรมฟรี ปี 2569');

  // Keyboard-accessible insert: click a palette item (inserts after the selection).
  await page.getByTestId('palette-Text').click();
  await expect(inspector(page).getByLabel('ข้อความ', { exact: true })).toHaveValue('พิมพ์ข้อความของคุณที่นี่');
  await inspector(page).getByLabel('ข้อความ', { exact: true }).fill('ข้อความใหม่จากการทดสอบ');
  await waitSaved(page);

  // Undo / redo
  await page.locator('body').press('Escape');
  await page.getByRole('button', { name: /เลิกทำ/ }).click();
  await page.getByRole('button', { name: /เลิกทำ/ }).click();
  await expect(canvas.getByText('ข้อความใหม่จากการทดสอบ')).toHaveCount(0);
  await page.getByRole('button', { name: /ทำซ้ำ/ }).click();
  await page.getByRole('button', { name: /ทำซ้ำ/ }).click();
  await expect(canvas.getByText('ข้อความใหม่จากการทดสอบ')).toBeVisible();
  await waitSaved(page);

  // Persisted on the server: reload shows the edits.
  await page.reload();
  await expect(page.getByTestId('canvas-frame').locator('h1').first()).toHaveText('อบรมฟรี ปี 2569');
  await expect(page.getByTestId('canvas-frame').getByText('ข้อความใหม่จากการทดสอบ')).toBeVisible();

  // Responsive edit: font size only on mobile.
  await page.getByRole('radio', { name: 'มือถือ' }).click();
  await page.getByTestId('canvas-frame').locator('h1').first().click();
  await inspector(page).getByRole('textbox', { name: 'ขนาดตัวอักษร', exact: true }).fill('22px');
  await waitSaved(page);

  // Private preview with page navigation.
  await page.goto(`/app/projects/${projectId}/preview`);
  await expect(page.locator('main h1').first()).toHaveText('อบรมฟรี ปี 2569');
  await page.getByRole('radio', { name: 'mobile' }).click();
  await expect(page.locator('main h1').first()).toHaveCSS('font-size', '22px');
  await page.getByRole('radio', { name: 'base' }).click();
  await expect(page.locator('main h1').first()).toHaveCSS('font-size', '44px');
  await page.locator('main').getByRole('link', { name: 'หลักสูตร' }).first().click();
  await expect(page.locator('main h1').first()).toHaveText('หลักสูตรที่เปิดรับสมัคร');
});

test('dashboard: search, favorite, archive and trash with restore', async ({ page }) => {
  await register(page);
  await createProject(page, 'โปรเจกต์ค้นหา', 'blank');
  await page.goto('/app');
  await page.getByRole('button', { name: 'สร้างโปรเจกต์', exact: true }).click();
  await createProject(page, 'เว็บโรงเรียน', /เว็บไซต์โรงเรียน/);
  await page.goto('/app');

  await page.getByLabel('ค้นหาโปรเจกต์').fill('โรงเรียน');
  await expect(page.getByRole('listitem')).toHaveCount(1);
  await page.getByLabel('ค้นหาโปรเจกต์').fill('');
  await expect(page.getByRole('listitem')).toHaveCount(2);

  await page.getByRole('button', { name: 'เพิ่มในรายการโปรด' }).first().click();
  await page.getByRole('button', { name: 'รายการโปรด', exact: true }).click();
  await expect(page.getByRole('listitem')).toHaveCount(1);
  await page.getByRole('button', { name: 'รายการโปรด', exact: true }).click();

  await page.getByRole('button', { name: 'ตัวเลือกของ โปรเจกต์ค้นหา' }).click();
  await page.getByRole('menuitem', { name: 'เก็บถาวร' }).click();
  await expect(page.getByRole('listitem')).toHaveCount(1);
  await page.getByRole('tab', { name: 'เก็บถาวร' }).click();
  await expect(page.getByRole('listitem')).toHaveCount(1);

  await page.getByRole('tab', { name: 'ใช้งานอยู่' }).click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'ตัวเลือกของ เว็บโรงเรียน' }).click();
  await page.getByRole('menuitem', { name: 'ย้ายไปถังขยะ' }).click();
  await expect(page.getByText('ยังไม่มีโปรเจกต์')).toBeVisible();
  await page.getByRole('tab', { name: 'ถังขยะ' }).click();
  await page.getByRole('button', { name: 'ตัวเลือกของ เว็บโรงเรียน' }).click();
  await page.getByRole('menuitem', { name: 'กู้คืน' }).click();
  await page.getByRole('tab', { name: 'ใช้งานอยู่' }).click();
  await expect(page.getByRole('listitem')).toHaveCount(1);
});
