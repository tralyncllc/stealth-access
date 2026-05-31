import { test, expect } from '@playwright/test';
import { clearSession, loginViaSecureFlow } from '../helpers/auth';
import { getSettings, restoreSettingsFromSnapshot, updateSettings } from '../helpers/settings';
import { shot, startConsoleCapture } from '../helpers/capture';

test.describe('Settings page', () => {
  test.beforeAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.afterAll(() => {
    restoreSettingsFromSnapshot();
  });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
  });

  test('settings page loads with no errors', async ({ page }) => {
    const cap = startConsoleCapture(page);
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    await expect(page.locator('h1', { hasText: 'Stealth Access' })).toBeVisible();
    const html = await page.content();
    expect(html).not.toMatch(/<b>(Warning|Notice|Fatal|Parse error)<\/b>/);
    await shot(page, '20-settings-page');
    expect(cap.pageErrors).toEqual([]);
  });

  test('toggling each boolean setting persists across refresh', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');

    // Toggle disable_password_reset and hide_default_login_urls.
    await page.locator('input[name="tssl_settings[disable_password_reset]"]').check();
    await page.locator('input[name="tssl_settings[hide_default_login_urls]"]').check();
    await page.locator('input[name="tssl_settings[enable_login_branding]"]').check();
    await Promise.all([
      page.waitForURL(/settings-updated=true/),
      page.locator('#submit').click(),
    ]);
    await shot(page, '21-settings-after-save');

    // Read back via WP-CLI to verify persistence at the DB layer.
    const stored = getSettings();
    expect(stored.disable_password_reset).toBe(1);
    expect(stored.hide_default_login_urls).toBe(1);
    expect(stored.enable_login_branding).toBe(1);

    // Reload the page and confirm the UI reflects what was saved.
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    await expect(page.locator('input[name="tssl_settings[disable_password_reset]"]')).toBeChecked();
    await expect(page.locator('input[name="tssl_settings[hide_default_login_urls]"]')).toBeChecked();
    await expect(page.locator('input[name="tssl_settings[enable_login_branding]"]')).toBeChecked();

    // Restore defaults BEFORE leaving the test so later tests aren't affected.
    restoreSettingsFromSnapshot();
  });

  test('changing the captcha provider dropdown persists', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    await page.locator('select[name="tssl_settings[captcha_provider]"]').selectOption('cloudflare_turnstile');
    await page.locator('select[name="tssl_settings[captcha_location]"]').selectOption('both');
    await page.locator('input[name="tssl_settings[turnstile_site_key]"]').fill('0xFAKEsiteKey');
    await Promise.all([
      page.waitForURL(/settings-updated=true/),
      page.locator('#submit').click(),
    ]);
    const stored = getSettings();
    expect(stored.captcha_provider).toBe('cloudflare_turnstile');
    expect(stored.captcha_location).toBe('both');
    expect(stored.turnstile_site_key).toBe('0xFAKEsiteKey');
    // Tidy up.
    restoreSettingsFromSnapshot();
  });

  test('reserved slug "wp-admin" is rejected with a settings error', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    await page.locator('input[name="tssl_settings[custom_login_slug]"]').fill('wp-admin');
    await Promise.all([
      page.waitForURL(/settings-updated=true/),
      page.locator('#submit').click(),
    ]);
    // Slug should have been reset to the default; an admin notice should appear.
    const stored = getSettings();
    expect(stored.custom_login_slug).toBe('secure-login');
    // Scope to the settings-error notice so we don't collide with the
    // WordPress core update-available `.notice` that CI's WP install
    // sometimes shows.
    await expect(page.locator('.notice.notice-error.settings-error')).toContainText(/reserved/i);
    await shot(page, '22-settings-reserved-slug');
  });
});

