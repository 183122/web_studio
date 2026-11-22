import { expect, test } from '@playwright/test';
import { createProject, inspector, login, register, waitSaved } from './helpers.ts';

test('concurrent edits produce a conflict that can be merged without data loss', async ({ browser }) => {
  const a = await browser.newPage();
  const email = await register(a);
  const projectId = await createProject(a, 'แก้ไขพร้อมกัน', /Landing page/);

  const b = await browser.newPage();
  await login(b, email);
  await b.goto(`/app/projects/${projectId}`);
  await expect(b.getByTestId('canvas-frame')).toBeVisible();

  // A saves first.
  await a.getByTestId('canvas-frame').locator('h1').first().click();
  await inspector(a).getByLabel('ข้อความ', { exact: true }).fill('แก้โดย A');
  await waitSaved(a);

  // B edits a different component on a stale revision → conflict.
  await b.getByTestId('canvas-frame').locator('h2').first().click();
  await inspector(b).getByLabel('ข้อความ', { exact: true }).fill('แก้โดย B');
  await expect(b.getByRole('alert').filter({ hasText: 'มีผู้อื่นแก้ไข' })).toBeVisible({ timeout: 15_000 });
  await b.getByRole('button', { name: 'รวมการแก้ไขของฉัน' }).click();
  await waitSaved(b);
  await expect(b.getByTestId('canvas-frame').locator('h1').first()).toHaveText('แก้โดย A');
  await expect(b.getByTestId('canvas-frame').locator('h2').first()).toHaveText('แก้โดย B');

  await a.reload();
  await expect(a.getByTestId('canvas-frame').locator('h2').first()).toHaveText('แก้โดย B');
});

test('another tenant cannot open a project by URL (J8)', async ({ browser }) => {
  const owner = await browser.newPage();
  await register(owner, 'เจ้าของ');
  const projectId = await createProject(owner, 'ข้อมูลลับ', 'blank');

  const intruderCtx = await browser.newContext();
  const intruder = await intruderCtx.newPage();
  await register(intruder, 'ผู้บุกรุก');
  await intruder.goto(`/app/projects/${projectId}`);
  await expect(intruder.getByRole('alert')).toContainText('ไม่พบข้อมูลที่ต้องการ');
  // Same-origin SPA request (Referer marks it as a first-party, cookie-authenticated call).
  const res = await intruder.request.get(`/api/v1/projects/${projectId}/schema`, { headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:4173/app' } });
  expect(res.status()).toBe(404);
  await intruderCtx.close();
});
