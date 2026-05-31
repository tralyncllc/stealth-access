import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { clearSession, loginViaSecureFlow, loginViaWpLogin } from '../helpers/auth';
import { getSettings, restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { shot, startConsoleCapture } from '../helpers/capture';
import { WP_CLI_CMD } from '../helpers/env';

const SETTINGS_URL = '/wp-admin/options-general.php?page=tssl-settings';

/**
 * Run PHP inside the WP container via `wp eval`. The PHP must not contain
 * literal single quotes since we wrap it in single quotes for the shell.
 *
 * @param php Code without the opening `<?php` tag.
 */
function wpEval(php: string): string {
  const code = php.replace(/^<\?php\s*/, '').trim();
  const out = execSync(
    `${WP_CLI_CMD} eval '${code}'`,
    { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] },
  );
  return out.trim();
}

/**
 * Write a raw value into tssl_settings bypassing the sanitize filter. Used
 * to seed legacy/invalid values for backward-compat tests.
 */
function writeRawSetting(key: string, value: unknown): void {
  wpEval(`
global $wpdb;
$cur = get_option("tssl_settings");
if (!is_array($cur)) { $cur = array(); }
$cur["${key}"] = ${JSON.stringify(value)};
$wpdb->update($wpdb->options, array("option_value" => maybe_serialize($cur)), array("option_name" => "tssl_settings"));
wp_cache_delete("tssl_settings", "options");
echo "ok";
  `);
}

/**
 * Invoke the sanitize callback with a single field and return the cleaned
 * value as a parsed JSON value.
 */
function sanitizeOne(field: string, raw: unknown): unknown {
  const result = wpEval(`
require_once "/var/www/html/wp-content/plugins/stealth-access/includes/class-tssl-settings.php";
$s = new TSSL_Settings();
$out = $s->sanitize(array("${field}" => ${JSON.stringify(raw)}));
echo isset($out["${field}"]) ? json_encode($out["${field}"]) : "null";
  `);
  return JSON.parse(result);
}

