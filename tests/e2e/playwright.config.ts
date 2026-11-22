import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const api = `${root}apps/platform-api`;
const db = `${api}/database/e2e.sqlite`;

/**
 * Runs against real servers: Laravel (fresh SQLite e2e database, seeded) + the built studio
 * served by `vite preview` (which proxies /api to Laravel, same as production nginx).
 */
export default defineConfig({
  testDir: './specs',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:4173',
    locale: 'th-TH',
    trace: 'off',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
  webServer: [
    {
      command: `rm -f ${db} && touch ${db} && DB_CONNECTION=sqlite DB_DATABASE=${db} php artisan migrate:fresh --seed --force && DB_CONNECTION=sqlite DB_DATABASE=${db} SESSION_DRIVER=database QUEUE_CONNECTION=sync MAIL_MAILER=log STUDIO_REGISTER_PER_HOUR=500 ANTHROPIC_API_KEY= PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8001`,
      cwd: api,
      url: 'http://127.0.0.1:8001/api/health',
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: 'npm run build && STUDIO_API_URL=http://127.0.0.1:8001 npx vite preview --host 127.0.0.1 --port 4173 --strictPort',
      cwd: `${root}apps/studio-web`,
      url: 'http://127.0.0.1:4173/login',
      reuseExistingServer: false,
      timeout: 180_000,
    },
  ],
});
