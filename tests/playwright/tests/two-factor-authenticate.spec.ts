/**
 * Conditional 2FA step for providers that validate the second factor inside
 * the `authenticate` filter (Wordfence Login Security model).
 *
 * Production bug (the v1.0.4 follow-up): with `hide_default_login_urls` on and
 * a Wordfence-style 2FA plugin active, the two-step portal collapsed the
 * provider's "code required" WP_Error (`wfls_twofactor_required`) into the
 * generic "Invalid login details" message after the password step, so the
 * user could never reach a 2FA prompt and was locked out.
 *
 * The fix recognises the provider's 2FA-required / 2FA-failed error codes and
 * advances to a conditional 2FA step (re-enter password + code), handing the
 * code to the provider through its native request field (`wfls-token`) so the
 * provider performs the validation. No cookies are forced and no auth hook is
 * short-circuited.
 *
 * This spec is portable: instead of depending on a live Wordfence install it
 * uses a fixture mu-plugin that emulates the same `authenticate`-filter
 * contract, driven against a dedicated test user that is NOT enrolled in any
 * real 2FA (so a real Wordfence on the dev box passes it through and the
 * fixture is the only second factor in play).
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { WP_BASE_URL, WP_CLI_CMD } from '../helpers/env';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';

const TEST_USER = 'tssl2fa_probe';
const TEST_PASS = 'Tssl-2fa-Pass-9x7q!';
const GOOD_CODE = '246810';

function wpCli(args: string, stdin?: string): string {
  return execSync(`${WP_CLI_CMD} ${args}`, { encoding: 'utf8', input: stdin, stdio: ['pipe', 'pipe', 'pipe'] });
}
function wpEval(php: string): string {
  return execSync(`${WP_CLI_CMD} eval '${php.replace(/^<\?php\s*/, '').trim()}'`, {
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  }).trim();
}

// Fixture provider: emulates Wordfence's authenticate-filter 2FA. Requires a
// code (via $_POST['wfls-token']) for any valid user while the option flag is
// on; accepts only GOOD_CODE.
const FIXTURE_PHP = `<?php
/* Plugin Name: TSSL Fake 2FA Provider (test fixture) */
add_filter( 'authenticate', function ( $user, $username, $password ) {
	if ( ! ( $user instanceof WP_User ) ) { return $user; }
	if ( '1' !== (string) get_option( 'tssl_test_2fa_required', '0' ) ) { return $user; }
	$token = ( isset( $_POST['wfls-token'] ) && is_string( $_POST['wfls-token'] ) ) ? $_POST['wfls-token'] : '';
	if ( '' === $token ) { return new WP_Error( 'wfls_twofactor_required', 'CODE REQUIRED: Please provide your 2FA code when prompted.' ); }
	if ( '${GOOD_CODE}' === $token ) { return $user; }
	return new WP_Error( 'wfls_twofactor_failed', 'CODE INVALID' );
}, 30, 3 );
`;

function installFixture(): void {
  const b64 = Buffer.from(FIXTURE_PHP).toString('base64');
  wpEval(`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . "/tssl-fake-2fa.php", base64_decode( "${b64}" ) );`);
}
function removeFixture(): void {
  wpEval(`$f = WPMU_PLUGIN_DIR . "/tssl-fake-2fa.php"; if ( file_exists( $f ) ) { unlink( $f ); }`);
}

async function startPasswordStep(page: import('@playwright/test').Page) {
  await page.context().clearCookies();
  await page.goto(`${WP_BASE_URL}/secure-login/`);
  await page.locator('#tssl-identifier').fill(TEST_USER);
  await Promise.all([
    page.waitForURL(/tssl_step=2(?!fa)/, { timeout: 30_000 }),
    page.locator('form.tssl-login-form button[type="submit"]').click(),
  ]);
}

test.describe('Conditional 2FA step (authenticate-filter providers)', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // Idempotent: remove any leftover user from a prior run.
    try { wpCli(`user delete ${TEST_USER} --yes --network`); } catch { /* ignore */ }
    try { wpCli(`user delete ${TEST_USER} --yes`); } catch { /* ignore */ }
    wpCli(`user create ${TEST_USER} ${TEST_USER}@example.test --role=administrator --user_pass=${JSON.stringify(TEST_PASS)} --porcelain`);
    installFixture();
    wpCli('option update tssl_test_2fa_required 1');
    updateSettings({ hide_default_login_urls: 1, captcha_provider: 'none' });
  });

  test.afterAll(() => {
    removeFixture();
    try { wpCli('option delete tssl_test_2fa_required'); } catch { /* ignore */ }
    try { wpCli(`user delete ${TEST_USER} --yes`); } catch { /* ignore */ }
    restoreSettingsFromSnapshot();
  });

  test('correct password + 2FA active → 2FA step, NOT "Invalid login details"', async ({ page }) => {
    await startPasswordStep(page);
    await page.locator('#tssl-password').fill(TEST_PASS);
    await Promise.all([
      page.waitForURL(/tssl_step=2fa/, { timeout: 30_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('#tssl-2fa-code')).toBeVisible();
    await expect(page.locator('#tssl-password')).toBeVisible();
    await expect(page.locator('.tssl-error')).toHaveCount(0);
  });

  test('wrong 2FA code fails safely (stays on 2FA step, not logged in)', async ({ page }) => {
    await startPasswordStep(page);
    await page.locator('#tssl-password').fill(TEST_PASS);
    await Promise.all([
      page.waitForURL(/tssl_step=2fa/, { timeout: 30_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await page.locator('#tssl-password').fill(TEST_PASS);
    await page.locator('#tssl-2fa-code').fill('000000');
    await Promise.all([
      page.waitForURL(/tssl_step=2fa/, { timeout: 30_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('.tssl-error')).toContainText('Invalid authentication code');
    await expect(page.locator('#wpadminbar')).toHaveCount(0);
  });

  test('correct password + correct 2FA code → login succeeds', async ({ page }) => {
    await startPasswordStep(page);
    await page.locator('#tssl-password').fill(TEST_PASS);
    await Promise.all([
      page.waitForURL(/tssl_step=2fa/, { timeout: 30_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await page.locator('#tssl-password').fill(TEST_PASS);
    await page.locator('#tssl-2fa-code').fill(GOOD_CODE);
    await Promise.all([
      page.waitForURL(/\/wp-admin/, { timeout: 30_000 }),
      page.locator('form.tssl-login-form button[type="submit"]').click(),
    ]);
    await expect(page.locator('#wpadminbar')).toBeVisible();
  });

  test('user without 2FA still logs in normally (no 2FA step)', async ({ page }) => {
    wpCli('option update tssl_test_2fa_required 0');
    try {
      await startPasswordStep(page);
      await page.locator('#tssl-password').fill(TEST_PASS);
      await Promise.all([
        page.waitForURL(/\/wp-admin/, { timeout: 30_000 }),
        page.locator('form.tssl-login-form button[type="submit"]').click(),
      ]);
      await expect(page.locator('#wpadminbar')).toBeVisible();
    } finally {
      wpCli('option update tssl_test_2fa_required 1');
    }
  });

  test('wrong password still shows "Invalid login details"', async ({ page }) => {
    await startPasswordStep(page);
    await page.locator('#tssl-password').fill('definitely-the-wrong-password');
    await page.locator('form.tssl-login-form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.tssl-error')).toContainText('Invalid login details');
    await expect(page.locator('#tssl-2fa-code')).toHaveCount(0);
    await expect(page.locator('#wpadminbar')).toHaveCount(0);
  });
});
