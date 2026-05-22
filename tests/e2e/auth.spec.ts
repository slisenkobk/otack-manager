import { test, expect } from '@playwright/test';
import fs from 'fs';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync('data/app.sqlite', { force: true });
  fs.rmSync('data/.schema', { recursive: true, force: true });
});

test('first user becomes admin and logs in', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/');
});

test('second user goes pending', async ({ page }) => {
  await page.goto('/register');
  await page.fill('input[name=name]', 'User');
  await page.fill('input[name=email]', 'u@example.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button[type=submit]');
  await expect(page).toHaveURL('/pending');
});
