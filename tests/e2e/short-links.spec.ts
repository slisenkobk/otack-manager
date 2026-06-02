import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

const TARGET = 'https://example.com/landing-page';

test('admin creates a short link, visits it publicly, stats increment', async ({ page, request, baseURL }) => {
  // Register admin (first user → admin/approved automatically).
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@links.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Open /links — empty state initially.
  await page.goto('/links');
  await expect(page.locator('.empty-state, .cards-row')).toBeVisible();

  // Create a short link via the modal triggered from "+ New link".
  await page.locator('[data-action="new-link"]').first().click();
  await page.waitForSelector('.modal');
  await page.locator('.modal input.input').first().fill(TARGET);
  await page.click('.modal .modal-actions button.submit');

  // Landed on /links/{id}.
  await page.waitForURL(/\/links\/\d+$/);
  await expect(page.locator('[data-link-target]')).toHaveValue(TARGET);

  // Pull the public /s/{slug} URL from the show page.
  const publicUrl = (await page.locator('[data-public-url-text]').textContent())?.trim();
  expect(publicUrl).toBeTruthy();
  const slugMatch = publicUrl!.match(/\/s\/([A-Za-z0-9_-]+)$/);
  expect(slugMatch).not.toBeNull();
  const slug = slugMatch![1];

  // Visit the public redirect via an HTTP request that doesn't follow
  // redirects — we want to assert the 302 + Location header directly so the
  // test isn't dependent on example.com being reachable from the runner.
  const res = await request.get('/s/' + slug, { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  expect(res.headers()['location']).toBe(TARGET);

  // Reload the show page and verify total = 1 (the request above was logged).
  await page.reload();
  await expect(page.locator('.link-stats__num').first()).toHaveText('1');

  // Disable the link via the "Manage" section and confirm the public path
  // now 404s instead of redirecting. Wait on the POST response so we don't
  // race the JS auto-reload that follows the toggle.
  await Promise.all([
    page.waitForResponse(r => /\/links\/\d+\/toggle$/.test(r.url()) && r.request().method() === 'POST' && r.ok()),
    page.locator('[data-action=toggle-link]').last().click(),
  ]);
  const disabledRes = await request.get('/s/' + slug, { maxRedirects: 0 });
  expect(disabledRes.status()).toBe(404);
});

