/**
 * Security regression tests for the two High-severity findings closed in
 * v0.1.13. These tests must keep passing forever — if any of them go red, a
 * known authentication-bypass surface has been re-opened.
 *
 * H1: /wp-login.php?action=interim-login decayed to action=login in WP core
 *     and bypassed the two-step flow when hide_default_login_urls was on.
 *
 * H2: /xmlrpc.php and Application Passwords were not gated by the plugin,
 *     so credentials could authenticate without touching the two-step
 *     flow or CAPTCHA.
 *
 * The tests also exercise the opt-out path — admins who explicitly need
 * XML-RPC or Application Passwords (mobile apps, legacy integrations) can
 * turn the new toggles OFF and the alternative-auth surfaces light back
 * up. That cover-both-directions discipline keeps us from silently
 * regressing the feature into an over-block.
 */

import { test, expect, request as pwRequest, APIRequestContext } from '@playwright/test';
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

/**
 * Create an Application Password for the CI admin via wp-cli. Returns
 * `username:password` formatted for HTTP Basic auth. The password the
 * REST API expects is the raw value with spaces, not the slug-with-dashes
 * UI version, but wp-cli already returns the raw format.
 */
function ensureApplicationPassword(user: string, appName: string): string {
  // Remove any prior copy of the same app so the test is idempotent.
  try {
    execSync(`${WP_CLI_CMD} user application-password delete ${user} --app-name=${JSON.stringify(appName)}`, {
      stdio: 'pipe',
    });
  } catch {
    /* may not exist yet; ignore */
  }
  const raw = execSync(
    `${WP_CLI_CMD} user application-password create ${user} ${JSON.stringify(appName)} --porcelain`,
    { encoding: 'utf8' },
  ).trim();
  return `${user}:${raw}`;
}

function deleteApplicationPassword(user: string, appName: string): void {
  try {
    execSync(`${WP_CLI_CMD} user application-password delete ${user} --app-name=${JSON.stringify(appName)}`, {
      stdio: 'pipe',
    });
  } catch {
    /* best effort */
  }
}

// XML-RPC system.listMethods needs no auth — used to confirm whether the
// XML-RPC dispatcher is alive. wp.getUsersBlogs DOES need creds — used to
// confirm whether auth-via-xmlrpc actually works.
const XMLRPC_LIST_METHODS = `<?xml version="1.0"?>
<methodCall><methodName>system.listMethods</methodName><params/></methodCall>`;

function xmlrpcGetUsersBlogs(user: string, pass: string): string {
  return `<?xml version="1.0"?>
<methodCall>
  <methodName>wp.getUsersBlogs</methodName>
  <params>
    <param><value><string>${user}</string></value></param>
    <param><value><string>${pass}</string></value></param>
  </params>
</methodCall>`;
}

test.describe('H1 — /wp-login.php?action=interim-login bypass', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // The bypass only triggers when hide_default_login_urls is on. The
    // canonical /wp-login.php form is reachable on default settings (that
    // is the documented behavior of the plugin), so we only assert the
    // hardened configuration here.
    updateSettings({ hide_default_login_urls: 1 });
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('action=interim-login does NOT render the standard login form when hide_default_login_urls=1', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-login.php?action=interim-login`, {
      maxRedirects: 0,
    });
    // The plugin's blocked-behavior path either:
    //   - returns 404 (show_404 default) with the theme 404 template, or
    //   - redirects (302) to home_url() / a custom URL.
    // Either way the response MUST NOT contain the standard wp-login form
    // and MUST NOT accept credentials.
    expect([200, 302, 404]).toContain(res.status());
    if (res.status() === 200) {
      const html = await res.text();
      expect(html).not.toContain('id="loginform"');
      expect(html).not.toContain('name="log"');
      expect(html).not.toContain('name="pwd"');
    }
    await ctx.dispose();
  });

  test('POSTing valid credentials to action=interim-login does NOT authenticate', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.post(`${WP_BASE_URL}/wp-login.php?action=interim-login`, {
      form: { log: WP_ADMIN_USER, pwd: WP_ADMIN_PASS, 'interim-login': '1' },
      maxRedirects: 0,
    });
    // No auth cookie set. The whole point of the High finding was that
    // wp_signon used to succeed here; verify it cannot.
    const setCookie = (res.headers()['set-cookie'] ?? '');
    expect(setCookie).not.toContain('wordpress_logged_in_');
    expect(setCookie).not.toContain('wordpress_sec_');
    await ctx.dispose();
  });

  test('canonical hide_default_login_urls=0 path is unaffected (regression guard)', async () => {
    // Turn the hider off — we should NOT have over-blocked /wp-login.php
    // in the unhardened configuration just to fix the bypass.
    updateSettings({ hide_default_login_urls: 0 });
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-login.php`, { maxRedirects: 0 });
    expect(res.status()).toBe(200);
    const html = await res.text();
    expect(html).toContain('id="loginform"');
    await ctx.dispose();
    // Restore for the next test.
    updateSettings({ hide_default_login_urls: 1 });
  });
});

