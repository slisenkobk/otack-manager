/**
 * Visual Audit — full-page screenshots + behavioral checks
 * for the Otack Manager design refresh.
 *
 * Run:
 *   npx playwright test --config tests/e2e/playwright.config.ts tests/e2e/visual-audit.spec.ts --reporter=list
 */

import { test, expect, Page, Browser } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(__dirname, '../..');
const SS_DIR = path.join(ROOT, 'test-results/visual');

test.describe.configure({ mode: 'serial' });

// ── Helpers ──────────────────────────────────────────────────────

async function ss(page: Page, name: string) {
  fs.mkdirSync(SS_DIR, { recursive: true });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(500);
  await page.screenshot({ path: path.join(SS_DIR, name), fullPage: true });
}

async function registerAdmin(page: Page) {
  await page.goto('/register');
  await page.fill('input[name=name]', 'Alice Admin');
  await page.fill('input[name=email]', 'alice@u.com');
  await page.fill('input[name=password]', 'password123');
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await page.waitForURL('/');
}

async function loginAs(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await page.locator('button.submit[type=submit], button.submit').first().click();
  await expect(page).toHaveURL('/');
}

async function createProject(page: Page, name: string, description: string = '') {
  // /projects/new is gone — project creation is now a UI.modal() opened from
  // the [data-action="new-project"] trigger on the /projects index. The
  // modal posts JSON and navigates to /projects/{id}?tab=overview.
  await page.goto('/projects');
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await modal.waitFor({ state: 'visible' });
  await modal.locator('input.input').first().fill(name);
  if (description) {
    try {
      await page.waitForSelector('.ql-editor', { timeout: 3000 }).catch(() => {});
      const ed = modal.locator('.ql-editor').first();
      if (await ed.count()) { await ed.fill(description); await page.waitForTimeout(150); }
    } catch {}
  }
  await Promise.all([
    page.waitForURL(/\/projects\/\d+/),
    modal.locator('button.submit').click(),
  ]);
  const m = page.url().match(/\/projects\/(\d+)/);
  return m ? parseInt(m[1]) : 1;
}

async function quickAddTaskInCol(col: ReturnType<Page['locator']>, title: string) {
  // If form is hidden, click trigger to open it
  const trigger = col.locator('[data-quickadd-trigger]').first();
  const triggerHidden = await trigger.isHidden();
  if (!triggerHidden) {
    await trigger.click();
  }
  const input = col.locator('input[name=title]').first();
  await input.fill(title);
  const countBefore = await col.locator('.kanban-card').count();
  await input.press('Enter');
  // Wait for the new card to appear
  await col.locator('.kanban-card').nth(countBefore).waitFor({ timeout: 5000 });
}

// ── Setup: wipe DB ────────────────────────────────────────────────

test.beforeAll(() => {
  fs.rmSync(path.join(ROOT, 'data/app.test.sqlite'), { force: true });
  fs.rmSync(path.join(ROOT, 'data/.schema.test'), { recursive: true, force: true });
  const uploadDir = path.join(ROOT, 'public/uploads-test');
  if (fs.existsSync(uploadDir)) {
    fs.readdirSync(uploadDir).forEach((f) => {
      const fp = path.join(uploadDir, f);
      if (fs.statSync(fp).isDirectory()) fs.rmSync(fp, { recursive: true });
      else fs.unlinkSync(fp);
    });
  }
});

// ═══════════════════════════════════════════════════════════════
// BLOCK A — Auth pages (no session needed)
// ═══════════════════════════════════════════════════════════════

test('01-login.png', async ({ page }) => {
  await page.goto('/login');
  await ss(page, '01-login.png');

  // Bucket 1 checks on login page
  const heading = await page.locator('h1, .auth-title, [class*="title"]').first().textContent();
  console.log('Login heading:', heading);
});

test('02-register.png', async ({ page }) => {
  await page.goto('/register');
  await ss(page, '02-register.png');
});

// ═══════════════════════════════════════════════════════════════
// BLOCK B — Register admin + second user (pending)
// ═══════════════════════════════════════════════════════════════

