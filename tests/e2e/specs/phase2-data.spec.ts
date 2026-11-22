import { expect, test, type Page } from '@playwright/test';
import { createProject, inspector, register, waitSaved } from './helpers.ts';

async function fillAndSubmit(page: Page, values: Record<string, string>, submit: string) {
  for (const [label, value] of Object.entries(values)) {
    const el = page.locator('main').getByLabel(label, { exact: false }).first();
    if ((await el.evaluate((n) => n.tagName)) === 'SELECT') await el.selectOption(value);
    else await el.fill(value);
  }
  await page.waitForTimeout(1600); // spam guard: minimum fill time
  await page.locator('main').getByRole('button', { name: submit }).click();
}

/**
 * J2 (without the AI step, which is Phase 3): template with data model → public form in preview →
 * record saved → workflow ran → record visible in the generated admin.
 */
test('J2: registration form saves a record, runs the workflow and appears in admin', async ({ page, context }) => {
  await register(page);
  const projectId = await createProject(page, 'ลงทะเบียน E2E');

  await page.goto(`/app/projects/${projectId}/preview?page=/register&role=public`);
  await fillAndSubmit(page, { 'ชื่อ–นามสกุล': 'สมชาย ทดสอบ', 'อีเมล': 'Somchai.E2E@example.com', 'หลักสูตร': 'การวิเคราะห์ข้อมูลเบื้องต้น' }, 'ส่งใบสมัคร');
  await expect(page.getByRole('status').filter({ hasText: 'ได้รับใบสมัครแล้ว' })).toBeVisible();

  // Public role cannot read the registrant list; admin can.
  await page.goto(`/app/projects/${projectId}/preview?page=/registrants&role=public`);
  await expect(page.locator('main').getByText('บทบาทนี้ไม่มีสิทธิ์ดูข้อมูลในตารางนี้')).toBeVisible();
  await page.goto(`/app/projects/${projectId}/preview?page=/registrants&role=admin`);
  await expect(page.locator('main table')).toContainText('สมชาย ทดสอบ');
  await expect(page.locator('main table')).toContainText('รอตรวจสอบ');

  // Generated admin
  await page.goto(`/app/projects/${projectId}/data`);
  await expect(page.getByRole('table')).toContainText('somchai.e2e@example.com');
  await page.getByRole('tab', { name: 'Workflow runs' }).click();
  await expect(page.getByText('succeeded')).toBeVisible();
  void context;
});

