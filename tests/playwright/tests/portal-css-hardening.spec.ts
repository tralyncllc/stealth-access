/**
 * Login portal CSS hardening regression.
 *
 * Verifies that the portal's brand-critical visual contract holds even
 * when the active theme injects CSS that *would* otherwise win the
 * cascade. The spec injects three hostile-theme patterns via
 * `addStyleTag()` AFTER the page has finished loading — that puts the
 * hostile rules at the END of `<head>`, which is the worst-case scenario
 * for source-order cascade ties (whatever wins here wins on every real
 * production install too).
 *
 *   1. body { font-family: serif !important }   — theme overrides body font
 *   2. h1 { font-family: serif !important }     — theme overrides heading
 *   3. button { background: green !important }  — theme overrides button
 *
 * The portal must keep:
 *   - title font: the Stealth Access sans-serif system stack
 *   - button background: Stealth Access blue (#2563eb = rgb(37, 99, 235))
 *   - input background: white
 *
 * If any of these flips under the hostile CSS, the hardening regressed
 * and a production theme would visibly override the portal.
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

// When WP_BASE_URL points at a public hostname served behind a CDN
// (Cloudflare in the reference deployment), the edge cache will
// happily serve a stale copy of `login.css` with a 21-hour `max-age`,
// masking any edits made during this spec's iteration. To guarantee
// the regression reads the on-disk CSS, we intercept every plugin-
// asset request and serve the file directly from the working tree.
// This also keeps the spec self-contained in CI — no dependency on
// any specific Apache / hosting setup.
const PLUGIN_ROOT = path.resolve(__dirname, '..', '..', '..');
const LOGIN_CSS_PATH = path.join(PLUGIN_ROOT, 'assets', 'css', 'login.css');

const HOSTILE_THEME_CSS = `
  /* Simulate a production block theme: green primary, serif headings,
   * generous use of !important. Selectors target tag names + classes
   * core block themes already use (.wp-element-button, body, h1). */
  :root {
    --wp--preset--color--primary: #00bf63;
    --wp--preset--color--accent: #00bf63;
    --wp--preset--font-family--heading: Georgia, "Times New Roman", serif;
    --wp--preset--font-family--body: Georgia, "Times New Roman", serif;
  }
  body {
    font-family: Georgia, "Times New Roman", serif !important;
    background: #ffe9e9 !important;
  }
  h1, h2, h3, h4, h5, h6 {
    font-family: Georgia, "Times New Roman", serif !important;
    color: #006e3a !important;
  }
  p, label, span {
    font-family: Georgia, "Times New Roman", serif !important;
  }
  button,
  input[type="submit"],
  input[type="button"],
  .wp-element-button,
  .wp-block-button__link {
    background: #00bf63 !important;
    background-color: #00bf63 !important;
    color: #ffffff !important;
    font-family: Georgia, "Times New Roman", serif !important;
    border: 2px solid #006e3a !important;
    border-radius: 2px !important;
    padding: 1.5em 2em !important;
    text-transform: uppercase !important;
  }
  input[type="text"],
  input[type="email"],
  input[type="password"] {
    background: #e9ffe9 !important;
    background-color: #e9ffe9 !important;
    color: #006e3a !important;
    font-family: Georgia, "Times New Roman", serif !important;
    border: 2px dashed #006e3a !important;
    border-radius: 0 !important;
  }