test.describe('Plugin metadata branding', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
  });

  test('WordPress Plugins page lists "Stealth Access" by "Tralync LLC" — and not by Donnie Hanna', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    const html = await page.content();
    expect(html).toContain('Stealth Access');
    expect(html).toContain('Tralync LLC');
    expect(html).not.toContain('Donnie Hanna');
    // Old display name must not appear anywhere on the Plugins page.
    expect(html).not.toContain('Secure Login Shield');
  });

  test('Plugins page no longer matches the wrong wordpress.org plugin', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    // The row's data-slug must be our new slug, not the old one.
    const rowSlugs = await page.locator('tr[data-slug]').evaluateAll(
      (els) => els.map((e) => (e as HTMLElement).getAttribute('data-slug')),
    );
    expect(rowSlugs).toContain('stealth-access');
    expect(rowSlugs).not.toContain('secure-login-shield');
    // No "wrong-plugin" update row should be attached.
    await expect(page.locator('#stealth-access-update')).toHaveCount(0);
    await expect(page.locator('#secure-login-shield-update')).toHaveCount(0);
    // No phantom "2.0.5" version text (the legacy repo plugin's version).
    const rowHtml = await page.locator('tr[data-slug="stealth-access"]').innerHTML();
    expect(rowHtml).not.toContain('2.0.5');
    // No "View details" thickbox link pointing at the wp.org page for the
    // wrong plugin (WordPress only attaches that link to plugins it has
    // matched against the repo).
    const detailsHref = await page
      .locator('tr[data-slug="stealth-access"] a.thickbox')
      .first()
      .getAttribute('href')
      .catch(() => null);
    if (detailsHref) {
      expect(detailsHref).not.toContain('plugin=secure-login-shield');
      expect(detailsHref).not.toContain('plugin-information&plugin=secure-login-shield');
    }
  });

  test('admin sidebar shows a top-level "Stealth Access" entry (not buried under Settings)', async ({ page }) => {
    await page.goto('/wp-admin/');
    // Top-level menu item: <li id="toplevel_page_stealth-access">.
    const topLevel = page.locator('#adminmenu #toplevel_page_stealth-access');
    await expect(topLevel).toBeAttached();
    // Its first link goes to admin.php?page=stealth-access (the Dashboard).
    const topLink = topLevel.locator('a.menu-top').first();
    await expect(topLink).toHaveAttribute('href', /admin\.php\?page=stealth-access/);
    // It must NOT be a child of the WordPress Settings menu.
    const settingsMenu = page.locator('#adminmenu #menu-settings');
    await expect(settingsMenu.locator('a[href*="page=tssl-settings"]')).toHaveCount(0);
  });

  test('top-level menu has Dashboard + Settings submenus', async ({ page }) => {
    await page.goto('/wp-admin/');
    const subMenu = page.locator('#adminmenu #toplevel_page_stealth-access ul.wp-submenu');
    // Dashboard submenu item points at admin.php?page=stealth-access.
    await expect(subMenu.locator('a[href*="page=stealth-access"]')).toHaveCount(1);
    await expect(subMenu.locator('a[href*="page=stealth-access"]')).toContainText(/Dashboard/i);
    // Settings submenu item points at admin.php?page=tssl-settings.
    await expect(subMenu.locator('a[href*="page=tssl-settings"]')).toHaveCount(1);
    await expect(subMenu.locator('a[href*="page=tssl-settings"]')).toContainText(/Settings/i);
  });

  test('Dashboard page (admin.php?page=stealth-access) loads and shows the status card', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=stealth-access');
    // Plugin header is on the page.
    await expect(page.locator('.tssl-plugin-title')).toContainText('Stealth Access');
    // Status card (the panel re-used from the settings page).
    await expect(page.locator('#tssl-summary-title')).toContainText(/Stealth Access Status/i);
    // The four status badges should all be present.
    await expect(page.locator('.tssl-summary .tssl-badge')).toHaveCount(4);
    // The Settings CTA at the bottom links to admin.php?page=tssl-settings.
    const cta = page.locator('.tssl-dashboard-cta a');
    await expect(cta).toHaveAttribute('href', /admin\.php\?page=tssl-settings/);
    // No PHP errors / notices in the rendered HTML.
    const html = await page.content();
    expect(html).not.toMatch(/<b>(Warning|Notice|Fatal|Parse error)<\/b>/);
  });

  test('Settings page loads at the new URL (admin.php?page=tssl-settings)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=tssl-settings');
    // The settings form heading.
    await expect(page.locator('h1.tssl-plugin-title')).toContainText('Stealth Access');
    // Every settings card is present (proves we're on the real Settings page).
    for (const id of [
      'tssl-card-general',
      'tssl-card-custom-login',
      'tssl-card-captcha',
      'tssl-card-branding',
      'tssl-card-recovery',
    ]) {
      await expect(page.locator(`#${id}`)).toBeVisible();
    }
  });

  test('legacy URL (Settings → Stealth Access) redirects to the new top-level page', async ({ page }) => {
    const response = await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    // After the redirect, the final URL should be admin.php?page=tssl-settings.
    expect(page.url()).toMatch(/\/wp-admin\/admin\.php\?page=tssl-settings/);
    // The destination renders the real Settings page (not Settings → General).
    await expect(page.locator('#tssl-card-captcha')).toBeVisible();
    // The first response was a 301 redirect.
    void response;
  });

  test('Plugins page shows a "Settings" action link before "Deactivate"', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    const actions = page.locator('tr[data-slug="stealth-access"] .row-actions, tr[data-slug="stealth-access"] .plugin-title + .plugin-version-author-uri-license + *, tr.active[data-slug="stealth-access"] .plugin-title, tr[data-slug="stealth-access"] td.plugin-title');
    // The plugin action links live inside the main plugin-title cell as a
    // <div class="row-actions"> in classic WP. Newer themes put them in a
    // `<span>`. The reliable selector is the cell that contains "Deactivate".
    const cell = page.locator('tr[data-slug="stealth-access"] .plugin-title');
    await expect(cell).toContainText(/Settings/);
    await expect(cell).toContainText(/Deactivate/);
    // Settings link points at the new top-level Settings URL.
    const settingsLink = cell.locator('a:has-text("Settings")');
    await expect(settingsLink.first()).toHaveAttribute('href', /admin\.php\?page=tssl-settings/);
    // Settings appears BEFORE Deactivate in document order.
    const text = (await cell.innerText()).replace(/\s+/g, ' ');
    expect(text.indexOf('Settings')).toBeLessThan(text.indexOf('Deactivate'));
    void actions;
  });
});

