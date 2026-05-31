/**
 * Security regression tests for Medium-severity findings closed in v0.1.14:
 *
 *   M4 — CAPTCHA silently fails open when keys are missing.
 *        Fix: admin_notices banner + rate-limited error_log line. Login
 *        still proceeds when keys are missing (the policy is unchanged),
 *        but the misconfiguration is now LOUD instead of silent.
 *
 *   M5 — Hidden login slug disclosed via REST /wp/v2/pages and /wp/v2/search.
 *        Fix: rest_page_query + rest_post_search_query filters that
 *        exclude login_page_id for callers who cannot edit_pages.
 *
 *   M6 — Login page exposed via WordPress core sitemap.
 *        Fix: wp_sitemaps_posts_query_args filter excludes login_page_id
 *        when hide_login_page_from_lists is on.
 *
 *   M7 — Login page appears in front-end search results.
 *        Fix: pre_get_posts filter excludes login_page_id from the main
 *        search query for unauthenticated callers.
 *
 * Each test pair below exercises BOTH the blocking path (default secure
 * configuration) AND the opt-out / admin-management path so that a
 * future change cannot regress us into either over-blocking (breaking
 * admin UX) or under-blocking (reintroducing the disclosure).
 */

import { test, expect, request as pwRequest } from '@playwright/test';
import { execSync } from 'child_process';
import { WP_ADMIN_PASS, WP_ADMIN_USER, WP_BASE_URL, WP_CLI_CMD } from '../helpers/env';
import { restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { loginViaSecureFlow, loginViaWpLogin } from '../helpers/auth';

function wpEval(php: string): string {
  const code = php.replace(/^<\?php\s*/, '').trim();
  return execSync(`${WP_CLI_CMD} eval '${code}'`, {
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  }).trim();
}

/**
 * Slug of the auto-created login page. Pulled from settings on demand
 * so the assertions match whatever the admin has configured.
 */
function loginSlug(): string {
  return wpEval(
    'echo (string) ( get_option( "tssl_settings" )["custom_login_slug"] ?? "secure-login" );',
  ).trim();
}

/**
 * Permalink of the auto-created login page. We compare full URLs in
 * sitemap + REST responses so a slug substring match cannot misfire
 * (e.g. against "secure-login.css" elsewhere in the document).
 */
function loginPermalink(): string {
  const slug = loginSlug();
  return `${WP_BASE_URL}/${slug}/`;
}

/**
 * Regex variant that matches the login page URL in BOTH unescaped HTML
 * form (`/secure-login/`) and JSON-escaped form (`\/secure-login\/`).
 * The plain-string `loginPermalink()` works for HTML/XML responses; this
 * variant is needed for JSON REST responses where forward slashes are
 * escaped as `\/`.
 */
function restPermalinkRegex(): RegExp {
  const slug = loginSlug();
  return new RegExp(`(?:\\\\?/)${slug}(?:\\\\?/)`);
}

test.describe('M5 — REST API does not disclose the hidden login page to anonymous callers', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // hide_login_page_from_lists is on in the default snapshot, so no
    // additional setup is needed. We assert that explicitly inside each
    // test rather than trusting a default that could drift.
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('GET /wp-json/wp/v2/pages does NOT include the login page for anonymous callers', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-json/wp/v2/pages?per_page=100`);
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).not.toMatch(restPermalinkRegex());
    // The login page's content is the [two_step_secure_login] shortcode.
    // If it were leaking, the rendered content would mention the shortcode
    // or "Stealth Access" — neither should appear.
    expect(body).not.toContain('two_step_secure_login');
    await ctx.dispose();
  });

  test('GET /wp-json/wp/v2/search does NOT match the login page for anonymous callers', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    // Search for several keywords that would otherwise match the page's
    // default title ("Secure Login"). The slug itself is searchable in
    // WordPress's title-based search.
    for (const term of ['secure', 'login', 'stealth']) {
      const res = await ctx.get(
        `${WP_BASE_URL}/wp-json/wp/v2/search?search=${encodeURIComponent(term)}&per_page=100`,
      );
      expect(res.status()).toBe(200);
      const body = await res.text();
      expect(body, `search term=${term}`).not.toMatch(restPermalinkRegex());
    }
    await ctx.dispose();
  });

  test('admin REST /wp-json/wp/v2/pages STILL includes the login page (admin management preserved)', async ({ page }) => {
    await loginViaSecureFlow(page);
    // WP REST cookie auth requires an X-WP-Nonce header — without it the
    // request is treated as anonymous regardless of auth cookies. Land
    // on a wp-admin page first to pull wpApiSettings.nonce (always
    // present on admin pages because WP enqueues wp-api-request there).
    await page.goto(`${WP_BASE_URL}/wp-admin/`);
    const nonce = await page.evaluate(() => {
      const w = window as unknown as { wpApiSettings?: { nonce?: string } };
      return w.wpApiSettings?.nonce ?? '';
    });
    expect(nonce, 'expected wpApiSettings.nonce to be populated on /wp-admin/').not.toBe('');
    const json = await page.evaluate(
      async ({ base, n }) => {
        const res = await fetch(`${base}/wp-json/wp/v2/pages?per_page=100`, {
          credentials: 'include',
          headers: { Accept: 'application/json', 'X-WP-Nonce': n },
        });
        return res.text();
      },
      { base: WP_BASE_URL, n: nonce },
    );
    expect(json).toMatch(restPermalinkRegex());
  });

  test('opt-out: hide_login_page_from_lists=0 makes the page visible in anonymous REST (regression guard)', async () => {
    updateSettings({ hide_login_page_from_lists: 0 });
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-json/wp/v2/pages?per_page=100`);
    const body = await res.text();
    // With the toggle off the admin has explicitly opted INTO disclosure,
    // so the page should be visible. The filter must respect the toggle.
    expect(body).toMatch(restPermalinkRegex());
    await ctx.dispose();
    // Restore secure default for subsequent tests.
    updateSettings({ hide_login_page_from_lists: 1 });
  });
});

