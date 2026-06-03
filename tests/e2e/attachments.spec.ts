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

test('upload a small PNG and see it as thumbnail', async ({ page }) => {
  // Register — first user becomes admin
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'a@att.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Create a project — modal flow lands us on ?tab=overview already.
  await createProject(page, 'Att Test');
  await expect(page).toHaveURL(/\/projects\/\d+\?tab=overview$/);

  // Create a tiny 1×1 PNG in the data dir
  const tinyPng = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    'base64'
  );
  const tmpPath = path.join(ROOT, 'data/test-tiny.png');
  fs.writeFileSync(tmpPath, tinyPng);

  // Upload via the file input
  await page.locator('input[type=file][data-attach-input]').setInputFiles(tmpPath);

  // Wait for the thumbnail to appear
  await expect(page.locator('.attach-item img').first()).toBeVisible({ timeout: 8000 });

  // Cleanup
  fs.unlinkSync(tmpPath);
});

test('over-limit file shows toast error (client-side)', async ({ page }) => {
  // Log in as the admin created in the previous test
  await page.goto('/login');
  await page.fill('input[name=email]', 'a@att.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Navigate directly to the project Overview tab
  await page.goto('/projects/1?tab=overview');
  await expect(page.locator('.attachments-section')).toBeVisible({ timeout: 5000 });

  // Override the meta tag limit to a tiny value so we can trigger the client-side error
  await page.evaluate(() => {
    const meta = document.querySelector('meta[name=upload-max-image]') as HTMLMetaElement;
    if (meta) meta.content = '10'; // 10 bytes — any real PNG exceeds this
  });

  // Create a file bigger than 10 bytes
  const bigFile = Buffer.alloc(100, 0);
  const tmpPath = path.join(ROOT, 'data/test-big.png');
  fs.writeFileSync(tmpPath, bigFile);

  await page.locator('input[type=file][data-attach-input]').setInputFiles(tmpPath);

  // Toast with error type should appear
  await expect(page.locator('.toast--error').first()).toBeVisible({ timeout: 5000 });

  fs.unlinkSync(tmpPath);
});

test('opening an image thumbnail launches the lightbox + ArrowRight cycles (T-6)', async ({ page }) => {
  // Log in as the admin created in the first test.
  await page.goto('/login');
  await page.fill('input[name=email]', 'a@att.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  await page.goto('/projects/1?tab=overview');
  await expect(page.locator('.attachments-section')).toBeVisible({ timeout: 5000 });

  // Upload a second tiny PNG so navigation has something to cycle to. The
  // first upload from the previous test in this serial run already attached
  // one; we add another so the lightbox has at least two slides.
  const tinyPng = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    'base64'
  );
  const tmpPath = path.join(ROOT, 'data/test-tiny-2.png');
  fs.writeFileSync(tmpPath, tinyPng);
  await page.locator('input[type=file][data-attach-input]').setInputFiles(tmpPath);

  // Wait for at least one image thumbnail to be present.
  const thumb = page.locator('.attach-item[data-is-image="1"] [data-action=lightbox]').first();
  await expect(thumb).toBeVisible({ timeout: 8000 });

  // Click the thumbnail — lightbox should mount.
  await thumb.click();
  await expect(page.locator('.lightbox-backdrop')).toBeVisible();
  await expect(page.locator('.lightbox-img')).toBeVisible();

  // ArrowRight navigates without crashing; src should still be a valid URL.
  await page.keyboard.press('ArrowRight');
  const src = await page.locator('.lightbox-img').getAttribute('src');
  expect(src).toBeTruthy();

  // Escape closes the lightbox.
  await page.keyboard.press('Escape');
  await expect(page.locator('.lightbox-backdrop')).toHaveCount(0);

  fs.unlinkSync(tmpPath);
});