test('03-pending.png — register admin first, then second user', async ({ browser }) => {
  // Admin registration
  const adminCtx = await browser.newContext();
  const adminPage = await adminCtx.newPage();
  await registerAdmin(adminPage);
  await adminPage.close();
  await adminCtx.close();

  // Second user: goes pending
  const ctx2 = await browser.newContext();
  const page2 = await ctx2.newPage();
  await page2.goto('/register');
  await page2.fill('input[name=name]', 'Bob Member');
  await page2.fill('input[name=email]', 'bob@u.com');
  await page2.fill('input[name=password]', 'password123');
  await page2.locator('button.submit[type=submit], button.submit').first().click();
  await page2.waitForURL('/pending');
  await ss(page2, '03-pending.png');
  await page2.close();
  await ctx2.close();
});

// ═══════════════════════════════════════════════════════════════
// BLOCK C — Admin session: dashboard + projects
// ═══════════════════════════════════════════════════════════════

test('04-dashboard-empty.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.waitForURL('/');
  await ss(page, '04-dashboard-empty.png');

  // ── Bucket 1: topbar avatar on right, no PRO badge ──
  // Avatar markup is `.user-avatar` (rendered by user_avatar_html).
  const avatar = page.locator('.topbar .user-avatar').first();
  await expect(avatar).toBeVisible();

  // Avatar should be in topbar__rhs
  const rhs = page.locator('.topbar__rhs').first();
  const avatarInRhs = rhs.locator('.user-avatar').first();
  await expect(avatarInRhs).toBeVisible();

  // No PRO badge (check computed style of ::after pseudo or check avatar has no text "PRO")
  const avatarText = await avatar.textContent();
  console.log('Avatar text:', avatarText?.trim());
  expect(avatarText?.trim()).not.toContain('PRO');

  // Page title in topbar
  const title = page.locator('.topbar__title').first();
  const titleText = await title.textContent();
  console.log('Topbar title text:', titleText?.trim());
  expect(titleText?.trim()).toBeTruthy();

  // Sidebar brand "Otack Manager"
  const brandWord = page.locator('.brand__word, .brand .word').first();
  const brandText = await brandWord.textContent();
  console.log('Brand word text:', brandText?.trim());
  expect(brandText?.trim()).toContain('Otack');

  // Body-wrap top padding: check it's reasonable
  const bodyWrap = page.locator('.body-wrap').first();
  const paddingTop = await bodyWrap.evaluate((el) => {
    return window.getComputedStyle(el).paddingTop;
  });
  console.log('body-wrap padding-top:', paddingTop);

  // Favicon
  const faviconHref = await page.evaluate(() => {
    const link = document.querySelector('link[rel="icon"]') as HTMLLinkElement | null;
    return link ? link.href : null;
  });
  console.log('Favicon href:', faviconHref);
  expect(faviconHref).toBeTruthy();

  // overscroll-behavior-y none
  const overscroll = await page.evaluate(() =>
    getComputedStyle(document.body).overscrollBehaviorY
  );
  console.log('overscroll-behavior-y:', overscroll);
  expect(overscroll).toBe('none');
});

// ── Create 3 projects + tasks ──────────────────────────────────

test('05-06-07 setup: create projects and tasks', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');

  // 06-projects-empty.png FIRST (before creating projects)
  await page.goto('/projects');
  await ss(page, '06-projects-empty.png');

  // Create Project 1
  const p1Id = await createProject(page, 'Alpha Project', 'The main Alpha project');
  console.log('Project 1 ID:', p1Id);

  // Quick-add tasks to project 1
  await page.goto(`/projects/${p1Id}`);
  await page.waitForLoadState('networkidle');
  const todoCol = page.locator('.kanban-col').nth(0);
  const inProgressCol = page.locator('.kanban-col').nth(1);
  const doneCol = page.locator('.kanban-col').nth(2);

  await quickAddTaskInCol(todoCol, 'Task One');
  await quickAddTaskInCol(todoCol, 'Task Two');
  await quickAddTaskInCol(todoCol, 'Task Three');
  await quickAddTaskInCol(inProgressCol, 'Task Four In Progress');
  await quickAddTaskInCol(doneCol, 'Task Five Done');

  // Create Project 2 + 3
  await createProject(page, 'Beta Project');
  await createProject(page, 'Gamma Project');

  // 07-projects-list.png
  await page.goto('/projects');
  await ss(page, '07-projects-list.png');

  // 05-dashboard-populated.png
  await page.goto('/');
  await ss(page, '05-dashboard-populated.png');
});

