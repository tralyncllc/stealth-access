# Stealth Access

Lightweight, dependency-free WordPress login hardening by **Tralync LLC**: two-step login (username then password), CAPTCHA support (Cloudflare Turnstile, Google reCAPTCHA Checkbox, Google reCAPTCHA Score-Based), password-reset controls, hidden login URLs, and optional custom branding.

- **Requires WordPress:** 6.8+
- **Requires PHP:** 8.1+
- **License:** GPLv2 or later
- **Author:** [Tralync LLC](https://tralync.com)

> **Internal compatibility note:** Internal code still uses the `TSSL` PHP class prefix and the `tssl_settings` option key (plus the `tssl_login_*` cookie/transient names) for backward compatibility with the original Secure Login Shield 0.x line. These identifiers are kept stable so 0.x installs that updated to Stealth Access retain their settings, custom login slug, login page, and CAPTCHA keys. The plugin folder is `stealth-access`. User-facing branding everywhere reads **Stealth Access**.

## Features

- Two-step login flow: enter username/email first, password second.
- Username enumeration protection — Step 1 *always* advances to Step 2 regardless of whether the account exists. All error messages are generic.
- Optional Cloudflare Turnstile, Google reCAPTCHA v2 Checkbox, or Google reCAPTCHA v3 (server-side validated, no client-only trust).
- Optional disable of the password-reset feature.
- Optional hiding of the "Lost your password?" link on the default WordPress login screen.
- Optional hiding of `/wp-login.php` and the `/wp-admin/` redirect for unauthenticated users.
- Custom login slug (e.g. `/secure-login/`).
- Auto-created login page on activation containing the `[two_step_secure_login]` shortcode, with optional exclusion from frontend page lists and navigation menus.
- Optional logo branding above the custom login form.
- Safe redirects via `wp_validate_redirect()` and `wp_safe_redirect()`.
- Card-based admin settings page with a status summary panel and a copy-to-clipboard button for the login URL.

## Threat model

Stealth Access is a login-hardening plugin. It raises the cost of common attacks and closes specific bypass surfaces that WordPress core leaves open by default. It is **not** a substitute for a WAF, a rate-limiting plugin, or competent hosting. The boundaries below are intentional and audited — knowing them is part of running the plugin correctly.

### What Stealth Access defends against

- **Brute-force login attempts** against the secure-login two-step form and (when `hide_default_login_urls` is on) against `/wp-login.php`. Both steps are CAPTCHA-gateable and nonce-protected; Step 1 is enumeration-resistant by design.
- **Automated bot logins** that scrape WordPress sites for default `/wp-login.php` endpoints. When the URL hider is on, `/wp-login.php` is blocked at `login_init` with a configurable behaviour (404, redirect home, redirect to a custom URL).
- **The `wp-login.php?action=interim-login` action-coercion bypass** (audit finding H1). WordPress core decays unknown action values to the standard `login` form; the plugin no longer whitelists `interim-login`, so the bypass is closed.
- **Authentication via `/xmlrpc.php`** (audit finding H2). XML-RPC's auth path is rate-limit-free in WordPress core; the plugin disables `xmlrpc_enabled` and short-circuits `/xmlrpc.php` requests at `wp_loaded` by default. Admins can opt back in for Jetpack / mobile-app integrations.
- **Authentication via REST Application Passwords** (audit finding H2). Application Passwords accept HTTP Basic auth against `/wp-json/wp/v2/users/me` regardless of two-step state; the plugin disables `wp_is_application_passwords_available` by default. Admins can opt back in.
- **Discovery of the hidden login URL** via `/wp-json/wp/v2/pages`, `/wp-json/wp/v2/search`, `/wp-sitemap.xml`, the front-end `?s=…` search, and the lost-password URL filter on theme comment forms (audit findings M2, M5, M6, M7). The slug-secrecy contract holds for the default hardened configuration.
- **Silent CAPTCHA misconfiguration** (audit finding M4). When a provider is selected but keys are missing, an admin banner appears across wp-admin and a rate-limited line is written to `error.log`. The login flow still works — missing keys never block login — but the degradation in defence depth is now loud.

### What it does NOT defend against

- **Multisite / network deployments.** Not tested. Compatible single-site only. Evaluate carefully before activating on a network.
- **Sophisticated timing attacks against Step 2** to enumerate registered emails (audit finding M3, accepted risk). Step 1 is enumeration-resistant; Step 2's `wp_signon` path has measurable timing differences for known vs unknown emails. Mitigated by the per-attempt CAPTCHA cost but not eliminated.
- **Per-IP or per-credential brute-force lockout.** The plugin raises per-attempt cost via CAPTCHA but does not lock accounts after N failures. Pair with Limit Login Attempts Reloaded, Wordfence, or a hosting-layer rate limiter if you need lockout-based defence.
- **In-browser credential theft via XSS in unrelated plugins or in your theme.** The `tssl_login_token` Step-1 cookie is HttpOnly, but the WordPress auth cookies issued after a successful login are subject to the same XSS surface as any other WordPress site.
- **Slug disclosure when `hide_default_login_urls` is explicitly off.** That setting is the master toggle for slug secrecy; turning it off is an informed choice to publish the URL in HTML.
- **Slug disclosure in the `Location` header of `/wp-login.php?action=lostpassword`** when `disable_password_reset=1` AND `hide_default_login_urls=0` (audit finding M8, accepted risk). The trigger configuration contradicts its own intent.

### Assumed deployment model

- Single-site WordPress 6.8 or newer.
- PHP 8.1 or newer.
- HTTPS recommended. The plugin still works on HTTP, but the `Secure` cookie attribute is only set when WordPress sees `is_ssl()` as true. Run behind TLS in production.
- Behind a reasonable hosting layer. Stealth Access is not a substitute for a WAF.
- The custom login URL is a **soft secret**, not a hard authentication boundary. Slug discovery is not authentication failure; an attacker who finds the URL still has to defeat CAPTCHA, the two-step flow, and (where configured) external rate-limiting.
- The XML-RPC / Application Passwords disable-by-default posture means that mobile-app, Jetpack, and external-tooling integrations require explicit opt-in. Document this in your operations runbook before activating the plugin on a site that depends on those paths.

## Installation

1. Copy the `stealth-access` folder into `wp-content/plugins/`.
2. In **Plugins**, activate **Stealth Access**.
3. Visit **Settings → Stealth Access** to review and configure.

## CAPTCHA setup

**Cloudflare Turnstile is the recommended CAPTCHA provider.** Google reCAPTCHA is also supported — kept for compatibility with existing deployments — and now lives behind a single "Google reCAPTCHA" choice with a Mode selector for the underlying flavor (Checkbox Challenge / Score-Based).

CAPTCHA is **disabled until both the site key and the secret key for the selected provider are saved**. While disabled it does not block login: no widget renders on the front-end, no verification runs, and the admin settings page shows a "Selected, not configured" callout under the provider dropdown so you know what state you're in.

If an admin selects a CAPTCHA provider but at least one key is missing, the plugin now surfaces the misconfiguration **everywhere it can**, so the silent fail-open documented above can never go unnoticed:

- A persistent red banner appears on every wp-admin page (`Stealth Access — CAPTCHA is disabled.`) with a link back to the settings screen.
- A one-line entry is written to `error.log` the first time a login slips past CAPTCHA due to the missing key, rate-limited via a transient so the log is never flooded under a brute-force attempt (one line per minute is plenty for an admin tailing the log to catch the regression).
- The login form still works for end users — the documented policy that missing keys must never block login is **unchanged** by this hardening. CAPTCHA loss is a degradation in defence depth, not a denial of service.

Tokens are always verified server-side through `wp_remote_post()`. Secret keys are never rendered verbatim in HTML — once saved, the admin field is shown blank and a **masked fingerprint** of the existing secret is displayed below it so you can recognise which key is currently stored without exposing it:

> A secret key is saved: `••••••••••••8f3K`
> Leave blank to keep the existing key.

Only the last 4 characters of the saved secret are visible. Every other character is replaced one-for-one with a bullet (U+2022). If a saved secret is 4 characters or shorter, the fingerprint collapses to `••••` and reveals nothing. The full secret is never placed in the input's `value`, in any `data-*` attribute, or in any JavaScript variable — the fingerprint string is computed server-side and rendered as escaped HTML.

Submitting the form with a blank secret keeps the existing value; submitting a non-blank secret replaces it.

### Cloudflare Turnstile (Recommended)

Free, privacy-friendly, and simple to configure.

1. Go to <https://dash.cloudflare.com/> → Turnstile.
2. Create a widget.
3. Paste the **Site Key** and **Secret Key** into **Settings → Stealth Access → CAPTCHA → Cloudflare Turnstile**.
4. Reference: <https://developers.cloudflare.com/turnstile/get-started/>.

### Google reCAPTCHA (Compatibility)

Provided for compatibility with existing Google reCAPTCHA deployments. Pick a **Mode** depending on the key you already have:

- **Checkbox Challenge** — the visible "I'm not a robot" widget (formerly known as reCAPTCHA v2).
- **Score-Based** — invisible scoring (formerly known as reCAPTCHA v3); also expects a **Score threshold** (Google recommends starting at 0.5).

1. Go to the Google Cloud reCAPTCHA console and create or reuse a key.
2. Paste the **Site Key** and **Secret Key** into **Settings → Stealth Access → CAPTCHA → Google reCAPTCHA → \<Mode\>**.
3. For Score-Based, set the threshold. Plan to tune it after observing real traffic.
4. Reference: <https://cloud.google.com/recaptcha/docs>.

> **Note:** Google has deprecated the legacy reCAPTCHA documentation in favor of reCAPTCHA Enterprise documentation. Existing integrations remain supported.

For Score-Based mode the plugin executes reCAPTCHA on form submit (not page load) and passes one of two action names — `tssl_username_step` / `tssl_password_step` — depending on which step is being submitted. Server-side validation enforces success, score ≥ threshold, action match, and hostname presence.

### Migration is automatic

If your site was previously configured with one of the older provider slugs, it will be migrated transparently on read. Existing keys and the threshold are preserved:

| Stored before | Becomes |
|---|---|
| `none` | `cloudflare_turnstile` |
| `google_recaptcha` (0.1.0) | `google_recaptcha` + `mode=checkbox` |
| `google_recaptcha_v2` (0.1.1+) | `google_recaptcha` + `mode=checkbox` |
| `google_recaptcha_v3` (0.1.1+) | `google_recaptcha` + `mode=score` |

The first time you visit the settings page after upgrading, the dropdown shows the right choice already selected and your existing keys are still in place.

## Hiding the login page from navigation

Default block themes include a `core/page-list` block that lists every published top-level page — including the auto-created Secure Login page. With **Hide login page from navigation** enabled (default), the plugin filters `wp_list_pages_excludes`, `get_pages`, `wp_nav_menu_objects`, and `render_block_core/navigation-link`. The page stays published and reachable by URL; admins still see it inside wp-admin.

## Hide Login URL feature

When enabled:
- `/wp-login.php` is blocked except `logout`, `postpass`, and `interim-login` (and password-reset actions when reset is not disabled).
- Unauthenticated `/wp-admin/` applies the blocked-behavior setting instead of redirecting to wp-login.
- `admin-ajax.php` and `admin-post.php` remain reachable.
- `wp_login_url()` and `site_url('wp-login.php')` are rewritten to the custom slug.

## Password reset disabling — warning

If you disable password reset **and** forget your password, you cannot reset it through the UI. You will need WP-CLI (`wp user update <login> --user_pass='newpass'`), a direct DB reset, or a host recovery procedure. Test this on staging first.

## Recovery instructions

### Filesystem recovery (recommended)

```bash
mv wp-content/plugins/stealth-access wp-content/plugins/stealth-access-disabled
```

WordPress silently deactivates the plugin on the next admin page load.

### Database recovery

Plugin settings live in `wp_options` under the name `tssl_settings` (PHP-serialized array). Prefer WP-CLI:

```bash
wp option delete tssl_settings
```

The plugin reseeds defaults on the next request.

## Known limitations

- The hide-login feature is a hardening layer, not perfect invisibility.
- One shortcode form per page.
- reCAPTCHA v3 score may need tuning after real traffic is observed.

## Security notes

- Every form: `wp_nonce_field` + `wp_verify_nonce`.
- Every input sanitized; every output escaped.
- **CAPTCHA secret keys never rendered in HTML.** The admin password input is always shown blank — saved values stay in the database only. Submitting a blank secret preserves the existing value; submitting a non-blank secret replaces it.
- All redirects pass through `wp_safe_redirect()` and, where user-supplied, `wp_validate_redirect()`.
- Cookies: HttpOnly, SameSite=Lax, Secure when SSL, 10-min TTL.
- No file modifications, no .htaccess/Nginx edits, no obfuscation, no telemetry, no remote license calls.

## Development status

Version `1.0.0` (first public release). Code style targets WordPress Coding Standards (advisory in CI). See `Security_Audit.md` (local-only) for the closed audit findings, accepted risks, and remediation history.

## Testing

The plugin ships with a Playwright end-to-end suite under `tests/playwright/`.
There are two ways to run it.

### Against your existing local WordPress

Use this when you already have WordPress running (Docker, Local, MAMP, whatever)
and you're iterating on the plugin.

Prerequisites:

- WordPress with the plugin installed and active
- An admin user you have credentials for
- Node.js 20+
- Either:
  - A way to run `wp-cli` against the WordPress (defaults to
    `docker exec -i -u www-data plugin-app wp --path=/var/www/html` — i.e. a
    container literally named `plugin-app`), OR
  - `WP_CLI_CMD` set to your own wrapper

Steps:

```bash
cd tests/playwright
npm ci
npx playwright install --with-deps chromium
cp .env.example .env   # then edit WP_BASE_URL / WP_ADMIN_USER / WP_ADMIN_PASS
npm test
```

Environment variables (read by `helpers/env.ts` and `playwright.config.ts`):

| Var | Purpose | Default |
| --- | --- | --- |
| `WP_BASE_URL` | Where Playwright points its browser | — (required) |
| `WP_ADMIN_USER` | Admin login | — (required) |
| `WP_ADMIN_PASS` | Admin password | — (required) |
| `WP_CLI_CMD` | Full shell prefix that invokes `wp` against the site under test | `docker exec -i -u www-data plugin-app wp --path=/var/www/html` |
| `WP_CONTAINER` | Container name used in the default `WP_CLI_CMD` | `plugin-app` |
| `WP_PATH` | WordPress install path inside the container | `/var/www/html` |

### Against a clean disposable WordPress (matches CI)

Use this when you want to reproduce exactly what GitHub Actions runs.

```bash
# from the plugin repo root
./scripts/ci-start.sh                 # docker compose up wordpress + mysql + wp-cli
./scripts/ci-wait-for-wordpress.sh    # poll http://localhost:8889
./scripts/ci-install-wordpress.sh     # core install, activate plugin, set permalinks
./scripts/ci-run-playwright.sh        # runs the suite with CI env vars set
./scripts/ci-stop.sh                  # tear down + drop the DB
```

The CI stack exposes WordPress on `http://localhost:8889` (a different port
from the dev compose) so you can run both at once without a collision.

### PHP lint

```bash
find . -path ./tests -prune -o -path ./node_modules -prune -o \
  -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

(This is exactly what the `php-lint` CI job does.)

## CI

`.github/workflows/ci.yml` runs on every push and pull request. Three jobs:

1. **PHP lint** — `php -l` over every plugin `.php` file. Blocking.
2. **WordPress Coding Standards** — PHPCS with the `WordPress` + `WordPress-Extra`
   rulesets. Advisory (`continue-on-error: true`) until the codebase is fully
   conformant; the report is uploaded as the `phpcs-report` artifact so
   violations can be chipped away.
3. **Playwright** — spins up the CI WordPress stack
   (`tests/ci/docker-compose.ci.yml`), installs core, activates the plugin,
   runs the full Playwright suite. Blocking.

### Artifacts uploaded on failure

- `phpcs-report` — PHPCS checkstyle XML (always uploaded if non-empty)
- `playwright-artifacts` — `tests/playwright/playwright-results/` (HTML
  report, traces, screenshots, videos) plus `artifacts/docker-logs/` (logs
  from the WordPress, MySQL, and wp-cli containers, plus
  `wp-content/debug.log`).

### Secrets

**None required.** The CI WordPress install uses generated CI-only
credentials (admin: `ciadmin` / `Ci-Admin-Pass-2026!`). CAPTCHA tests
verify provider UI, missing-key behavior, and that secrets don't leak into
public HTML — none of them call out to Cloudflare or Google, so no live
keys are needed.

## Uninstall

`uninstall.php` removes the `tssl_settings` option and any leftover `tssl_login_*` transients. The login page and its content are **not** deleted.

## License

GPLv2 or later — see <https://www.gnu.org/licenses/gpl-2.0.html>.
