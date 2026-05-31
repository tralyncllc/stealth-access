import { test, expect } from '@playwright/test';
import { WP_ADMIN_USER, WP_ADMIN_PASS } from '../helpers/env';
import { clearSession, expectLoggedIn } from '../helpers/auth';
import { restoreSettingsFromSnapshot } from '../helpers/settings';
import { shot, startConsoleCapture } from '../helpers/capture';

test.describe('Secure login flow', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test('login page loads cleanly (HTTP 200, no console errors, no PHP warnings)', async ({ page }) => {
    const cap = startConsoleCapture(page);
    const response = await page.goto('/secure-login/', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);

    const html = await page.content();
    // Surface any PHP warnings/notices that bled into HTML.
    expect(html, 'page HTML should not contain PHP warnings').not.toMatch(/<b>(Warning|Notice|Fatal|Parse error)<\/b>/);

    await shot(page, '01-secure-login-step1');

    expect(cap.pageErrors, `page errors: ${cap.pageErrors.join('\n')}`).toEqual([]);
    expect(cap.errors, `console errors: ${cap.errors.join('\n')}`).toEqual([]);
  });

  test('step 1 exposes identifier field and continue button', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('#tssl-identifier')).toBeVisible();
    await expect(page.locator('form.tssl-login-form button[type="submit"]')).toContainText(/continue/i);
    await expect(page.locator('input[name="_tssl_step1_nonce"]')).toHaveCount(1);
  });

  test('submitting a fake username advances to step 2 with generic message and no enumeration leak', async ({ page }) => {
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill('fakeuser123');
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);

    await expect(page.locator('#tssl-password')).toBeVisible();
    // New design: the step-2 instruction lives in the card subtitle.
    await expect(page.locator('.tssl-portal-subtitle')).toContainText('Enter your password to continue');

    const html = (await page.content()).toLowerCase();
    for (const leak of [
      'user not found',
      'invalid username',
      'account does not exist',
      'email does not exist',
      'no such user',
      'account found',
    ]) {
      expect(html, `enumeration leak phrase: "${leak}"`).not.toContain(leak);
    }

    await shot(page, '02-secure-login-step2-after-fake-user');
  });

  test('submitting a fake password yields the generic invalid-login error', async ({ page }) => {
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill('fakeuser123');
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await page.locator('#tssl-password').fill('wrongpassword');
    await Promise.all([
      page.waitForURL(/tssl_error=/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('.tssl-error')).toContainText('Invalid login details. Please try again.');
    await shot(page, '03-secure-login-invalid-password');
  });

  test('portal isolates from theme — no admin bar, no WP nav menus, body has tssl-portal-body', async ({ page }) => {
    await page.goto('/secure-login/');
    // Body class set by the plugin's template + body_class filter.
    const bodyClass = await page.evaluate(() => document.body.className);
    expect(bodyClass).toContain('tssl-portal-body');
    // Hallmarks of the new card layout.
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('.tssl-portal-title')).toContainText(/Stealth Access/);
    await expect(page.locator('.tssl-portal-subtitle')).toContainText(/username or email/i);
    await expect(page.locator('.tssl-card-footer')).toContainText(/Protected by Stealth Access/i);
    // Help / Contact Support block must be removed.
    await expect(page.locator('.tssl-card-help')).toHaveCount(0);
    const html = await page.content();
    expect(html).not.toContain('Need help');
    expect(html).not.toContain('Contact Support');
    // No theme chrome should appear: no admin bar (logged out anyway) and no
    // wp-block-page-list (the theme's default header block).
    await expect(page.locator('#wpadminbar')).toHaveCount(0);
    await expect(page.locator('.wp-block-page-list')).toHaveCount(0);
    await shot(page, '60-portal-step1');
  });

  test('portal renders the same card structure on step 2', async ({ page }) => {
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill('anyone');
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('.tssl-portal-subtitle')).toContainText(/Enter your password to continue/i);
    await expect(page.locator('#tssl-password')).toBeVisible();
    await expect(page.locator('form.tssl-restart-form button.tssl-link')).toContainText(/Use a different account/i);
    await shot(page, '61-portal-step2');
  });

  test('portal is mobile-friendly: card fits a 375×667 viewport without horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/secure-login/');
    const card = page.locator('.tssl-card');
    await expect(card).toBeVisible();
    const cardBox = await card.boundingBox();
    expect(cardBox).not.toBeNull();
    expect(cardBox!.width).toBeLessThanOrEqual(375);
    // No horizontal scroll on the document.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await shot(page, '62-portal-mobile');
  });

  test('valid credentials log the user in and redirect to wp-admin', async ({ page }) => {
    const cap = startConsoleCapture(page);
    await page.goto('/secure-login/');
    await page.locator('#tssl-identifier').fill(WP_ADMIN_USER);
    await Promise.all([
      page.waitForURL(/tssl_step=2/),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await page.locator('#tssl-password').fill(WP_ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/wp-admin/, { timeout: 15_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expectLoggedIn(page);

    // wp-admin should be browseable.
    await page.goto('/wp-admin/index.php');
    await expect(page.locator('#wpadminbar')).toBeVisible();

    await shot(page, '04-secure-login-success-admin-dashboard');
    expect(cap.pageErrors, `pageerrors: ${cap.pageErrors.join('\n')}`).toEqual([]);
  });
});