test.describe('M6 — WordPress core XML sitemap does not disclose the hidden login page', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('/wp-sitemap-posts-page-1.xml does NOT list the login permalink', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-sitemap-posts-page-1.xml`);
    // WordPress core always serves the sitemap even when no pages match,
    // so a 200 is the expected status. A 404 here would itself be a
    // regression to investigate.
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).not.toMatch(restPermalinkRegex());
    await ctx.dispose();
  });

  test('the sitemap still lists other published pages (no over-blocking)', async () => {
    // Sample Page is the canonical WP fixture page and should appear in
    // the sitemap regardless of plugin settings. If the filter
    // accidentally clobbers the whole sitemap, this assertion catches it.
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-sitemap-posts-page-1.xml`);
    const body = await res.text();
    expect(body).toContain(`${WP_BASE_URL}/sample-page/`);
    await ctx.dispose();
  });

  test('opt-out: hide_login_page_from_lists=0 makes the login page appear in the sitemap (regression guard)', async () => {
    updateSettings({ hide_login_page_from_lists: 0 });
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/wp-sitemap-posts-page-1.xml`);
    const body = await res.text();
    expect(body).toContain(loginPermalink());
    await ctx.dispose();
    updateSettings({ hide_login_page_from_lists: 1 });
  });
});

test.describe('M7 — Front-end search does not expose the hidden login page', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });

  test('GET /?s=secure does NOT return the login page as a search result for anonymous visitors', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/?s=secure`);
    const html = await page.content();
    expect(html).not.toContain(loginPermalink());
  });

  test('GET /?s=login does NOT return the login page as a search result for anonymous visitors', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/?s=login`);
    const html = await page.content();
    expect(html).not.toContain(loginPermalink());
  });

  test('admin wp-admin/edit.php?s=secure-login STILL finds the login page (management preserved)', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=page&s=secure`);
    // The admin page-list shows results in a wp_list_table. We just need
    // to confirm the page title appears in the rendered table — any of
    // the standard renderings include the literal title "Secure Login".
    const html = await page.content();
    expect(html).toContain('Secure Login');
  });

  test('direct GET /secure-login/ still returns 200 (no over-blocking of the login URL itself)', async () => {
    const ctx = await pwRequest.newContext({ ignoreHTTPSErrors: true });
    const res = await ctx.get(`${WP_BASE_URL}/${loginSlug()}/`);
    expect(res.status()).toBe(200);
    const body = await res.text();
    // The portal renders with the two-step shortcode output.
    expect(body).toContain('tssl-login-form');
    await ctx.dispose();
  });
});

