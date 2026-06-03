import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});
test('unauthenticated visit to / shows the public landing page', async ({ page }) => {
  // Anonymous "/" now serves the landing page instead of redirecting to
  // /login — landing is the intentional entry point for the invite-only
  // product. Protected URLs still redirect (see next test).
  await page.goto('/');
  await expect(page).toHaveURL(/\/$/);
  await expect(page.locator('main.landing')).toBeVisible();
  await expect(page.locator('.brand__name')).toContainText('Otack');
});

test('unauthenticated visit to a protected path redirects to /login', async ({ page }) => {
  await page.goto('/projects');
  await expect(page).toHaveURL(/\/login$/);
});
test('after logging in, / shows the dashboard placeholder', async ({ page }) => {
  // Register first user (becomes admin/approved/logged-in)
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@test.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');
  await expect(page.locator('aside.sidebar')).toBeVisible();
  await expect(page.locator('aside.sidebar')).toContainText('Users'); // admin only
});
