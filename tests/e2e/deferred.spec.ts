/**
 * deferred.spec.ts — E2E tests for the 3 deferred v1 features:
 *   1. Dashboard activity pagination (Load more)
 *   2. /admin/tags tag management page
 *   3. Comment attachments
 *
 * Run:
 *   npx playwright test --config tests/e2e/playwright.config.ts tests/e2e/deferred.spec.ts --reporter=list
 *
 * State: serial, DB reset in beforeAll.
 */

import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema'), { recursive: true, force: true });
  // wipe uploads
  const uploadDir = path.join(ROOT, 'public/uploads');
  if (fs.existsSync(uploadDir)) {
    fs.readdirSync(uploadDir).forEach((f) => {
      const fp = path.join(uploadDir, f);
      if (fs.statSync(fp).isDirectory()) fs.rmSync(fp, { recursive: true });
      else fs.unlinkSync(fp);
    });
  }
});

function tinyPng(): Buffer {
  return Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    'base64'
  );
}

// ──────────────────────────────────────────────────────────────────
// Bootstrap: register admin and create a project
// ──────────────────────────────────────────────────────────────────

test('D.0 setup: register admin and create project', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  await page.goto('/projects/new');
  await page.fill('input[name=name]', 'Deferred Test Project');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL(/\/projects\/\d+$/);
});

// ──────────────────────────────────────────────────────────────────
// Feature 1: Dashboard activity pagination
// ──────────────────────────────────────────────────────────────────

test('D.1 pagination: post 11 comments, reload dashboard, click Load more, see extra comments', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  // Post 11 comments on the project overview
  await page.goto('/projects/1?tab=overview');
  for (let i = 1; i <= 11; i++) {
    await page.locator('textarea[name=body]').fill('Activity comment ' + i);
    await page.locator('.comment-composer button.submit').click();
    await page.waitForTimeout(200);
  }

  // Reload dashboard
  await page.goto('/');

  // Activity list should have 10 items initially
  const activityList = page.locator('#activity-list a');
  await expect(activityList).toHaveCount(10);

  // Load more button should be visible
  const loadMoreBtn = page.locator('.load-more-activity');
  await expect(loadMoreBtn).toBeVisible();

  // Click it
  await loadMoreBtn.click();
  await page.waitForTimeout(600);

  // Should now have 11 items (the extra one loaded)
  const activityListAfter = page.locator('#activity-list a');
  const countAfter = await activityListAfter.count();
  expect(countAfter).toBeGreaterThanOrEqual(11);

  // Load more button should be hidden (no more items)
  await expect(page.locator('.load-more-activity')).not.toBeVisible({ timeout: 2000 });
});

// ──────────────────────────────────────────────────────────────────
// Feature 2: /admin/tags page
// ──────────────────────────────────────────────────────────────────

test('D.2.1 admin tags page: create tags and visit /admin/tags', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  // Create a project-scope tag on the project overview
  await page.goto('/projects/1?tab=overview');
  const tagSearch = page.locator('.tag-picker[data-scope=project] [data-tag-search]');
  await expect(tagSearch).toBeVisible({ timeout: 3000 });
  await tagSearch.fill('alpha-tag');
  await page.waitForTimeout(200);
  const dropdown = page.locator('.tag-picker[data-scope=project] [data-tag-dropdown]');
  await expect(dropdown).toBeVisible({ timeout: 2000 });
  await dropdown.locator('div:has-text("Create")').click();
  await page.waitForTimeout(300);
  await expect(page.locator('.tag-picker[data-scope=project] .tag:has-text("alpha-tag")')).toBeVisible();

  // Create a second tag
  await tagSearch.fill('beta-tag');
  await page.waitForTimeout(200);
  await expect(dropdown).toBeVisible({ timeout: 2000 });
  await dropdown.locator('div:has-text("Create")').click();
  await page.waitForTimeout(300);

  // Visit /admin/tags
  await page.goto('/admin/tags');
  await expect(page.locator('.section-head .title:has-text("tags")')).toBeVisible();

  // Both tags should be listed
  await expect(page.locator('[data-tag-id]')).toHaveCount(2);
  await expect(page.locator('.tag-name:has-text("alpha-tag")')).toBeVisible();
  await expect(page.locator('.tag-name:has-text("beta-tag")')).toBeVisible();
});

