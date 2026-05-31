/**
 * Captures the 5 screenshots referenced in readme.txt for the
 * wordpress.org plugin listing. SKIPPED by default — the spec only
 * runs when the env var CAPTURE_SCREENSHOTS=1 is set, so the regular
 * CI suite never picks it up. Run explicitly with:
 *
 *   CAPTURE_SCREENSHOTS=1 \
 *     npx playwright test capture-wordpress-org-screenshots.spec.ts \
 *     --workers=1
 *
 * The screenshots are saved DIRECTLY to the .wordpress-org/ directory
 * relative to the plugin root, replacing any previous capture. We use
 * a 1280×800 viewport (matches what most plugin listings show at full
 * width) and capture either the full page or a card-scoped region
 * depending on what's most informative for each screenshot.
 *
 * Screenshot order must match the `== Screenshots ==` section in
 * readme.txt — if you reorder here, update there.
 *
 * Privacy: this script never sets a real CAPTCHA secret key. The
 * "configured" CAPTCHA screenshot uses Cloudflare's documented public
 * test pair (always-passing for site key, always-passing for secret).
 * No real production secrets ever land in a screenshot.
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import { WP_BASE_URL } from '../helpers/env';
import { loginViaSecureFlow } from '../helpers/auth';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';

const OUT_DIR = path.resolve(__dirname, '..', '..', '..', '.wordpress-org');

const VIEWPORT = { width: 1280, height: 800 };

test.use({ viewport: VIEWPORT });

test.describe.configure({ mode: 'serial' });

// Gate the entire spec on an env var so the default CI suite never
// runs it (the spec mutates settings + needs a clean state and would
// add minutes to every CI run for zero functional verification).
test.skip(
  process.env.CAPTURE_SCREENSHOTS !== '1',
  'Run with CAPTURE_SCREENSHOTS=1 to (re)generate the wordpress.org screenshots',
);

test.describe('Capture wordpress.org screenshots', () => {
  test.beforeAll(() => {
    // Start from a clean, secure-defaults state. Each screenshot adjusts
    // settings just enough to show the feature.
    restoreSettingsFromSnapshot();
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('screenshot-1.png — login portal (Step 1)', async ({ page, context }) => {
    // Logged-out capture of the secure-login portal so visitors see
    // exactly what a real user sees.
    await context.clearCookies();
    await page.goto(`${WP_BASE_URL}/secure-login/`);
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('#tssl-identifier')).toBeVisible();
    await page.screenshot({
      path: path.join(OUT_DIR, 'screenshot-1.png'),
      fullPage: false,
    });
  });

  test('screenshot-2.png — Stealth Access dashboard', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=stealth-access`);
    // Wait for the status grid to fully paint.
    await expect(page.locator('.tssl-status-grid')).toBeVisible();
    await expect(page.locator('.tssl-status-cell')).toHaveCount(4);
    // Capture the full viewport so the admin sidebar + page chrome
    // make it clear this is a WordPress dashboard.
    await page.screenshot({
      path: path.join(OUT_DIR, 'screenshot-2.png'),
      fullPage: false,
    });
  });

  test('screenshot-3.png — Settings page (General Protection card)', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=tssl-settings`);
    // Wait for the General Protection card; it's the first thing the
    // visitor sees on the Settings page.
    await expect(page.locator('#tssl-card-general')).toBeVisible();
    // Scroll the card into view at the top of the viewport before the
    // capture so the entire card is on-screen.
    await page.locator('#tssl-card-general').scrollIntoViewIfNeeded();
    await page.screenshot({
      path: path.join(OUT_DIR, 'screenshot-3.png'),
      fullPage: false,
    });
  });

  test('screenshot-4.png — CAPTCHA configuration card', async ({ page }) => {
    await loginViaSecureFlow(page);
    // Configure Turnstile with the public test pair so the card
    // renders the "configured" state and the masked-fingerprint UI is
    // visible. No real secret ever lands in the screenshot.
    updateSettings({
      captcha_provider: 'cloudflare_turnstile',
      captcha_location: 'username_step',
      // Cloudflare's documented "always passes" test pair. Public.
      turnstile_site_key: '1x00000000000000000000AA',
      turnstile_secret_key: '1x0000000000000000000000000000000AA',
    });
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=tssl-settings`);
    await expect(page.locator('#tssl-card-captcha')).toBeVisible();
    await page.locator('#tssl-card-captcha').scrollIntoViewIfNeeded();
    // Wait for the secret-key masked fingerprint to render so the
    // capture demonstrates the never-leak-secrets feature.
    await expect(page.locator('.tssl-secret-fingerprint').first()).toBeVisible();
    await page.screenshot({
      path: path.join(OUT_DIR, 'screenshot-4.png'),
      fullPage: false,
    });
    // Clean up so the next screenshot reflects defaults again.
    restoreSettingsFromSnapshot();
  });

  test('screenshot-5.png — Hidden-login protection status', async ({ page }) => {
    await loginViaSecureFlow(page);
    // Flip hide_default_login_urls + disable_xmlrpc + disable_application_passwords
    // on so the dashboard status grid shows "Enabled" badges for the
    // protection features that are most relevant to the slug-secrecy
    // value proposition.
    updateSettings({
      hide_default_login_urls: 1,
      disable_xmlrpc: 1,
      disable_application_passwords: 1,
    });
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=stealth-access`);
    await expect(page.locator('.tssl-status-grid')).toBeVisible();
    // Confirm the "Hidden Login URLs" badge says Enabled before capture.
    const hideCell = page.locator('.tssl-status-cell', { hasText: 'Hidden Login URLs' });
    await expect(hideCell.locator('.tssl-badge')).toContainText(/Enabled/i);
    await page.screenshot({
      path: path.join(OUT_DIR, 'screenshot-5.png'),
      fullPage: false,
    });
    restoreSettingsFromSnapshot();
  });
});
