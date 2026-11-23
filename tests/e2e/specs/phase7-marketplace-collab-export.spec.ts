import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { createProject, inspector, login, register, waitSaved } from './helpers.ts';

const api = fileURLToPath(new URL('../../../apps/platform-api', import.meta.url));
const asAdmin = (email: string) => execFileSync('php', ['artisan', 'studio:platform-admin', email], { cwd: api, env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${api}/database/e2e.sqlite` } });

test('collaboration: presence avatars and remote selection appear live', async ({ browser }) => {
  const a = await browser.newPage();
  const email = await register(a, 'อลิส');
  const projectId = await createProject(a, 'ทำงานร่วมกัน', /Landing page/);
  const b = await browser.newPage();
  await login(b, email);
  await b.goto(`/app/projects/${projectId}`);
  // Same account in two tabs counts as one participant; a teammate is needed for avatars.
  await expect(a.getByTestId('collaborators')).toHaveCount(0);

  const c = await browser.newPage();
  const teammate = await register(c, 'บ๊อบ');
  // Owner invites the teammate (token comes from the DB because e-mail goes to the log mailer).
  await a.goto('/app/team');
  await a.getByRole('textbox', { name: 'อีเมล' }).fill(teammate);
  await a.getByRole('button', { name: 'ส่งคำเชิญ' }).click();
  await expect(a.getByText(`ส่งคำเชิญถึง ${teammate} แล้ว`)).toBeVisible();
  const token = 'e2etoken'.padEnd(48, 'x');
  execFileSync('php', ['-r', `$p=new PDO('sqlite:${api}/database/e2e.sqlite');$p->exec("UPDATE invitations SET token_hash='".hash('sha256','${token}')."' WHERE email='${teammate}'");`]);
  await c.goto(`/invite/${token}`);
  await c.getByRole('button', { name: 'ตอบรับคำเชิญ' }).click();
  await expect(c).toHaveURL(/\/app$/);
  await c.goto(`/app/projects/${projectId}`);
  await c.getByTestId('canvas-frame').locator('h1').first().click();

  await a.goto(`/app/projects/${projectId}`);
  await expect(a.getByTestId('collaborators')).toContainText('บ๊อบ', { timeout: 10_000 });
  await expect(a.getByTestId('remote-selection')).toContainText('บ๊อบ', { timeout: 10_000 });

  // The teammate's edit appears on A's canvas without reloading.
  await inspector(c).getByLabel('ข้อความ', { exact: true }).fill('สวัสดีจากบ๊อบ');
  await waitSaved(c);
  await expect(a.getByTestId('canvas-frame').locator('h1').first()).toHaveText('สวัสดีจากบ๊อบ', { timeout: 15_000 });
});

test('marketplace: submit a template, moderate it, another user installs it', async ({ browser }) => {
  const author = await browser.newPage();
  const authorEmail = await register(author, 'ผู้สร้างเทมเพลต');
  await createProject(author, 'ชุดลงทะเบียน', /ระบบลงทะเบียนอบรม/);
  await waitSaved(author);
  await author.getByRole('button', { name: 'เผยแพร่', exact: true }).click();
  const dlg = author.getByRole('dialog', { name: 'เผยแพร่เว็บไซต์' });
  await dlg.getByRole('button', { name: 'ส่งโปรเจกต์นี้เป็นเทมเพลตชุมชน' }).click();
  await dlg.getByLabel('ชื่อเทมเพลต').fill('ชุดลงทะเบียนอบรมพร้อมใช้');
  await dlg.getByLabel('คำอธิบาย').fill('หน้าเว็บ ฟอร์มลงทะเบียน และหน้ารายชื่อผู้สมัคร');
  await dlg.getByLabel('หมวดหมู่').selectOption('event');
  await dlg.getByRole('checkbox', { name: /ฉันมีสิทธิ์ในเนื้อหา/ }).check();
  await dlg.getByRole('button', { name: 'ส่งให้ตรวจ' }).click();
  await expect(dlg.getByText(/ส่งให้ทีมงานตรวจแล้ว/)).toBeVisible();
  await expect(dlg.getByText(/นำการส่งอีเมลไปยังที่อยู่คงที่ออก/)).toBeVisible(); // the template e-mails staff at a fixed address

  asAdmin(authorEmail); // the author doubles as moderator in this test
  await author.goto('/app/admin');
  await author.getByRole('button', { name: 'เทมเพลตรอตรวจ' }).click();
  await author.getByRole('button', { name: /ชุดลงทะเบียนอบรมพร้อมใช้/ }).click();
  await expect(author.getByLabel('ตัวอย่างเทมเพลต')).toBeVisible();
  author.once('dialog', (d) => d.accept('เนื้อหาเหมาะสม'));
  await author.getByRole('button', { name: 'อนุมัติและเผยแพร่' }).click();
  await expect(author.getByText('ไม่มีเทมเพลตรอตรวจ')).toBeVisible();

  const user = await browser.newPage();
  await register(user, 'ผู้ใช้เทมเพลต');
  const create = user.getByRole('dialog', { name: 'สร้างโปรเจกต์ใหม่' });
  await expect(create.getByText('จากชุมชน (ผ่านการตรวจโดยทีมงาน)')).toBeVisible();
  await create.getByLabel('ชื่อโปรเจกต์').fill('งานอบรมของฉัน');
  await create.getByRole('radio', { name: /ชุดลงทะเบียนอบรมพร้อมใช้/ }).check();
  await expect(create.getByText(/โดย .* · ใช้ได้ทุกวัตถุประสงค์ · v1/)).toBeVisible();
  await create.getByRole('button', { name: 'สร้างโปรเจกต์' }).click();
  await expect(user).toHaveURL(/\/app\/projects\/[0-9a-z]{26}$/);
  await expect(user.getByTestId('canvas-frame')).toContainText('ลงทะเบียน');
});

test('export: ZIP with static site, data, schema and README', async ({ page }) => {
  await register(page);
  const projectId = await createProject(page, 'ส่งออกทดสอบ', /ระบบลงทะเบียนอบรม/);
  await waitSaved(page);
  await page.getByRole('button', { name: 'เผยแพร่', exact: true }).click();
  const dlg = page.getByRole('dialog', { name: 'เผยแพร่เว็บไซต์' });
  await dlg.getByLabel('ข้อมูลในตาราง').selectOption('preview');
  const dl = page.waitForEvent('download');
  await dlg.getByRole('link', { name: 'ดาวน์โหลด ZIP' }).click();
  const file = await (await dl).path();
  const list = execFileSync('unzip', ['-l', file!]).toString();
  for (const part of ['site/index.html', 'site/register/index.html', 'site/runtime/', 'data/registrations.csv', 'schema.sql', 'app.json', 'openapi.json', 'README.md']) {
    expect(list).toContain(part);
  }
  const readme = execFileSync('unzip', ['-p', file!, '*/README.md']).toString();
  expect(readme).toContain('Works without the platform?');
  expect(projectId).toMatch(/^[0-9a-z]{26}$/);
});