test.describe('H2a — /xmlrpc.php authentication bypass', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // disable_xmlrpc defaults to 1 in the snapshot; restore confirms it.
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('xmlrpc_enabled filter reports false when disable_xmlrpc=1', async () => {
    const out = wpEval(
      'echo apply_filters( "xmlrpc_enabled", true ) ? "ON" : "OFF";',
    );
    expect(out).toBe('OFF');
  });

  test('/xmlrpc.php system.listMethods is blocked when disable_xmlrpc=1', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.post(`${WP_BASE_URL}/xmlrpc.php`, {
      data: XMLRPC_LIST_METHODS,
      headers: { 'Content-Type': 'text/xml' },
      maxRedirects: 0,
    });
    // Blocked-behavior default is show_404; redirect_home and
    // redirect_custom_url are also acceptable. The body MUST NOT contain
    // an XML-RPC <methodResponse> with the methods array.
    expect([200, 302, 404]).toContain(res.status());
    const body = await res.text();
    expect(body).not.toContain('<methodResponse>');
    expect(body).not.toContain('wp.getUsersBlogs');
    await ctx.dispose();
  });

  test('/xmlrpc.php wp.getUsersBlogs with valid creds does NOT authenticate when disable_xmlrpc=1', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.post(`${WP_BASE_URL}/xmlrpc.php`, {
      data: xmlrpcGetUsersBlogs(WP_ADMIN_USER, WP_ADMIN_PASS),
      headers: { 'Content-Type': 'text/xml' },
      maxRedirects: 0,
    });
    const body = await res.text();
    // A successful XML-RPC response would contain <name>blogName</name>
    // followed by the site title. A blocked request never reaches that
    // serializer.
    expect(body).not.toContain('<methodResponse>');
    expect(body).not.toContain('<name>blogName</name>');
    expect(body).not.toContain('<name>blogid</name>');
    await ctx.dispose();
  });

  test('opt-out: disable_xmlrpc=0 restores the dispatcher (regression guard)', async () => {
    updateSettings({ disable_xmlrpc: 0 });
    const out = wpEval(
      'echo apply_filters( "xmlrpc_enabled", true ) ? "ON" : "OFF";',
    );
    expect(out).toBe('ON');
    // We only assert the filter contract; we don't actually want to
    // re-enable a working /xmlrpc.php during the test run because shared
    // CI state. Restore immediately.
    updateSettings({ disable_xmlrpc: 1 });
  });
});

test.describe('H2b — Application Password authentication bypass', () => {
  let basicAuth = '';
  const APP_NAME = 'h2b-regression-test';

  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // Even with disable_application_passwords=1, wp-cli can still create
    // an Application Password (the filter only gates the public API,
    // not the CLI). Create one so we have something to attempt auth with.
    basicAuth = ensureApplicationPassword(WP_ADMIN_USER, APP_NAME);
  });

  test.afterAll(() => {
    deleteApplicationPassword(WP_ADMIN_USER, APP_NAME);
    restoreSettingsFromSnapshot();
  });

  test('wp_is_application_passwords_available filter reports false when disable_application_passwords=1', async () => {
    // Use the filter directly rather than wp_is_application_passwords_available()
    // — the core helper also gates on is_ssl(), which would return false in
    // CI (plain HTTP) even with our setting off. We want to assert OUR
    // filter behavior independently of the core HTTPS gate.
    const out = wpEval(
      'echo apply_filters( "wp_is_application_passwords_available", true ) ? "ON" : "OFF";',
    );
    expect(out).toBe('OFF');
  });

  test('REST /users/me with Basic-auth Application Password does NOT authenticate when disabled', async () => {
    const ctx = await pwRequest.newContext({
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: {
        Authorization: 'Basic ' + Buffer.from(basicAuth).toString('base64'),
      },
    });
    const res = await ctx.get(`${WP_BASE_URL}/wp-json/wp/v2/users/me`);
    // 401 is the expected "not authenticated" response. WordPress
    // sometimes returns 400 when the App-Password code path rejects the
    // header itself; treat both as "did not authenticate." The response
    // body MUST NOT contain the admin user record.
    expect([400, 401, 403]).toContain(res.status());
    const body = await res.text();
    expect(body).not.toContain(`"slug":"${WP_ADMIN_USER}"`);
    expect(body).not.toContain('"capabilities"');
    await ctx.dispose();
  });

  test('opt-out: disable_application_passwords=0 restores the auth path (regression guard)', async () => {
    updateSettings({ disable_application_passwords: 0 });
    // Same caveat as above: we exercise the filter directly so the test
    // is portable across HTTPS dev and HTTP CI.
    const out = wpEval(
      'echo apply_filters( "wp_is_application_passwords_available", true ) ? "ON" : "OFF";',
    );
    expect(out).toBe('ON');
    // Restore the secure default before leaving.
    updateSettings({ disable_application_passwords: 1 });
  });
});

test.describe('H1 + H2 cohesion — interim-login + xmlrpc + app-pass cannot be played in sequence', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    updateSettings({ hide_default_login_urls: 1 });
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('all three bypass surfaces are simultaneously closed at the default-hardened configuration', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });

    const interim = await ctx.post(`${WP_BASE_URL}/wp-login.php?action=interim-login`, {
      form: { log: WP_ADMIN_USER, pwd: WP_ADMIN_PASS, 'interim-login': '1' },
      maxRedirects: 0,
    });
    const interimCookie = interim.headers()['set-cookie'] ?? '';
    expect(interimCookie).not.toContain('wordpress_logged_in_');

    const xmlrpc = await ctx.post(`${WP_BASE_URL}/xmlrpc.php`, {
      data: xmlrpcGetUsersBlogs(WP_ADMIN_USER, WP_ADMIN_PASS),
      headers: { 'Content-Type': 'text/xml' },
      maxRedirects: 0,
    });
    const xmlrpcBody = await xmlrpc.text();
    expect(xmlrpcBody).not.toContain('<name>blogName</name>');

    // The Application Password was created in the H2b describe block's
    // beforeAll there; we don't create it again here. We only confirm
    // the filter has the secure default state, which is the semantic
    // equivalent for this cohesion check.
    const appPassActive = wpEval(
      'echo apply_filters( "wp_is_application_passwords_available", true ) ? "ON" : "OFF";',
    );
    expect(appPassActive).toBe('OFF');

    await ctx.dispose();
  });
});
