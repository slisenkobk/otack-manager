import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { createProject } from './helpers/projects';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

test('rename a column and persist', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@c.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await createProject(page, 'Cols Test', { gotoBoard: true });

  // Click the first column's settings button
  await page.locator('.kanban-col').first().locator('.col-settings').click();
  // Modal appears
  await expect(page.locator('.modal')).toBeVisible();
  await page.locator('.modal input.input').first().fill('Backlog');
  await page.locator('.modal .submit').click();
  // Wait for reload
  await page.waitForTimeout(800);
  await expect(page.locator('.kanban-col').first().locator('.name')).toHaveText('Backlog');
});
