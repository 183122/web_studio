import { expect, test } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { createProject, inspector, register, waitSaved } from './helpers.ts';

test('drag a component from the palette onto the canvas and reorder via keyboard', async ({ page }) => {
  await register(page);
  await createProject(page, 'ลากวาง', /Landing page/);
  const canvas = page.getByTestId('canvas-frame');
  const target = canvas.locator('h1').first();
  const before = await canvas.locator('[data-node-type="Button"]').count();

  const src = await page.getByTestId('palette-Button').boundingBox();
  const dst = await target.boundingBox();
  await page.mouse.move(src!.x + src!.width / 2, src!.y + src!.height / 2);
  await page.mouse.down();
  await page.mouse.move(src!.x + 30, src!.y + 30, { steps: 4 });
  await page.mouse.move(dst!.x + 30, dst!.y + dst!.height + 4, { steps: 12 });
  await expect(canvas.getByTestId('drop-indicator')).toBeVisible();
  await page.mouse.up();

  await expect(canvas.locator('[data-node-type="Button"]')).toHaveCount(before + 1);
  await expect(inspector(page).getByLabel('ข้อความบนปุ่ม')).toHaveValue('คลิกที่นี่');
  // Unsafe links are rejected in the inspector and never saved.
  await inspector(page).getByLabel('ลิงก์', { exact: true }).fill('javascript:alert(1)');
  await expect(inspector(page).getByRole('alert')).toContainText('ใช้ได้เฉพาะ');
  await inspector(page).getByLabel('ลิงก์', { exact: true }).fill('/contact');
  await waitSaved(page);

  // Keyboard alternative to drag: Alt+ArrowDown moves the selection.
  const id = await canvas.locator('[data-node-type="Button"]', { hasText: 'คลิกที่นี่' }).getAttribute('data-node-id');
  const parentChildren = async () => canvas.locator(`[data-node-id="${id}"]`).evaluate((el) => Array.from(el.parentElement!.children).indexOf(el));
  const i0 = await parentChildren();
  await canvas.locator(`[data-node-id="${id}"]`).click(); // moves focus off the inspector input
  await page.keyboard.press('Alt+ArrowDown');
  await expect.poll(parentChildren).toBe(i0 + 1);
  await page.keyboard.press('Delete');
  await expect(canvas.locator(`[data-node-id="${id}"]`)).toHaveCount(0);
  await waitSaved(page);
});

test('upload an image asset and use it in an Image component', async ({ page }) => {
  await register(page);
  await createProject(page, 'คลังภาพ', 'blank');
  await page.getByTestId('palette-Image').click();
  await page.getByRole('button', { name: 'คลังภาพ' }).click();
  const file = fileURLToPath(new URL('../fixtures/sample.png', import.meta.url));
  await page.getByTestId('asset-upload-input').setInputFiles(file);
  await expect(page.getByText('อัปโหลด sample.png แล้ว')).toBeVisible();
  await page.getByRole('button', { name: 'sample.png' }).first().click();
  const img = page.getByTestId('canvas-frame').locator('img');
  await expect(img).toHaveAttribute('src', /\/api\/v1\/assets\/.+\/content\?expires=/);
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
  await waitSaved(page);
  await page.reload();
  await expect.poll(() => page.getByTestId('canvas-frame').locator('img').evaluate((el: HTMLImageElement) => el.naturalWidth)).toBeGreaterThan(0);
});