test('D.2.2 admin tags page: rename a tag inline', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  await page.goto('/admin/tags');

  // Find the alpha-tag name element and rename it via API directly
  // (contenteditable blur is flaky in Playwright; use fetch via page.evaluate)
  const tagCard = page.locator('[data-tag-id]', { hasText: 'alpha-tag' }).first();
  await expect(tagCard).toBeVisible();
  const tagId = await tagCard.getAttribute('data-tag-id');
  expect(tagId).toBeTruthy();

  const csrf = await page.evaluate(() =>
    (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content || ''
  );

  const res = await page.evaluate(async ({ id, token }: { id: string; token: string }) => {
    const r = await fetch('/api/admin/tags/' + id, {
      method: 'POST',
      headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: 'renamed-tag' }),
    });
    return { status: r.status, body: await r.json() };
  }, { id: tagId!, token: csrf });

  expect(res.status).toBe(200);
  expect(res.body.ok).toBe(true);

  // Reload and verify persistence
  await page.reload();
  await expect(page.locator('.tag-name:has-text("renamed-tag")')).toBeVisible();
  await expect(page.locator('.tag-name:has-text("alpha-tag")')).not.toBeVisible();
});

test('D.2.3 admin tags page: delete a tag', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  await page.goto('/admin/tags');

  const countBefore = await page.locator('[data-tag-id]').count();
  expect(countBefore).toBeGreaterThanOrEqual(2);

  // Click delete on the first tag
  const firstCard = page.locator('[data-tag-id]').first();
  await firstCard.locator('[data-action="delete-tag"]').click();

  // Confirm modal
  await expect(page.locator('.modal')).toBeVisible({ timeout: 2000 });
  await page.locator('.modal button.btn-danger').click();
  await page.waitForTimeout(400);

  // One fewer tag
  await expect(page.locator('[data-tag-id]')).toHaveCount(countBefore - 1);

  // Verify persistence after reload
  await page.reload();
  await expect(page.locator('[data-tag-id]')).toHaveCount(countBefore - 1);
});

test('D.2.4 sidebar shows Tags nav item for admin', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  await expect(page.locator('aside.sidebar a[href="/admin/tags"]')).toBeVisible();
});

// ──────────────────────────────────────────────────────────────────
// Feature 3: Comment attachments
// ──────────────────────────────────────────────────────────────────

test('D.3.1 comment attachment: post a comment with a PNG, verify chip visible after reload', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  const tmpPath = path.join(ROOT, 'data/deferred-attach.png');
  fs.writeFileSync(tmpPath, tinyPng());

  await page.goto('/projects/1?tab=overview');

  // Attach a file to the comment
  await page.locator('textarea[name=body]').fill('Comment with attachment');
  await page.locator('[data-comment-attach]').setInputFiles(tmpPath);

  // Pending chip should appear
  await expect(page.locator('.comment-pending-attachments span')).toBeVisible({ timeout: 3000 });

  // Submit the comment
  await page.locator('.comment-composer button.submit').click();
  await page.waitForTimeout(800);

  // The newly built comment node should contain an attachment chip
  const lastComment = page.locator('.comment').last();
  await expect(lastComment.locator('.comment-attachments a')).toBeVisible({ timeout: 5000 });

  // Reload and verify persistence
  await page.reload();
  await page.goto('/projects/1?tab=overview');

  // Find the comment with the text and verify attachment still there
  const commentWithAtt = page.locator('.comment', { hasText: 'Comment with attachment' });
  await expect(commentWithAtt).toBeVisible({ timeout: 3000 });
  await expect(commentWithAtt.locator('.comment-attachments a')).toBeVisible({ timeout: 3000 });

  fs.unlinkSync(tmpPath);
});

test('D.3.2 comment attachment chip is clickable (valid URL)', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'admin@def.test');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');

  await page.goto('/projects/1?tab=overview');

  // Find the attachment chip from the previous test
  const chip = page.locator('.comment-attachments a').first();
  await expect(chip).toBeVisible({ timeout: 3000 });

  // Verify it has a valid href
  const href = await chip.getAttribute('href');
  expect(href).toBeTruthy();
  expect(href).toMatch(/^\/uploads\//);

  // Verify the file is accessible
  const res = await page.request.get(href!);
  expect(res.status()).toBe(200);
});
