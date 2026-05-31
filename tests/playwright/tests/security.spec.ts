import { test, expect } from '@playwright/test';
import { WP_ADMIN_USER, WP_BASE_URL } from '../helpers/env';
import { clearSession, loginViaSecureFlow, logoutViaAdminBar } from '../helpers/auth';
import { restoreSettingsFromSnapshot } from '../helpers/settings';
import { shot } from '../helpers/capture';

test.describe('Security & enumeration', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  // Helper that returns the HTML of step 2 after submitting a given identifier.
  async function step2HtmlFor(page: import('@playwright/test').Page, identifier: string): Promise<string> {
    await clearSession(page);
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill(identifier);
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    // Strip the cookie-bound nonce + the JS-randomized referer field for comparison.
    const html = await page.content();
    return html
      .replace(/value="[a-f0-9]{10}"/g, 'value="NONCE"')
      .replace(/value="\/[^"]*"/g, 'value="REFERER"');
  }

  test('responses for real vs fake usernames and emails are equivalent (no enumeration)', async ({ page }) => {
    const realUser = WP_ADMIN_USER;
    const fakeUser = 'zzz_no_such_user_999';
    const realEmail = 'donnie@ultimaxmedia.com';
    const fakeEmail = 'nobody-at-all@example.invalid';

    const htmlRealUser = await step2HtmlFor(page, realUser);
    const htmlFakeUser = await step2HtmlFor(page, fakeUser);
    const htmlRealEmail = await step2HtmlFor(page, realEmail);
    const htmlFakeEmail = await step2HtmlFor(page, fakeEmail);

    // All four should reach step 2 with the same password prompt.
    for (const html of [htmlRealUser, htmlFakeUser, htmlRealEmail, htmlFakeEmail]) {
      expect(html).toContain('Enter your password to continue');
      expect(html).toContain('id="tssl-password"');
    }

    // The card body should match byte-for-byte — only the cookie-bound nonce
    // changes between identifiers.
    const extractForm = (h: string) => {
      const m = h.match(/<form class="tssl-login-form"[\s\S]*?<\/form>/);
      return m ? m[0] : h;
    };
    expect(extractForm(htmlRealUser)).toBe(extractForm(htmlFakeUser));
    expect(extractForm(htmlRealEmail)).toBe(extractForm(htmlFakeEmail));
    expect(extractForm(htmlRealUser)).toBe(extractForm(htmlRealEmail));
  });

  test('open redirect via ?redirect_to=https://evil.com is blocked', async ({ page }) => {
    const evil = 'https://evil.example.com/steal';
    await page.goto(`/secure-login/?redirect_to=${encodeURIComponent(evil)}`);
    await page.locator('#tssl-identifier').fill(WP_ADMIN_USER);
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await page.locator('#tssl-password').fill(process.env.WP_ADMIN_PASS as string);
    await Promise.all([
      page.waitForURL(
        (url) => !!url && (url.host === new URL(WP_BASE_URL).host || url.hostname === new URL(WP_BASE_URL).hostname),
        { timeout: 15_000 },
      ),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    const finalUrl = new URL(page.url());
    const baseHost = new URL(WP_BASE_URL).hostname;
    expect(finalUrl.hostname, 'must not redirect off-site').toBe(baseHost);
    expect(finalUrl.hostname).not.toBe('evil.example.com');
    await shot(page, '11-open-redirect-blocked');
  });

  test('step 1 and step 2 forms include nonces', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('input[name="_tssl_step1_nonce"]')).toHaveCount(1);
    await page.locator('#tssl-identifier').fill('anyone');
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('input[name="_tssl_step2_nonce"]')).toHaveCount(1);
    await expect(page.locator('input[name="_tssl_restart_nonce"]')).toHaveCount(1);
  });

  test('login flow cookie has HttpOnly, Secure, SameSite=Lax', async ({ page, context }) => {
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill('whoever');
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    const cookies = await context.cookies();
    const token = cookies.find((c) => c.name === 'tssl_login_token');
    expect(token, 'tssl_login_token cookie must exist after step 1').toBeDefined();
    expect(token!.httpOnly, 'cookie must be HttpOnly').toBe(true);
    // The Secure flag only attaches on HTTPS. The CI environment runs
    // WordPress over plain HTTP on localhost, where setting Secure would
    // prevent the cookie from being delivered at all — so WP correctly
    // omits it. Only assert Secure when WP_BASE_URL is HTTPS.
    if (WP_BASE_URL.startsWith('https://')) {
      expect(token!.secure, 'cookie must be Secure on HTTPS site').toBe(true);
    }
    expect(token!.sameSite, 'cookie must be SameSite=Lax').toBe('Lax');
  });

  test('WordPress auth cookies are HttpOnly + Secure', async ({ page, context }) => {
    await loginViaSecureFlow(page);
    const cookies = await context.cookies();
    const logged = cookies.find((c) => c.name.startsWith('wordpress_logged_in_'));
    expect(logged, 'wordpress_logged_in_* cookie present').toBeDefined();
    expect(logged!.httpOnly).toBe(true);
    // See note above: WP only flags wp auth cookies Secure on HTTPS sites.
    if (WP_BASE_URL.startsWith('https://')) {
      expect(logged!.secure).toBe(true);
    }
    await logoutViaAdminBar(page);
  });

  test('logout works and clears auth session', async ({ page }) => {
    await loginViaSecureFlow(page);
    await logoutViaAdminBar(page);
    // After logout, /wp-admin/ should bounce to wp-login.php (or our slug if hide is on — off by default here).
    await page.goto('/wp-admin/');
    await expect(page).not.toHaveURL(/\/wp-admin\/index\.php/);
    await expect(page.locator('#wpadminbar')).toHaveCount(0);
  });

  test('no secret CAPTCHA keys leaked in HTML', async ({ page }) => {
    await page.goto('/secure-login/');
    const html = await page.content();
    // We don't have keys configured by default, but if they were leaked we'd see
    // long random strings labelled secret. Check the option keys never appear.
    expect(html).not.toContain('turnstile_secret_key');
    expect(html).not.toContain('recaptcha_secret_key');
  });
});
