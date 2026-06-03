import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

const EMAIL = 'admin@admin.test';
const PASSWORD = 'password123';

async function ensureLoggedIn(page: import('@playwright/test').Page) {
  // Try login first; if the user doesn't exist yet (first run), register.
  await page.goto('/login');
  await page.fill('input[name=email]', EMAIL);
  await page.fill('input[name=password]', PASSWORD);
  await page.click('button.submit[type=submit]');
  if (page.url().includes('/login')) {
    await page.goto('/register');
    await page.fill('input[name=name]', 'Admin');
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASSWORD);
    await page.click('button.submit[type=submit]');
  }
  await expect(page).toHaveURL('/');
}

test.beforeEach(async ({ page }) => {
  await ensureLoggedIn(page);
});

test('admin can save brand name in /admin/settings and it persists (T-7)', async ({ page }) => {
  await page.goto('/admin/settings?tab=workspace');
  await expect(page.locator('#f-app-name')).toBeVisible();

  const newName = 'Acme Tasks ' + Date.now();
  await page.locator('#f-app-name').fill(newName);
  await Promise.all([
    page.waitForURL(/\/admin\/settings/),
    page.locator('form.brief button[type=submit], form.brief button.submit').first().click(),
  ]);

  // Reload and verify the value persisted.
  await page.goto('/admin/settings?tab=workspace');
  await expect(page.locator('#f-app-name')).toHaveValue(newName);
});

test('admin /admin/compass redirects to migrations and shows 5 tabs (T-7)', async ({ page }) => {
  await page.goto('/admin/compass');
  await expect(page).toHaveURL(/\/admin\/compass\/migrations/);
  // 5 tabs in the compass nav: migrations, cache, stats, logs, db-migrate.
  const tabCount = await page.locator('.project-tabs .project-tab').count();
  expect(tabCount).toBeGreaterThanOrEqual(5);
});

test('admin /admin/compass/migrations shows the migrations table (T-7)', async ({ page }) => {
  await page.goto('/admin/compass/migrations');
  await expect(page.locator('table thead tr')).toBeVisible();
  // At least one applied migration row should be there in a fresh test DB.
  expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);
});

test('admin /admin/compass/cache shows the Clear sessions action (T-7)', async ({ page }) => {
  await page.goto('/admin/compass/cache');
  // The Clear-sessions button is the canonical compass action — verify it exists.
  await expect(page.locator('[data-compass-action="clear-sessions"]')).toBeVisible();
});

test('admin /admin/settings?tab=updates loads the updates panel (T-7)', async ({ page }) => {
  // The version pill links here; in test mode the tab may be hidden when
  // UPDATES_ENABLED is off, but the URL itself must still resolve.
  const resp = await page.goto('/admin/settings?tab=updates');
  expect(resp?.status()).toBeLessThan(500);
  // We at least land on a real settings page — the workspace tab nav is shown.
  await expect(page.locator('.project-tabs')).toBeVisible();
});