test.describe('CAPTCHA provider — UI + persistence', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
  });

  test('provider dropdown exposes exactly 2 options (Turnstile + Google) — no "None", no v2/v3 split', async ({ page }) => {
    await page.goto(SETTINGS_URL);
    const sel = page.locator('select[name="tssl_settings[captcha_provider]"]');
    await expect(sel.locator('option')).toHaveCount(2);
    await expect(sel.locator('option[value="cloudflare_turnstile"]')).toHaveCount(1);
    await expect(sel.locator('option[value="google_recaptcha"]')).toHaveCount(1);
    await expect(sel.locator('option[value="none"]')).toHaveCount(0);
    await expect(sel.locator('option[value="google_recaptcha_v2"]')).toHaveCount(0);
    await expect(sel.locator('option[value="google_recaptcha_v3"]')).toHaveCount(0);
    await expect(sel.locator('option[value="cloudflare_turnstile"]')).toHaveText(/Recommended/i);
    await expect(sel.locator('option[value="google_recaptcha"]')).toHaveText(/Compatibility/i);
  });

  test('Google panel exposes a Mode select with Checkbox (v2) and Score-Based (v3)', async ({ page }) => {
    await page.goto(SETTINGS_URL);
    await page.locator('select[name="tssl_settings[captcha_provider]"]').selectOption('google_recaptcha');
    const mode = page.locator('select[name="tssl_settings[recaptcha_mode]"]');
    await expect(mode).toBeVisible();
    await expect(mode.locator('option')).toHaveCount(2);
    await expect(mode.locator('option[value="checkbox"]')).toContainText(/Checkbox Challenge/i);
    await expect(mode.locator('option[value="score"]')).toContainText(/Score-Based/i);
  });

  test('default CAPTCHA provider is Cloudflare Turnstile', async ({ page }) => {
    await page.goto(SETTINGS_URL);
    await expect(page.locator('select[name="tssl_settings[captcha_provider]"]')).toHaveValue('cloudflare_turnstile');
  });

  test('legacy DB value "none" normalizes to cloudflare_turnstile', async ({ page }) => {
    writeRawSetting('captcha_provider', 'none');
    try {
      await page.goto(SETTINGS_URL);
      await expect(page.locator('select[name="tssl_settings[captcha_provider]"]')).toHaveValue('cloudflare_turnstile');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('migration: legacy "google_recaptcha" → provider=google_recaptcha, mode=checkbox (keys preserved)', async ({ page }) => {
    writeRawSetting('captcha_provider', 'google_recaptcha');
    writeRawSetting('recaptcha_site_key', 'V2_SITE_FROM_LEGACY');
    writeRawSetting('recaptcha_secret_key', 'V2_SECRET_FROM_LEGACY');
    try {
      const raw = wpEval('$o = get_option("tssl_settings"); echo $o["captcha_provider"];');
      expect(raw).toBe('google_recaptcha'); // unchanged in DB
      await page.goto(SETTINGS_URL);
      await expect(page.locator('select[name="tssl_settings[captcha_provider]"]')).toHaveValue('google_recaptcha');
      await expect(page.locator('select[name="tssl_settings[recaptcha_mode]"]')).toHaveValue('checkbox');
      // v2 input still carries the previously saved site key.
      await expect(page.locator('#tssl-recaptcha-site')).toHaveValue('V2_SITE_FROM_LEGACY');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('migration: legacy "google_recaptcha_v2" → provider=google_recaptcha, mode=checkbox (keys preserved)', async ({ page }) => {
    writeRawSetting('captcha_provider', 'google_recaptcha_v2');
    writeRawSetting('recaptcha_site_key', 'KEEP_V2_SITE');
    writeRawSetting('recaptcha_secret_key', 'KEEP_V2_SECRET');
    try {
      await page.goto(SETTINGS_URL);
      await expect(page.locator('select[name="tssl_settings[captcha_provider]"]')).toHaveValue('google_recaptcha');
      await expect(page.locator('select[name="tssl_settings[recaptcha_mode]"]')).toHaveValue('checkbox');
      await expect(page.locator('#tssl-recaptcha-site')).toHaveValue('KEEP_V2_SITE');
      // Secret rendered blank by design but stored value is intact.
      expect(getSettings().recaptcha_secret_key).toBe('KEEP_V2_SECRET');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('migration: legacy "google_recaptcha_v3" → provider=google_recaptcha, mode=score (keys + threshold preserved)', async ({ page }) => {
    writeRawSetting('captcha_provider', 'google_recaptcha_v3');
    writeRawSetting('recaptcha_v3_site_key', 'KEEP_V3_SITE');
    writeRawSetting('recaptcha_v3_secret_key', 'KEEP_V3_SECRET');
    writeRawSetting('recaptcha_v3_threshold', 0.7);
    try {
      await page.goto(SETTINGS_URL);
      await expect(page.locator('select[name="tssl_settings[captcha_provider]"]')).toHaveValue('google_recaptcha');
      await expect(page.locator('select[name="tssl_settings[recaptcha_mode]"]')).toHaveValue('score');
      await expect(page.locator('#tssl-recaptcha-v3-site')).toHaveValue('KEEP_V3_SITE');
      await expect(page.locator('#tssl-recaptcha-v3-threshold')).toHaveValue('0.7');
      expect(getSettings().recaptcha_v3_secret_key).toBe('KEEP_V3_SECRET');
      expect(getSettings().recaptcha_v3_threshold).toBeCloseTo(0.7, 5);
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('selecting Google + Score-Based and saving persists the new pair', async ({ page }) => {
    await page.goto(SETTINGS_URL);
    await page.locator('select[name="tssl_settings[captcha_provider]"]').selectOption('google_recaptcha');
    await page.locator('select[name="tssl_settings[recaptcha_mode]"]').selectOption('score');
    await page.locator('input[name="tssl_settings[recaptcha_v3_site_key]"]').fill('SITE_V3_TEST');
    await page.locator('input[name="tssl_settings[recaptcha_v3_secret_key]"]').fill('SECRET_V3_TEST');
    await page.locator('input[name="tssl_settings[recaptcha_v3_threshold]"]').fill('0.7');
    await Promise.all([
      page.waitForURL(/settings-updated=true/),
      page.locator('#submit').click(),
    ]);
    const stored = getSettings();
    expect(stored.captcha_provider).toBe('google_recaptcha');
    expect(stored.recaptcha_mode).toBe('score');
    expect(stored.recaptcha_v3_site_key).toBe('SITE_V3_TEST');
    expect(stored.recaptcha_v3_secret_key).toBe('SECRET_V3_TEST');
    expect(stored.recaptcha_v3_threshold).toBeCloseTo(0.7, 5);
    restoreSettingsFromSnapshot();
  });

  test('threshold sanitize clamps [0.0, 1.0] and falls back to 0.5 for junk input', () => {
    expect(sanitizeOne('recaptcha_v3_threshold', 0.7)).toBeCloseTo(0.7, 5);
    expect(sanitizeOne('recaptcha_v3_threshold', 5)).toBe(1.0);
    expect(sanitizeOne('recaptcha_v3_threshold', -2)).toBe(0.0);
    expect(sanitizeOne('recaptcha_v3_threshold', 'not-a-number')).toBe(0.5);
    expect(sanitizeOne('recaptcha_v3_threshold', '0.3')).toBeCloseTo(0.3, 5);
  });
});

test.describe('reCAPTCHA v3 — runtime behavior', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
  });

  test('default provider with no keys → CAPTCHA disabled, no widget rendered, no scripts enqueued, login flow still works', async ({ page }) => {
    // Snapshot defaults already: provider=cloudflare_turnstile, keys empty.
    const cap = startConsoleCapture(page);

    const r = await page.goto('/secure-login/');
    expect(r?.status()).toBe(200);
    const html = (await page.content()).toLowerCase();
    expect(html).not.toMatch(/<b>(warning|fatal error|parse error)<\/b>/);

    // No CAPTCHA widget on the page.
    await expect(page.locator('.cf-turnstile')).toHaveCount(0);
    await expect(page.locator('.g-recaptcha')).toHaveCount(0);
    await expect(page.locator('.tssl-recaptcha-v3')).toHaveCount(0);
    // No CAPTCHA scripts enqueued.
    await expect(page.locator('script[src*="recaptcha/api.js"]')).toHaveCount(0);
    await expect(page.locator('script[src*="challenges.cloudflare.com"]')).toHaveCount(0);

    expect(cap.pageErrors, cap.pageErrors.join('\n')).toEqual([]);

    // Login flow still works end-to-end with CAPTCHA disabled.
    await loginViaSecureFlow(page);
    await expect(page).toHaveURL(/\/wp-admin/);
  });

  test('configured but unselected providers (v3 with no keys) keep CAPTCHA disabled', async ({ page }) => {
    updateSettings({
      captcha_provider: 'google_recaptcha_v3',
      captcha_location: 'username_step',
      recaptcha_v3_site_key: '',
      recaptcha_v3_secret_key: '',
    });
    try {
      const cap = startConsoleCapture(page);
      const r = await page.goto('/secure-login/');
      expect(r?.status()).toBe(200);
      const html = (await page.content()).toLowerCase();
      expect(html).not.toMatch(/<b>(warning|fatal error|parse error)<\/b>/);
      // No widget, no script.
      await expect(page.locator('.tssl-recaptcha-v3')).toHaveCount(0);
      await expect(page.locator('script[src*="recaptcha/api.js"]')).toHaveCount(0);
      expect(cap.pageErrors).toEqual([]);
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('admin status callout reads "Selected, not configured" when keys are missing', async ({ page }) => {
    await loginViaWpLogin(page);
    await page.goto(SETTINGS_URL);
    // Defaults: provider=Turnstile, no keys → callout visible.
    const status = page.locator('#tssl-captcha-status');
    await expect(status).toBeVisible();
    await expect(status).toContainText(/selected,\s*not configured/i);
  });

  test('reCAPTCHA v3 secret key never leaks into PUBLIC HTML', async ({ page }) => {
    updateSettings({
      captcha_provider: 'google_recaptcha_v3',
      captcha_location: 'username_step',
      recaptcha_v3_site_key: 'SITE_V3_TEST_PUBLIC',
      recaptcha_v3_secret_key: 'super_secret_should_never_render',
    });
    try {
      await page.goto('/secure-login/');
      const publicHtml = await page.content();
      expect(publicHtml).not.toContain('super_secret_should_never_render');

      // v3 widget wrapper + hidden input rendered, site key allowed.
      await expect(page.locator('.tssl-recaptcha-v3')).toHaveCount(1);
      await expect(page.locator('input[name="g-recaptcha-response"]')).toHaveCount(1);
      const sitekey = await page.locator('.tssl-recaptcha-v3').getAttribute('data-sitekey');
      expect(sitekey).toBe('SITE_V3_TEST_PUBLIC');
      const actionAttr = await page.locator('.tssl-recaptcha-v3').getAttribute('data-action');
      expect(actionAttr).toBe('tssl_username_step');
      await shot(page, '70-secure-login-with-v3');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('saved secret keys never appear in admin HTML', async ({ page }) => {
    updateSettings({
      captcha_provider: 'google_recaptcha_v3',
      turnstile_secret_key: 'TURN_SECRET_NEVER_LEAK',
      recaptcha_secret_key: 'V2_SECRET_NEVER_LEAK',
      recaptcha_v3_secret_key: 'V3_SECRET_NEVER_LEAK',
    });
    try {
      await loginViaWpLogin(page);
      await page.goto(SETTINGS_URL);
      const html = await page.content();
      // None of the three secrets may appear anywhere in the admin HTML.
      expect(html).not.toContain('TURN_SECRET_NEVER_LEAK');
      expect(html).not.toContain('V2_SECRET_NEVER_LEAK');
      expect(html).not.toContain('V3_SECRET_NEVER_LEAK');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('helper text shows "A secret key is saved" when one is stored, otherwise "Enter the secret key…"', async ({ page }) => {
    // Start with all secrets blank.
    updateSettings({
      turnstile_secret_key: '',
      recaptcha_secret_key: '',
      recaptcha_v3_secret_key: '',
    });
    await loginViaWpLogin(page);
    await page.goto(SETTINGS_URL);
    // Each secret input should have data-tssl-has-saved="0" and the
    // sibling .tssl-secret-hint should read "Enter the secret key…".
    const inputs = page.locator('input.tssl-secret-input');
    await expect(inputs).toHaveCount(3);
    for (let i = 0; i < 3; i++) {
      await expect(inputs.nth(i)).toHaveAttribute('data-tssl-has-saved', '0');
    }
    const hints = page.locator('.tssl-secret-hint');
    await expect(hints).toHaveCount(3);
    for (let i = 0; i < 3; i++) {
      await expect(hints.nth(i)).toContainText(/enter the secret key/i);
    }

    // Now populate all three and reload — hints flip to "A secret key is saved.".
    updateSettings({
      turnstile_secret_key: 'TURN_HINT_TEST',
      recaptcha_secret_key: 'V2_HINT_TEST',
      recaptcha_v3_secret_key: 'V3_HINT_TEST',
    });
    try {
      await page.goto(SETTINGS_URL);
      for (let i = 0; i < 3; i++) {
        await expect(inputs.nth(i)).toHaveAttribute('data-tssl-has-saved', '1');
        // The rendered value must be empty.
        await expect(inputs.nth(i)).toHaveValue('');
      }
      for (let i = 0; i < 3; i++) {
        await expect(hints.nth(i)).toContainText(/a secret key is saved/i);
      }
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('saved secret shows masked fingerprint — only last 4 characters visible, full secret never in HTML', async ({ page }) => {
    // Distinct multi-character secrets so we can tell each fingerprint apart.
    const TURN = 'TURNSTILE_LONG_SECRET_ABCD';   // last 4 = ABCD
    const V2   = 'RECAPTCHA_V2_LONG_SECRET_WXYZ'; // last 4 = WXYZ
    const V3   = 'RECAPTCHA_V3_LONG_SECRET_8f3K'; // last 4 = 8f3K
    updateSettings({
      turnstile_secret_key: TURN,
      recaptcha_secret_key: V2,
      recaptcha_v3_secret_key: V3,
    });
    try {
      await loginViaWpLogin(page);
      await page.goto(SETTINGS_URL);

      // No full secret may appear anywhere in the rendered HTML.
      const html = await page.content();
      expect(html).not.toContain(TURN);
      expect(html).not.toContain(V2);
      expect(html).not.toContain(V3);

      // Each provider's panel renders one .tssl-secret-fingerprint.
      const turnFp = await page.locator('#tssl-turnstile-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();
      const v2Fp   = await page.locator('#tssl-recaptcha-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();
      const v3Fp   = await page.locator('#tssl-recaptcha-v3-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();

      // Last 4 chars of each secret appear at the end of the fingerprint.
      expect(turnFp).not.toBeNull();
      expect(v2Fp).not.toBeNull();
      expect(v3Fp).not.toBeNull();
      expect(turnFp!.endsWith('ABCD')).toBe(true);
      expect(v2Fp!.endsWith('WXYZ')).toBe(true);
      expect(v3Fp!.endsWith('8f3K')).toBe(true);

      // Every character before the last 4 is the bullet (U+2022).
      const bullet = '•';
      expect(turnFp!.slice(0, -4)).toMatch(new RegExp('^[' + bullet + ']+$'));
      expect(v2Fp!.slice(0, -4)).toMatch(new RegExp('^[' + bullet + ']+$'));
      expect(v3Fp!.slice(0, -4)).toMatch(new RegExp('^[' + bullet + ']+$'));

      // The number of bullets equals (secret length − 4) — i.e. the mask is
      // 1:1 with secret length so we don't accidentally hide it as
      // arbitrarily short.
      expect(turnFp!.length).toBe(TURN.length);
      expect(v2Fp!.length).toBe(V2.length);
      expect(v3Fp!.length).toBe(V3.length);

      // Earlier portion of the original secret must NOT be visible.
      expect(turnFp).not.toContain('TURNSTILE');
      expect(v2Fp).not.toContain('RECAPTCHA');
      expect(v3Fp).not.toContain('RECAPTCHA');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('short secret (length ≤ 4) shows only bullets — no characters revealed', async ({ page }) => {
    // Login once, reuse the session across both short-secret cases.
    await loginViaWpLogin(page);
    // Test with exactly 4 chars AND with 1 char — both must collapse to ••••.
    for (const SHORT of ['abcd', 'q']) {
      updateSettings({ turnstile_secret_key: SHORT });
      try {
        await page.goto(SETTINGS_URL);

        // The fingerprint element itself must be exactly four bullets.
        // (We can't grep the full admin HTML for a 1-char secret because
        //  short common letters appear in WP admin chrome — header IDs,
        //  classes, etc. The real safety guarantee is fingerprint-local.)
        const fp = await page.locator('#tssl-turnstile-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();
        expect(fp).not.toBeNull();
        expect(fp).toBe('••••');
        // No characters from the original secret may appear inside the fingerprint.
        for (const ch of SHORT) {
          expect(fp).not.toContain(ch);
        }
        // Sanity: input value is still empty.
        await expect(page.locator('#tssl-turnstile-secret')).toHaveValue('');
      } finally {
        restoreSettingsFromSnapshot();
      }
    }
  });

  test('replacing a secret updates the fingerprint on the next page load', async ({ page }) => {
    updateSettings({ turnstile_secret_key: 'ORIGINAL_SECRET_END1' });
    try {
      await loginViaWpLogin(page);
      await page.goto(SETTINGS_URL);
      let fp = await page.locator('#tssl-turnstile-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();
      expect(fp!.endsWith('END1')).toBe(true);

      // Now type a new secret and save.
      await page.locator('#tssl-turnstile-secret').fill('REPLACEMENT_SECRET_END2');
      await Promise.all([
        page.waitForURL(/settings-updated=true/),
        page.locator('#submit').click(),
      ]);
      // Reload, fingerprint should now end in END2.
      await page.goto(SETTINGS_URL);
      fp = await page.locator('#tssl-turnstile-secret').locator('..').locator('.tssl-secret-fingerprint').textContent();
      expect(fp!.endsWith('END2')).toBe(true);
      expect(fp).not.toContain('END1');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('blank secret field on save preserves the existing stored value', async ({ page }) => {
    const ORIGINAL = 'KEEP_THIS_TURNSTILE_SECRET';
    updateSettings({ turnstile_secret_key: ORIGINAL });
    try {
      await loginViaWpLogin(page);
      await page.goto(SETTINGS_URL);

      // The rendered field is empty by design — just submit without typing.
      await expect(page.locator('#tssl-turnstile-secret')).toHaveValue('');
      await Promise.all([
        page.waitForURL(/settings-updated=true/),
        page.locator('#submit').click(),
      ]);

      // Stored value must be unchanged.
      const stored = getSettings();
      expect(stored.turnstile_secret_key).toBe(ORIGINAL);
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('non-blank secret field on save replaces the existing stored value', async ({ page }) => {
    // Set v3 as the current provider so the v3 panel is visible for editing.
    updateSettings({ captcha_provider: 'google_recaptcha_v3', recaptcha_v3_secret_key: 'OLD_V3_SECRET' });
    try {
      await loginViaWpLogin(page);
      await page.goto(SETTINGS_URL);
      // Field renders blank; type a replacement.
      await page.locator('#tssl-recaptcha-v3-secret').fill('NEW_V3_SECRET');
      await Promise.all([
        page.waitForURL(/settings-updated=true/),
        page.locator('#submit').click(),
      ]);
      const stored = getSettings();
      expect(stored.recaptcha_v3_secret_key).toBe('NEW_V3_SECRET');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('v3 script URL embeds the site key via ?render=', async ({ page }) => {
    updateSettings({
      captcha_provider: 'google_recaptcha_v3',
      captcha_location: 'username_step',
      recaptcha_v3_site_key: 'SITE_V3_TEST_PUBLIC',
      recaptcha_v3_secret_key: 'sekret',
    });
    try {
      await page.goto('/secure-login/');
      const apiScript = page.locator('script[src*="recaptcha/api.js"]');
      await expect(apiScript).toHaveCount(1);
      const src = await apiScript.getAttribute('src');
      expect(src).toContain('render=SITE_V3_TEST_PUBLIC');
    } finally {
      restoreSettingsFromSnapshot();
    }
  });
});
