import { Page, expect } from '@playwright/test';
import { WP_ADMIN_USER, WP_ADMIN_PASS } from './env';

/**
 * Log in via the plugin's two-step secure-login flow.
 * Returns once the browser lands on /wp-admin/.
 */
export async function loginViaSecureFlow(
  page: Page,
  user: string = WP_ADMIN_USER,
  pass: string = WP_ADMIN_PASS,
): Promise<void> {
  await page.goto('/secure-login/');
  await page.locator('#tssl-identifier').fill(user);
  await Promise.all([
    page.waitForURL(/tssl_step=2/, { timeout: 30_000 }),
    page.locator('form.tssl-login-form button[type="submit"]').click(),
  ]);
  await page.locator('#tssl-password').fill(pass);
  await Promise.all([
    page.waitForURL(/\/wp-admin/, { timeout: 30_000 }),
    page.locator('form.tssl-login-form button[type="submit"]').click(),
  ]);
}

/**
 * Log in via the default WordPress login screen.
 */
export async function loginViaWpLogin(
  page: Page,
  user: string = WP_ADMIN_USER,
  pass: string = WP_ADMIN_PASS,
): Promise<void> {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await Promise.all([
    page.waitForURL(/\/wp-admin/, { timeout: 30_000 }),
    page.locator('#wp-submit').click(),
  ]);
}

/**
 * Clear cookies — fast cleanup between tests.
 */
export async function clearSession(page: Page): Promise<void> {
  await page.context().clearCookies();
}

/**
 * Real WordPress logout — click the admin-bar logout link.
 */
export async function logoutViaAdminBar(page: Page): Promise<void> {
  await page.goto('/wp-admin/');
  const link = page.locator('a[href*="action=logout"]').first();
  const href = await link.getAttribute('href');
  if (!href) {
    throw new Error('Logout link not found on /wp-admin/');
  }
  await page.goto(href);
}

export async function expectLoggedIn(page: Page): Promise<void> {
  await expect(page).toHaveURL(/\/wp-admin/);
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

export async function expectLoggedOut(page: Page): Promise<void> {
  await page.goto('/wp-admin/');
  // When logged out, WP redirects to wp-login.php (or our hidden behavior).
  await expect(page.locator('#wpadminbar')).toHaveCount(0);
}
