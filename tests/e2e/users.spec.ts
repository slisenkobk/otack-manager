import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

test('admin can approve a pending user', async ({ browser }) => {
  // Admin context
  const adminCtx = await browser.newContext();
  const admin = await adminCtx.newPage();
  await admin.goto('/register');
  await admin.fill('input[name=name]', 'Admin');
  await admin.fill('input[name=email]', 'admin@u.com');
  await admin.fill('input[name=password]', 'password123');
  await admin.click('button.submit[type=submit]');
  await expect(admin).toHaveURL('/');

  // Second user registers (pending)
  const userCtx = await browser.newContext();
  const user = await userCtx.newPage();
  await user.goto('/register');
  await user.fill('input[name=name]', 'Bob');
  await user.fill('input[name=email]', 'bob@u.com');
  await user.fill('input[name=password]', 'password123');
  await user.click('button.submit[type=submit]');
  await expect(user).toHaveURL('/pending');

  // Admin visits /users, approves Bob. The users page is now a table with
  // <tr data-user-id="…"> rows; each pending row carries a button with
  // data-action="approve". After clicking, the JS reloads the page.
  await admin.goto('/users');
  const bobRow = admin.locator('tr', { hasText: 'Bob' }).first();
  await expect(bobRow).toBeVisible();
  await bobRow.locator('button[data-action="approve"]').click();
  // Wait for the page reload triggered by the script
  await admin.waitForLoadState('networkidle');

  // Bob can now log in
  await user.goto('/login');
  await user.fill('input[name=email]', 'bob@u.com');
  await user.fill('input[name=password]', 'password123');
  await user.click('button.submit[type=submit]');
  await expect(user).toHaveURL('/');

  await adminCtx.close();
  await userCtx.close();
});