`;

const OUT_DIR = path.resolve(__dirname, '..', '..', '..', 'artifacts', 'portal-hardening');

// Make sure the artifact dir exists so screenshot writes never fail
// when the test runs on a clean checkout.
fs.mkdirSync(OUT_DIR, { recursive: true });

// Allow the screenshot suffix to be parameterised via the env var. The
// suite-runner sets it to 'before' for a pre-hardening capture and to
// 'after' for the post-hardening regression. Falls back to 'after' so
// CI always overwrites the regression image and not the baseline.
const SUFFIX = process.env.PORTAL_HARDENING_LABEL || 'after';

// Helper: report whether a `font-family` value resolves to a serif
// font. We can't just match `/serif/i` because the portal's legitimate
// font stack ends in the generic `sans-serif` fallback. Strip
// `sans-serif` occurrences first, then test the residue for any of the
// bad-font markers the hostile CSS injects.
function isSerifFontFamily(fontFamily: string): boolean {
  const residue = fontFamily.toLowerCase().replace(/sans-serif/g, '');
  return /georgia|times|\bserif\b/.test(residue);
}

test.describe('Login portal — CSS hardening against hostile theme', () => {
  test.beforeEach(async ({ context, page }) => {
    await context.clearCookies();
    // Force-clear Chromium's HTTP cache so plugin-asset edits surface
    // immediately. WordPress's `?ver=1.0.0` cache buster doesn't change
    // between runs while a single version is in flight, so Chromium
    // can otherwise serve a cached login.css from a prior run and mask
    // the very behaviour this spec is trying to verify.
    const client = await context.newCDPSession(page);
    await client.send('Network.clearBrowserCache');
    await client.send('Network.setCacheDisabled', { cacheDisabled: true });

    // Serve `login.css` straight from the working tree so the test is
    // immune to any CDN/Apache cache between Playwright and the file
    // on disk. See the PLUGIN_ROOT comment above.
    await context.route('**/wp-content/plugins/stealth-access/assets/css/login.css*', async (route) => {
      const body = fs.readFileSync(LOGIN_CSS_PATH, 'utf-8');
      await route.fulfill({
        status: 200,
        contentType: 'text/css',
        headers: { 'cache-control': 'no-store, no-cache' },
        body,
      });
    });
  });

  test('vanilla portal (no hostile CSS) — baseline', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('.tssl-card')).toBeVisible();
    await expect(page.locator('.tssl-portal-title')).toBeVisible();
    await page.screenshot({
      path: path.join(OUT_DIR, `vanilla-${SUFFIX}.png`),
      fullPage: false,
    });
  });

  test('portal under hostile theme CSS — title font stays sans-serif', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('.tssl-portal-title')).toBeVisible();

    // Inject hostile theme CSS AFTER the portal CSS — worst-case
    // source-order cascade tie.
    await page.addStyleTag({ content: HOSTILE_THEME_CSS });
    // Force a layout pass before reading computed styles.
    await page.evaluate(() => document.body.offsetHeight);

    const title = page.locator('.tssl-portal-title');
    const titleFontFamily = await title.evaluate(
      (el) => getComputedStyle(el).fontFamily,
    );
    const titleColor = await title.evaluate(
      (el) => getComputedStyle(el).color,
    );

    // Title must NOT pick up Georgia / serif from the hostile rule.
    expect(
      isSerifFontFamily(titleFontFamily),
      `title font-family should not resolve to serif: ${titleFontFamily}`,
    ).toBe(false);
    // Title color must remain the portal navy (#0f172a = rgb(15, 23, 42)).
    expect(titleColor).toBe('rgb(15, 23, 42)');
  });

  test('portal under hostile theme CSS — continue button stays Stealth Access blue', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('.tssl-button').first()).toBeVisible();
    await page.addStyleTag({ content: HOSTILE_THEME_CSS });
    await page.evaluate(() => document.body.offsetHeight);

    const button = page.locator('.tssl-button').first();
    const bg = await button.evaluate((el) => getComputedStyle(el).backgroundColor);
    const color = await button.evaluate((el) => getComputedStyle(el).color);
    const fontFamily = await button.evaluate(
      (el) => getComputedStyle(el).fontFamily,
    );
    const textTransform = await button.evaluate(
      (el) => getComputedStyle(el).textTransform,
    );
    const borderRadius = await button.evaluate(
      (el) => getComputedStyle(el).borderRadius,
    );

    // Stealth Access blue: #2563eb = rgb(37, 99, 235).
    expect(bg, 'button background should be Stealth Access blue').toBe(
      'rgb(37, 99, 235)',
    );
    // Button text stays white.
    expect(color).toBe('rgb(255, 255, 255)');
    // Hostile rule tried to force uppercase + serif + 2px border-radius.
    // Our hardening must keep none/sans-serif/12px.
    expect(isSerifFontFamily(fontFamily), `font-family should not resolve to serif: ${fontFamily}`).toBe(false);
    expect(textTransform).toBe('none');
    expect(borderRadius).toBe('12px');
  });

  test('portal under hostile theme CSS — input field stays white + sans-serif', async ({ page }) => {
    await page.goto('/secure-login/');
    await expect(page.locator('.tssl-input').first()).toBeVisible();
    await page.addStyleTag({ content: HOSTILE_THEME_CSS });
    await page.evaluate(() => document.body.offsetHeight);

    const input = page.locator('.tssl-input').first();
    const bg = await input.evaluate((el) => getComputedStyle(el).backgroundColor);
    const fontFamily = await input.evaluate(
      (el) => getComputedStyle(el).fontFamily,
    );
    const borderRadius = await input.evaluate(
      (el) => getComputedStyle(el).borderRadius,
    );

    expect(bg, 'input background should stay white').toBe('rgb(255, 255, 255)');
    expect(isSerifFontFamily(fontFamily), `font-family should not resolve to serif: ${fontFamily}`).toBe(false);
    expect(borderRadius).toBe('10px');
  });

  test('portal screenshot under hostile theme CSS', async ({ page }) => {
    // This is the visual proof — the screenshot saved here should look
    // (essentially) identical to the vanilla baseline. Any deviation is
    // the regression we're guarding against.
    await page.goto('/secure-login/');
    await expect(page.locator('.tssl-card')).toBeVisible();
    await page.addStyleTag({ content: HOSTILE_THEME_CSS });
    await page.evaluate(() => document.body.offsetHeight);
    await page.screenshot({
      path: path.join(OUT_DIR, `hostile-${SUFFIX}.png`),
      fullPage: false,
    });
  });
});
