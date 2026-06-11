# Changelog

All notable changes to **Stealth Access** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A condensed version of this log lives in `readme.txt` for the wordpress.org
plugin directory; this file is the canonical record for the GitHub repo.

## [1.0.4] - 2026-06-11

Patch release. Restores compatibility with WordPress two-factor (2FA) plugins
when **Hide default login URLs** is enabled. This is a production-breaking
authentication-compatibility fix and ships outside the normal monthly cadence.
Authentication is not weakened and 2FA is never bypassed.

### Fixed
- **2FA second-factor steps were blocked, locking users out.** A 2FA plugin
  completes login in two requests: the first (username + password) succeeds,
  and the plugin then renders a challenge whose form posts back to
  `wp-login.php?action=<provider-action>` (e.g. `validate_2fa` / `backup_2fa`
  for the official *Two-Factor* plugin). With hidden login on,
  `TSSL_Login_Hider::maybe_block_wp_login()` only honoured a fixed allowlist
  (`logout`, `postpass`, and the reset actions), so the second-factor action
  was 404'd and the half-authenticated user could never submit their code.
  - `allowed_login_action()` now also permits any action that a plugin
    services via a registered `login_form_{$action}` handler — precisely how
    2FA providers hook the login flow. Because such an action is handled by
    the plugin and never falls through to wp-login.php's default
    username/password form, this does **not** reopen the H1 `interim-login`
    action-coercion bypass (which has no `login_form_` handler). The
    credential/registration actions (`login`, `register`, `confirmaction`) are
    excluded explicitly as defence in depth.
  - New `tssl_allow_login_action` filter (`bool $allow, string $action`) lets
    a site curtail or extend which plugin-registered login actions pass.
- **2FA challenge forms posted to the hidden page instead of wp-login.php.**
  2FA plugins build their form action from `site_url( 'wp-login.php', … )`,
  which `filter_site_url()` rewrote to the hidden slug — so the challenge
  submitted to the wrong place and the second factor was lost. `filter_site_url()`
  and `filter_wp_redirect()` now leave `wp-login.php` URLs intact while an
  authentication is being completed (inside the `wp_login` hook, via the new
  `is_authenticating()` guard). The challenge is only ever produced after the
  username and password steps, so the hidden login URL is not exposed to
  anonymous traffic.

### Tests
- New `tests/playwright/tests/two-factor-compat.spec.ts` (5 tests). A mu-plugin
  fixture stands in for a 2FA provider by registering a `login_form_` handler:
  a registered second-factor action reaches `wp-login.php` (not 404'd); an
  unregistered action and `action=login` stay blocked (no broad allowlist, no
  credential-form re-exposure); `interim-login` stays blocked (H1 closed); and
  `wp-login.php` URLs are intact inside `wp_login` but rewritten otherwise.

### Unchanged
- The H1 `interim-login` bypass, the `wp-login.php` credential form, XML-RPC
  (H2a), and Application Password (H2b) protections — verified still green.
- Hidden-login REST (M5) / sitemap (M6) / search (M7) protections, the v1.0.3
  PATHINFO permalink fix, the v1.0.2 self-heal, and the v1.0.1 CSS hardening.
- CAPTCHA behavior.

## [1.0.3] - 2026-06-02

Patch release. Fixes the custom login URL on sites using PATHINFO permalinks
(`/index.php/%postname%/`), where the page lives behind an `/index.php/`
prefix the plugin was dropping. No functional, authentication, or CAPTCHA
behavior changed.

### Fixed
- **Custom login URL ignored the permalink structure.** The plugin built
  the login URL as `home_url( '/' . $slug . '/' )`, which 404s on PATHINFO
  permalink setups because the page actually resolves at
  `/index.php/$slug/`. Every place the URL is displayed, copied, opened, or
  redirected to now derives it from `get_permalink( login_page_id )`:
  - new `TSSL_Settings::get_login_url()` — `get_permalink()` of the tracked,
    published login page, with the slug-based URL as a fallback only when no
    valid page is tracked;
  - dashboard Login URL, Settings page "Resolves to", the Copy URL button
    input, and the Open Login Page button (all flow from the same value);
  - `TSSL_Login_Hider::custom_login_url()` (wp-login redirects, the
    `login_url` / `site_url` rewrites, and the lost-password URL) now uses
    the same permalink-aware helper.
  - (`TSSL_Login_Flow` already used `get_permalink()` for its post-login
    and lost-password redirects.)

### Changed
- **Duplicate-page reconciliation.** When the tracked page's slug differs
  from the configured slug, the reconcile now prefers adopting a *different*
  page that already owns the configured slug and carries the
  `[two_step_secure_login]` shortcode — switching `login_page_id` off a
  `-2` duplicate instead of fighting `wp_unique_post_slug`. Duplicates are
  never deleted; they are simply no longer tracked.

### Tests
- New `tests/playwright/tests/permalink-pathinfo.spec.ts` (2 tests): under a
  `/index.php/%postname%/` permalink structure, the displayed Login URL and
  the Open Login Page button both include `/index.php/`, that URL returns
  200 and renders the portal, and the bare (non-`/index.php/`) URL is not
  advertised.

### Unchanged
- Hidden-login REST (M5) / sitemap (M6) / search (M7) protections.
- The self-heal reconcile from 1.0.2 and the CSS hardening from 1.0.1.
- Authentication and CAPTCHA behavior.

## [1.0.2] - 2026-06-02

Patch release. Fixes a fresh-install bug where the configured custom login
slug could return a theme 404, and makes the login page self-healing. No
functional, authentication, or CAPTCHA behavior changed.

### Fixed
- **Custom login URL 404 on fresh installs.** When the custom slug in
  settings drifted from the tracked login page's `post_name` (e.g. a slug
  change whose rename silently skipped), the configured URL 404'd even
  though the page existed and was published. Re-saving permalinks and
  downgrading did not help because the bad state was persisted in the
  database. The login page now reconciles against the configured slug.
