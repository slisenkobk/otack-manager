import { api, UI } from './ui.js';

// SQLite → MySQL migration wizard. Three actions:
//   1. Test connection — validates the form against the target MySQL.
//      Enables the Start button on success.
//   2. Start migration — POSTs the form; the server runs the synchronous
//      copy. On success the .env block is shown.
//   3. Verify env — re-opens the connection from .env after the operator
//      pastes + reloads the file; reports whether the app sees MySQL.

const root = document.querySelector('[data-db-migrate-wizard]');
if (root && root.dataset.currentDriver === 'sqlite') {
  const form = root.querySelector('[data-form=connect]');
  const testBtn = root.querySelector('[data-action=test]');
  const runBtn = root.querySelector('[data-action=run]');
  const verifyBtn = root.querySelector('[data-action=verify]');
  const testResult = root.querySelector('[data-test-result]');
  const runResult = root.querySelector('[data-run-result]');
  const envBlock = root.querySelector('[data-env-block]');
  const verifyResult = root.querySelector('[data-verify-result]');

  function configBody() {
    const fd = new FormData(form);
    return new URLSearchParams(fd);
  }

  testBtn.addEventListener('click', async () => {
    testBtn.disabled = true;
    testResult.textContent = '';
    try {
      const res = await fetch('/admin/compass/db-migrate/test', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
        body: configBody(),
      });
      const data = await res.json();
      if (data.ok) {
        testResult.textContent = 'MySQL ' + (data.version || '') + ' OK';
        testResult.style.color = '#1e6f3a';
        runBtn.disabled = false;
      } else {
        testResult.textContent = data.error || 'Connection failed';
        testResult.style.color = '#b00020';
        runBtn.disabled = true;
      }
    } catch (e) {
      UI.toast('Test failed', 'error');
    } finally {
      testBtn.disabled = false;
    }
  });

  runBtn.addEventListener('click', async () => {
    const ok = await UI.confirm(
      runBtn.dataset.confirmBody || 'Run the migration now?',
      {
        title: runBtn.dataset.confirmTitle || 'Confirm migration',
        confirmLabel: runBtn.dataset.confirmLabel || 'Run migration',
        danger: true,
      }
    );
    if (!ok) return;
    runBtn.disabled = true;
    runBtn.replaceChildren();
    const sp = document.createElement('i');
    sp.className = 'fa-solid fa-spinner fa-spin';
    sp.setAttribute('aria-hidden', 'true');
    runBtn.append(sp, ' ' + (runBtn.dataset.runningLabel || 'Migrating…'));
    try {
      const res = await fetch('/admin/compass/db-migrate/start', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '',
        },
        body: configBody(),
      });
      const data = await res.json();
      if (!data.ok) {
        UI.toast(data.error || 'Migration failed', 'error');
        return;
      }
      envBlock.textContent = data.env || '';
      runResult.style.display = '';
      UI.toast('Migration complete — paste the new env vars and reload', 'success');
    } catch (e) {
      UI.toast('Migration failed', 'error');
    }
  });

  verifyBtn.addEventListener('click', async () => {
    verifyBtn.disabled = true;
    verifyResult.textContent = '';
    try {
      const data = await api('/admin/compass/db-migrate/verify');
      verifyResult.textContent = data.message || '';
      verifyResult.style.color = data.ok ? '#1e6f3a' : '#b00020';
    } catch {} finally { verifyBtn.disabled = false; }
  });
}
