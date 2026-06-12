=== Stealth Access ===
Contributors: tralyncllc
Tags: login, security, two-factor, captcha, hardening
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-step login, optional CAPTCHA, hidden login URLs, and the parallel auth surfaces closed by default. Lightweight, no dependencies.

== Description ==

Stealth Access is a WordPress login-hardening plugin from **Tralync LLC**. It modifies the default WordPress authentication paths to make brute-force attacks measurably more expensive and to close the alternative auth surfaces that ship enabled in core.

**What it does**

* **Two-step login flow.** A custom login page asks for the username or email first, then the password on a second screen. Step 1 always advances to Step 2 — even for accounts that don't exist — to resist username enumeration.
* **CAPTCHA support.** Cloudflare Turnstile (recommended), Google reCAPTCHA v2 Checkbox, and Google reCAPTCHA v3 Score-Based. Server-side verification only — never trusts client-only signals. Secret keys are masked in the admin UI.
* **Hidden login URLs (optional).** Blocks `/wp-login.php` and unauthenticated `/wp-admin/` requests; routes new login traffic through your configured slug. Excluded from `wp_list_pages`, the front-end search, the WP core sitemap, and the REST API.
* **XML-RPC + Application Passwords disabled by default.** These are parallel auth paths that bypass the two-step flow; Stealth Access closes them out of the box with explicit opt-in toggles for mobile apps and integrations.
* **Password reset controls.** Optional "disable password reset" mode with explicit lockout warnings. CSS-hide for the Lost Password link on the default WordPress login screen.
* **Custom login branding.** Optional logo, alt text, and max-width on the secure-login portal.
* **Card-based admin UI.** Status summary panel with copy-to-clipboard for the login URL, status badges for every feature, and inline help tooltips on every major setting.

**What it does NOT do**

Stealth Access is not a substitute for a Web Application Firewall, a rate-limiting plugin, or competent hosting. It does **not** ship per-IP brute-force lockout, multisite support, or full timing-attack resistance. Pair it with Limit Login Attempts Reloaded / Wordfence / your host's rate limiter for lockout-based defence. See the README and the in-plugin help text for the full threat model.

**No external dependencies**

* No external SaaS API calls except to the CAPTCHA provider you configure (Cloudflare or Google).
* No external CDN for plugin assets.
* No tracking, telemetry, or remote update server.
* No Composer or npm runtime dependencies (Playwright + dotenv are dev-only).

== Installation ==

1. Upload the `stealth-access` folder to `wp-content/plugins/` (or install via the WordPress Plugin Directory).
2. Activate **Stealth Access** from the **Plugins** screen.
3. Visit **Stealth Access → Settings** (top-level admin menu, shield icon).
4. Pick your custom login slug under **Custom login URL**.
5. (Recommended) Configure a Cloudflare Turnstile site key + secret key under **CAPTCHA** before enabling **Hide default login URLs**, so you don't lock yourself out.
6. When you're confident the new login URL works, enable **Hide default login URLs** to block `/wp-login.php`.

**Recovery if you lock yourself out**

Rename the plugin folder via SFTP or SSH from `stealth-access` to `stealth-access-disabled`. WordPress will deactivate the plugin automatically and `/wp-login.php` becomes reachable again. Your settings (including your custom slug, CAPTCHA keys, and login page) survive deactivation.

== Frequently Asked Questions ==

= Will I get locked out if I misconfigure the plugin? =

Plausibly. The most common lockout scenarios are: (a) enabling **Hide default login URLs** before confirming the custom slug works, (b) misconfiguring the CAPTCHA so the login form is unsubmittable, and (c) enabling **Disable password reset** and then forgetting your password. The settings UI shows lockout warnings on every risky toggle. Recovery via SFTP folder-rename always works and is documented above.

= Does it work with Jetpack, the WordPress mobile app, or the Application Password REST API? =

Stealth Access disables XML-RPC and Application Passwords **by default** in v1.0.0 because both bypass the two-step flow. If you use any of those, turn off the corresponding setting under **General Protection**. Both toggles ship with a Compatibility note explaining the trade-off.

= Why disable XML-RPC by default? =

The audit that drove v1.0.0 identified `/xmlrpc.php` as an unrate-limited parallel-auth surface — a brute-force tool can grind credentials there with no CAPTCHA, no nonce, and no two-step. For a plugin marketed as login hardening, leaving XML-RPC open contradicts the value proposition. If your site genuinely needs XML-RPC (Jetpack, some publishing tools), opt back in.

= Does it work on multisite? =

Not tested. Stealth Access is built for single-site WordPress installs in v1.0.0. Multisite support is on the roadmap but not in this release. Evaluate carefully before activating on a network.

= What about HTTPS? =

