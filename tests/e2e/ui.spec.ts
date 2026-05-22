import { test, expect } from '@playwright/test';

test('toast appears', async ({ page }) => {
  await page.goto('/ui-sandbox');
  await page.waitForFunction(() => !!(window as any).UI);
  await page.evaluate(() => (window as any).UI.toast('hello', 'success'));
  await expect(page.locator('.toast--success')).toHaveText('hello');
});

test('confirm resolves true on OK', async ({ page }) => {
  await page.goto('/ui-sandbox');
  await page.waitForFunction(() => !!(window as any).UI);
  const result = page.evaluate(() => (window as any).UI.confirm('Sure?'));
  await page.click('.modal-actions .submit, .modal-actions .btn-danger');
  expect(await result).toBe(true);
});
