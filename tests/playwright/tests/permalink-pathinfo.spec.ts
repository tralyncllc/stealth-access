/**
 * PATHINFO-permalink login URL regression.
 *
 * Guards the production bug where a site using a PATHINFO permalink
 * structure (`/index.php/%postname%/`, common on hosts without clean-URL
 * rewriting) saw the custom login URL 404. The plugin was hand-building
 * the URL as `home_url('/' . slug . '/')`, which drops the `/index.php/`
 * prefix the page actually lives behind.
 *
 * The plugin now derives the login URL from `get_permalink()` of the
 * tracked page, so it always honours the active permalink structure.
 *
 * This spec mutates the site's permalink structure; `afterAll` restores
 * the standard structure and the settings snapshot for downstream specs.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { WP_BASE_URL, WP_CLI_CMD } from '../helpers/env';
import { loginViaWpLogin, clearSession } from '../helpers/auth';
import { getSettings, restoreSettingsFromSnapshot } from '../helpers/settings';

const PATHINFO_STRUCTURE = '/index.php/%year%/%monthnum%/%day%/%postname%/';
const STANDARD_STRUCTURE = '/%year%/%monthnum%/%day%/%postname%/';

function wpCli(args: string, stdin?: string): string {
  return execSync(`${WP_CLI_CMD} ${args}`, {
    encoding: 'utf8',
    input: stdin,
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

function setPermalinks(structure: string): void {
  wpCli(`option update permalink_structure ${JSON.stringify(structure)}`);
  wpCli('rewrite flush');
}

test.describe('PATHINFO permalink login URL', () => {
  test.afterAll(() => {
    // Restore the standard permalink structure + snapshot so other specs
    // (which rely on /secure-login/ resolving directly) are unaffected.
    setPermalinks(STANDARD_STRUCTURE);
    restoreSettingsFromSnapshot();
  });

  test('displayed login URL includes /index.php/ and resolves; the bare URL is not used', async ({ page }) => {
    setPermalinks(PATHINFO_STRUCTURE);
    const slug = getSettings().custom_login_slug;

    // 1) The Login URL shown on the dashboard must honour the permalink
    //    structure — i.e. include the /index.php/ prefix.
    await loginViaWpLogin(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=stealth-access`);
    const shown = await page.locator('#tssl-summary-login-url').inputValue();
    expect(shown, 'dashboard Login URL should include /index.php/').toContain('/index.php/');
    expect(shown).toContain(`/index.php/${slug}/`);

    // 2) Direct access to the displayed URL renders the portal.
    await clearSession(page);
    const resp = await page.goto(shown);
    expect(resp?.status(), 'displayed login URL should return 200').toBe(200);
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('.tssl-portal-title')).toBeVisible();

    // 3) The bare, hand-built URL (no /index.php/) is NOT what the plugin
    //    uses — under PATHINFO permalinks it 404s.
    const bare = await page.goto(`${WP_BASE_URL}/${slug}/`);
    expect(bare?.status(), 'the non-index.php URL must not be the one the plugin advertises').toBe(404);
  });

  test('the Open Login Page button links to the /index.php/ URL', async ({ page }) => {
    setPermalinks(PATHINFO_STRUCTURE);
    const slug = getSettings().custom_login_slug;

    await loginViaWpLogin(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=stealth-access`);

    const href = await page.locator('a.tssl-status-open').getAttribute('href');
    expect(href, 'Open Login Page button href should include /index.php/').toContain(
      `/index.php/${slug}/`,
    );
  });
});
