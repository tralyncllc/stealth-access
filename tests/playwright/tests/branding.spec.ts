import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { clearSession, loginViaSecureFlow } from '../helpers/auth';
import { getSettings, restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { shot } from '../helpers/capture';
import { WP_CLI_CMD } from '../helpers/env';

/**
 * Import a built-in WordPress test image as a media attachment and return
 * its attachment ID. We use one of the core admin SVGs so we don't need to
 * upload an external file.
 */
function importAdminLogoAsAttachment(): number {
  const result = execSync(
    [
      `${WP_CLI_CMD} media import`,
      '/var/www/html/wp-includes/images/admin-bar-sprite.png',
      '--title="Test Login Logo"',
      '--alt="Site logo (test)"',
      '--porcelain',
    ].join(' '),
    { encoding: 'utf8' },
  ).trim();
  return parseInt(result, 10);
}

test.describe('Login branding', () => {
  let attachmentId = 0;

  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    try {
      attachmentId = importAdminLogoAsAttachment();
    } catch (e) {
      console.warn('Could not import core WP logo as attachment:', (e as Error).message);
    }
    updateSettings({
      enable_login_branding: 1,
      login_logo_id: attachmentId || 0,
      login_logo_max_width: '180px',
      login_logo_alt: 'Test alt text',
    });
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
    if (attachmentId) {
      try {
        execSync(`${WP_CLI_CMD} post delete ${attachmentId} --force`, {
          stdio: 'pipe',
        });
      } catch { /* best effort */ }
    }
  });

  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test('logo, alt text, and max-width render when branding is enabled', async ({ page }) => {
    const stored = getSettings();
    test.skip(!stored.login_logo_id, 'No attachment available — skipping render verification');

    await page.goto('/secure-login/');
    // New portal: the brand block uses .tssl-brand.tssl-brand-custom and the
    // <img> carries .tssl-brand-logo.
    const wrapper = page.locator('.tssl-brand.tssl-brand-custom');
    await expect(wrapper).toBeVisible();
    const img = wrapper.locator('img.tssl-brand-logo');
    await expect(img).toBeVisible();
    await expect(img).toHaveAttribute('alt', 'Test alt text');
    const style = await img.getAttribute('style');
    expect(style ?? '').toContain('max-width:180px');
    await shot(page, '50-login-with-branding');
  });

  test('settings page logo picker UI is present', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    await expect(page.locator('#tssl-upload-logo')).toBeVisible();
    await expect(page.locator('#tssl-login-logo-id')).toBeAttached();
    await shot(page, '51-settings-branding-picker');
  });
});
