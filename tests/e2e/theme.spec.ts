import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

// T-8: dark-theme toggle persists across reload. Cookie-driven, so the
// reload should land on the same theme without a flash. This is the only
// place we exercise the toggle from the workspace topbar; the no-flash
// init script (public/assets/js/theme-init.js) is verified implicitly —
// if it stops running, `data-theme` is missing on reload and the assertion
// catches it.
const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

test('dark theme toggle persists across reload', async ({ page }) => {
  // Seed an admin so the topbar (which holds the theme toggle) is reachable.
  await page.goto('/register');
  await page.fill('input[name=name]', 'Theme Admin');
  await page.fill('input[name=email]', 'theme@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // The toggle lives inside the user-menu popover — open it first.
  await page.click('[data-user-menu-toggle]');

  // Pick dark.
  const dark = page.locator('[data-theme-set="dark"]');
  await expect(dark).toBeVisible();
  await dark.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

  // Reload: cookie should round-trip and the page lands on dark again.
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

  // Flip to light and confirm round-trip the other way.
  await page.click('[data-user-menu-toggle]');
  await page.locator('[data-theme-set="light"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});

test('auto theme follows system preference (light emulation)', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/login');
  // theme-init.js stamps data-theme="light" before CSS loads for an
  // anonymous visitor with cookie=auto (the default). Confirm directly
  // instead of going through the toggle to lock the init script's
  // behaviour.
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});

test('auto theme follows system preference (dark emulation)', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto('/login');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
