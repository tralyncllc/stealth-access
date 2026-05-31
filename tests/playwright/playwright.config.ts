import { defineConfig } from '@playwright/test';
import * as dotenv from 'dotenv';
import * as path from 'path';

dotenv.config({ path: path.resolve(__dirname, '.env') });

const required = ['WP_BASE_URL', 'WP_ADMIN_USER', 'WP_ADMIN_PASS'];
for (const name of required) {
  if (!process.env[name]) {
    throw new Error(`Required env var missing: ${name} (see .env.example)`);
  }
}

export default defineConfig({
  testDir: './tests',
  outputDir: './playwright-results/output',
  fullyParallel: false,
  workers: 1, // Tests mutate shared site state — must run serially.
  // CI surfaces an intermittent Chromium autofill quirk on
  // loginViaWpLogin: after some accumulated browser-credentials state,
  // .fill('#user_login', ...) gets overwritten by the auto-saved
  // password before submission. Retry once so the auth helper gets a
  // clean slate. Two consecutive failures still surface as a real
  // failure. Local runs (with CI=false) keep retries: 0 for fast
  // fail-fast feedback.
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-results/html', open: 'never' }],
    ['json', { outputFile: 'playwright-results/results.json' }],
  ],
  use: {
    baseURL: process.env.WP_BASE_URL,
    ignoreHTTPSErrors: true, // Local NPM cert may be self-signed.
    trace: 'on',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
    },
  ],
});
