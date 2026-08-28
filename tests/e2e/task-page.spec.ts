import { test, expect } from '@playwright/test';
import fs from 'fs';
import { execFileSync } from 'child_process';
import path from 'path';
import { createProject } from './helpers/projects';
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

  const projectId = await createProject(page, 'Task Page Test', { gotoBoard: true });
  const projectUrl = '/projects/' + projectId;

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

  // Change column to "Done". The column control is no longer a <select>;
  // it's a custom-select widget: click the button to open the popup, then
  // click the Done option. JS writes the hidden input and POSTs to the API.
  const colSelect = page.locator('.custom-select').filter({
    has: page.locator('[data-field=column_id]'),
  }).first();
  await colSelect.locator('.custom-select__btn').click();
  await colSelect.locator('.custom-select__opt', { hasText: 'Done' }).click();
  // wait briefly for AJAX
  await page.waitForTimeout(500);

  // Go back to project board
  await page.goto(projectUrl);
  // Done is the third column
  await expect(page.locator('.kanban-col').nth(2).locator('.kanban-card')).toContainText('Task one');
});

// The render end of the stored-XSS chain. Every write path now sanitises, so
// the only way to get a raw payload into the row is to write it directly —
// which is exactly the scenario the render-site defence exists for ("a single
// missed write path can never become an XSS"). Poison the DB behind the app's
// back, then prove the page neither emits nor executes the payload.
test('a poisoned task description is neutralised at render', async ({ page }) => {
  // One probe per injection context, so a surviving <script>'s *text* (which
  // the sanitiser deliberately keeps, inert, when it unwraps the element) is
  // never mistaken for a surviving attribute or href.
  const PAYLOAD =
    '<p>legit body</p>' +
    '<div><img src=x onerror="window.__xssAttrProbe=1"></div>' +
    '<script>window.__xssScriptProbe=1</script>' +
    '<a href="javascript:window.__xssHrefProbe=1">x</a>';

  // Log in as the admin registered by the first test in this serial file.
  await page.goto('/login');
  await page.fill('input[name=email]', 'a@t.com');
  await page.fill('input[name=password]', 'password123');
  await page.click('button.submit[type=submit]');

  await createProject(page, 'XSS Render Test', { gotoBoard: true });
  const firstCol = page.locator('.kanban-col').first();
  await firstCol.locator('[data-quickadd-trigger]').click();
  const todoInput = firstCol.locator('input[name=title]');
  await todoInput.fill('XSS probe');
  await todoInput.press('Enter');
  await expect(page.locator('.kanban-card')).toHaveCount(1);
  const taskId = await page.locator('.kanban-card').first().getAttribute('data-task-id');
  expect(taskId).toBeTruthy();

  execFileSync('php', [
    '-r',
    '$pdo = new PDO("sqlite:" . $argv[1]);'
    + '$pdo->prepare("UPDATE tasks SET description = ? WHERE id = ?")->execute([$argv[2], (int)$argv[3]]);',
    '--',
    path.join(ROOT, 'data/app.test.sqlite'),
    PAYLOAD,
    String(taskId),
  ]);

  // 1. Server output: the raw HTML the browser is handed carries no live
  //    payload. page.request shares the session cookie and runs no JS, so this
  //    is the server's bytes, not a DOM Quill has already rewritten.
  const res = await page.request.get('/tasks/' + taskId);
  expect(res.status()).toBe(200);
  const body = await res.text();
  expect(body).not.toContain('__xssAttrProbe');            // onerror= stripped whole
  // `<img>` itself is *on* the rich allow-list task descriptions now use, so
  // asserting the element is gone no longer states anything true: what the
  // sanitiser strips here is `src=x` (not http(s)/data:image) and `onerror=`,
  // leaving a bare, inert `<img>`. Assert that instead. Pin the exact
  // sanitised shape this payload produces (verified against
  // HtmlSanitizer::cleanRich() directly, not assumed)...
  expect(body).toContain('<div><img></div>');
  // ...and keep the negative pattern alongside it: unlike the positive
  // assertion, it also catches an attribute leak on ANY <img> that might
  // appear elsewhere in the page, not just this payload's. Note this is a
  // narrower net than `not.toContain('<img')` used to be (a bare `<img>`
  // now passes it, where it used to fail) — it's not a superset of the old
  // assertion, just a targeted attribute check the positive assertion above
  // doesn't fully subsume on its own.
  expect(body).not.toMatch(/<img\s[^>]/);                  // no attribute survived on any img
  expect(body).not.toContain('javascript:__xssHrefProbe');
  expect(body).not.toContain('javascript:window');         // href scheme rejected
  expect(body).not.toContain('<script>window.__xssScriptProbe'); // element gone
  expect(body).toContain('legit body');                    // allow-listed markup survives

  // 2. Nothing executes in a real browser either — including via wysiwyg.js,
  //    which seeds Quill with `editor.root.innerHTML = <hidden input value>`.
  //    Note this check alone would NOT have caught the bug: the app's
  //    nonce-only CSP (`script-src 'self'`, see public/index.php) already
  //    blocks inline handlers and `javascript:` URIs, so the raw payload was
  //    emitted but never ran. The byte-level assertions above are what pin the
  //    fix; this one guards against a CSP regression stacking on a sanitiser
  //    regression.
  await page.goto('/tasks/' + taskId);
  await expect(page.locator('.task-description-rendered')).toContainText('legit body');
  for (const probe of ['__xssAttrProbe', '__xssScriptProbe', '__xssHrefProbe']) {
    expect(await page.evaluate((m) => (window as any)[m], probe)).toBeUndefined();
  }
});
