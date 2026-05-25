import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

test('open task page, change column, reload board', async ({ page }) => {
  // Bootstrap: admin + project + 1 task
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@t.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');

  await page.goto('/projects/new');
  await page.fill('input[name=name]', 'Task Page Test');
  await page.click('button.submit[type=submit]');
  // capture project URL
  const projectUrl = page.url();
  const projectId = projectUrl.match(/\/projects\/(\d+)/)[1];

  // Add a task via fold pattern
  const firstCol = page.locator('.kanban-col').first();
  await firstCol.locator('[data-quickadd-trigger]').click();
  const todoInput = firstCol.locator('input[name=title]');
  await todoInput.fill('Task one');
  await todoInput.press('Enter');
  await expect(page.locator('.kanban-card')).toHaveCount(1);

  // Get the task ID from the rendered card
  const taskId = await page.locator('.kanban-card').first().getAttribute('data-task-id');

  // Visit the task page directly
  await page.goto('/tasks/' + taskId);
  await expect(page.locator('.task-title')).toHaveText('Task one');

  // Change column to "Done"
  await page.locator('[data-field=column_id]').selectOption({ label: 'Done' });
  // wait briefly for AJAX
  await page.waitForTimeout(300);

  // Go back to project board
  await page.goto(projectUrl);
  // Done is the third column
  await expect(page.locator('.kanban-col').nth(2).locator('.kanban-card')).toContainText('Task one');
});