test.describe('Settings UI — cards + summary panel', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
  });

  test('all 5 card sections are present', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    for (const id of [
      'tssl-card-general',
      'tssl-card-custom-login',
      'tssl-card-captcha',
      'tssl-card-branding',
      'tssl-card-recovery',
    ]) {
      await expect(page.locator(`#${id}`)).toBeVisible();
    }
    await shot(page, '60-settings-cards-overview');
  });

  test('summary panel shows the title, login URL, Copy + Open buttons, and 4 status badges', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    const summary = page.locator('.tssl-summary');
    await expect(summary).toBeVisible();
    await expect(summary.locator('#tssl-summary-title')).toContainText('Stealth Access Status');

    // Hero URL row — the URL lives in the input's value attribute.
    await expect(page.locator('#tssl-summary-login-url')).toHaveValue(/\/secure-login\//);

    // Buttons.
    await expect(summary.locator('button.button-primary.tssl-copy')).toContainText(/copy url/i);
    await expect(summary.locator('a.tssl-status-open')).toContainText(/open login page/i);

    // Four status badges.
    await expect(summary.locator('.tssl-badge')).toHaveCount(4);
  });

  test('Copy URL button reports "Copied!" after click', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    const btn = page.locator('button.button-primary.tssl-copy');
    await expect(btn).toBeVisible();
    await btn.click();
    await expect(btn).toHaveText(/copied/i);
  });

  test('warning callouts appear for the two risky options', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    const warns = page.locator('#tssl-card-general .tssl-callout-warn');
    await expect(warns).toHaveCount(2); // disable-reset + hide-default-urls
  });

  test('major settings carry info-icon tooltips with aria-label', async ({ page }) => {
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
    // Spec asks for tooltips on ≥11 settings; we ship 11.
    const helpIcons = page.locator('.tssl-help');
    const count = await helpIcons.count();
    expect(count).toBeGreaterThanOrEqual(11);
    // Each must have a non-empty aria-label.
    for (let i = 0; i < count; i++) {
      await expect(helpIcons.nth(i)).toHaveAttribute('aria-label', /.+/);
    }
  });
});