- **Stale settings cache.** `TSSL_Settings` cached `get_all()` and never
  invalidated it when the option changed out-of-band (options.php save,
  WP-CLI, another component), so a same-request consumer — notably the
  page reconcile — could read or write back a stale snapshot. The cache is
  now flushed on `update_option`/`add_option` of the settings key, and
  `update()` re-reads fresh before merging.

### Added
- **Self-healing login-page reconcile** (`TSSL_Page_Manager::reconcile_login_page`).
  Runs on every settings save and every admin page load (idempotent — no
  writes when state is consistent). It:
  - re-slugs a drifted login page back to the configured slug,
  - re-publishes the page if it was unpublished,
  - repairs a stale / wrong / missing / zero stored `login_page_id` by
    adopting the page that owns the slug (or any page carrying the
    `[two_step_secure_login]` shortcode), and
  - creates a fresh login page only when there is nothing to adopt.
- **One-time rewrite flush** scheduled (via a non-autoloaded option flag,
  consumed on `wp_loaded`) whenever the login page is created, adopted, or
  re-slugged — never on a normal request.

### Tests
- New `tests/playwright/tests/login-page-healing.spec.ts` (5 tests):
  custom-slug configure resolves + stored id matches the real page id, no
  duplicate page, stale-id self-heal (adopt branch), drifted-slug self-heal
  (re-slug branch), and an upgrade-path guard that unrelated saves never
  disturb a healthy login page.

### Unchanged
- Hidden-login REST (M5) / sitemap (M6) / search (M7) protections.
- The login-portal CSS hardening from 1.0.1.
- Authentication and CAPTCHA behavior.

## [1.0.1] - 2026-06-01

Patch release. The login portal's styling is now isolated from the active
theme so the secure-login page renders consistently across themes. This
ships the CSS hardening work completed after the v1.0.0 tag. No functional,
authentication, or CAPTCHA behavior changed.

### Fixed
- **Login portal CSS isolation.** Every portal rule in `assets/css/login.css`
  is now scoped under `body.tssl-portal-body .tssl-card` (specificity (0,3,1)),
  so portal styles no longer collide with theme rules on the secure-login page.
- **Theme CSS override issues.** `maybe_preload_login_assets` enqueues the
  portal stylesheet at `wp_enqueue_scripts` priority 999 so its `<link>` lands
  after the theme's stylesheet in `<head>` source order; brand-critical
  properties are marked `!important` so theme rules using `!important`
  themselves can no longer win the cascade.
- **Typography consistency.** The portal title, subtitle, labels, and body
  text pin `font-family` explicitly, so block-theme heading/body font presets
  (e.g. a serif `--wp--preset--font-family--heading`) no longer override the
  intended Stealth Access system font stack via direct tag selectors.
- **Button color consistency.** The Continue button stays Stealth Access blue
  (`#2563eb`) with the intended border-radius and no forced uppercase, even
  under themes that repaint `button` / `.wp-element-button` / `.wp-block-button__link`.
- **Input field styling consistency.** Login inputs keep their white
  background, border, and 10px radius regardless of theme form styling.

### Changed
- Bumped plugin version to **1.0.1** in `stealth-access.php`, `TSSL_VERSION`,
  and `readme.txt`. The `TSSL_VERSION` bump also busts the `?ver=` cache on
  `login.css` / `admin.css` so visitors pick up the hardened stylesheet
  immediately.

### Notes
- The CAPTCHA host (`.tssl-captcha`) is deliberately scoped to layout-only
  rules; no font/color resets cascade toward the provider iframe, so
  Cloudflare Turnstile / Google reCAPTCHA widgets are unchanged.
- Regression coverage: new `tests/playwright/tests/portal-css-hardening.spec.ts`
  injects synthetic hostile theme CSS and asserts the brand contract holds.

## [1.0.0] - 2026-06-01

First public release. The work for v1.0.0 was driven by an internal
multi-agent security audit (81 findings across all severities) followed by
three remediation passes (v0.1.13 → v0.1.15), then a release-preparation
pass that hardened CI, added supply-chain controls, and produced the
wordpress.org submission package.

