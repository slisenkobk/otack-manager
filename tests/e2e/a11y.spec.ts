import { test, expect } from '@playwright/test';

// V-3: a focus trap inside UI.modal so Tab can't escape back to the
// underlying page. Uses /ui-sandbox to avoid coupling to login/setup.
test.describe('a11y modal focus trap', () => {
  test('Tab from last button cycles back to first', async ({ page }) => {
    await page.goto('/ui-sandbox');
    await page.waitForFunction(() => !!(window as any).UI);

    // Open a modal with two action buttons. The close (X) button counts
    // as a focusable too, so the trap operates over [close, cancel, ok].
    await page.evaluate(() => {
      (window as any).UI.modal({
        title: 'Trap test',
        body: '<p>body</p>',
        actions: [
          { label: 'Cancel', variant: 'btn--ghost' },
          { label: 'OK', variant: 'submit' },
        ],
      });
    });
    const modal = page.locator('.modal').last();
    await modal.waitFor({ state: 'visible' });

    // First focusable is the close button — gets initial focus.
    await expect(modal.locator('.modal-close')).toBeFocused();

    // Focus the last action button, then Tab and expect to cycle back.
    await modal.locator('button.submit').focus();
    await expect(modal.locator('button.submit')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(modal.locator('.modal-close')).toBeFocused();

    // Shift+Tab from first should land on last.
    await page.keyboard.press('Shift+Tab');
    await expect(modal.locator('button.submit')).toBeFocused();
  });
});