Strongly recommended. WordPress only sets the `Secure` cookie attribute on auth cookies when `is_ssl()` returns true. The plugin still works on HTTP, but cookie security degrades. Run behind TLS in production.

= How are CAPTCHA keys handled? =

Secret keys are never rendered verbatim in HTML. The admin field is blank after save and a masked fingerprint of the last four characters is displayed below. The full secret stays server-side. If keys go missing — accidentally cleared, partial backup restore, etc. — a persistent admin banner appears across wp-admin and a rate-limited line is written to `error.log`. Logins still succeed (missing keys never block login by design) but the degradation in defence depth is loud.

== Screenshots ==

1. The custom secure-login portal (Step 1) — the two-step form a real visitor sees in place of `/wp-login.php`.
2. Stealth Access dashboard — status summary panel, copy-to-clipboard login URL, four feature status badges, and the persistent CAPTCHA-misconfig banner that surfaces when keys are missing.
3. Settings page — General Protection card with the password-reset, hide-default-login-URLs, disable-XML-RPC, and disable-Application-Passwords toggles, each with a Lockout/Compatibility callout.
4. Settings page — CAPTCHA Protection card with provider selector, the "Configured" status callout, and the masked-fingerprint secret-key UI that never renders the saved secret in HTML.
5. Dashboard with hidden-login protection ENABLED — the "Hidden Login URLs" status badge flips to "Enabled" and `/wp-login.php` is no longer publicly reachable.

== Changelog ==

= 1.0.5 =

Patch release. Restores login on sites using a two-factor (2FA) plugin that validates its code inside the WordPress `authenticate` filter — most notably **Wordfence Login Security**.

* **Two-step portal now completes Wordfence-style 2FA.** When the password was correct but the 2FA plugin still needed a code, it returned an intermediate "code required" error that the portal mistook for bad credentials and showed *"Invalid login details. Please try again."* — leaving the user with no way to enter a code. The portal now recognises those provider responses and presents a conditional **Authentication Code** step instead.
* **Conditional 2FA step.** After a correct password, the portal asks the user to re-enter their password and their authentication code, then hands the code to the provider through its native request field so the provider performs the validation. A correct code logs in; a wrong code shows *"Invalid authentication code. Please try again."*; a wrong password still shows *"Invalid login details."*; accounts without 2FA are unaffected.
* **Recognised codes are filterable.** The default set (`wfls_twofactor_required`, `wfls_twofactor_failed`) can be extended via the `tssl_2fa_required_error_codes` filter, and the request field the code is written to via `tssl_2fa_code_post_fields`.

Security is unchanged: the password is never stored between requests (it is requested again on the 2FA step by design), no authentication cookie is forced, no authentication hook is short-circuited, and the 2FA provider still performs the actual code validation. The v1.0.4 `login_form_{action}` compatibility and the v1.0.3 permalink behavior are untouched.

= 1.0.4 =

Patch release. Restores compatibility with WordPress two-factor (2FA) plugins when hidden login URLs are enabled.

* **2FA second-factor steps are no longer blocked.** With **Hide default login URLs** on, the second step of a 2FA plugin (for example the official *Two-Factor* plugin, which posts its code back to `wp-login.php?action=validate_2fa`) was treated as a disallowed login action and returned a 404 — so a user who had entered their username and password could never submit their authentication code and was locked out. The plugin now lets any login action that a plugin services through a `login_form_{action}` handler reach `wp-login.php`, which is exactly how 2FA providers add their challenge step. A new `tssl_allow_login_action` filter lets you override this per action.
* **2FA challenge forms post back to the real endpoint.** While an authentication is being completed (inside the `wp_login` hook), the plugin no longer rewrites `wp-login.php` URLs to the hidden slug, so a 2FA plugin's challenge form submits to the correct place. The challenge is only ever shown after the username and password steps, so this does not expose the hidden login URL to anonymous visitors.
* The `interim-login` action-coercion bypass (H1), the `wp-login.php` credential form, XML-RPC, and Application Password protections are all unchanged — none of those actions has a `login_form_` handler, so they stay blocked.

No CAPTCHA behavior changed, and authentication is not weakened: the 2FA provider still runs its full challenge before the user is logged in.

= 1.0.3 =

Patch release. Fixes the custom login URL on sites with "PATHINFO" permalinks.

* **Custom login URL now respects the permalink structure.** On sites whose permalinks include `/index.php/` (e.g. `/index.php/%postname%/`, common on hosts without clean-URL rewriting), the login page lives at `/index.php/your-slug/`. The plugin previously advertised and linked to `/your-slug/`, which 404s on those sites. Everywhere the plugin shows, copies, opens, or redirects to the login URL — the dashboard Login URL, the Settings page, the Copy URL button, the Open Login Page button, and the wp-login redirects — it now derives the URL from the page's real permalink. The slug-based URL is used only as a fallback when no login page is tracked yet.
* **Better duplicate-page handling.** If a page already sits at the configured slug and contains the `[two_step_secure_login]` shortcode, the plugin adopts it instead of tracking a `-2` duplicate, and switches tracking off a duplicate back to the configured-slug page. Duplicates are never deleted automatically — they are simply no longer tracked.

