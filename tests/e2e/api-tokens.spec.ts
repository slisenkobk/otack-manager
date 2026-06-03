import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

const TOKEN_NAME = 'ci-deploy';

test('user can create, view-once, and revoke an API token', async ({ page }) => {
  // Register admin (first user → admin/approved automatically).
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@tokens.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Profile page has a link to /profile/tokens — verify it exists, then go.
  await page.goto('/profile');
  await expect(page.locator('a[href="/profile/tokens"]')).toBeVisible();

  await page.goto('/profile/tokens');
  await expect(page).toHaveURL('/profile/tokens');

  // Empty state — no tokens yet.
  await expect(page.locator('[data-token-row]')).toHaveCount(0);

  // Create one.
  await page.fill('input[name=name]', TOKEN_NAME);
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/profile/tokens');

  // One-time reveal block shows the plaintext token (prefixed `otk_`).
  const reveal = page.locator('[data-token-reveal]');
  await expect(reveal).toBeVisible();
  const revealed = await reveal.locator('pre.copyable').textContent();
  expect(revealed).toMatch(/^otk_[A-Za-z0-9]{40}$/);

  // Row appears in the table with the chosen name + active status.
  const row = page.locator('[data-token-row]').first();
  await expect(row).toBeVisible();
  await expect(row.locator('[data-token-name]')).toHaveText(TOKEN_NAME);
  await expect(row.locator('[data-token-status]')).toHaveText(/Active/i);

  // Reload — reveal is single-use and must be gone, but the row stays.
  await page.reload();
  await expect(page.locator('[data-token-reveal]')).toHaveCount(0);
  await expect(page.locator('[data-token-row]')).toHaveCount(1);
  await expect(page.locator('[data-token-row] [data-token-name]')).toHaveText(TOKEN_NAME);

  // Revoke through the UI.confirm() custom modal — click the danger action,
  // then accept the modal. The form is submit-intercepted by api-tokens.js.
  await page.click('[data-action=revoke-token]');
  // The modal renders inside #modal-root; the confirm button uses class
  // `submit` for non-danger and `btn-danger` for destructive flows.
  const modal = page.locator('.modal-backdrop .modal');
  await expect(modal).toBeVisible();
  await modal.locator('button.btn-danger').click();

  // After revoke the row's status flips to "Revoked".
  await expect(page).toHaveURL('/profile/tokens');
  await expect(page.locator('[data-token-row]')).toHaveCount(1);
  await expect(page.locator('[data-token-row] [data-token-status]')).toHaveText(/Revoked/i);
});
