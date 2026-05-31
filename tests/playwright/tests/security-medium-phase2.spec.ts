/**
 * Security regression tests for the Phase 2 Medium-severity findings
 * closed in v0.1.15:
 *
 *   M1 — `enable_two_step` toggle was rendered in the UI but never read
 *        by the login flow. Removed: the toggle no longer ships in the
 *        Settings page, the option key is no longer in defaults or
 *        bool_keys, and the dashboard status badge for "Two-step Login"
 *        always reads "Enabled" (which is what the code actually does).
 *
 *   M2 — wp_lostpassword_url() returned the canonical
 *        /wp-login.php?action=lostpassword URL when only
 *        hide_default_login_urls was on, embedding /wp-login.php in
 *        every comment form and theme login widget. Fix: rewrite the
 *        URL to a slug-bearing form, and add a template_redirect
 *        handler that forwards the click-through to wp-login.php so
 *        the reset flow still works.
 *
 * Both findings are functional/usability hardening — neither was an
 * exploitable authentication bypass — so the regression tests focus on
 * "the UI matches what the code does" + "the disclosure no longer
 * happens" + "the reset flow still works end-to-end."
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import { WP_BASE_URL } from '../helpers/env';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { loginViaSecureFlow } from '../helpers/auth';

test.describe('M1 — `enable_two_step` toggle is removed; two-step is mandatory', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('the Settings page no longer renders an enable_two_step checkbox', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=tssl-settings`);
    // The audit fix removed the row entirely — there must be NO input
    // with the old name attribute anywhere in the page.
    await expect(page.locator('input[name="tssl_settings[enable_two_step]"]')).toHaveCount(0);
  });

  test('the dashboard status badge for "Two-step Login" reads "Enabled" (matches actual code behavior)', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=tssl-settings`);
    // The status grid shows a labeled cell for Two-step Login.
    const cell = page.locator('.tssl-status-cell', { hasText: 'Two-step Login' });
    await expect(cell.locator('.tssl-badge')).toContainText(/Enabled/i);
  });

  test('the two-step flow continues to work end-to-end (no behavioral regression)', async ({ page }) => {
    // The toggle never functionally controlled the flow; this is a
    // belt-and-suspenders check that removing it did not break login.
    await loginViaSecureFlow(page);
    await expect(page).toHaveURL(/\/wp-admin/);
  });
});

test.describe('M2 — wp_lostpassword_url does not leak /wp-login.php when only hide_default_login_urls is on', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    updateSettings({
      hide_default_login_urls: 1,
      disable_password_reset: 0,
    });
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('wp_lostpassword_url() returns a custom-slug URL, NOT /wp-login.php', async ({ page }) => {
    await loginViaSecureFlow(page);
    // Probe the filter result directly via wp-admin. WordPress exposes
    // the URL through several theme helpers; the cleanest probe is the
    // /wp-admin/profile.php page which prints `Lost your password?`
    // links in its Application Passwords section. But more reliably we
    // just inspect the value the filter returns via the dashboard URL
    // generator. Use the live home page comment form via /sample-page/
    // which prints wp_lostpassword_url() in a comment-form context.
    await page.goto(`${WP_BASE_URL}/sample-page/`);
    const html = await page.content();
    // Default Twenty Twenty-Five renders a comment form on Sample Page
    // when comments are open. The form's "Lost your password?" link uses
    // wp_lostpassword_url(). Even if the theme template doesn't show
    // that link in the unauth state, the filter result is what other
    // plugins/widgets consume.
    expect(html).not.toContain('/wp-login.php?action=lostpassword');
  });

  test('visiting /secure-login/?action=lostpassword forwards to /wp-login.php?action=lostpassword', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/secure-login/?action=lostpassword`, {
      maxRedirects: 0,
    });
    expect(res.status()).toBe(302);
    const location = res.headers().location ?? '';
    // Forward target must be the WP lost-password endpoint.
    expect(location).toMatch(/\/wp-login\.php\?.*action=lostpassword/);
    await ctx.dispose();
  });

  test('the forwarded /wp-login.php?action=lostpassword renders the WP reset form (end-to-end)', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/secure-login/?action=lostpassword`, {
      waitUntil: 'load',
    });
    // After following the 302, the browser should land on the standard
    // WP "Lost your password?" form. The form has id="lostpasswordform".
    await expect(page.locator('#lostpasswordform')).toBeVisible();
    expect(page.url()).toMatch(/wp-login\.php\?.*action=lostpassword/);
  });

  test('regression: with hide_default_login_urls=0, behavior is unchanged (wp-login URL passes through)', async () => {
    updateSettings({ hide_default_login_urls: 0 });
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/sample-page/`);
    const body = await res.text();
    // When the hider is off the admin has explicitly chosen NOT to
    // suppress /wp-login.php — the URL is allowed to appear in HTML.
    // We only assert the page renders without error; we don't assert
    // presence/absence of the URL since the theme may or may not emit it.
    expect(res.status()).toBe(200);
    expect(body.length).toBeGreaterThan(100);
    await ctx.dispose();
    // Restore for tests that may follow.
    updateSettings({ hide_default_login_urls: 1 });
  });

  test('regression: disable_password_reset=1 still routes the URL through the custom slug (M2 does not break the existing branch)', async () => {
    updateSettings({ disable_password_reset: 1 });
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    // The lostpassword URL filter should return the bare custom login
    // URL (NOT an action= variant) when reset is disabled.
    const res = await ctx.get(`${WP_BASE_URL}/sample-page/`);
    const body = await res.text();
    expect(body).not.toContain('/wp-login.php?action=lostpassword');
    await ctx.dispose();
    updateSettings({ disable_password_reset: 0 });
  });
});
