import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.',
  workers: 1,
  fullyParallel: false,
  use: { baseURL: 'http://localhost:8000', trace: 'on-first-retry' },
  webServer: {
    command: 'php -S localhost:8000 -t public public/index.php',
    cwd: '../..',
    url: 'http://localhost:8000',
    reuseExistingServer: !process.env.CI,
  },
});
