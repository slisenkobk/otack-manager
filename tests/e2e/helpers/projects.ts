import type { Page } from '@playwright/test';

/**
 * Create a project via the modal flow.
 *
 * The standalone `/projects/new` page was retired; project creation is now a
 * `UI.modal()` triggered from any `[data-action="new-project"]` element. The
 * modal mounts under `#modal-root`, has an `input.input` for the name, and
 * its primary action button carries the `submit` class. On success the modal
 * JS POSTs JSON to `/projects` and navigates to `/projects/{id}?tab=overview`.
 *
 * The helper returns the new project id (parsed from the URL). Pass
 * `gotoBoard=true` to also navigate to the kanban board tab afterwards —
 * needed when the test wants to interact with `.kanban-col` selectors.
 */
export async function createProject(
  page: Page,
  name: string,
  opts: { gotoBoard?: boolean } = {},
): Promise<number> {
  await page.goto('/projects');
  await page.click('[data-action="new-project"]');
  const modal = page.locator('.modal').last();
  await modal.waitFor({ state: 'visible' });
  await modal.locator('input.input').first().fill(name);
  await Promise.all([
    page.waitForURL(/\/projects\/\d+/),
    modal.locator('button.submit').click(),
  ]);
  const m = page.url().match(/\/projects\/(\d+)/);
  const id = m ? parseInt(m[1], 10) : 0;
  if (opts.gotoBoard) {
    await page.goto('/projects/' + id);
  }
  return id;
}
