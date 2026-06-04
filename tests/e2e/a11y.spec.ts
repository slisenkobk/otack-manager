import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

// V-3: a focus trap inside UI.modal so Tab can't escape back to the
// underlying page. Uses /ui-sandbox to avoid coupling to login/setup.
test.describe('a11y modal focus trap', () => {
  test('Tab from last button cycles back to first', async ({ page }) => {
    await page.goto('/ui-sandbox');
    await page.waitForFunction(() => !!(window as any).UI);

    // Open a modal with two action buttons. The close (X) button counts
    // as a focusable too, so the trap operates over [close, cancel, ok].
    await page.evaluate(() => {
      (window as any).UI.modal({
        title: 'Trap test',
        body: '<p>body</p>',
        actions: [
          { label: 'Cancel', variant: 'btn--ghost' },
          { label: 'OK', variant: 'submit' },
        ],
      });
    });
    const modal = page.locator('.modal').last();
    await modal.waitFor({ state: 'visible' });

    // First focusable is the close button — gets initial focus.
    await expect(modal.locator('.modal-close')).toBeFocused();

    // Focus the last action button, then Tab and expect to cycle back.
    await modal.locator('button.submit').focus();
    await expect(modal.locator('button.submit')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(modal.locator('.modal-close')).toBeFocused();

    // Shift+Tab from first should land on last.
    await page.keyboard.press('Shift+Tab');
    await expect(modal.locator('button.submit')).toBeFocused();
  });
});

// ─────────────────────────────────────────────────────────────────
// Wave 9.1e Task 11 — axe-core sweep across 10 key pages.
//
// Runs serially with its own DB reset + admin registration + minimal
// fixture setup. Each route is its own test so failures show which
// page broke. Only wcag2a + wcag2aa rules are enforced.
// ─────────────────────────────────────────────────────────────────

async function registerAdmin(page: Page) {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Alice Admin');
  await page.fill('input[name=email]', 'alice@u.com');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await page.waitForURL('/');
}

async function loginAsAdmin(page: Page) {
  await page.goto('/login');
  await page.fill('input[name=email]', 'alice@u.com');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');
}

async function createProjectAndTask(page: Page) {
  await page.goto('/projects');
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await modal.waitFor({ state: 'visible' });
  await modal.locator('input.input').first().fill('A11y Project');
  await Promise.all([
    page.waitForURL(/\/projects\/\d+/),
    modal.locator('button.submit').click(),
  ]);

  // Quick-add a task in the first column.
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');
  const col = page.locator('.kanban-col').first();
  const trigger = col.locator('[data-quickadd-trigger]').first();
  if (!(await trigger.isHidden())) {
    await trigger.click();
  }
  const input = col.locator('input[name=title]').first();
  await input.fill('A11y Task');
  await input.press('Enter');
  await col.locator('.kanban-card').first().waitFor({ timeout: 5000 });
}

test.describe('a11y axe sweep setup', () => {
  // Setup runs serially: reset DB, register admin, seed 1 project + 1 task
  // so /projects/1 and /tasks/1 routes are reachable in the sweep below.
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
    fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
    const uploadDir = path.join(ROOT, 'public/uploads-test');
    if (fs.existsSync(uploadDir)) {
      fs.readdirSync(uploadDir).forEach((f) => {
        const fp = path.join(uploadDir, f);
        if (fs.statSync(fp).isDirectory()) fs.rmSync(fp, { recursive: true });
        else fs.unlinkSync(fp);
      });
    }
  });

  test('register admin + seed fixtures', async ({ page }) => {
    await registerAdmin(page);
    await createProjectAndTask(page);
  });
});

test.describe('a11y axe sweep', () => {
  // NOT serial: each route is independent (each test re-logs in). One
  // failing route should not hide violations on the other nine.

  // Anonymous-accessible pages (no login required).
  const anonRoutes: { label: string; path: string }[] = [
    { label: '/login',    path: '/login' },
    { label: '/register', path: '/register' },
  ];

  // Authenticated pages.
  const authRoutes: { label: string; path: string }[] = [
    { label: '/',               path: '/' },
    { label: '/projects',       path: '/projects' },
    { label: '/projects/1',     path: '/projects/1' },
    { label: '/tasks/1',        path: '/tasks/1' },
    { label: '/admin/tags',     path: '/admin/tags' },
    { label: '/profile',        path: '/profile' },
    { label: '/users',          path: '/users' },
    { label: '/admin/settings', path: '/admin/settings' },
  ];

  async function runAxe(page: Page, label: string) {
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();
    if (results.violations.length > 0) {
      // Compact summary so the assertion failure surfaces the rule
      // ids; the JSON dump gives reviewers full node detail.
      const summary = results.violations.map(v => ({
        id: v.id,
        impact: v.impact,
        nodes: v.nodes.length,
        help: v.help,
      }));
      console.log(`[axe ${label}] violations:`, JSON.stringify(summary, null, 2));
      console.log(`[axe ${label}] full:`, JSON.stringify(results.violations, null, 2));
    }
    expect(results.violations, `axe violations on ${label}`).toEqual([]);
  }

  for (const route of anonRoutes) {
    test(`axe: ${route.label}`, async ({ page }) => {
      await page.goto(route.path);
      await page.waitForLoadState('networkidle');
      await runAxe(page, route.label);
    });
  }

  for (const route of authRoutes) {
    test(`axe: ${route.label}`, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(route.path);
      await page.waitForLoadState('networkidle');
      await runAxe(page, route.label);
    });
  }
});
