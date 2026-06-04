/**
 * Platform Settings tab (TODO #10, Task 5).
 *
 * Post-install editor for the same `data/config.json` surface the install
 * wizard writes. Runs WITHOUT the install gate marker file (Platform Settings
 * is for already-installed boxes). Registers an admin via the existing
 * /register flow in beforeAll — the first registered user is auto-approved
 * (AuthController), giving us the admin we need to reach /admin/compass/*.
 *
 * We share a single browser context across the four tests so the admin
 * session cookie survives between them. Each test would otherwise need to
 * re-login, which is brittle once one of the tests turns on LOGIN_HASH —
 * `/login` then requires `?hash=…` and a fresh page can't read the secret.
 */
import { test, expect, Page, BrowserContext } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const CONFIG_PATH = path.join(ROOT, 'data/config.test.json');

test.describe.configure({ mode: 'serial' });

const EMAIL = 'platform-admin@example.com';
const PASSWORD = 'password123';

let ctx: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }) => {
  // Fresh slate: drop the test DB + ConfigStore so the first registered
  // user becomes the auto-approved admin and so prior config writes don't
  // bleed into these assertions.
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite-wal'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite-shm'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
  fs.rmSync(CONFIG_PATH, { force: true });

  ctx = await browser.newContext();
  page = await ctx.newPage();
  // Bootstrap the schema by hitting /, then register the first admin (auto-approved).
  await page.goto('/');
  await page.goto('/register');
  await page.fill('input[name=name]', 'Platform Admin');
  await page.fill('input[name=email]', EMAIL);
  await page.fill('input[name=password]', PASSWORD);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/');
});

test.afterAll(async () => {
  await ctx?.close();
  fs.rmSync(CONFIG_PATH, { force: true });
});

test('admin sees Platform tab in Compass', async () => {
  await page.goto('/admin/compass');
  await expect(page.locator('a[href="/admin/compass/platform"]')).toBeVisible();
});

test('Platform tab toggles LOGIN_HASH on and surfaces a value', async () => {
  await page.goto('/admin/compass/platform');
  await page.check('input[name=enable_login_hash]');
  await page.click('button[data-action=save-auth]');
  await expect(page).toHaveURL(/saved=auth/);
  // After redirect, the checkbox stays checked (LOGIN_HASH persisted) and
  // the masked hash indicator becomes visible.
  await expect(page.locator('input[name=enable_login_hash]')).toBeChecked();
  await expect(page.locator('[data-login-hash-value]')).toBeVisible();
});

test('Platform tab rotates APP_SECRET', async () => {
  // The button posts a section=rotate-app-secret form — confirm dialog is
  // shown via onclick="return confirm(...)" so we auto-accept it.
  page.once('dialog', d => d.accept());
  await page.goto('/admin/compass/platform');
  await page.click('button[data-action=rotate-app-secret]');
  await expect(page).toHaveURL(/saved=rotate-app-secret/);
  await expect(page.locator('.alert--success')).toBeVisible();
});

test('Platform tab updates Telegram credentials and re-masks token', async () => {
  await page.goto('/admin/compass/platform');
  await page.fill('input[name=tg_bot_token]', '123456:abcdef-real-token');
  await page.fill('input[name=tg_chat_id]', '-100123');
  await page.click('button[data-action=save-telegram]');
  await expect(page).toHaveURL(/saved=telegram/);
  // The reloaded form shows a masked placeholder (bot****XXXX), never the
  // raw secret.
  const tokenValue = await page.locator('input[name=tg_bot_token]').inputValue();
  expect(tokenValue).toMatch(/^bot\*+/);
  await expect(page.locator('input[name=tg_chat_id]')).toHaveValue('-100123');
});
