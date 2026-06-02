/**
 * Login-page self-healing regression.
 *
 * Guards the fresh-install / custom-slug bug where `custom_login_slug` in
 * settings pointed at one slug while the tracked login page kept another
 * `post_name`, so the configured URL 404'd even though a published login
 * page existed (and neither "save permalinks" nor a downgrade fixed it,
 * because the bad state lived in the database).
 *
 * The plugin now reconciles the tracked page against the configured slug
 * on every settings save and on every admin page load, repairing stale /
 * wrong / missing IDs and re-slugging a drifted page so the configured
 * URL always resolves.
 *
 * These tests intentionally mutate the custom slug (which renames the
 * login page). `afterAll` restores the canonical `secure-login` state so
 * downstream specs are unaffected.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { WP_BASE_URL, WP_CLI_CMD } from '../helpers/env';
import { clearSession } from '../helpers/auth';
import {
  getSettings,
  updateSettings,
  restoreSettingsFromSnapshot,
} from '../helpers/settings';

const HEAL_SLUG = 'ultimax-login';

/** Run a wp-cli command against the WordPress under test. */
function wpCli(args: string, stdin?: string): string {
  return execSync(`${WP_CLI_CMD} ${args}`, {
    encoding: 'utf8',
    input: stdin,
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

/**
 * Evaluate PHP against the WordPress under test, piping the code via STDIN
 * to `wp eval-file -`. Passing the code on STDIN (rather than as a shell
 * argument) keeps `$var` tokens out of the shell, so PHP variables aren't
 * mangled by bash interpolation.
 */
function wpEval(php: string): string {
  return wpCli('eval-file -', `<?php\n${php}`);
}

/** Resolve the actual page ID that owns a given slug (0 if none). */
function pageIdForSlug(slug: string): number {
  const out = wpCli(
    `post list --post_type=page --name=${slug} --post_status=publish --field=ID --format=ids`,
  ).trim();
  const id = parseInt(out.split(/\s+/)[0] || '0', 10);
  return Number.isNaN(id) ? 0 : id;
}

/**
 * Corrupt the stored option WITHOUT going through the reconcile-on-save
 * hook, so we can prove the admin-load self-heal path (not just the
 * save-time path). `remove_all_actions` is scoped to this one CLI process.
 */
function corruptSettingsBypassingReconcile(js: string): void {
  wpEval(
    [
      'remove_all_actions("update_option_tssl_settings");',
      'remove_all_actions("add_option_tssl_settings");',
      '$o = get_option("tssl_settings");',
      js,
      'update_option("tssl_settings", $o);',
      'echo "ok";',
    ].join('\n'),
  );
}

test.describe('Login page self-healing', () => {
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test.afterAll(() => {
    // Restore the canonical secure-login slug + snapshot for downstream specs.
    updateSettings({ custom_login_slug: 'secure-login', auto_create_login_page: 1 });
    restoreSettingsFromSnapshot();
  });

  test('configuring a custom slug renames the tracked page and the URL resolves', async ({ page }) => {
    updateSettings({ custom_login_slug: HEAL_SLUG, auto_create_login_page: 1 });

    // The configured URL must resolve (200) and render the portal.
    const resp = await page.goto(`${WP_BASE_URL}/${HEAL_SLUG}/`);
    expect(resp?.status(), 'configured custom login URL should return 200').toBe(200);
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('.tssl-portal-title')).toBeVisible();

    // The stored login_page_id must match the page that actually owns the slug.
    const settings = getSettings();
    const realId = pageIdForSlug(HEAL_SLUG);
    expect(realId, 'a published page should own the configured slug').toBeGreaterThan(0);
    expect(
      settings.login_page_id,
      'stored login_page_id must match the real page id',
    ).toBe(realId);
    expect(settings.custom_login_slug).toBe(HEAL_SLUG);
  });

  test('the page is renamed (not duplicated) — only one login page exists', async () => {
    updateSettings({ custom_login_slug: HEAL_SLUG, auto_create_login_page: 1 });

    // Count pages carrying the login shortcode. Renaming must not leave an
    // orphaned second copy behind.
    const count = wpEval(
      'echo count(array_filter(get_posts(array("post_type"=>"page","post_status"=>"any","numberposts"=>-1)), function($p){ return strpos($p->post_content, "two_step_secure_login") !== false; }));',
    ).trim();
    expect(parseInt(count, 10), 'exactly one login page should exist').toBe(1);
  });

  test('a stale login_page_id self-heals (adopt branch)', async ({ page }) => {
    // Start from the healed custom-slug state.
    updateSettings({ custom_login_slug: HEAL_SLUG, auto_create_login_page: 1 });
    const realId = pageIdForSlug(HEAL_SLUG);
    expect(realId).toBeGreaterThan(0);

    // Write a bogus tracked id through the normal save path. reconcile-on-save
    // must immediately repair it by adopting the page that owns the slug.
    // (The same reconcile runs on every admin load, so a corrupt id that
    // somehow lands in the DB out-of-band heals on the next wp-admin visit.)
    updateSettings({ login_page_id: 999999 });

    expect(
      getSettings().login_page_id,
      'a stale login_page_id must self-heal to the real page id',
    ).toBe(realId);

    // The configured URL still resolves and renders the portal.
    const resp = await page.goto(`${WP_BASE_URL}/${HEAL_SLUG}/`);
    expect(resp?.status()).toBe(200);
    await expect(page.locator('.tssl-card')).toBeVisible();
  });

  test('a drifted page slug (settings vs post_name divergence) self-heals (re-slug branch)', async ({ page }) => {
    // Reproduce the exact user bug: settings say the slug is HEAL_SLUG but the
    // tracked page keeps a different post_name, so the configured URL 404s.
    updateSettings({ custom_login_slug: HEAL_SLUG, auto_create_login_page: 1 });
    const realId = pageIdForSlug(HEAL_SLUG);
    expect(realId).toBeGreaterThan(0);

    // Force the page to a different post_name and point settings at HEAL_SLUG,
    // both WITHOUT firing reconcile — the persisted divergent state.
    wpCli(`post update ${realId} --post_name=drifted-login`);
    corruptSettingsBypassingReconcile(
      `$o["custom_login_slug"] = "${HEAL_SLUG}"; $o["login_page_id"] = ${realId};`,
    );

    // Confirm the bug exists before healing: configured URL 404s.
    const broken = await page.goto(`${WP_BASE_URL}/${HEAL_SLUG}/`);
    expect(broken?.status(), 'pre-heal: divergent slug should 404').toBe(404);

    // The next settings save (here an unrelated toggle) reconciles the drifted
    // page slug back to the configured slug. The same reconcile runs on every
    // admin load, so an admin simply opening wp-admin heals it too.
    const cur = getSettings();
    updateSettings({ hide_lost_password: cur.hide_lost_password ? 0 : 1 });
    updateSettings({ hide_lost_password: cur.hide_lost_password });

    // Post-heal: configured URL resolves and renders the portal.
    const healed = await page.goto(`${WP_BASE_URL}/${HEAL_SLUG}/`);
    expect(healed?.status(), 'post-heal: configured URL should resolve').toBe(200);
    await expect(page.locator('.tssl-card')).toBeVisible();

    // The tracked page's post_name now matches the configured slug.
    expect(getSettings().login_page_id).toBe(realId);
    expect(pageIdForSlug(HEAL_SLUG)).toBe(realId);
  });

  test('upgrade path preserved: saving unrelated settings never breaks a healthy login page', async ({ page }) => {
    // Healthy state at the canonical slug.
    updateSettings({ custom_login_slug: 'secure-login', auto_create_login_page: 1 });
    const before = getSettings();
    const beforeId = pageIdForSlug('secure-login');
    expect(beforeId).toBeGreaterThan(0);
    expect(before.login_page_id).toBe(beforeId);

    // Toggle an unrelated setting (mirrors a routine save on an upgraded install).
    updateSettings({ hide_default_login_urls: 1 });
    updateSettings({ hide_default_login_urls: 0 });

    // The login page id is untouched and the portal still resolves.
    const after = getSettings();
    expect(after.login_page_id, 'unrelated saves must not change login_page_id').toBe(beforeId);

    const resp = await page.goto(`${WP_BASE_URL}/secure-login/`);
    expect(resp?.status()).toBe(200);
    await expect(page.locator('.tssl-card')).toBeVisible();
  });
});
