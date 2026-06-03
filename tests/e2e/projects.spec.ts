import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

test('admin can create a project and see 3 default columns', async ({ page }) => {
  // Register admin
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@p.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Navigate to /projects
  await page.goto('/projects');
  await expect(page.locator('.topbar__title')).toContainText('Projects');

  // Open the new-project modal and create the project. The standalone
  // /projects/new page was removed in favour of a UI.modal() flow that
  // POSTs JSON to /projects and navigates to /projects/{id}?tab=overview.
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await expect(modal).toBeVisible();
  await modal.locator('input.input').first().fill('My First Project');
  await Promise.all([
    page.waitForURL(/\/projects\/\d+/),
    modal.locator('button.submit').click(),
  ]);
  // Modal flow lands on ?tab=overview; strip it to view the board.
  const pid = page.url().match(/\/projects\/(\d+)/)![1];
  await page.goto('/projects/' + pid);
  await expect(page.locator('.kanban-col')).toHaveCount(3);
  await expect(page.locator('.kanban-col-head .name')).toHaveText(['To Do', 'In Progress', 'Done']);
});
