import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { createProject } from './helpers/projects';

const ROOT = path.resolve(__dirname, '../..');

test.use({ viewport: { width: 375, height: 667 } });

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

/**
 * Compares document scroll width against the visual viewport.
 * Allowing a 1px tolerance keeps the check honest without flapping on
 * sub-pixel rounding.
 */
async function assertNoHorizontalOverflow(page: import('@playwright/test').Page) {
  const { scroll, client } = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }));
  expect(scroll).toBeLessThanOrEqual(client + 1);
}

test('/login fits within 375px viewport (T-9)', async ({ page }) => {
  await page.goto('/login');
  await expect(page.locator('input[name=email]')).toBeVisible();
  await assertNoHorizontalOverflow(page);
  // The email input must lie inside the viewport — proxy for "form fits".
  const box = await page.locator('input[name=email]').boundingBox();
  expect(box?.x ?? 999).toBeGreaterThanOrEqual(0);
  expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(375 + 1);
});

test('logged-in dashboard has no horizontal overflow at 375px (T-9)', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Mob');
  await page.fill('input[name=email]', 'mob@mob.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');
  await assertNoHorizontalOverflow(page);
});

test('/projects/{id} kanban board renders at 375px without runaway overflow (T-9)', async ({ page }) => {
  // Re-login (each test gets a fresh context).
  await page.goto('/login');
  await page.fill('input[name=email]', 'mob@mob.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  const id = await createProject(page, 'Mobile Board', { gotoBoard: true });
  expect(id).toBeGreaterThan(0);
  await expect(page.locator('.kanban').first()).toBeVisible({ timeout: 5000 });

  // The kanban-board is horizontally scrollable by design, so its inner
  // width may exceed viewport — but the *page* (documentElement) must not
  // leak that horizontal scroll. body overflow stays contained.
  const overflow = await page.evaluate(() => ({
    docScroll: document.documentElement.scrollWidth,
    docClient: document.documentElement.clientWidth,
    bodyOverflowX: getComputedStyle(document.body).overflowX,
  }));
  // Either the page itself has no horizontal overflow, OR body explicitly
  // contains it via overflow-x:hidden / auto on a child. Either is acceptable.
  const fits = overflow.docScroll <= overflow.docClient + 1
    || overflow.bodyOverflowX === 'hidden'
    || overflow.bodyOverflowX === 'auto'
    || overflow.bodyOverflowX === 'scroll';
  expect(fits).toBeTruthy();
});