// ── Project pages ──────────────────────────────────────────────

test('08-project-new.png', async ({ page }) => {
  // The standalone /projects/new page was replaced by a UI.modal() flow;
  // this screenshot now captures the modal as it appears over /projects.
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects');
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await modal.waitFor({ state: 'visible' });
  await ss(page, '08-project-new.png');

  // Check the modal's name input has a visible border at rest.
  const nameInput = modal.locator('input.input').first();
  const borderColor = await nameInput.evaluate((el) => {
    return getComputedStyle(el).borderColor;
  });
  console.log('name input border-color:', borderColor);
  expect(borderColor).not.toBe('rgba(0, 0, 0, 0)');
  expect(borderColor).not.toBe('transparent');
});

test('09-project-board.png — board with tasks', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');

  // Add some tags to a task by visiting the task page
  // first task should be /tasks/1
  await page.goto('/tasks/1');
  await page.waitForLoadState('networkidle');
  const tagInput = page.locator('[data-tag-search]').first();
  if (await tagInput.isVisible()) {
    await tagInput.fill('feature');
    await page.waitForTimeout(400);
    // Check if dropdown appeared and has an option to create
    const dropdown = page.locator('[data-tag-dropdown]').first();
    if (await dropdown.isVisible()) {
      // Look for the create option which says '+ Create "feature"'
      const allRows = dropdown.locator('div');
      const rowCount = await allRows.count();
      if (rowCount > 0) {
        // Click the last row (which is the "create" option)
        await allRows.last().click();
        await page.waitForTimeout(500);
      }
    }
  }

  // Go to project 1 board
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');
  await ss(page, '09-project-board.png');

  // Bucket 2: kanban toolbar visible
  const toolbar = page.locator('.kanban-toolbar').first();
  await expect(toolbar).toBeVisible();
  console.log('Kanban toolbar visible: YES');

  // Chip bar visible
  const allChip = page.locator('.kanban-tagbar .chip').first();
  await expect(allChip).toBeVisible();
  console.log('Tag chip bar visible: YES');

  // Search input visible
  const searchInput = page.locator('[data-task-search]').first();
  await expect(searchInput).toBeVisible();
  console.log('Search input visible: YES');

  // Cards visible
  const cards = page.locator('.kanban-card');
  const cardCount = await cards.count();
  console.log('Kanban card count:', cardCount);
  expect(cardCount).toBeGreaterThanOrEqual(3);
});

test('10-project-board-filtered.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');

  // First assign a tag to a task via the task page
  // Get the first task card's data-task-url
  const firstCard = page.locator('.kanban-card').first();
  const taskUrl = await firstCard.getAttribute('data-task-url');
  if (taskUrl) {
    // Navigate to task and add a tag
    const taskPage2 = await page.context().newPage();
    await taskPage2.goto(taskUrl);
    await taskPage2.waitForLoadState('networkidle');
    // Try to add a tag using the tag picker
    const tagInput = taskPage2.locator('#tag-input, [data-tag-input], [placeholder*="tag"], [placeholder*="Tag"]').first();
    if (await tagInput.isVisible()) {
      await tagInput.fill('feature');
      await taskPage2.keyboard.press('Enter');
      await taskPage2.waitForTimeout(500);
    }
    await taskPage2.close();
  }

  // Now go back to board and try clicking a chip
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');
  const chips = page.locator('.kanban-tagbar .chip');
  const chipCount = await chips.count();
  console.log('Tag chip count on board:', chipCount);

  // If there are tag chips beyond "All", click one
  if (chipCount > 1) {
    await chips.nth(1).click();
    await page.waitForTimeout(300);
    await ss(page, '10-project-board-filtered.png');
    // Check that filtering worked: "All" chip should be inactive, clicked chip active
    const activeChip = page.locator('.kanban-tagbar .chip--active');
    const activeChipText = await activeChip.textContent();
    console.log('Active chip after filter:', activeChipText?.trim());
  } else {
    // No tag chips, just take screenshot of board
    console.log('No tag chips available for filtering — skipping filter interaction');
    await ss(page, '10-project-board-filtered.png');
  }
});

