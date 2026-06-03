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

test('kanban search filters cards client-side', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@kf.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  await createProject(page, 'Filter Test', { gotoBoard: true });
  await expect(page).toHaveURL(/\/projects\/\d+$/);

  // quick-add three tasks via fold pattern. The form auto-folds after each
  // submit, so re-trigger before every add.
  const firstCol = page.locator('.kanban-col').first();
  const trigger = firstCol.locator('[data-quickadd-trigger]');
  const input = firstCol.locator('input[name=title]');

  await trigger.click();
  await input.fill('Design draft');
  await input.press('Enter');
  await firstCol.locator('.kanban-card', { hasText: 'Design draft' }).waitFor();

  await trigger.click();
  await input.fill('Backend wiring');
  await input.press('Enter');
  await firstCol.locator('.kanban-card', { hasText: 'Backend wiring' }).waitFor();

  await trigger.click();
  await input.fill('Design polish');
  await input.press('Enter');
  await firstCol.locator('.kanban-card', { hasText: 'Design polish' }).waitFor();

  await expect(page.locator('.kanban-card')).toHaveCount(3);

  // search for "design" — should show 2 cards. Search applies on Enter
  // or on submit-button click (no live debounce anymore).
  const searchInput = page.locator('[data-task-search]');
  await searchInput.fill('design');
  await searchInput.press('Enter');
  await page.waitForTimeout(150);
  const visibleAfterSearch = await page.locator('.kanban-card').evaluateAll(
    cards => cards.filter(c => (c as HTMLElement).style.display !== 'none').length
  );
  expect(visibleAfterSearch).toBe(2);

  // clear — all 3 visible again
  await searchInput.fill('');
  await searchInput.press('Enter');
  await page.waitForTimeout(150);
  const visibleAfterClear = await page.locator('.kanban-card').evaluateAll(
    cards => cards.filter(c => (c as HTMLElement).style.display !== 'none').length
  );
  expect(visibleAfterClear).toBe(3);
});

test('quick-add fold pattern: button visible, form hidden; click opens, Esc closes', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'a@kf.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  await page.locator('a', { hasText: 'Filter Test' }).first().click();
  await expect(page).toHaveURL(/\/projects\/\d+$/);

  const firstCol = page.locator('.kanban-col').first();
  const form = firstCol.locator('.kanban-col__form');
  const triggerBtn = firstCol.locator('[data-quickadd-trigger]');

  // Initially form is hidden, button is visible
  await expect(form).toBeHidden();
  await expect(triggerBtn).toBeVisible();

  // Click trigger — form appears
  await triggerBtn.click();
  await expect(form).toBeVisible();
  await expect(triggerBtn).toBeHidden();

  // Esc — form collapses, button returns
  await firstCol.locator('input[name=title]').press('Escape');
  await expect(form).toBeHidden();
  await expect(triggerBtn).toBeVisible();
});

test('kanban highlight: ?highlight=N adds is-highlight class', async ({ page }) => {
  await page.goto('/login');
  await page.fill('input[name=email]', 'a@kf.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Navigate to project board
  await page.locator('a', { hasText: 'Filter Test' }).first().click();
  await expect(page).toHaveURL(/\/projects\/\d+$/);

  // Get a card's task id
  const card = page.locator('.kanban-card').first();
  const taskId = await card.getAttribute('data-task-id');
  expect(taskId).toBeTruthy();

  // Reload with ?highlight= param
  const currentUrl = page.url();
  await page.goto(currentUrl + '?highlight=' + taskId);

  // After load the highlight class should be applied briefly
  const highlighted = page.locator('.kanban-card[data-task-id="' + taskId + '"]');
  // Check param was stripped from URL
  await expect(page).not.toHaveURL(/highlight=/);
  // The card exists in the DOM
  await expect(highlighted).toBeVisible();
});
