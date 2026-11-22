import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { createProject, inspector, register, upgradeTo, waitSaved } from './helpers.ts';

// Chromium resolves every *.localhost name to loopback, so published sites are reachable on the API port.
const SITE_PORT = 8001;
const DNS_FILE = fileURLToPath(new URL('../../../apps/platform-api/database/e2e-dns.json', import.meta.url));
const uid = () => `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4)}`;

async function openPublish(page: Page) {
  await page.getByRole('button', { name: 'เผยแพร่', exact: true }).click();
  const dlg = page.getByRole('dialog', { name: 'เผยแพร่เว็บไซต์' });
  await expect(dlg).toBeVisible();
  return dlg;
}

/**
 * J1 (complete): template → edit → publish on a subdomain → live site renders the frozen release,
 * accepts form submissions into production data, offers end-user accounts → studio sees real data;
 * later edits go live only after re-publishing; rollback restores the previous release.
 */
test('J1: publish to a subdomain, live form writes production data, rollback', async ({ page, context }) => {
  await register(page);
  const projectId = await createProject(page, 'ศูนย์อบรมออนไลน์');
  await waitSaved(page);
  const slug = `e2e-${uid()}`;

  let dlg = await openPublish(page);
  await dlg.getByLabel('ชื่อโดเมนย่อย').fill(slug);
  await dlg.getByRole('button', { name: 'ใช้ชื่อนี้' }).click();
  await expect(dlg.getByRole('link', { name: /เปิดเว็บไซต์/ })).toHaveCount(0);
  await dlg.getByRole('button', { name: 'เผยแพร่', exact: true }).click();
  await expect(dlg.getByTestId('deploy-status')).toHaveText('สำเร็จ', { timeout: 30_000 });
  await expect(dlg.getByText('ตรวจสุขภาพหลังเผยแพร่')).toBeVisible();
  await expect(dlg.getByText(/ออนไลน์ · v1/)).toBeVisible();
  const siteUrl = `http://${slug}.sites.localhost:${SITE_PORT}`;
  await expect(dlg.getByRole('link', { name: /เปิดเว็บไซต์/ })).toHaveAttribute('href', `https://${slug}.sites.localhost`);
  await page.keyboard.press('Escape');

  // ---- the live site (separate origin, no studio session) ----
  const site = await context.newPage();
  const res = await site.goto(`${siteUrl}/register`);
  expect(res?.status()).toBe(200);
  expect(res?.headers()['content-security-policy']).toContain("script-src 'self'");
  await expect(site).toHaveTitle(/สมัคร|ลงทะเบียน/);
  await site.getByLabel('ชื่อ–นามสกุล').fill('ลูกค้า จริงจัง');
  await site.getByLabel('อีเมล').first().fill('real.customer@example.com');
  await site.getByLabel('หลักสูตร').selectOption('การวิเคราะห์ข้อมูลเบื้องต้น');
  await site.waitForTimeout(1600);
  await site.getByRole('button', { name: 'ส่งใบสมัคร' }).click();
  await expect(site.getByRole('status').filter({ hasText: 'ได้รับใบสมัครแล้ว' })).toBeVisible();

  // Registrant list is admin-only; a newly registered end user is a member.
  await site.goto(`${siteUrl}/registrants`);
  await expect(site.getByText('เข้าสู่ระบบด้วยบัญชีที่มีสิทธิ์เพื่อดูข้อมูลนี้')).toBeVisible();
  await site.getByRole('button', { name: 'สมัครสมาชิก' }).click();
  const acct = site.getByRole('dialog', { name: 'สมัครสมาชิก' });
  await acct.getByLabel('ชื่อ', { exact: true }).fill('สมาชิก ทดสอบ');
  await acct.getByLabel('อีเมล').fill('member@example.com');
  await acct.getByLabel('รหัสผ่าน', { exact: true }).fill('memberpass123');
  await acct.getByLabel('ยืนยันรหัสผ่าน').fill('memberpass123');
  await acct.getByRole('button', { name: 'สมัคร' }).click();
  await expect(site.getByText('สวัสดี สมาชิก ทดสอบ')).toBeVisible();
  const cookies = await context.cookies(siteUrl);
  const session = cookies.find((c) => c.name === '__site_session');
  expect(session?.httpOnly).toBe(true);
  expect(session?.domain).toBe(`${slug}.sites.localhost`); // host-only

  // Unknown pages return a real 404.
  expect((await site.goto(`${siteUrl}/does-not-exist`))?.status()).toBe(404);

  // ---- studio: production data, app users ----
  await page.goto(`/app/projects/${projectId}/data?env=production`);
  await expect(page.getByRole('table')).toContainText('real.customer@example.com');
  await page.getByRole('radio', { name: /ข้อมูลทดลอง/ }).click();
  await expect(page.getByRole('table')).not.toContainText('real.customer@example.com');
  await page.getByRole('tab', { name: 'ผู้ใช้แอป' }).click();
  await expect(page.getByRole('cell', { name: 'member@example.com' })).toBeVisible();
  await page.getByLabel('บทบาทของ member@example.com').selectOption('admin');
  await expect(page.getByText('บันทึกแล้ว')).toBeVisible();
  // Role change revoked the session; signing in again as admin shows the list.
  await site.goto(`${siteUrl}/registrants`);
  await site.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
  const login = site.getByRole('dialog', { name: 'เข้าสู่ระบบ' });
  await login.getByLabel('อีเมล').fill('member@example.com');
  await login.getByLabel('รหัสผ่าน').fill('memberpass123');
  await login.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
  await expect(site.locator('table')).toContainText('ลูกค้า จริงจัง');

  // ---- edits are not live until re-published; rollback ----
  await page.goto(`/app/projects/${projectId}`);
  await inspector(page).getByRole('tab', { name: 'หน้า & SEO' }).click();
  const siteName = inspector(page).getByLabel('ชื่อเว็บไซต์', { exact: true });
  await siteName.fill('ศูนย์อบรม เวอร์ชันสอง');
  await siteName.press('Tab');
  await waitSaved(page);
  await site.goto(siteUrl);
  await expect(site).not.toHaveTitle(/เวอร์ชันสอง/);
  dlg = await openPublish(page);
  await expect(dlg.getByText('มีการแก้ไขที่ยังไม่เผยแพร่')).toBeVisible();
  await dlg.getByRole('button', { name: 'เผยแพร่การเปลี่ยนแปลง' }).click();
  await expect(dlg.getByText(/ออนไลน์ · v2/)).toBeVisible({ timeout: 30_000 });
  await site.goto(siteUrl);
  await expect(site).toHaveTitle(/เวอร์ชันสอง/);
  await dlg.getByRole('listitem').filter({ hasText: 'v1' }).getByRole('button', { name: 'ใช้เวอร์ชันนี้' }).click();
  await expect(dlg.getByText(/ออนไลน์ · v1/)).toBeVisible();
  await site.goto(siteUrl);
  await expect(site).not.toHaveTitle(/เวอร์ชันสอง/);

  // Share link for reviewers (read-only draft, no login).
  await dlg.getByRole('button', { name: 'สร้างลิงก์' }).click();
  const shareUrl = await dlg.getByRole('textbox', { name: 'ลิงก์แชร์', exact: true }).inputValue();
  const reviewer = await context.browser()!.newContext();
  const rp = await reviewer.newPage();
  await rp.goto(shareUrl);
  await expect(rp.getByText(/ตัวอย่าง: ศูนย์อบรมออนไลน์/)).toBeVisible();
  await reviewer.close();
});