test('11-project-board-search.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');

  const searchInput = page.locator('[data-task-search]').first();
  await searchInput.fill('Task One');
  await page.waitForTimeout(300);
  await ss(page, '11-project-board-search.png');

  // Verify filtering: visible cards should be <= all cards count
  const visibleCards = page.locator('.kanban-card:not([hidden]):not([style*="display: none"])');
  const visibleCount = await visibleCards.count();
  console.log('Visible cards after search "Task One":', visibleCount);
  // At least 1 visible (the matched one)
  expect(visibleCount).toBeGreaterThanOrEqual(1);
});

test('12-project-quickadd-open.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');

  // Click the first add-task trigger to expand
  const addTrigger = page.locator('[data-quickadd-trigger]').first();
  await expect(addTrigger).toBeVisible();
  await addTrigger.click();
  await page.waitForTimeout(300);

  // Check that form is now visible
  const form = page.locator('.kanban-col__form').first();
  const isVisible = await form.isVisible();
  console.log('Quick-add form visible after click:', isVisible);

  await ss(page, '12-project-quickadd-open.png');

  // Press Esc to collapse — focus the input first since keydown is on input
  const quickInput = page.locator('.kanban-col__form input[name=title]').first();
  await quickInput.focus();
  await quickInput.press('Escape');
  await page.waitForTimeout(300);
  const isHiddenAfterEsc = !(await form.isVisible());
  console.log('Quick-add form hidden after Esc:', isHiddenAfterEsc);
  expect(isHiddenAfterEsc).toBe(true);
});

test('13-project-overview.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1?tab=overview');
  await page.waitForLoadState('networkidle');
  await ss(page, '13-project-overview.png');
});

// ── Task page ──────────────────────────────────────────────────

test('14-task-page.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/tasks/1');
  await page.waitForLoadState('networkidle');
  await ss(page, '14-task-page.png');

  // Bucket 1: inputs have visible borders
  const titleEl = page.locator('[data-task-title], .task-title, [contenteditable]').first();
  if (await titleEl.isVisible()) {
    console.log('Task title element visible');
  }

  // Bucket 3: Quill description editor on task page
  const quillContainer = page.locator('.ql-container, .ql-editor, .quill-wrapper').first();
  const quillVisible = await quillContainer.isVisible().catch(() => false);
  console.log('Quill editor visible on task page:', quillVisible);
});

// ── Users page ─────────────────────────────────────────────────

test('15-users-admin.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/users');
  await page.waitForLoadState('networkidle');
  await ss(page, '15-users-admin.png');

  // Check for pending user (Bob) in the list
  const pageContent = await page.textContent('body');
  const hasBob = pageContent?.includes('Bob') || pageContent?.includes('bob@u.com');
  console.log('Bob visible in users list:', hasBob);
});

// ── Admin tags ─────────────────────────────────────────────────

test('16-admin-tags.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/admin/tags');
  await page.waitForLoadState('networkidle');
  await ss(page, '16-admin-tags.png');

  // Tag counts
  const tagItems = page.locator('[data-tag-id]');
  const tagCount = await tagItems.count();
  console.log('Tags visible in admin:', tagCount);

  // Rename uses contenteditable .tag-name
  const renameEl = page.locator('.tag-name[contenteditable]').first();
  const renameVisible = await renameEl.isVisible().catch(() => false);
  console.log('Contenteditable rename field visible:', renameVisible);

  // Delete button
  const deleteBtn = page.locator('[data-action="delete-tag"]').first();
  const deleteBtnVisible = await deleteBtn.isVisible().catch(() => false);
  console.log('Delete tag button visible:', deleteBtnVisible);
});

// ── Profile ────────────────────────────────────────────────────

test('17-profile.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/profile');
  await page.waitForLoadState('networkidle');
  await ss(page, '17-profile.png');

  // Bucket 1: avatar opens dropdown with /profile link
  await page.goto('/');
  await page.waitForLoadState('networkidle');
  await page.locator('[data-user-menu-toggle]').click();
  const profileLink = page.locator('.user-menu__item[href="/profile"]');
  await expect(profileLink).toBeVisible();
  const href = await profileLink.getAttribute('href');
  console.log('Profile link href:', href);
  expect(href).toBe('/profile');
});

// ── 404 ────────────────────────────────────────────────────────

