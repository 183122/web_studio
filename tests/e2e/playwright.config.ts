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
    // Containers often run without a locale; Chromium then can't use non-ASCII (Thai) download filenames.
    launchOptions: { env: { ...process.env, LANG: 'C.UTF-8', LC_ALL: 'C.UTF-8' } },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
  webServer: [
    {
      // Stand-in for Google APIs + a webhook receiver (no real Google access in CI).
      command: 'node fake-google/server.mjs',
      cwd: fileURLToPath(new URL('.', import.meta.url)),
      url: 'http://127.0.0.1:8002/__state',
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      // The published-site runtime bundle is served by Laravel from public/runtime.
      command: `(cd ${root} && npm run build -w apps/runtime-web) && rm -f ${db} && touch ${db} && DB_CONNECTION=sqlite DB_DATABASE=${db} php artisan migrate:fresh --seed --force && DB_CONNECTION=sqlite DB_DATABASE=${db} SESSION_DRIVER=database QUEUE_CONNECTION=sync MAIL_MAILER=log STUDIO_REGISTER_PER_HOUR=500 ANTHROPIC_API_KEY= STUDIO_PUBLISH_REQUIRE_VERIFIED_EMAIL=false STUDIO_DNS_DRIVER=static STUDIO_DNS_STATIC_FILE=${api}/database/e2e-dns.json STUDIO_TLS_CHECK=assume GOOGLE_FAKE_BASE=http://127.0.0.1:8002 FRONTEND_URL=http://127.0.0.1:4173 STUDIO_EGRESS_ALLOW_PRIVATE=true PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8001`,
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
