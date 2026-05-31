=== Stealth Access ===
Contributors: tralyncllc
Tags: login, security, two-factor, captcha, hardening
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
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

= 1.0.0 =

First public release.

The work for v1.0.0 was driven by an internal multi-agent security audit (81 findings across all severities) followed by three remediation passes:

* **v0.1.13** closed both High-severity findings: the `/wp-login.php?action=interim-login` action-coercion authentication bypass (H1), and the XML-RPC + REST Application Passwords parallel-auth bypass (H2). XML-RPC and Application Passwords now default to disabled with explicit opt-out.
* **v0.1.14** closed four Mediums: the CAPTCHA silent fail-open when keys are missing (M4) now surfaces a persistent admin banner and a rate-limited `error.log` line; the hidden-login-URL slug no longer leaks via the WP core REST API (M5), the XML sitemap (M6), or the front-end search (M7).
* **v0.1.15** closed two more Mediums: the dead `enable_two_step` toggle was removed (M1) and the lost-password URL no longer publishes `/wp-login.php` in theme HTML (M2).
* Two remaining Mediums (Step-2 timing differential, lost-password redirect Location header) ship as documented accepted risks. Both have narrow trigger configurations.

Combined regression coverage: 124 Playwright tests covering blocking, admin-management, opt-out, and behavioural-regression paths for every closed finding.

== Upgrade Notice ==

= 1.0.0 =

First public release. New installs only. If you ran a pre-release 0.1.x, audit your XML-RPC and Application Password usage before upgrading — both default to disabled.