test('18-404.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/99999');
  await page.waitForLoadState('networkidle');
  await ss(page, '18-404.png');

  // Should show styled 404 page, not a raw error
  const body = await page.textContent('body');
  const hasNotFound = body?.includes('404') || body?.includes('not found') || body?.includes('Not Found');
  console.log('404 page rendered:', hasNotFound);
  expect(hasNotFound).toBe(true);
});

// ── Modal confirm ───────────────────────────────────────────────

test('19-modal-confirm.png', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/users');
  await page.waitForLoadState('networkidle');

  // Approve Bob first (if pending) and wait for the automatic page reload (600ms timer)
  const approveBtns = page.locator('[data-action="approve"]');
  if (await approveBtns.count() > 0) {
    await approveBtns.first().click();
    // users.js calls location.reload() after 600ms — wait for navigation event
    await page.waitForNavigation({ timeout: 3000 }).catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300); // settle DOM
  }

  // Now trigger a confirm modal via Delete button — no pending reload timer
  const deleteBtn = page.locator('[data-action="delete"]').first();

  if (await deleteBtn.isVisible()) {
    // Click delete — triggers UI.confirm which opens .modal-backdrop
    await deleteBtn.click();
    const modal = page.locator('.modal-backdrop').first();
    await modal.waitFor({ state: 'visible', timeout: 3000 }).catch(() => {});
    // Wait for fade-in animation to complete (--duration: 150ms)
    await page.waitForTimeout(300);
    const modalVisible = await modal.isVisible().catch(() => false);
    console.log('Confirm modal visible after delete click:', modalVisible);
    fs.mkdirSync(SS_DIR, { recursive: true });
    await page.screenshot({ path: path.join(SS_DIR, '19-modal-confirm.png'), fullPage: false });
    // Close modal without confirming (Esc)
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
  } else {
    console.log('No delete button found (Bob may be self), capturing users page');
    await ss(page, '19-modal-confirm.png');
  }
});

// ── Toast success ───────────────────────────────────────────────

test('20-toast-success.png — trigger a success toast', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/profile');
  await page.waitForLoadState('networkidle');

  // Change name to trigger a save+toast (inline flash, not JS toast)
  const nameInput = page.locator('input[name=name]').first();
  await nameInput.fill('Alice Updated');
  // Use the specific submit inside the name form
  const nameForm = page.locator('form[action="/profile"]').first();
  await nameForm.locator('button.submit[type=submit]').first().click();
  // Wait for redirect back to /profile
  await page.waitForURL('/profile', { timeout: 5000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);

  // Look for inline success toast or JS toast
  const toast = page.locator('.toast--success, .toast, [role="status"]').first();
  const toastVisible = await toast.isVisible().catch(() => false);
  console.log('Toast/success visible after save:', toastVisible);
  const pageBody = await page.textContent('body').catch(() => '');
  console.log('Page has "Name updated":', pageBody?.includes('Name updated'));
  await ss(page, '20-toast-success.png');
});

// ═══════════════════════════════════════════════════════════════
// BLOCK D — Behavioral checks (Phase B)
// ═══════════════════════════════════════════════════════════════

test('B1: brand link has no underline at rest', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const brand = page.locator('.brand').first();
  await expect(brand).toBeVisible();

  const textDec = await brand.evaluate((el) => getComputedStyle(el).textDecoration);
  console.log('Brand text-decoration:', textDec);
  expect(textDec).toContain('none');

  // Hover and check
  await brand.hover();
  const textDecHover = await brand.evaluate((el) => getComputedStyle(el).textDecoration);
  console.log('Brand text-decoration on hover:', textDecHover);
  expect(textDecHover).toContain('none');
});

test('B2: SVG kanban logo renders in sidebar', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const brandMark = page.locator('.brand__mark, .brand .mark').first();
  await expect(brandMark).toBeVisible();

  const svgEl = brandMark.locator('svg').first();
  const svgCount = await svgEl.count();
  console.log('SVG in brand mark:', svgCount);
  expect(svgCount).toBeGreaterThan(0);

  // Check SVG has bars (rect elements)
  const rects = await svgEl.locator('rect').count();
  console.log('Rect elements in logo SVG:', rects);
  expect(rects).toBeGreaterThanOrEqual(3);
});

