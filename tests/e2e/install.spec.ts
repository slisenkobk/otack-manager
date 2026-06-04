/**
 * Install wizard end-to-end (TODO #10, Task 4).
 *
 * Serial: each test mutates global state (DB, config.json). beforeAll wipes
 * the test SQLite and the test ConfigStore overlay so the gate fires on the
 * first hit; the walk-through then populates both, and the post-done test
 * asserts /install/* 404s.
 */
import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const CONFIG_PATH = path.join(ROOT, 'data/config.test.json');
const FLAG_FILE   = path.join(ROOT, 'data/install-gate-on.test');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite-wal'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite-shm'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
  fs.rmSync(CONFIG_PATH, { force: true });
  // Opt into the install gate for this spec only. Other specs don't create
  // the marker, so they get the normal (gate-off) behaviour.
  fs.writeFileSync(FLAG_FILE, '1');
});

test.afterAll(() => {
  fs.rmSync(CONFIG_PATH, { force: true });
  fs.rmSync(FLAG_FILE, { force: true });
});

test('redirects to /install on first hit when no admin exists', async ({ page }) => {
  const r = await page.goto('/');
  expect(r?.url()).toMatch(/\/install$/);
});

test('walks all 6 steps with SQLite default', async ({ page }) => {
  await page.goto('/install');
  await page.click('[data-action="install-start"]');
  await expect(page).toHaveURL('/install/db');

  // SQLite is the default; submit without choosing MySQL.
  await page.click('button[type=submit][data-driver=sqlite]');
  await expect(page).toHaveURL('/install/admin');

  await page.fill('input[name=name]',     'Alice Admin');
  await page.fill('input[name=email]',    'alice@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/install/security');

  await page.check('input[name=enable_login_hash]');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/install/integrations');

  await page.click('button[data-action="skip-integrations"]');
  await expect(page).toHaveURL('/install/done');

  // After done, /install/* (other than the done page itself, which is now
  // also gated off because installed_at is set) is gone.
  const r = await page.goto('/install/db');
  expect(r?.status()).toBe(404);
});

test('install routes 404 after installed_at is set', async ({ page }) => {
  // Subsequent runs in the same suite: already installed.
  const r = await page.goto('/install');
  expect(r?.status()).toBe(404);
});

test('normal app reachable after install', async ({ page }) => {
  // Anonymous "/" now serves the landing page (not /login). Asserting status
  // 200 is enough — the prior gate test proved /install is gone.
  const r = await page.goto('/');
  expect(r?.status()).toBe(200);
});