/** J3: data model → form binding → permission checks → CRUD → pagination/filter → audit. */
test('J3: design a model, bind a form, enforce permissions and manage records', async ({ page }) => {
  await register(page);
  const projectId = await createProject(page, 'ข้อมูลลูกค้า', 'blank');

  // 1) Data model with fields and permissions
  await page.getByRole('button', { name: 'ฐานข้อมูล' }).click();
  await page.getByRole('button', { name: 'เพิ่มตารางข้อมูล' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('ชื่อตาราง (แสดงผล)').fill('ลูกค้า');
  await dlg.getByLabel('ชื่อสำหรับ API (a-z, 0-9, _)').fill('customers');
  await dlg.getByLabel('ป้ายกำกับฟิลด์ 1').fill('ชื่อลูกค้า');
  await dlg.getByRole('button', { name: 'เพิ่มฟิลด์' }).click();
  await dlg.getByLabel('ป้ายกำกับฟิลด์ 2').fill('อีเมล');
  await dlg.getByLabel('ชื่อ API ฟิลด์ 2').fill('email');
  await dlg.getByLabel('ชนิดฟิลด์ 2').selectOption('email');
  await dlg.getByLabel('ฟิลด์ 2 จำเป็น').check();
  await dlg.getByLabel('ฟิลด์ 2 ห้ามซ้ำ').check();
  await dlg.getByRole('button', { name: 'เพิ่มฟิลด์' }).click();
  await dlg.getByLabel('ป้ายกำกับฟิลด์ 3').fill('ระดับ');
  await dlg.getByLabel('ชื่อ API ฟิลด์ 3').fill('tier');
  await dlg.getByLabel('ชนิดฟิลด์ 3').selectOption('select');
  await dlg.getByLabel('ตัวเลือกของฟิลด์ 3 (คั่นด้วย ,)').fill('silver, gold');
  await dlg.getByLabel('ผู้เยี่ยมชม (public) สร้าง').check();
  await dlg.getByRole('button', { name: 'บันทึก' }).click();
  await expect(page.getByText('customers · 3 ฟิลด์')).toBeVisible();

  // 2) Form bound to the model
  await page.getByRole('button', { name: 'คอมโพเนนต์' }).click();
  await page.getByTestId('palette-Form').click();
  await inspector(page).getByLabel('บันทึกลงตารางข้อมูล').selectOption({ label: 'ลูกค้า (customers)' });
  await inspector(page).getByRole('button', { name: /เพิ่มช่องกรอกที่ยังไม่มี/ }).click();
  await expect(page.getByTestId('canvas-frame').locator('[data-node-type="FormField"]')).toHaveCount(3);
  await waitSaved(page);

  // 3) Public submission in preview; server-side validation and uniqueness
  await page.goto(`/app/projects/${projectId}/preview?role=public`);
  await fillAndSubmit(page, { 'ชื่อลูกค้า': 'บริษัท ก', 'อีเมล': 'a@example.com', 'ระดับ': 'gold' }, 'ส่งข้อมูล');
  await expect(page.getByRole('status').filter({ hasText: 'ส่งข้อมูลเรียบร้อย' })).toBeVisible();
  await page.getByRole('button', { name: 'ส่งอีกครั้ง' }).click();
  await fillAndSubmit(page, { 'ชื่อลูกค้า': 'บริษัท ข', 'อีเมล': 'A@example.com' }, 'ส่งข้อมูล');
  await expect(page.locator('main').getByText('ข้อมูลนี้มีอยู่แล้วในระบบ')).toBeVisible();

  // 4) Admin CRUD, filter, pagination, audit
  await page.goto(`/app/projects/${projectId}/data`);
  for (let i = 1; i <= 21; i++) {
    const res = await page.request.post(`/api/v1/projects/${projectId}/data/${await modelId(page, projectId)}/records`, {
      headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:4173/app', 'X-XSRF-TOKEN': await xsrf(page) },
      data: { data: { [await fieldId(page, projectId, 'name')]: `ลูกค้า ${i}`, [await fieldId(page, projectId, 'email')]: `c${i}@example.com`, [await fieldId(page, projectId, 'tier')]: i % 2 ? 'silver' : 'gold' } },
    });
    expect(res.status()).toBe(201);
  }
  await page.reload();
  await expect(page.getByText('ทั้งหมด 22 รายการ')).toBeVisible();
  await expect(page.getByText('หน้า 1 / 2')).toBeVisible();
  await page.getByRole('button', { name: 'ถัดไป' }).click();
  await expect(page.getByText('หน้า 2 / 2')).toBeVisible();
  await page.getByLabel('ระดับ').selectOption('silver');
  await expect(page.getByText('ทั้งหมด 11 รายการ')).toBeVisible();
  await page.getByLabel('ค้นหาข้อมูล').fill('บริษัท');
  await expect(page.getByText('ทั้งหมด 0 รายการ')).toBeVisible();
  await page.getByLabel('ระดับ').selectOption('');
  await expect(page.getByText('ทั้งหมด 1 รายการ')).toBeVisible();

  await page.getByRole('button', { name: 'บริษัท ก' }).click();
  await page.getByRole('dialog').getByLabel(/ระดับ/).selectOption('silver');
  await page.getByRole('dialog').getByRole('button', { name: 'บันทึก' }).click();
  await expect(page.getByText('บันทึกแล้ว')).toBeVisible();
  await page.getByRole('tab', { name: 'ประวัติการแก้ไข' }).click();
  await expect(page.getByText('record.updated').first()).toBeVisible();
});

async function schemaDoc(page: Page, projectId: string) {
  const res = await page.request.get(`/api/v1/projects/${projectId}/schema`, { headers: { Accept: 'application/json', Referer: 'http://127.0.0.1:4173/app' } });
  return (await res.json()).data.document;
}
async function modelId(page: Page, projectId: string): Promise<string> {
  return (await schemaDoc(page, projectId)).dataModels[0].id;
}
async function fieldId(page: Page, projectId: string, name: 'name' | 'email' | 'tier'): Promise<string> {
  const fields = (await schemaDoc(page, projectId)).dataModels[0].fields;
  return name === 'name' ? fields[0].id : fields.find((f: { name: string }) => f.name === name).id;
}
async function xsrf(page: Page): Promise<string> {
  const cookie = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
  return decodeURIComponent(cookie?.value ?? '');
}