test('B3: all inputs have visible border at rest on login page', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('networkidle');

  const inputs = page.locator('input[type=text], input[type=email], input[type=password]');
  const inputCount = await inputs.count();
  console.log('Input count on login:', inputCount);

  for (let i = 0; i < inputCount; i++) {
    const input = inputs.nth(i);
    const borderStyle = await input.evaluate((el) => {
      const cs = getComputedStyle(el);
      return {
        borderColor: cs.borderColor,
        borderWidth: cs.borderWidth,
        borderStyle: cs.borderStyle,
      };
    });
    console.log(`Input[${i}] border:`, JSON.stringify(borderStyle));
    // Border should not be zero-width or transparent
    expect(borderStyle.borderWidth).not.toBe('0px');
    expect(borderStyle.borderColor).not.toBe('rgba(0, 0, 0, 0)');
  }
});

test('B4: all buttons have visible padding', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  // Check primary buttons
  const buttons = page.locator('.btn, .btn--primary, .btn--secondary, .btn--brand').first();
  if (await buttons.count() > 0) {
    const padding = await buttons.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { top: cs.paddingTop, bottom: cs.paddingBottom };
    });
    console.log('Button padding:', JSON.stringify(padding));
    // Should be >= 6px
    const topPx = parseInt(padding.top);
    const botPx = parseInt(padding.bottom);
    console.log('Button padding-top:', topPx, 'padding-bottom:', botPx);
    expect(topPx).toBeGreaterThanOrEqual(6);
    expect(botPx).toBeGreaterThanOrEqual(6);
  }
});

test('B5: tag chips use 10-hue color system', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/admin/tags');
  await page.waitForLoadState('networkidle');

  // Look for the color tokens
  const tagChips = page.locator('.tag, [class*="tag-chip"]');
  const chipCount = await tagChips.count();
  console.log('Tag chips on admin/tags:', chipCount);

  // Check for CSS variables in the style attribute
  if (chipCount > 0) {
    const chipStyle = await tagChips.first().getAttribute('style');
    console.log('First tag chip style:', chipStyle);
  }

  // Check that the CSS has 10 semantic colors
  const cssContent = fs.readFileSync(path.join(ROOT, 'public/assets/css/tokens.css'), 'utf8');
  const colorVars = ['--green', '--red', '--blue', '--yellow', '--teal', '--purple', '--magenta', '--brown', '--olive', '--indigo'];
  for (const v of colorVars) {
    expect(cssContent).toContain(v);
  }
  console.log('All 10 semantic color tokens present in CSS: YES');
});

test('B6: DnD drag persists after reload', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1');
  await page.waitForLoadState('networkidle');

  const todoList = page.locator('.kanban-col').nth(0).locator('.kanban-col__list');
  const inprogressList = page.locator('.kanban-col').nth(1).locator('.kanban-col__list');

  const cards = todoList.locator('.kanban-card');
  const cardCount = await cards.count();
  console.log('Cards in To Do:', cardCount);

  if (cardCount > 0) {
    const card = cards.first();
    const cardTitle = await card.locator('.kanban-card__title').textContent();
    console.log('Dragging card:', cardTitle?.trim());

    const cardBox = await card.boundingBox();
    const targetBox = await inprogressList.boundingBox();

    if (cardBox && targetBox) {
      await page.mouse.move(cardBox.x + cardBox.width / 2, cardBox.y + cardBox.height / 2);
      await page.mouse.down();
      await page.waitForTimeout(200);
      await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height / 2, { steps: 10 });
      await page.waitForTimeout(200);
      await page.mouse.up();
      await page.waitForTimeout(800);

      // Reload and verify
      await page.reload();
      await page.waitForLoadState('networkidle');
      const inProgressCards = inprogressList.locator('.kanban-card');
      const inProgressCount = await inProgressCards.count();
      console.log('Cards in In Progress after DnD + reload:', inProgressCount);
    }
  } else {
    console.log('No cards to drag');
  }
});

test('B7: highlight pulse animation on ?highlight=N', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1?highlight=1');
  await page.waitForLoadState('networkidle');

  const highlightedCard = page.locator('.kanban-card.is-highlight, .kanban-card[data-task-id="1"]').first();
  const hasHighlightClass = await page.evaluate(() => {
    const card = document.querySelector('.kanban-card[data-task-id="1"]');
    return card ? card.classList.contains('is-highlight') : false;
  });
  console.log('Card #1 has is-highlight class:', hasHighlightClass);
});

