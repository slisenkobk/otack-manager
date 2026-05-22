import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';
const ROOT = path.resolve(__dirname, '../..');
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema'), { recursive: true, force: true });
});

test('quick-add then drag from To Do to Done', async ({ page }) => {
  // Register admin and create project
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@k.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  await page.goto('/projects/new');
  await page.fill('input[name=name]', 'Kanban Test');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL(/\/projects\/\d+$/);

  // Quick-add two tasks into "To Do" (first column)
  const todoForm = page.locator('.kanban-col').first().locator('.kanban-quickadd input');
  await todoForm.fill('First task');
  await todoForm.press('Enter');
  await expect(page.locator('.kanban-col').first().locator('.kanban-card')).toHaveCount(1);

  await todoForm.fill('Second task');
  await todoForm.press('Enter');
  await expect(page.locator('.kanban-col').first().locator('.kanban-card')).toHaveCount(2);

  // Drag "First task" from To Do to Done (third column)
  const card = page.locator('.kanban-card', { hasText: 'First task' });
  const doneList = page.locator('.kanban-col').nth(2).locator('.kanban-list');

  // Try dragTo first; SortableJS uses pointer events which Playwright simulates
  const cardBox = await card.boundingBox();
  const doneBox = await doneList.boundingBox();

  if (cardBox && doneBox) {
    // Use manual mouse drag sequence for reliable SortableJS triggering
    const startX = cardBox.x + cardBox.width / 2;
    const startY = cardBox.y + cardBox.height / 2;
    const endX = doneBox.x + doneBox.width / 2;
    const endY = doneBox.y + doneBox.height / 2;

    await page.mouse.move(startX, startY);
    await page.mouse.down();
    // Move in small increments to trigger SortableJS drag detection
    await page.mouse.move(startX + 5, startY + 2);
    await page.mouse.move(startX + 20, startY + 5);
    await page.mouse.move((startX + endX) / 2, (startY + endY) / 2);
    await page.mouse.move(endX - 20, endY - 5);
    await page.mouse.move(endX, endY);
    await page.mouse.up();
  } else {
    // Fallback to dragTo
    await card.dragTo(doneList);
  }

  // Wait for AJAX to finish
  await page.waitForTimeout(500);

  // Reload and verify persistence
  await page.reload();
  const doneCards = page.locator('.kanban-col').nth(2).locator('.kanban-card');
  await expect(doneCards).toHaveCount(1);
  await expect(doneCards.first()).toContainText('First task');
});