No functional, authentication, or CAPTCHA behavior changed.

= 1.0.2 =

Patch release. Fixes a fresh-install bug where a custom login slug could 404.

* **Custom login URL now always resolves.** On some fresh installs, changing the custom login slug left the tracked login page sitting at its old slug, so the configured URL returned a theme 404 even though the page existed and was published (and neither re-saving permalinks nor reinstalling fixed it, because the bad state lived in the database).
* **Self-healing login page.** The plugin now reconciles the tracked login page against the configured slug on every settings save and every admin page load: it re-slugs a drifted page, re-publishes it if needed, and repairs a stale, wrong, missing, or zero stored page ID by adopting the real login page (or creating one if none exists).
* **One-time rewrite flush** is scheduled only when the login page is actually created, adopted, or re-slugged — never on a normal request.
* Hidden-login REST / sitemap / search protections and the upgrade path for existing installs are unchanged.

= 1.0.1 =

Patch release. Login portal styling is now isolated from the active theme.

* **Login portal CSS isolation.** Every portal rule is now scoped under `body.tssl-portal-body .tssl-card`, so the secure-login page renders identically regardless of the active theme.
* **Theme CSS override protection.** The portal stylesheet is enqueued after the theme's stylesheet in the `<head>` source order, and brand-critical properties are locked so aggressive theme rules (including `!important`) can no longer win the cascade.
* **Typography consistency.** The portal title and body text always render in the intended Stealth Access font stack — block-theme heading/body font presets no longer leak in.
* **Button styling consistency.** The Continue button stays Stealth Access blue with the intended shape and casing, even under themes that repaint `button` / `.wp-element-button`.
* **Input styling consistency.** Login fields keep their white background, border, and radius regardless of theme form styling.

No functional, authentication, or CAPTCHA behavior changed. CAPTCHA provider widgets are untouched.

= 1.0.0 =

First public release.

The work for v1.0.0 was driven by an internal multi-agent security audit (81 findings across all severities) followed by three remediation passes:

* **v0.1.13** closed both High-severity findings: the `/wp-login.php?action=interim-login` action-coercion authentication bypass (H1), and the XML-RPC + REST Application Passwords parallel-auth bypass (H2). XML-RPC and Application Passwords now default to disabled with explicit opt-out.
* **v0.1.14** closed four Mediums: the CAPTCHA silent fail-open when keys are missing (M4) now surfaces a persistent admin banner and a rate-limited `error.log` line; the hidden-login-URL slug no longer leaks via the WP core REST API (M5), the XML sitemap (M6), or the front-end search (M7).
* **v0.1.15** closed two more Mediums: the dead `enable_two_step` toggle was removed (M1) and the lost-password URL no longer publishes `/wp-login.php` in theme HTML (M2).
* Two remaining Mediums (Step-2 timing differential, lost-password redirect Location header) ship as documented accepted risks. Both have narrow trigger configurations.

Combined regression coverage: 124 Playwright tests covering blocking, admin-management, opt-out, and behavioural-regression paths for every closed finding.

== Upgrade Notice ==

= 1.0.5 =

Production-fix patch. Restores login for sites that pair Stealth Access with a 2FA plugin that validates its code in the WordPress authenticate filter (e.g. Wordfence Login Security) — the password step no longer dead-ends with "Invalid login details." Security model unchanged; no 2FA bypass. Recommended for anyone using two-factor authentication.

= 1.0.4 =

Production-fix patch. Restores login for sites that pair Stealth Access with a WordPress 2FA plugin while hidden login URLs are on — the 2FA second-factor step was being blocked. No CAPTCHA changes; authentication is not weakened. Recommended for anyone using two-factor authentication.

= 1.0.3 =

Bug-fix patch. Fixes the custom login URL 404 on sites with /index.php/ (PATHINFO) permalinks by deriving the URL from the page permalink. No functional, authentication, or CAPTCHA changes. Safe drop-in upgrade.

= 1.0.2 =

Bug-fix patch. Repairs a fresh-install case where a custom login slug could return a 404, and self-heals the login page on save / admin load. No functional, authentication, or CAPTCHA changes. Safe drop-in upgrade.

= 1.0.1 =

Styling-only patch. The secure-login portal is now isolated from theme CSS so it renders consistently across themes. No functional, authentication, or CAPTCHA changes. Safe drop-in upgrade.

= 1.0.0 =

First public release. New installs only. If you ran a pre-release 0.1.x, audit your XML-RPC and Application Password usage before upgrading — both default to disabled.
