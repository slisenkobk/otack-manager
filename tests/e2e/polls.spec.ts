import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

const PROJECT_NAME = 'Team offsite';
const POLL_TITLE   = 'Venue vote';
const OPT_LISBON   = 'Lisbon';
const OPT_PRAGUE   = 'Prague';
const VOTER_EMAIL  = 'voter@polls.test';

test('admin creates poll, voter votes, dedup works, summary task spawns', async ({ page }) => {
  // Register admin (first user → admin/approved automatically).
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@polls.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Create the project so the poll can attach to it.
  await page.goto('/projects?new=1');
  await page.waitForSelector('.modal');
  await page.locator('.modal .field input.input').first().fill(PROJECT_NAME);
  await page.click('.modal .modal-actions button.submit');
  await page.waitForURL(/\/projects\/\d+/);

  // Open the poll builder.
  await page.goto('/polls/new');
  await page.fill('input[data-poll-title]', POLL_TITLE);

  // Rename the two preset options to readable labels.
  const opts = page.locator('[data-options-list] [data-option-input]');
  await opts.nth(0).fill(OPT_LISBON);
  await opts.nth(1).fill(OPT_PRAGUE);

  // Attach to project via custom-select.
  await page.click('[data-poll-project-wrap] .custom-select__btn');
  await page.click(`[data-poll-project-wrap] .custom-select__opt:has-text("${PROJECT_NAME}")`);

  // Save and land on the edit screen with the public URL now populated.
  await Promise.all([
    page.waitForURL(/\/polls\/\d+$/),
    page.click('button[data-action=save-poll]'),
  ]);
  const pollIdMatch = page.url().match(/\/polls\/(\d+)/);
  expect(pollIdMatch).not.toBeNull();
  const pollId = pollIdMatch![1];

  const publicUrl = (await page.locator('[data-public-url-text]').textContent())?.trim();
  expect(publicUrl).toBeTruthy();
  const hashMatch = publicUrl!.match(/\/p\/([A-Za-z0-9_-]+)$/);
  expect(hashMatch).not.toBeNull();
  const hash = hashMatch![1];

  // /p/{hash} on a DRAFT poll should show the "not yet available" page.
  await page.goto('/p/' + hash);
  await expect(page.locator('body')).toContainText(/not.*available|jeszcze|ще не доступне/i);

  // Back to the draft builder.
  await page.goto('/polls/' + pollId);
  await Promise.all([
    page.waitForResponse(r => /\/polls\/\d+\/activate$/.test(r.url()) && r.request().method() === 'POST' && r.ok()),
    (async () => {
      await page.click('button[data-action=activate-poll]');
      await page.click('.modal .modal-actions button.submit');
    })(),
  ]);
  await page.waitForLoadState('networkidle');

  // Public vote flow. Stage 1: contact.
  await page.goto('/p/' + hash);
  await expect(page.locator('body')).toContainText(POLL_TITLE);
  await page.fill('input[name=contact]', VOTER_EMAIL);
  // Time-trap requires ≥ 2 s between page load and submit.
  await page.waitForTimeout(2500);
  await page.click('button[type=submit]');

  // Stage 2: vote.
  await expect(page.locator('body')).toContainText(VOTER_EMAIL);
  await page.locator(`input[name=choice][value^="opt"]`).first().check();
  await page.waitForTimeout(2500);
  await page.click('button[type=submit]');

  // Thanks page.
  await expect(page.locator('body')).toContainText(/thanks|dziękujemy|дякуємо|recorded|zapisany|враховано/i);

  // Second attempt with the same contact → "already voted".
  await page.goto('/p/' + hash);
  await page.fill('input[name=contact]', VOTER_EMAIL);
  await page.waitForTimeout(2500);
  await page.click('button[type=submit]');
  await expect(page.locator('body')).toContainText(/already.*voted|już.*głosowa|вже.*голосували/i);

  // Admin: stats tab shows 1 vote.
  await page.goto('/polls/' + pollId);
  await expect(page.locator('.poll-stats__count').first()).toContainText('1');

  // Voters tab shows the voter row.
  await page.click('.project-tab:has-text("Voters"), .project-tab:has-text("Голосуючі"), .project-tab:has-text("Głosujący")');
  await expect(page.locator('.voters-table tbody tr')).toHaveCount(1);
  await expect(page.locator('.voters-table tbody tr')).toContainText(VOTER_EMAIL);

  // Close the poll. UI.confirm with {danger:true} renders the confirm button
  // as .btn-danger (not .submit), so target the trailing button.
  await Promise.all([
    page.waitForResponse(r => /\/polls\/\d+\/close$/.test(r.url()) && r.request().method() === 'POST' && r.ok()),
    (async () => {
      await page.click('button[data-action=close-poll]');
      await page.click('.modal .modal-actions button:last-of-type');
    })(),
  ]);
  await page.waitForLoadState('networkidle');

  // After close the public page should refuse new votes.
  await page.goto('/p/' + hash);
  await expect(page.locator('body')).toContainText(/voting has ended|głosowanie zakończone|голосування завершено/i);

  // Create summary task from the closed-poll view.
  await page.goto('/polls/' + pollId);
  await Promise.all([
    page.waitForURL(/\/tasks\/\d+/),
    page.click('button[data-action=create-summary-task]'),
  ]);
  // Task page should show the poll title in the description / title.
  await expect(page.locator('body')).toContainText(POLL_TITLE);
});
