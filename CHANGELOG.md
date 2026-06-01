# Changelog

All notable changes to **Stealth Access** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A condensed version of this log lives in `readme.txt` for the wordpress.org
plugin directory; this file is the canonical record for the GitHub repo.

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

[1.0.1]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.1
[1.0.0]: https://github.com/tralyncllc/stealth-access/releases/tag/v1.0.0
[0.1.15]: https://github.com/tralyncllc/stealth-access/commit/6c4a554
[0.1.14]: https://github.com/tralyncllc/stealth-access/commit/a9512a1
[0.1.13]: https://github.com/tralyncllc/stealth-access/commit/58eef08
[0.1.12]: https://github.com/tralyncllc/stealth-access/commit/d401cbb
