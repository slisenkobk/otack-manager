import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema'), { recursive: true, force: true });
});

test('admin can create a project and see 3 default columns', async ({ page }) => {
  // Register admin
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@p.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/');

  // Navigate to /projects
  await page.goto('/projects');
  await expect(page.locator('.section-head')).toBeVisible();

  // Create
  await page.click('a:has-text("New project")');
  await expect(page).toHaveURL('/projects/new');
  await page.fill('input[name=name]', 'My First Project');
  await page.fill('textarea[name=description]', 'A test project');
  await page.click('button.submit[type=submit]');

  // Now on /projects/{id}
  await expect(page).toHaveURL(/\/projects\/\d+$/);
  await expect(page.locator('.kanban-col')).toHaveCount(3);
  await expect(page.locator('.kanban-col-head .name')).toHaveText(['To Do', 'In Progress', 'Done']);
});