test.describe('Tooltip portal — clipping + visibility audit', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
  });

  test('tooltip is appended to <body>, not nested inside cards', async ({ page }) => {
    await page.locator('.tssl-help').first().hover();
    // Wait for the JS to create + position the floating tip.
    await page.waitForSelector('.tssl-help-tip-floating', { state: 'visible' });
    const parentTag = await page.locator('.tssl-help-tip-floating').evaluate(
      (el) => el.parentElement?.tagName,
    );
    expect(parentTag).toBe('BODY');
  });

  test('hover shows the tooltip; mouseleave hides it', async ({ page }) => {
    const icon = page.locator('.tssl-help').first();
    await icon.hover();
    const tip = page.locator('.tssl-help-tip-floating');
    await expect(tip).toBeVisible();
    // Move mouse far away.
    await page.mouse.move(0, 0);
    await expect(tip).toBeHidden();
  });

  test('keyboard focus shows the tooltip; blur hides it; Escape closes', async ({ page }) => {
    const icon = page.locator('.tssl-help').first();
    await icon.focus();
    const tip = page.locator('.tssl-help-tip-floating');
    await expect(tip).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(tip).toBeHidden();
  });

  /**
   * Return only visible help icons. Some icons live inside provider panels
   * or branding rows that the default settings state keeps hidden, so we
   * skip those rather than fight `scrollIntoViewIfNeeded` on a 0×0 element.
   */
  async function visibleIcons(page: import('@playwright/test').Page) {
    const all = await page.locator('.tssl-help').all();
    const out: typeof all = [];
    for (const icon of all) {
      if (await icon.isVisible()) out.push(icon);
    }
    return out;
  }

  test('tooltip width never exceeds 280px', async ({ page }) => {
    const icons = await visibleIcons(page);
    expect(icons.length).toBeGreaterThan(0);
    for (let i = 0; i < icons.length; i++) {
      await icons[i].scrollIntoViewIfNeeded();
      await icons[i].hover();
      const box = await page.locator('.tssl-help-tip-floating').boundingBox();
      expect(box, `icon #${i} produced no box`).not.toBeNull();
      expect(box!.width, `icon #${i} tooltip width`).toBeLessThanOrEqual(280);
      await page.mouse.move(0, 0);
    }
  });

  test('tooltip never extends past the viewport in any direction, for every visible icon', async ({ page }) => {
    const vp = page.viewportSize()!;
    const icons = await visibleIcons(page);
    for (let i = 0; i < icons.length; i++) {
      await icons[i].scrollIntoViewIfNeeded();
      await icons[i].hover();
      const box = await page.locator('.tssl-help-tip-floating').boundingBox();
      expect(box, `icon #${i} produced no box`).not.toBeNull();
      expect(box!.x, `icon #${i} left edge`).toBeGreaterThanOrEqual(0);
      expect(box!.y, `icon #${i} top edge`).toBeGreaterThanOrEqual(0);
      expect(box!.x + box!.width, `icon #${i} right edge`).toBeLessThanOrEqual(vp.width);
      expect(box!.y + box!.height, `icon #${i} bottom edge`).toBeLessThanOrEqual(vp.height);
      await page.mouse.move(0, 0);
    }
  });

  test('top-of-page icon flips tooltip to placement="bottom"', async ({ page }) => {
    const firstIcon = page.locator('.tssl-help').first();
    // Pin the icon at the very top of the scrollable region — `block: start`
    // puts it right at the top edge, so there's no room above for a tooltip.
    await firstIcon.evaluate((el) => (el as HTMLElement).scrollIntoView({ block: 'start' }));
    await firstIcon.hover();
    const placement = await page.locator('.tssl-help-tip-floating').getAttribute('data-placement');
    expect(placement).toBe('bottom');
  });

  test('bottom-of-page icon places tooltip above', async ({ page }) => {
    // Use the last visible icon (some hidden ones at end-of-file would be
    // inside provider panels).
    const icons = await visibleIcons(page);
    const last = icons[icons.length - 1];
    await last.evaluate((el) => (el as HTMLElement).scrollIntoView({ block: 'end' }));
    await last.hover();
    const placement = await page.locator('.tssl-help-tip-floating').getAttribute('data-placement');
    expect(placement).toBe('top');
  });

  test('tooltip never clipped by .tssl-settings-card (which uses overflow: hidden)', async ({ page }) => {
    // Pick the first icon inside General Protection (the first tooltip in
    // the topmost card) — the historical clipping case.
    const icon = page.locator('#tssl-card-general .tssl-help').first();
    await icon.scrollIntoViewIfNeeded();
    await icon.hover();
    const cardBox = await page.locator('#tssl-card-general').boundingBox();
    const tipBox = await page.locator('.tssl-help-tip-floating').boundingBox();
    expect(cardBox).not.toBeNull();
    expect(tipBox).not.toBeNull();
    // The whole tooltip is allowed to extend above the card top — that's the
    // entire point of the portal — as long as it stays inside the viewport.
    const vp = page.viewportSize()!;
    expect(tipBox!.y).toBeGreaterThanOrEqual(0);
    expect(tipBox!.y + tipBox!.height).toBeLessThanOrEqual(vp.height);
    // And the tooltip must be visible (not display: none / opacity 0).
    await expect(page.locator('.tssl-help-tip-floating')).toBeVisible();
  });

  test('tooltip is not clipped by .tssl-provider-panel either', async ({ page }) => {
    // The Site key field inside the Cloudflare Turnstile sub-card has a
    // tooltip — and Turnstile is the default visible provider.
    const icon = page.locator('.tssl-provider-panel[data-provider="cloudflare_turnstile"] .tssl-help').first();
    await icon.scrollIntoViewIfNeeded();
    await icon.hover();
    await expect(page.locator('.tssl-help-tip-floating')).toBeVisible();
    const vp = page.viewportSize()!;
    const box = await page.locator('.tssl-help-tip-floating').boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(vp.width);
  });

  test('z-index is high enough to sit above WP admin chrome', async ({ page }) => {
    await page.locator('.tssl-help').first().hover();
    const z = await page.locator('.tssl-help-tip-floating').evaluate(
      (el) => parseInt(window.getComputedStyle(el).zIndex || '0', 10),
    );
    // WP admin bar uses 99999; tooltip needs to be ≥ that to win.
    expect(z).toBeGreaterThanOrEqual(99999);
  });

  test('screenshot: first, middle, last tooltip', async ({ page }) => {
    const icons = await visibleIcons(page);
    const samples = [
      { idx: 0,                                   name: 'first'  },
      { idx: Math.floor((icons.length - 1) / 2),  name: 'middle' },
      { idx: icons.length - 1,                    name: 'last'   },
    ];
    for (const s of samples) {
      await icons[s.idx].scrollIntoViewIfNeeded();
      await icons[s.idx].hover();
      await page.waitForSelector('.tssl-help-tip-floating', { state: 'visible' });
      await shot(page, `90-tooltip-${s.name}`);
      await page.mouse.move(0, 0);
    }
  });
});

