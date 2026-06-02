import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
});

const FORM_TITLE = 'Lead intake';
const FORM_TITLE_COPY = `${FORM_TITLE} (copy)`;
const PROJECT_NAME = 'Sales pipeline';
const SUBMITTER = 'Bob Submitter';
const CUSTOM_THANKS = 'Custom thank-you sentinel — we got it.';

test('admin attaches form to project, public submission auto-creates a task', async ({ page }) => {
  // Register admin (first user → admin/approved automatically)
  await page.goto('/register');
  await page.fill('input[name=name]', 'Admin');
  await page.fill('input[name=email]', 'admin@forms.test');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');
  await expect(page).toHaveURL('/');

  // Create the project so it can be picked in the form builder. Project
  // creation is a modal launched from /projects via the ?new=1 hint.
  await page.goto('/projects?new=1');
  await page.waitForSelector('.modal');
  await page.locator('.modal .field input.input').first().fill(PROJECT_NAME);
  await page.click('.modal .modal-actions button.submit');
  await page.waitForURL(/\/projects\/\d+/);
  const projectUrl = page.url().split('?')[0];

  // Open the form builder and fill it in.
  await page.goto('/forms/new');
  await page.fill('input[data-form-title]', FORM_TITLE);

  // Add a single text field with key=name.
  await page.click('button[data-action=add-field]');
  const card = page.locator('.field-card').first();
  await card.locator('[data-field-label]').fill('Your name');
  // Key input is the second text input in the card body (label is first).
  await card.locator('.field-card__cell input.input').nth(1).fill('name');

  // Pick the project via custom-select.
  await page.click('[data-form-project-wrap] .custom-select__btn');
  await page.click('[data-form-project-wrap] .custom-select__opt:has-text("Sales pipeline")');

  // Auto-create-task row should now be visible.
  await expect(page.locator('[data-auto-task-row]')).toBeVisible();
  await page.check('input[data-auto-create-task]');
  await expect(page.locator('[data-task-template-row]')).toBeVisible();

  // Fill the title template.
  await page.fill('input[data-task-title-template]', '{formName} — new lead from {name}');

  // Save and land on the edit screen (hash + integration are now persisted).
  await page.click('button[data-action=save-form]');
  await expect(page).toHaveURL(/\/forms\/\d+$/);

  // Grab the public URL from the edit page.
  const publicUrl = (await page.locator('[data-public-url-text]').textContent())?.trim();
  expect(publicUrl).toBeTruthy();
  const hashMatch = publicUrl!.match(/\/f\/([A-Za-z0-9_-]+)$/);
  expect(hashMatch).not.toBeNull();
  const hash = hashMatch![1];

  // Submit the public form anonymously. The time-trap requires ≥2 s between
  // GET and POST; we wait 2.5 s after navigation before clicking submit.
  await page.goto('/f/' + hash);
  await expect(page.locator('h1, .form-title, .form-card__title').first()).toContainText(FORM_TITLE);
  await page.fill('input[name=name]', SUBMITTER);
  await page.waitForTimeout(2500);
  await page.click('button[type=submit]');
  // Thanks page lives at the same URL on a successful POST.
  await expect(page.locator('body')).toContainText(/thank|thanks|спас|dzięk/i, { timeout: 5000 });

  // Hop back to the project and check the auto-created task. The "To Do"
  // column is the first non-backlog kanban column.
  await page.goto(projectUrl);
  const expectedTitle = `${FORM_TITLE} — new lead from ${SUBMITTER}`;
  const todoCol = page.locator('.kanban-col').first();
  await expect(todoCol.locator('.kanban-card')).toContainText([expectedTitle]);

  // Duplicate flow: from the forms list, hitting the clone button creates a
  // new form titled "<original> (copy)" and lands the user on its builder.
  await page.goto('/forms');
  const formCard = page.locator('.card[data-form-id]').first();
  await expect(formCard).toContainText(FORM_TITLE);
  await Promise.all([
    page.waitForURL(/\/forms\/\d+$/),
    formCard.locator('button[data-action=duplicate-form]').click(),
  ]);
  await expect(page.locator('input[data-form-title]')).toHaveValue(FORM_TITLE_COPY);

  // List shows both the original and the copy now.
  await page.goto('/forms');
  await expect(page.locator('.card[data-form-id]')).toHaveCount(2);

  // Custom thank-you message: open the copy, fill the success-message textarea,
  // save, submit publicly, verify the override (not the default) is shown.
  // The card-level click handler ignores [data-stop] buttons — click on the
  // form's title (.name) to guarantee we hit the navigation, not a button.
  await page.locator('.card[data-form-id]', { hasText: FORM_TITLE_COPY }).locator('.name').click();
  await page.waitForURL(/\/forms\/\d+$/);
  await expect(page.locator('input[data-form-title]')).toHaveValue(FORM_TITLE_COPY);
  await page.locator('textarea[data-success-message]').fill(CUSTOM_THANKS);
  // Saving an existing form is a fetch-only API call (no navigation). Wait
  // for it to land via the network response so the next GET sees the column.
  await Promise.all([
    page.waitForResponse(r => /\/forms\/\d+$/.test(r.url()) && r.request().method() === 'POST' && r.ok()),
    page.click('button[data-action=save-form]'),
  ]);

  // Submit the copy's public form and confirm the sentinel renders verbatim.
  const copyPublicUrl = (await page.locator('[data-public-url-text]').textContent())?.trim();
  const copyHash = copyPublicUrl!.match(/\/f\/([A-Za-z0-9_-]+)$/)![1];
  await page.goto('/f/' + copyHash);
  await page.fill('input[name=name]', 'Carol');
  await page.waitForTimeout(2500);
  await page.click('button[type=submit]');
  await expect(page.locator('[data-pf-thanks] p')).toHaveText(CUSTOM_THANKS, { timeout: 5000 });
});