### Added
- Threat-model section in `README.md` covering what Stealth Access defends
  against, what it does NOT defend against, and the assumed deployment
  model.
- `readme.txt` in wordpress.org plugin-directory format with 5 captioned
  screenshots, FAQ, and recovery instructions.
- `.wordpress-org/` assets: 1280×800 screenshots, banner (1544×500 + 772×250),
  and icon (128×128 + 256×256).
- Dependabot configuration (`.github/dependabot.yml`) for weekly bumps of
  GitHub Actions and the Playwright npm tree.
- Per-test retry on CI to absorb a Chromium autofill quirk on the secure
  login flow.

### Changed
- Bumped plugin version to **1.0.0** in `stealth-access.php` and
  `TSSL_VERSION`.
- Tightened CI `GITHUB_TOKEN` scope to `contents: read` and SHA-pinned
  third-party actions (e.g. `shivammathur/setup-php@7c071df…` v2.37.1).
- Updated the lockout-recovery copy in the Settings page so the SFTP
  rename instruction references the current plugin folder name
  (`stealth-access` → `stealth-access-disabled`).

### Security
Closed during the v1.0.0 audit-remediation cycle. See `Security_Audit.md`
in-repo (local-only) for full per-finding writeups.
- **H1** — `/wp-login.php?action=interim-login` action-coercion bypass of
  the two-step flow. Removed `interim-login` from the always-allowed
  action whitelist in `class-tssl-login-hider.php`.
- **H2** — XML-RPC + REST Application Passwords as parallel auth surfaces.
  Both now ship disabled by default with explicit opt-out toggles.
- **M1** — Dead `enable_two_step` toggle. Removed from the Settings UI
  and from the saved option payload.
- **M2** — Lost-password URL leaked `/wp-login.php` in theme HTML.
  `lostpassword_url` is now rewritten to the custom slug, and
  `template_redirect` forwards stale links.
- **M4** — CAPTCHA misconfiguration silent fail-open. A persistent admin
  banner now surfaces when keys are missing, and a rate-limited
  `error.log` line is written (60s transient).
- **M5** — Hidden login slug leaked via the WP core REST `/pages`
  endpoint. Filtered via `rest_page_query` / `rest_post_search_query`.
- **M6** — Hidden login slug leaked via the WP core sitemap. Filtered
  via `wp_sitemaps_posts_query_args`.
- **M7** — Hidden login slug surfaced in the front-end search results.
  Filtered via `pre_get_posts`.

### Accepted risks (documented)
- **M3** — Step-2 timing differential between existent and non-existent
  accounts. Narrow trigger configuration; deferred to v1.1.0.
- **M8** — Lost-password redirect Location header. Narrow trigger
  configuration; deferred to v1.1.0.

### Deferred
- 15 Low and 56 Informational findings deferred to the v1.x lifecycle.

### Test coverage
- 124 Playwright tests across blocking, admin-management, opt-out, and
  behavioural-regression paths.
- 5 screenshot-capture specs gated behind `CAPTURE_SCREENSHOTS=1`
  (skipped by default in CI).

## [0.1.15] - 2026-05-31

### Security
- **M1** closed: removed the dead `enable_two_step` toggle from Settings
  and from the persisted option payload.
- **M2** closed: `filter_lostpassword_url` now rewrites the URL to the
  custom slug; `maybe_forward_reset_actions` on `template_redirect`
  forwards stale links that still point at `/wp-login.php?action=…`.

## [0.1.14] - 2026-05-31

### Security
- **M4** closed: `class-tssl-captcha.php` now renders a persistent
  admin banner when CAPTCHA keys are missing and writes a rate-limited
  `error.log` line via a 60-second transient.
- **M5** / **M6** / **M7** closed: hidden-login slug is filtered out of
  the WP core REST API, the core sitemap, and the front-end search
  results via shared helpers in `class-tssl-page-manager.php`.

## [0.1.13] - 2026-05-31

### Security
- **H1** closed: dropped `interim-login` from the `$always` whitelist in
  `class-tssl-login-hider.php` so the two-step flow can no longer be
  bypassed via `wp-login.php?action=interim-login`.
- **H2** closed: filtered `xmlrpc_enabled` and
  `wp_is_application_passwords_available` to disable both parallel auth
  surfaces by default. Explicit opt-out toggles ship under General
  Protection.

## [0.1.12] - 2026-05-30

### Fixed
- Legacy settings URL now redirects correctly to the new Settings page.
- Restored missing notice rendering on the Settings page.

## [0.1.0 – 0.1.11]

Pre-release iteration in a private repo. No public install advised.

[1.0.4]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.4
[1.0.3]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.3
[1.0.2]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.2
[1.0.1]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.1
[1.0.0]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.0
[0.1.15]: https://github.com/tralyncllc/stealth-access/commit/6c4a554
[0.1.14]: https://github.com/tralyncllc/stealth-access/commit/a9512a1
[0.1.13]: https://github.com/tralyncllc/stealth-access/commit/58eef08
[0.1.12]: https://github.com/tralyncllc/stealth-access/commit/d401cbb