test.describe('M4 — CAPTCHA silent fail-open is now LOUD', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
    // Force the "configured but missing keys" failure mode:
    //  - provider is set in the snapshot (cloudflare_turnstile)
    //  - keys are deliberately blanked
    // This is exactly the misconfiguration scenario the audit POC
    // described.
    updateSettings({
      turnstile_site_key: '',
      turnstile_secret_key: '',
    });
    // Clear any prior rate-limit transient so the error_log assertion
    // below is deterministic.
    wpEval('delete_transient( "tssl_captcha_misconfig_logged" );');
  });

  test.afterAll(() => {
    restoreSettingsFromSnapshot();
    wpEval('delete_transient( "tssl_captcha_misconfig_logged" );');
  });

  test('is_active_for() returns false when keys are missing (policy unchanged)', async () => {
    // Confirm the documented policy ("missing keys never block login") is
    // still in force. M4 changes visibility, NOT this policy.
    const out = wpEval(
      '$c = new TSSL_Captcha( new TSSL_Settings(), new TSSL_Security() );' +
      'echo $c->is_active_for( "username_step" ) ? "ON" : "OFF";',
    );
    expect(out).toBe('OFF');
  });

  test('admin sees a persistent admin_notices banner explaining the misconfiguration', async ({ page }) => {
    await loginViaSecureFlow(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/`);
    // Scope to the specific banner — WP dashboards include several
    // unrelated `.notice-error` elements (community-events JS-required,
    // update nag, etc.) so a bare locator hits strict-mode.
    const banner = page.locator('.notice-error', { hasText: /CAPTCHA is disabled/i });
    await expect(banner).toHaveCount(1);
    await expect(banner).toContainText(/Cloudflare Turnstile/);
    await expect(banner.locator('a')).toHaveAttribute(
      'href',
      /admin\.php\?page=tssl-settings/,
    );
  });

  test('the banner DISAPPEARS when keys are configured (regression guard)', async ({ page }) => {
    updateSettings({
      // Cloudflare's documented "always passes" test pair. Useful here as
      // a CONFIGURED state, not as a working CAPTCHA — we log in via
      // /wp-login.php to sidestep the two-step flow's CAPTCHA check.
      turnstile_site_key: '1x00000000000000000000AA',
      turnstile_secret_key: '1x0000000000000000000000000000000AA',
    });
    // Use wp-login.php directly so the test doesn't depend on the real
    // Turnstile widget loading in a headless browser. With the snapshot
    // default `hide_default_login_urls=0`, /wp-login.php is reachable
    // and goes through standard WP auth (no plugin CAPTCHA check).
    await loginViaWpLogin(page);
    await page.goto(`${WP_BASE_URL}/wp-admin/`);
    const captchaWarn = page.locator('.notice-error', { hasText: /CAPTCHA is disabled/i });
    await expect(captchaWarn).toHaveCount(0);
    // Restore the misconfigured state for tests that may run after.
    updateSettings({
      turnstile_site_key: '',
      turnstile_secret_key: '',
    });
  });

  test('error_log line fires on the first verification skip and is rate-limited by transient', async () => {
    // The rate-limit transient gates re-logging. Calling is_active_for()
    // twice in a row should set the transient on the first call and
    // skip the log on the second.
    wpEval('delete_transient( "tssl_captcha_misconfig_logged" );');
    expect(wpEval('echo get_transient( "tssl_captcha_misconfig_logged" ) ? "SET" : "UNSET";')).toBe(
      'UNSET',
    );
    wpEval(
      '$c = new TSSL_Captcha( new TSSL_Settings(), new TSSL_Security() );' +
      '$c->is_active_for( "username_step" );',
    );
    expect(wpEval('echo get_transient( "tssl_captcha_misconfig_logged" ) ? "SET" : "UNSET";')).toBe(
      'SET',
    );
    // A second invocation must not throw and must leave the transient
    // intact (it's a quiet no-op when the transient is already set).
    wpEval(
      '$c = new TSSL_Captcha( new TSSL_Settings(), new TSSL_Security() );' +
      '$c->is_active_for( "username_step" );',
    );
    expect(wpEval('echo get_transient( "tssl_captcha_misconfig_logged" ) ? "SET" : "UNSET";')).toBe(
      'SET',
    );
  });

  test('the login flow still WORKS for users when CAPTCHA is silently disabled', async ({ page }) => {
    // The most important M4 invariant: even though we've made the
    // misconfiguration loud, we must NOT have broken the documented
    // policy that missing keys do not block login. A real user with the
    // right password should still be able to authenticate via the
    // two-step flow.
    await loginViaSecureFlow(page);
    await expect(page).toHaveURL(/\/wp-admin/);
  });
});