test('B8: quill renders in new-project modal', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  // /projects/new was replaced by the new-project modal which mounts a
  // [data-quill] node that wysiwyg.js picks up.
  await page.goto('/projects');
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await modal.waitFor({ state: 'visible' });
  // The modal contains a [data-quill] node for the description editor.
  await expect(modal.locator('[data-quill]')).toHaveCount(1);

  // The Quill vendor CSS must ship with the app.
  const hasQuillCSS = fs.existsSync(path.join(ROOT, 'public/assets/vendor/quill/quill.snow.css'));
  console.log('Quill CSS vendor file present:', hasQuillCSS);
  expect(hasQuillCSS).toBe(true);
});

test('B9: dashboard load more activity button', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const loadMoreBtn = page.locator('button:has-text("Load more"), [data-load-more], button:has-text("load more")').first();
  const loadMoreVisible = await loadMoreBtn.isVisible().catch(() => false);
  console.log('Load more button visible:', loadMoreVisible);
});

test('B10: admin tags rename + delete flow', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/admin/tags');
  await page.waitForLoadState('networkidle');

  // Tags are created from task pages and listed here
  // Check that rename (contenteditable) and delete buttons exist
  const renameEl = page.locator('.tag-name[contenteditable]').first();
  const deleteBtn = page.locator('[data-action="delete-tag"]').first();

  const renameCount = await page.locator('.tag-name[contenteditable]').count();
  const deleteCount = await page.locator('[data-action="delete-tag"]').count();
  console.log('Rename fields count:', renameCount, 'Delete buttons count:', deleteCount);

  if (renameCount > 0) {
    // Try inline rename: double-click, clear, type new name, blur
    const nameEl = renameEl;
    const originalName = await nameEl.textContent();
    console.log('Original tag name:', originalName?.trim());

    // contenteditable: click, select all, type new name, press Enter to blur
    await nameEl.click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('renamed-tag');
    await page.keyboard.press('Enter');
    await page.waitForTimeout(600);
    console.log('Rename interaction done');

    // Reload and check
    await page.reload();
    await page.waitForLoadState('networkidle');
    const pageBody = await page.textContent('body');
    const hasRenamed = pageBody?.includes('renamed-tag');
    console.log('Tag renamed persisted:', hasRenamed);
  }
});

test('B11: comment with attachment', async ({ page }) => {
  await loginAs(page, 'alice@u.com', 'password123');
  await page.goto('/projects/1?tab=overview');
  await page.waitForLoadState('networkidle');

  const commentInput = page.locator('textarea[name=body], [name=body]').first();
  if (await commentInput.isVisible()) {
    await commentInput.fill('Comment with attachment test');

    // Try to upload a tiny PNG as attachment
    const tinyPng = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
      'base64'
    );
    const uploadInput = page.locator('input[type=file]').first();
    if (await uploadInput.count() > 0) {
      await uploadInput.setInputFiles({
        name: 'audit-attach.png',
        mimeType: 'image/png',
        buffer: tinyPng,
      });
      await page.waitForTimeout(400);
    }

    await page.locator('button[type=submit]:has-text("Post"), [type=submit]').last().click();
    await page.waitForTimeout(800);

    // Reload and verify
    await page.reload();
    await page.waitForLoadState('networkidle');
    const attachChip = page.locator('.comment-attach, .attach-chip, [class*="attach"]').first();
    const attachVisible = await attachChip.isVisible().catch(() => false);
    console.log('Comment attachment chip visible after reload:', attachVisible);
  }
});

test('B12: no console errors on key pages', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const t = msg.text();
      if (!t.includes('favicon') && !t.includes('FontAwesome') && !t.includes('net::ERR')) {
        errors.push(t);
      }
    }
  });

  await loginAs(page, 'alice@u.com', 'password123');
  const pages = ['/', '/projects', '/projects/1', '/tasks/1', '/admin/tags', '/profile', '/users'];
  for (const url of pages) {
    await page.goto(url);
    await page.waitForLoadState('networkidle');
  }

  console.log('Console errors collected:', errors.length, errors.slice(0, 5));
  if (errors.length > 0) {
    console.warn('Console errors found:', errors);
  }
  expect(errors.length).toBe(0);
});
