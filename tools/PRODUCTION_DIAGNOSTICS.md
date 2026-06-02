# Stealth Access — production 404 diagnostics

**Status: no new patch shipped.** This is diagnostics only. We need real
data from a failing production site before changing any code.

---

## What we already ruled out

The **published v1.0.2 release ZIP is correct** (verified this round):

- Downloaded `stealth-access-1.0.2.zip` from the GitHub release; its
  SHA-256 matches the published `.sha256`
  (`f65a6b54…34d82e63`).
- `includes/class-tssl-page-manager.php` and `includes/class-tssl-settings.php`
  in the ZIP are **byte-identical to the `v1.0.2` git tag**.
- Confirmed present in the ZIP: header `Version: 1.0.2`, `TSSL_VERSION
  '1.0.2'`, `reconcile_login_page()`, `find_adoptable_login_page()`,
  `flush_cache()` + its `update_option`/`add_option` hooks,
  `FLUSH_FLAG` + `schedule_rewrite_flush()` + `maybe_flush_rewrite_rules()`,
  and the old fragile `maybe_rename_page` / `maybe_create_on_save` are
  **gone**.

So **hypothesis #1 (ZIP missing the fix) is eliminated.** The most likely
remaining causes are #2 (production isn't actually *running* v1.0.2) and
#4/#5/#6/#8 (URL not resolving / a conflicting plugin / a slug or
parent/status mismatch the dev box didn't have). The script below
distinguishes them.

---

## How to run the diagnostic

The script is `tools/tssl-diagnostics.php`. It is **read-only** — it writes
nothing, renames no pages, changes no settings. CAPTCHA secrets are not
printed. Run it on a site where `/your-login-slug/` 404s, by whichever
method you have access to:

### Option A — WP-CLI (best, needs SSH)

```bash
wp eval-file tools/tssl-diagnostics.php
# or pipe it if the file is only on your laptop:
wp eval-file - < tssl-diagnostics.php
```

### Option B — drop-in mu-plugin (no SSH, needs SFTP/file manager)

1. Upload `tssl-diagnostics.php` to `wp-content/mu-plugins/`
   (create the `mu-plugins` folder if it doesn't exist).
2. Logged in as an **administrator**, visit:
   `https://YOURSITE/?tssl_diag=1`
   (add `&slug=ultimax-login` to force-test a specific slug).
3. Copy the plain-text report.
4. **Delete the file** when done.

### Option C — Code Snippets plugin (no SSH, no SFTP)

Paste the body of `tssl_diag_report()` into a one-off PHP snippet, run it,
copy the output.

**Paste the full output back.** It contains no secrets.

---

## How to read the output (maps to the 9 hypotheses)

Read these lines first, in this order:

| Line in report | If it shows… | Means | Hypothesis |
|---|---|---|---|
| `TSSL_VERSION (constant)` | **not** `'1.0.2'` | The site is **not running v1.0.2** — opcache, a stale file copy, wordpress.org serving an older version, or a second plugin copy. **This is the #1 thing to confirm.** | #2 |
| `method reconcile_login_page()` | `false` | The fix code isn't loaded even if the header says 1.0.2 → wrong/old `class-tssl-page-manager.php` on disk, or opcache. Check `page-manager sha256` / `mtime`. | #2 |
| `plugin header Version` vs `TSSL_VERSION` | they disagree | Mixed files (partial upload, two installs). | #2 |
| `opcache enabled` | `true` | Old bytecode may be cached — flush opcache / restart PHP, retest. | #2, #7 |
| `url_to_postid(test URL)` | `0 (→ 404)` | **WordPress itself cannot resolve the URL** — not a plugin intercept. Then check `post_parent`, `slug matches configured`, `posts LIKE slug%`, slug hex. | #4, #6, #8 |
| `url_to_postid(test URL)` | a **different** post id | Another post owns that path (slug collision / a `-2` suffix). | #6 |
| `url_to_postid` = tracked id **but** browser still 404s | — | WP resolves it; something downstream returns 404 → a redirect/security/cache plugin or the theme. See `flagged plugins`. | #5 |
| `slug matches configured` | `false` | The tracked page's `post_name` ≠ configured slug — the divergence bug. (Should self-heal on v1.0.2 admin load; if it persists, the fix isn't running → back to #2.) | #6 |
| `post_parent` / `PARENT PAGE` | non-zero | The page is **nested**, so its real URL is `/parent/slug/`, not `/slug/`. | #6 |
| `post_status` | not `publish` | Draft/pending/private → public URL 404s. | #6 |
| `slug == trim(slug)` / `slug == sanitize_title(slug)` | `false`, or `slug hex` has odd bytes | **Hidden character / trailing space / casing** in the stored slug. | #8 |
| `posts LIKE slug%` | shows `…-2` or multiple rows | A **duplicate** post took the slug; the login page got suffixed. | #6 |
| `pages with shortcode` | count ≠ 1, or `(none)` | Missing or duplicated login page. | #6 |
| `content has shortcode` | `false` **and** `_elementor_data set` `true` | Page rebuilt in **Elementor** — content no longer holds the shortcode (note: the plugin template injects the shortcode directly, so this alone shouldn't 404, but flag it). | #5 |
| `flagged plugins …` | lists Wordfence / Redirection / “hide login” / SEO / cache | A **conflicting plugin** may intercept the URL or hide it. | #5 |
| `template_include has hooks` = `NO`, or `shortcode registered` = `false` | — | Plugin hooks didn't wire up (load order / fatal earlier in boot). | #3, #7 |
| `rewrite_rules stored` | `NONE` | Rewrite rules never generated — Settings → Permalinks → Save once. | #4 |
| `mu-plugins` | unexpected entries | A must-use plugin altering routing. | #5, #7 |

---

## Manual wp-admin checklist (do these too)

1. **Plugins → Installed Plugins:** what version does **Stealth Access**
   report? Is there **more than one** copy installed (e.g. a leftover
   `secure-login-shield`)? Screenshot it.
2. **Stealth Access → Settings:** what exact string is in the **Custom
   login URL** field? Copy it character-for-character.
3. **Pages → All Pages:** find the Secure Login page. Hover **Quick Edit**
   and note the **Slug**, **Status**, and **Parent**. Does the slug match
   the setting *exactly*? Is the Parent “(no parent)”? Is it **Published**?
4. **Pages:** open the page in the editor — is it a normal block/classic
   page with `[two_step_secure_login]`, or has it been opened/edited in
   **Elementor** or another builder?
5. **Settings → Reading:** is “**Your homepage displays**” set to a static
   page? Is the login page accidentally that page?
6. **Settings → Permalinks:** click **Save Changes** once (flushes rules),
   then retry the URL. (Note whether it changes anything — for us it
   shouldn't, but confirm.)
7. Try the URL **with and without a trailing slash**, and try
   `?page_id=<login_page_id>` (from the diagnostic) — does the page render
   that way? If `?page_id=` works but `/slug/` doesn't, it's a
   rewrite/slug/parent issue, not the template.

---

## What to send back

1. The full text output of `tools/tssl-diagnostics.php` from a failing
   site.
2. Answers to the manual checklist (especially the plugin version, the
   exact slug string, and the page Status/Parent).
3. Whether `?page_id=<id>` renders the portal when `/slug/` 404s.

With that, we can pinpoint which hypothesis is real and whether a code
change is even the right fix — before cutting any v1.0.x patch.
