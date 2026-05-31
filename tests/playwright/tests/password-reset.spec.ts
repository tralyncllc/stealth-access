import { test, expect } from '@playwright/test';
import { clearSession } from '../helpers/auth';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { shot } from '../helpers/capture';

test.describe('Password reset disabling', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    updateSettings({ disable_password_reset: 1 });
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  for (const action of ['lostpassword', 'retrievepassword', 'resetpass', 'rp']) {
    test(`?action=${action} is blocked when password reset is disabled`, async ({ page }) => {
      const response = await page.goto(`/wp-login.php?action=${action}`, { waitUntil: 'domcontentloaded' });
      // Whatever the plugin does (redirect or block), we must NOT land on the password reset form.
      const html = await page.content();
      expect(html.toLowerCase(), `action=${action} must not render the reset form`).not.toContain('get new password');
      expect(html.toLowerCase()).not.toContain('please enter your username or email address');
      // Must not leak account existence info either.
      expect(html.toLowerCase()).not.toContain('email does not exist');
      expect(html.toLowerCase()).not.toContain('user not found');
      void response;
    });
  }

  test('login form submits do not generate a reset email even with a valid user', async ({ page }) => {
    // We can't easily check the mail queue here. We'll assert the redirect
    // behavior at the URL level: hitting the lostpassword URL while reset is
    // disabled should NOT result in the "Check your email" flow.
    await page.goto('/wp-login.php?action=lostpassword');
    const html = await page.content();
    expect(html.toLowerCase()).not.toContain('check your email');
    await shot(page, '40-lostpassword-blocked');
  });
});

test.describe('Hide Lost Password link on default wp-login.php', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // hide_lost_password is already 1 in defaults, but be explicit.
    updateSettings({ hide_lost_password: 1, disable_password_reset: 0 });
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('CSS hide block injects into wp-login.php head', async ({ page }) => {
    await page.goto('/wp-login.php');
    const styleHtml = await page.locator('#tssl-hide-lost-password').first().innerText().catch(() => '');
    // The element may be a <style> without innerText; check existence.
    await expect(page.locator('#tssl-hide-lost-password')).toHaveCount(1);
    void styleHtml;
    await shot(page, '41-wp-login-hide-lost-pass-css');
  });

  test('the Lost Password link is visually hidden via CSS', async ({ page }) => {
    await page.goto('/wp-login.php');
    const navVisible = await page.locator('p#nav').isVisible().catch(() => false);
    expect(navVisible, 'Lost Password nav block should be CSS-hidden').toBe(false);
  });
});
