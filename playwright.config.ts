import { defineConfig } from '@playwright/test';

// E2E runs against an isolated SQLite DB + uploads dir so specs never touch
// the developer's dev data. The webServer boots `php -S` on a non-dev port
// (8001) with these env vars wired in.
//
// Both `make e2e` and a bare `npx playwright test ...` resolve to this single
// config — previously there were two competing configs (this one + one at
// tests/e2e/playwright.config.ts) and the dev-data leak from running specs
// against port 8000 + the prod DB was a real footgun.
export default defineConfig({
  testDir: './tests/e2e',
  workers: 1,
  fullyParallel: false,
  use: { baseURL: 'http://localhost:8001', trace: 'on-first-retry' },
  webServer: {
    command: 'php -S localhost:8001 -t public public/index.php',
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
    },
  },
});
