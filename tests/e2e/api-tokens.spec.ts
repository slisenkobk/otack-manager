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
  // `submit` for non-danger and `btn--danger` for destructive flows.
  const modal = page.locator('.modal-backdrop .modal');
  await expect(modal).toBeVisible();
  await modal.locator('button.btn--danger').click();

  // After revoke the row's status flips to "Revoked".
  await expect(page).toHaveURL('/profile/tokens');
  await expect(page.locator('[data-token-row]')).toHaveCount(1);
  await expect(page.locator('[data-token-row] [data-token-status]')).toHaveText(/Revoked/i);
});

// ─── Admin view of another user's tokens (Task 18) ──────────────────────────
// Builds on the same fixture DB seeded by the test above. The first test
// already created an admin (admin@tokens.test). Here we register a second
// user, approve them, log them in to mint two tokens, then switch back to
// the admin and exercise per-row revoke + revoke-all on /users/{id}.

test.describe('admin tokens view', () => {
  const ADMIN_EMAIL = 'admin@tokens.test';
  const ADMIN_PASSWORD = 'password123';
  const BOB_EMAIL = 'bob@tokens.test';
  const BOB_PASSWORD = 'password123';
  const BOB_TOKEN_A = 'bob-laptop';
  const BOB_TOKEN_B = 'bob-ci';
  let bobId = 0;

  test.beforeAll(async ({ browser }) => {
    // Bob registers (pending), admin approves him, Bob logs in and mints two
    // tokens. All of this happens through the UI to keep the fixture aligned
    // with the production code path (no direct PDO inserts needed).
    const adminCtx = await browser.newContext();
    const admin = await adminCtx.newPage();
    await admin.goto('/login');
    await admin.fill('input[name=email]', ADMIN_EMAIL);
    await admin.fill('input[name=password]', ADMIN_PASSWORD);
    await admin.click('button.submit[type=submit]');
    await expect(admin).toHaveURL('/');

    const bobCtx = await browser.newContext();
    const bob = await bobCtx.newPage();
    await bob.goto('/register');
    await bob.fill('input[name=name]', 'Bob Tokens');
    await bob.fill('input[name=email]', BOB_EMAIL);
    await bob.fill('input[name=password]', BOB_PASSWORD);
    await bob.click('button.submit[type=submit]');
    await expect(bob).toHaveURL('/pending');

    await admin.goto('/users');
    // Capture Bob's id off the row so the admin view URL is deterministic.
    const bobRow = admin.locator('tr[data-user-id]', { hasText: BOB_EMAIL }).first();
    bobId = Number(await bobRow.getAttribute('data-user-id'));
    expect(bobId).toBeGreaterThan(0);
    await bobRow.locator('[data-action=approve]').click();
    await admin.waitForLoadState('networkidle');

    // Bob logs in and mints two tokens.
    await bob.goto('/login');
    await bob.fill('input[name=email]', BOB_EMAIL);
    await bob.fill('input[name=password]', BOB_PASSWORD);
    await bob.click('button.submit[type=submit]');
    await expect(bob).toHaveURL('/');
    for (const name of [BOB_TOKEN_A, BOB_TOKEN_B]) {
      await bob.goto('/profile/tokens');
      await bob.fill('input[name=name]', name);
      await bob.click('button.submit[type=submit]');
      await expect(bob.locator('[data-token-reveal]')).toBeVisible();
    }

    await adminCtx.close();
    await bobCtx.close();
  });

  test("admin sees another user's tokens metadata (no plaintext)", async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name=email]', ADMIN_EMAIL);
    await page.fill('input[name=password]', ADMIN_PASSWORD);
    await page.click('button.submit[type=submit]');
    await expect(page).toHaveURL('/');

    await page.goto(`/users/${bobId}`);
    const section = page.locator('[data-admin-tokens]');
    await expect(section).toBeVisible();

    // Both of Bob's tokens are listed by name; neither plaintext is on the page.
    await expect(page.locator('[data-token-row]')).toHaveCount(2);
    await expect(page.locator('[data-token-row] [data-token-name]', { hasText: BOB_TOKEN_A })).toBeVisible();
    await expect(page.locator('[data-token-row] [data-token-name]', { hasText: BOB_TOKEN_B })).toBeVisible();

    // No "otk_…" plaintext anywhere — admins never see token values.
    const html = await page.content();
    expect(html).not.toMatch(/otk_[A-Za-z0-9]{40}/);
    // And no creation UI is rendered on the admin view.
    await expect(page.locator('input[name=name]')).toHaveCount(0);
  });

  test('admin can revoke a single token + revoke all', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name=email]', ADMIN_EMAIL);
    await page.fill('input[name=password]', ADMIN_PASSWORD);
    await page.click('button.submit[type=submit]');
    await page.goto(`/users/${bobId}`);

    // Revoke the first token via the per-row form + UI.confirm() modal.
    const firstRow = page.locator('[data-token-row]').first();
    await firstRow.locator('[data-action=revoke-token]').click();
    let modal = page.locator('.modal-backdrop .modal');
    await expect(modal).toBeVisible();
    await modal.locator('button.btn--danger').click();
    await expect(page).toHaveURL(`/users/${bobId}`);

    // Exactly one row is now Revoked and one is still Active.
    await expect(page.locator('[data-token-row] [data-token-status]', { hasText: /Revoked/i })).toHaveCount(1);
    await expect(page.locator('[data-token-row] [data-token-status]', { hasText: /Active/i })).toHaveCount(1);

    // Revoke-all flips the remaining active token to Revoked.
    await page.click('[data-action=revoke-all-tokens]');
    modal = page.locator('.modal-backdrop .modal');
    await expect(modal).toBeVisible();
    await modal.locator('button.btn--danger').click();
    await expect(page).toHaveURL(`/users/${bobId}`);

    await expect(page.locator('[data-token-row] [data-token-status]', { hasText: /Revoked/i })).toHaveCount(2);
    await expect(page.locator('[data-token-row] [data-token-status]', { hasText: /Active/i })).toHaveCount(0);
    // With no active tokens left, the bulk button should disappear.
    await expect(page.locator('[data-action=revoke-all-tokens]')).toHaveCount(0);
  });
});
