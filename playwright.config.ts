import { defineConfig, devices } from '@playwright/test';

// E2E runs against an isolated SQLite DB + uploads dir so specs never touch
// the developer's dev data. The webServer boots `php -S` on a non-dev port
// (8001) with these env vars wired in.
//
// Both `make e2e` and a bare `npx playwright test ...` resolve to this single
// config — previously there were two competing configs (this one + one at
// tests/e2e/playwright.config.ts) and the dev-data leak from running specs
// against port 8000 + the prod DB was a real footgun.
//
// Cross-browser matrix: each project runs the full suite sequentially against
// the same shared webServer + SQLite test DB. `workers: 1` + `fullyParallel:
// false` are deliberate — the test DB is single-threaded and concurrent
// writes would race. Webkit + firefox were added in 9.1e Task 7 to prove the
// nonce-only CSP (Task 5) works across browsers.
export default defineConfig({
  testDir: './tests/e2e',
  workers: 1,
  fullyParallel: false,
  use: { baseURL: 'http://localhost:8001', trace: 'on-first-retry' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit',   use: { ...devices['Desktop Safari'] } },
    { name: 'firefox',  use: { ...devices['Desktop Firefox'] } },
  ],
  webServer: {
    // -d max_execution_time=0: `php -S` is single-process — a slow
    // password_verify() call (system/Auth/PasswordHasher.php) that exceeds
    // PHP's default 30s max_execution_time fatals the whole server, and
    // every remaining test in the run then fails with connection-refused.
    // Scoped to this test harness only; production PHP-FPM config is
    // untouched, and the hashing cost in PasswordHasher.php is unchanged.
    command: 'php -d max_execution_time=0 -S localhost:8001 -t public public/index.php',
    url: 'http://localhost:8001',
    reuseExistingServer: !process.env.CI,
    env: {
      DB_PATH: 'data/app.test.sqlite',
      SCHEMA_PATH: 'data/.schema.test',
      UPLOAD_DIR: 'public/uploads-test',
      APP_URL: 'http://localhost:8001',
      SEED_DEFAULT_ADMIN_EMAIL: '',
      SEED_DEFAULT_ADMIN_PASSWORD_HASH: '',
      LOGIN_HASH: '',
      // Install wizard (Task #10): isolate the ConfigStore overlay to a
      // test-only file so the developer's data/config.json is never touched
      // by Playwright runs. The wizard's redirect gate (INSTALL_GATE_ENABLED)
      // is opted in PER-SPEC via a marker file (data/install-gate-on.test) —
      // install.spec.ts creates it in beforeAll, removes in afterAll. Other
      // specs leave the marker absent and the gate is inert.
      INSTALL_GATE_FLAG_FILE: 'data/install-gate-on.test',
      CONFIG_STORE_PATH: 'data/config.test.json',
    },
  },
});
