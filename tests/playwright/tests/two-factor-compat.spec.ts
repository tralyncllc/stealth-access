/**
 * 2FA second-factor compatibility (v1.0.4).
 *
 * Production bug: with `hide_default_login_urls` on, a WordPress 2FA plugin
 * could not complete login. The second-factor step posts back to
 * `wp-login.php?action=validate_2fa` (the official "Two-Factor" plugin), or a
 * similar provider-specific action. That action was not on the plugin's
 * hard-coded allowlist, so `maybe_block_wp_login()` 404'd it and the user was
 * stranded — they had entered username + password but could never submit the
 * second factor.
 *
 * The fix lets any action that a plugin services via a `login_form_{$action}`
 * handler reach wp-login.php (that is exactly how 2FA providers hook the login
 * flow), while keeping credential/registration/`interim-login` actions blocked
 * so the H1 action-coercion bypass stays closed. It also leaves
 * `site_url('wp-login.php')` intact while inside the `wp_login` hook, so a 2FA
 * plugin's challenge form posts back to the real endpoint instead of the
 * hidden page.
 *
 * The fixture simulates a 2FA plugin with a tiny mu-plugin that registers a
 * `login_form_tssl_2fa_probe` handler — we don't depend on any specific 2FA
 * plugin being installed; we assert the generic hook contract the fix is
 * built on.
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import { execSync } from 'child_process';
import { WP_ADMIN_PASS, WP_ADMIN_USER, WP_BASE_URL, WP_CLI_CMD } from '../helpers/env';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';

function wpEval(php: string): string {
  const code = php.replace(/^<\?php\s*/, '').trim();
  return execSync(`${WP_CLI_CMD} eval '${code}'`, {
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  }).trim();
}

// A throwaway mu-plugin standing in for a 2FA provider: it registers a
// `login_form_{action}` handler, which is the exact integration point real
// 2FA plugins use for their second-factor step.
const PROBE_ACTION = 'tssl_2fa_probe';
const PROBE_MARKER = 'TSSL_2FA_PROBE_REACHED';
const MU_PLUGIN_PHP = `<?php
/* Plugin Name: TSSL 2FA Probe (test fixture) */
add_action( 'login_form_${PROBE_ACTION}', function () {
\tstatus_header( 200 );
\theader( 'Content-Type: text/plain' );
\techo '${PROBE_MARKER}';
\texit;
} );
`;

function installProbeMuPlugin(): void {
  const b64 = Buffer.from(MU_PLUGIN_PHP).toString('base64');
  wpEval(
    `wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . "/tssl-2fa-probe.php", base64_decode( "${b64}" ) );`,
  );
}

function removeProbeMuPlugin(): void {
  wpEval(
    `$f = WPMU_PLUGIN_DIR . "/tssl-2fa-probe.php"; if ( file_exists( $f ) ) { unlink( $f ); }`,
  );
}

test.describe('v1.0.4 — 2FA second-factor compatibility', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    installProbeMuPlugin();
    // The whole conflict only exists when the login URLs are hidden.
    updateSettings({ hide_default_login_urls: 1 });
  });

  test.afterAll(() => {
    removeProbeMuPlugin();
    restoreSettingsFromSnapshot();
  });

  test('a plugin-registered second-factor action reaches wp-login.php (not blocked)', async () => {
    // Sanity: the fixture really is registered, so this test is meaningful.
    expect(wpEval(`echo has_action( "login_form_${PROBE_ACTION}" ) ? "Y" : "N";`)).toBe('Y');

    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-login.php?action=${PROBE_ACTION}`, {
      maxRedirects: 0,
    });
    expect(res.status()).toBe(200);
    const body = await res.text();
    // The second-factor handler ran — the request was NOT 404'd by the hider.
    expect(body).toContain(PROBE_MARKER);
    await ctx.dispose();
  });

  test('an action with NO login_form_ handler is still blocked (no broad allowlist)', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-login.php?action=tssl_unregistered_xyz`, {
      maxRedirects: 0,
    });
    expect([200, 302, 404]).toContain(res.status());
    const body = await res.text();
    expect(body).not.toContain(PROBE_MARKER);
    // And it must NOT have fallen through to the standard login form.
    expect(body).not.toContain('id="loginform"');
    expect(body).not.toContain('name="pwd"');
    await ctx.dispose();
  });

  test('action=login is still blocked — the credential form is never re-exposed', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-login.php?action=login`, { maxRedirects: 0 });
    expect([200, 302, 404]).toContain(res.status());
    if (res.status() === 200) {
      const body = await res.text();
      expect(body).not.toContain('id="loginform"');
      expect(body).not.toContain('name="pwd"');
    }
    await ctx.dispose();
  });

  test('H1 stays closed: interim-login has no handler and remains blocked', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.post(`${WP_BASE_URL}/wp-login.php?action=interim-login`, {
      form: { log: WP_ADMIN_USER, pwd: WP_ADMIN_PASS, 'interim-login': '1' },
      maxRedirects: 0,
    });
    const setCookie = res.headers()['set-cookie'] ?? '';
    expect(setCookie).not.toContain('wordpress_logged_in_');
    expect(setCookie).not.toContain('wordpress_sec_');
    await ctx.dispose();
  });

  test('wp-login.php URLs stay intact while completing login, rewritten otherwise', async () => {
    // Outside the wp_login hook the hider rewrites wp-login.php away from
    // public output (hidden-login feature). Inside wp_login — where a 2FA
    // plugin builds its challenge-form action — the URL is left intact so the
    // second factor posts back to the real endpoint.
    const out = wpEval(
      `$outside = site_url( "wp-login.php", "login_post" );` +
        `$inside = "";` +
        `add_action( "wp_login", function () use ( &$inside ) { $inside = site_url( "wp-login.php", "login_post" ); }, 1 );` +
        `$u = wp_get_current_user();` +
        `do_action( "wp_login", $u->user_login, $u );` +
        `echo ( strpos( $outside, "wp-login.php" ) !== false ? "INTACT" : "REWRITTEN" ) . "|" . ( strpos( $inside, "wp-login.php" ) !== false ? "INTACT" : "REWRITTEN" );`,
    );
    expect(out).toBe('REWRITTEN|INTACT');
  });
});
