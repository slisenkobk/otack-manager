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

test('post a comment with markdown', async ({ page }) => {
  // Register (first user becomes admin)
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@c.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Create a project (modal flow lands on ?tab=overview already)
  await createProject(page, 'Comments Test');
  await expect(page).toHaveURL(/\/projects\/\d+\?tab=overview$/);
  await expect(page.locator('.comment-thread')).toBeVisible();

  // Post a comment with markdown bold
  await page.locator('textarea[name=body]').fill('**hello** world');
  await page.locator('.comment-composer button.submit').click();

  // Wait for the comment to appear in the DOM
  await expect(page.locator('.comment-body strong').first()).toBeVisible();

  // Reload and verify persistence (still on overview tab)
  await page.reload();
  await expect(page.locator('.comment-body strong').first()).toHaveText('hello');
});