/** J6: custom domain → DNS instructions → TXT + CNAME (static resolver in E2E) → verify → served. */
test('J6: custom domain is served only after DNS verification', async ({ page, context }) => {
  writeFileSync(DNS_FILE, JSON.stringify({ txt: {}, cname: {} }));
  await register(page);
  await createProject(page, 'ร้านค้าโดเมนเอง', 'blank');
  await waitSaved(page);
  const editorUrl = page.url();
  await upgradeTo(page, 'Pro'); // custom domains are a paid feature
  await page.goto(editorUrl);
  const slug = `e2e-${uid()}`;
  const host = `www-${uid()}.localhost`;

  const dlg = await openPublish(page);
  await dlg.getByLabel('ชื่อโดเมนย่อย').fill(slug);
  await dlg.getByRole('button', { name: 'ใช้ชื่อนี้' }).click();
  await dlg.getByRole('button', { name: 'เผยแพร่', exact: true }).click();
  await expect(dlg.getByTestId('deploy-status')).toHaveText('สำเร็จ', { timeout: 30_000 });

  await dlg.getByRole('textbox', { name: 'โดเมน', exact: true }).fill(host);
  await dlg.getByRole('button', { name: 'เพิ่มโดเมน' }).click();
  await expect(dlg.getByTestId(`domain-status-${host}`)).toHaveText('รอยืนยัน DNS');
  const txtRow = dlg.getByRole('row').filter({ hasText: `_studio-verify.${host}` });
  const token = (await txtRow.getByRole('button').textContent())!.trim();
  expect(token).toMatch(/^studio-verify=/);
  await expect(dlg.getByRole('row').filter({ hasText: 'CNAME' })).toContainText('edge.sites.localhost');

  // Not served before verification.
  const site = await context.newPage();
  expect((await site.goto(`http://${host}:${SITE_PORT}/`))?.status()).toBe(404);

  writeFileSync(DNS_FILE, JSON.stringify({ txt: { [`_studio-verify.${host}`]: [token] }, cname: { [host]: ['edge.sites.localhost'] } }));
  await dlg.getByRole('button', { name: 'ตรวจสอบอีกครั้ง' }).click();
  await expect(dlg.getByTestId(`domain-status-${host}`)).toHaveText('ใช้งานได้');
  expect((await site.goto(`http://${host}:${SITE_PORT}/`))?.status()).toBe(200);
  await expect(site.locator('script#__SITE__')).toHaveCount(1); // the blank template renders an empty page
  await expect(site).toHaveTitle(/ร้านค้าโดเมนเอง|หน้าแรก/);
});