test.describe('Progressive disclosure', () => {
  test.beforeAll(() => { restoreSettingsFromSnapshot(); });
  test.afterAll(() => { restoreSettingsFromSnapshot(); });
  test.beforeEach(async ({ page }) => {
    await clearSession(page);
    await loginViaSecureFlow(page);
    await page.goto('/wp-admin/options-general.php?page=tssl-settings');
  });

  test('CAPTCHA card shows only the selected provider panel by default (Turnstile)', async ({ page }) => {
    const turnstile = page.locator('.tssl-provider-fields[data-provider="cloudflare_turnstile"]');
    const v2 = page.locator('.tssl-provider-fields[data-provider="google_recaptcha_v2"]');
    const v3 = page.locator('.tssl-provider-fields[data-provider="google_recaptcha_v3"]');
    await expect(turnstile).toBeVisible();
    await expect(v2).toBeHidden();
    await expect(v3).toBeHidden();
  });

  test('switching provider + Google mode toggles the visible panels and docs link', async ({ page }) => {
    const sel = page.locator('select[name="tssl_settings[captcha_provider]"]');

    // Google → Checkbox sub-panel visible, Score hidden, v2 docs link.
    await sel.selectOption('google_recaptcha');
    await expect(page.locator('.tssl-provider-fields[data-provider="cloudflare_turnstile"]')).toBeHidden();
    const googlePanel = page.locator('.tssl-provider-fields[data-provider="google_recaptcha"]');
    await expect(googlePanel).toBeVisible();
    const modeSel = page.locator('select[name="tssl_settings[recaptcha_mode]"]');
    await modeSel.selectOption('checkbox');
    await expect(googlePanel.locator('.tssl-mode-panel[data-mode="checkbox"]')).toBeVisible();
    await expect(googlePanel.locator('.tssl-mode-panel[data-mode="score"]')).toBeHidden();
    await expect(googlePanel.locator('.tssl-provider-docs-link')).toHaveAttribute(
      'href',
      /cloud\.google\.com\/recaptcha\/docs/,
    );

    // Switch mode to Score → score sub-panel visible (with threshold), checkbox hidden.
    await modeSel.selectOption('score');
    await expect(googlePanel.locator('.tssl-mode-panel[data-mode="score"]')).toBeVisible();
    await expect(googlePanel.locator('.tssl-mode-panel[data-mode="checkbox"]')).toBeHidden();
    await expect(page.locator('input[name="tssl_settings[recaptcha_v3_threshold]"]')).toBeVisible();

    // Back to Turnstile.
    await sel.selectOption('cloudflare_turnstile');
    await expect(page.locator('.tssl-provider-fields[data-provider="cloudflare_turnstile"]')).toBeVisible();
    await expect(page.locator('.tssl-provider-fields[data-provider="cloudflare_turnstile"] .tssl-provider-docs-link')).toHaveAttribute(
      'href',
      /developers\.cloudflare\.com\/turnstile\/get-started/,
    );
  });

  test('status panel CAPTCHA badge displays the full provider + mode combo when configured', async ({ page }) => {
    // Configure Google + Score-Based with real keys so status flips to "on".
    updateSettings({
      captcha_provider: 'google_recaptcha',
      recaptcha_mode: 'score',
      recaptcha_v3_site_key: 'SITE',
      recaptcha_v3_secret_key: 'SECRET',
    });
    try {
      await page.goto('/wp-admin/options-general.php?page=tssl-settings');
      // The CAPTCHA cell is the 3rd cell in the status grid.
      const captchaBadge = page.locator('.tssl-status-cell').nth(2).locator('.tssl-badge');
      await expect(captchaBadge).toContainText(/Google reCAPTCHA \(Score-Based\)/i);
    } finally {
      restoreSettingsFromSnapshot();
    }
  });

  test('branding fields hide and show with the Enable Branding checkbox', async ({ page }) => {
    const cb = page.locator('#tssl-enable-branding');
    // Default: branding off → conditional rows hidden.
    await expect(page.locator('.tssl-conditional-branding').first()).toBeHidden();
    await cb.check();
    await expect(page.locator('.tssl-conditional-branding').first()).toBeVisible();
    await cb.uncheck();
    await expect(page.locator('.tssl-conditional-branding').first()).toBeHidden();
  });

  test('custom redirect URL row hides/shows based on blocked behavior', async ({ page }) => {
    const sel = page.locator('#tssl-blocked-behavior');
    // Default behavior is show_404 → conditional row hidden.
    await expect(page.locator('.tssl-conditional-redirect-custom')).toBeHidden();
    await sel.selectOption('redirect_custom_url');
    await expect(page.locator('.tssl-conditional-redirect-custom')).toBeVisible();
    await sel.selectOption('redirect_home');
    await expect(page.locator('.tssl-conditional-redirect-custom')).toBeHidden();
  });
});
