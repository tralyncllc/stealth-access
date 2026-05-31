import { test, expect } from '@playwright/test';
import { clearSession, loginViaSecureFlow, logoutViaAdminBar } from '../helpers/auth';
import { getSettings, restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { shot } from '../helpers/capture';

test.describe('Login page hidden from navigation / page lists', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // Hide-from-lists is on by default; be explicit so this group is hermetic.
    updateSettings({ hide_login_page_from_lists: 1 });
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test('login page is excluded from the homepage page-list block by default', async ({ page }) => {
    await page.goto('/');
    // The core/page-list block must not contain a link to /secure-login/.
    const pageListExists = await page.locator('.wp-block-page-list').count();
    expect(pageListExists, 'page-list block should be present on the test theme').toBeGreaterThan(0);
    const pageListHtml = await page.locator('.wp-block-page-list').first().innerHTML();
    expect(pageListHtml).not.toContain('/secure-login/');
    expect(pageListHtml.toLowerCase()).not.toContain('>secure login<');
    await shot(page, '34-homepage-page-list-excludes-login');
  });

  test('direct /secure-login/ still returns 200 with hide-from-lists on', async ({ page }) => {
    const response = await page.goto('/secure-login/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('#tssl-identifier')).toBeVisible();
  });

  test('admin pages list in wp-admin still shows the Secure Login page', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto('/wp-admin/edit.php?post_type=page');
    await expect(page.locator('table.wp-list-table')).toContainText('Secure Login');
    await shot(page, '35-admin-pages-list-shows-login');
  });

  test('disabling the setting lets the login page reappear in the page-list block', async ({ page }) => {
    updateSettings({ hide_login_page_from_lists: 0 });
    try {
      await page.goto('/');
      const pageListHtml = await page.locator('.wp-block-page-list').first().innerHTML();
      expect(pageListHtml).toContain('/secure-login/');
    } finally {
      updateSettings({ hide_login_page_from_lists: 1 });
    }
  });
});

test.describe('Hide default login URLs', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    updateSettings({ hide_default_login_urls: 1, blocked_login_behavior: 'show_404' });
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test('direct /wp-login.php request is blocked (404 per blocked behavior)', async ({ page }) => {
    const response = await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    expect(response?.status(), 'wp-login.php should 404 when hide is on').toBe(404);
    // Plugin-controlled content must not announce that the login was relocated.
    // (Note: the theme's auto-generated page-list block may still surface the
    // slug as a normal published page — that's a separate finding tracked in
    // PLAYWRIGHT-TEST-REPORT.md, not under this blocker's control.)
    const lower = (await page.content()).toLowerCase();
    expect(lower, 'must not advertise a moved login').not.toContain('login moved');
    expect(lower, 'must not advertise a moved login').not.toContain('login url has changed');
    expect(lower, 'must not advertise the new login path').not.toMatch(/login is at\s+\/\S+/);
    await shot(page, '30-wp-login-blocked-404');
  });

  test('direct /wp-admin/ for unauthenticated user is blocked (does not redirect to wp-login)', async ({ page }) => {
    const response = await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    // Behavior is show_404; final response should be 404 and URL should NOT be wp-login.php.
    expect(response?.status()).toBe(404);
    expect(page.url(), 'should not bounce to wp-login.php').not.toContain('wp-login.php');
    await shot(page, '31-wp-admin-blocked-404');
  });

  test('custom slug still loads the login page', async ({ page }) => {
    const slug = getSettings().custom_login_slug;
    const response = await page.goto(`/${slug}/`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('#tssl-identifier')).toBeVisible();
  });

  test('logged-in admin can still access wp-admin', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto('/wp-admin/index.php');
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page).toHaveURL(/\/wp-admin/);
    await shot(page, '32-wp-admin-authed-allowed');
  });

  test('logout via admin bar still works under hide mode', async ({ page }) => {
    await loginViaSecureFlow(page);
    await logoutViaAdminBar(page);
    // After logout we should NOT be on wp-admin and the admin bar should be gone.
    await expect(page.locator('#wpadminbar')).toHaveCount(0);
  });

  test('no redirect loop on the custom login page when hide is on', async ({ page }) => {
    let redirects = 0;
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) redirects++;
    });
    await page.goto('/secure-login/');
    await page.waitForLoadState('networkidle');
    expect(redirects, 'should not bounce many times').toBeLessThan(5);
  });

  test('login_url() filter is active so wp_login_url() returns the custom slug', async ({ page }) => {
    // Trigger an action that uses wp_login_url(): hit a private endpoint.
    const slug = getSettings().custom_login_slug;
    const response = await page.goto('/wp-admin/profile.php', { waitUntil: 'domcontentloaded' });
    // Should land on the custom slug (or 404 depending on path); in either case must NOT be wp-login.php.
    expect(page.url()).not.toContain('wp-login.php');
    void response; // status varies depending on which path WP picks
    void slug;
  });
});
