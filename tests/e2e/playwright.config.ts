import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  workers: 1,
  fullyParallel: false,
  use: { baseURL: 'http://localhost:8001', trace: 'on-first-retry' },
  webServer: {
    command: 'php -S localhost:8001 -t public public/index.php',
    cwd: '../..',
    url: 'http://localhost:8001',
    reuseExistingServer: !process.env.CI,
    env: {
      DB_PATH: 'data/app.test.sqlite',
      SCHEMA_PATH: 'data/.schema.test',
      UPLOAD_DIR: 'public/uploads-test',
      APP_URL: 'http://localhost:8001',
      SEED_DEFAULT_ADMIN_EMAIL: '',
      SEED_DEFAULT_ADMIN_PASSWORD_HASH: '',
    },
  },
});
