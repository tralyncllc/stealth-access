<?php
/**
 * Stealth Access — production diagnostics.
 *
 * Read-only. Writes nothing, changes no settings, renames no pages. It
 * gathers the facts needed to explain a "custom login URL returns a theme
 * 404" report on a live site, covering all nine working hypotheses.
 *
 * ── How to run ────────────────────────────────────────────────────────
 *
 * A) WP-CLI (preferred, needs SSH):
 *      wp eval-file tssl-diagnostics.php
 *    or, if you only have the file on your machine, pipe it:
 *      wp eval-file - < tssl-diagnostics.php
 *
 * B) No SSH — drop-in mu-plugin:
 *      Upload this file to wp-content/mu-plugins/tssl-diagnostics.php via
 *      SFTP/your host file manager, then, logged in as an administrator,
 *      visit:  https://YOURSITE/?tssl_diag=1
 *      A plain-text report renders. DELETE the file when finished.
 *
 * C) Code Snippets plugin (no SSH, no SFTP):
 *      Paste the body of tssl_diag_report() into a PHP snippet, run once,
 *      copy the output. (Set $slug_override below if needed.)
 *
 * Paste the full output back for analysis. It contains no secrets — CAPTCHA
 * keys are masked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow `wp eval-file` (ABSPATH defined) but refuse direct web hits.
	if ( PHP_SAPI !== 'cli' ) {
		exit;
	}
}

if ( ! function_exists( 'tssl_diag_report' ) ) {
	/**
	 * Build the diagnostic report as a plain-text string.
	 *
	 * @param string $slug_override Optional slug to test instead of the stored one.
	 * @return string
	 */
	function tssl_diag_report( $slug_override = '' ) {
		$out = array();
		$line = function ( $k, $v ) use ( &$out ) {
			$out[] = sprintf( '%-34s %s', $k, is_scalar( $v ) || null === $v ? var_export( $v, true ) : wp_json_encode( $v ) );
		};
		$head = function ( $t ) use ( &$out ) {
			$out[] = '';
			$out[] = '===== ' . $t . ' =====';
		};

		$out[] = 'Stealth Access diagnostics @ ' . gmdate( 'c' );
		$out[] = 'site_url=' . site_url() . '  home_url=' . home_url();

		/* ── A. Running code identity (hyp 1, 2, 7) ───────────────────── */
		$head( 'A. RUNNING CODE IDENTITY' );
		$line( 'TSSL_VERSION (constant)', defined( 'TSSL_VERSION' ) ? TSSL_VERSION : '(undefined!)' );
		$pm_exists = class_exists( 'TSSL_Page_Manager' );
		$line( 'class TSSL_Page_Manager loaded', $pm_exists );
		$line( 'method reconcile_login_page()', $pm_exists && method_exists( 'TSSL_Page_Manager', 'reconcile_login_page' ) );
		$line( 'method find_adoptable_login_page()', $pm_exists && method_exists( 'TSSL_Page_Manager', 'find_adoptable_login_page' ) );
		$line( 'method maybe_flush_rewrite_rules()', $pm_exists && method_exists( 'TSSL_Page_Manager', 'maybe_flush_rewrite_rules' ) );
		$line( 'const FLUSH_FLAG defined', $pm_exists && defined( 'TSSL_Page_Manager::FLUSH_FLAG' ) );
		$line( 'settings flush_cache()', class_exists( 'TSSL_Settings' ) && method_exists( 'TSSL_Settings', 'flush_cache' ) );

		// Plugin header version + file fingerprints.
		if ( ! function_exists( 'get_plugin_data' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$main = defined( 'TSSL_PLUGIN_FILE' ) ? TSSL_PLUGIN_FILE : '';
		if ( $main && function_exists( 'get_plugin_data' ) && file_exists( $main ) ) {
			$data = get_plugin_data( $main, false, false );
			$line( 'plugin header Version', $data['Version'] ?? '?' );
		}
		$line( 'main file', $main );
		if ( $main && file_exists( $main ) ) {
			$line( 'main file mtime', gmdate( 'c', filemtime( $main ) ) );
		}
		$pm_file = defined( 'TSSL_PLUGIN_DIR' ) ? TSSL_PLUGIN_DIR . 'includes/class-tssl-page-manager.php' : '';
		if ( $pm_file && file_exists( $pm_file ) ) {
			$src = file_get_contents( $pm_file );
			$line( 'page-manager mtime', gmdate( 'c', filemtime( $pm_file ) ) );
			$line( 'page-manager sha256', hash( 'sha256', $src ) );
			$line( 'page-manager has reconcile (grep)', false !== strpos( $src, 'function reconcile_login_page' ) );
			$line( 'page-manager has OLD maybe_rename_page', false !== strpos( $src, 'function maybe_rename_page' ) );
		}
		$line( 'opcache enabled', function_exists( 'opcache_get_status' ) ? (bool) @opcache_get_status( false ) : 'n/a' );

		// Hook wiring / load order (hyp 7).
		$line( "has_action admin_init reconcile", has_action( 'admin_init' ) ? 'admin_init has hooks' : 'NONE' );
		$line( 'is plugin active', function_exists( 'is_plugin_active' ) && $main ? is_plugin_active( plugin_basename( $main ) ) : 'n/a' );
		$mu = array();
		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( (array) glob( WPMU_PLUGIN_DIR . '/*.php' ) as $f ) {
				$mu[] = basename( $f );
			}
		}
		$line( 'mu-plugins', $mu ? $mu : '(none)' );

		/* ── B. Settings + slug bytes (hyp 6, 8, 9) ───────────────────── */
		$head( 'B. SETTINGS + SLUG' );
		$opts = get_option( 'tssl_settings' );
		if ( ! is_array( $opts ) ) {
			$line( 'tssl_settings', 'MISSING or not an array (!)' );
			$opts = array();
		}
		$slug_raw = (string) ( $opts['custom_login_slug'] ?? '' );
		$slug     = $slug_override !== '' ? $slug_override : $slug_raw;
		$line( 'custom_login_slug (raw)', $slug_raw );
		$line( 'slug length (bytes)', strlen( $slug_raw ) );
		$line( 'slug hex', bin2hex( $slug_raw ) );
		$line( 'slug == trim(slug)', $slug_raw === trim( $slug_raw ) );
		$line( 'slug == sanitize_title(slug)', $slug_raw === sanitize_title( $slug_raw ) );
		$line( 'sanitize_title(slug)', sanitize_title( $slug_raw ) );
		$line( 'login_page_id', (int) ( $opts['login_page_id'] ?? 0 ) );
		$line( 'auto_create_login_page', $opts['auto_create_login_page'] ?? null );
		$line( 'hide_default_login_urls', $opts['hide_default_login_urls'] ?? null );
		$line( 'hide_login_page_from_lists', $opts['hide_login_page_from_lists'] ?? null );
		$line( 'blocked_login_behavior', $opts['blocked_login_behavior'] ?? null );

		/* ── C. The tracked page (hyp 4, 6) ───────────────────────────── */
		$head( 'C. TRACKED LOGIN PAGE' );
		$lpid = (int) ( $opts['login_page_id'] ?? 0 );
		$page = $lpid > 0 ? get_post( $lpid ) : null;
		if ( $page instanceof WP_Post ) {
			$line( 'page ID', $page->ID );
			$line( 'post_name', $page->post_name );
			$line( 'post_status', $page->post_status );
			$line( 'post_type', $page->post_type );
			$line( 'post_parent', $page->post_parent );
			$line( 'post_password set', '' !== $page->post_password );
			$line( 'content has shortcode', false !== strpos( (string) $page->post_content, 'two_step_secure_login' ) );
			$line( 'content first 120 chars', substr( (string) $page->post_content, 0, 120 ) );
			$line( 'get_permalink', get_permalink( $page->ID ) );
			$line( 'slug matches configured', $page->post_name === sanitize_title( $slug ) );
			if ( (int) $page->post_parent > 0 ) {
				$line( 'PARENT PAGE', get_post_field( 'post_name', $page->post_parent ) . ' (nested → URL is not /slug/ !)' );
			}
		} else {
			$line( 'tracked page', 'MISSING — get_post(' . $lpid . ') returned null' );
		}

		// Pages at / near the configured slug (detect -2 suffix duplicates).
		$byp = get_page_by_path( sanitize_title( $slug ) );
		$line( 'get_page_by_path(slug)', $byp instanceof WP_Post ? 'ID ' . $byp->ID . ' name=' . $byp->post_name . ' status=' . $byp->post_status : 'NULL' );
		global $wpdb;
		$like = $wpdb->esc_like( sanitize_title( $slug ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_status, post_type, post_parent FROM {$wpdb->posts} WHERE post_name LIKE %s", $like ) );
		$dups = array();
		foreach ( (array) $rows as $r ) {
			$dups[] = "{$r->ID}:{$r->post_name}:{$r->post_status}:{$r->post_type}:parent{$r->post_parent}";
		}
		$line( 'posts LIKE slug%', $dups ? $dups : '(none)' );

		// All pages carrying the shortcode anywhere.
		$shortcode_pages = $wpdb->get_results( "SELECT ID, post_name, post_status FROM {$wpdb->posts} WHERE post_type='page' AND post_content LIKE '%two_step_secure_login%'" );
		$sc = array();
		foreach ( (array) $shortcode_pages as $r ) {
			$sc[] = "{$r->ID}:{$r->post_name}:{$r->post_status}";
		}
		$line( 'pages with shortcode', $sc ? $sc : '(none)' );

		/* ── D. URL resolution (hyp 3, 4) ─────────────────────────────── */
		$head( 'D. URL RESOLUTION' );
		$line( 'permalink_structure', get_option( 'permalink_structure' ) );
		$line( 'show_on_front', get_option( 'show_on_front' ) );
		$line( 'page_on_front', (int) get_option( 'page_on_front' ) );
		$test_url = home_url( '/' . trim( sanitize_title( $slug ), '/' ) . '/' );
		$line( 'test URL', $test_url );
		// THE key check: does WP's rewrite/query layer map the URL to a post?
		$resolved = url_to_postid( $test_url );
		$line( 'url_to_postid(test URL)', $resolved . ( $resolved === $lpid && $lpid ? '  (== tracked page ✓)' : ( 0 === $resolved ? '  (0 → WP cannot resolve → 404)' : '  (resolves to a DIFFERENT post!)' ) ) );
		$rr = get_option( 'rewrite_rules' );
		$line( 'rewrite_rules stored', is_array( $rr ) ? count( $rr ) . ' rules' : 'NONE (flush needed)' );

		/* ── E. Theme / plugins / Elementor (hyp 5) ───────────────────── */
		$head( 'E. THEME / PLUGINS / PAGE BUILDER' );
		$theme = wp_get_theme();
		$line( 'active theme', $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) );
		$line( 'is block theme', function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : 'n/a' );
		$active = (array) get_option( 'active_plugins', array() );
		$line( 'active plugin count', count( $active ) );
		$line( 'active plugins', $active );
		// Elementor / builder signals on the login page.
		if ( $page instanceof WP_Post ) {
			$line( 'page _elementor_edit_mode', get_post_meta( $page->ID, '_elementor_edit_mode', true ) ?: '(none)' );
			$line( 'page _elementor_data set', '' !== (string) get_post_meta( $page->ID, '_elementor_data', true ) );
			$line( 'page _wp_page_template', get_post_meta( $page->ID, '_wp_page_template', true ) ?: '(default)' );
		}
		$watch = array( 'elementor', 'wordfence', 'redirection', 'really-simple-ssl', 'seo', 'rank-math', 'wp-rocket', 'w3-total-cache', 'litespeed', 'security', 'cloudflare', 'hide', 'wps-hide-login' );
		$flagged = array();
		foreach ( $active as $p ) {
			foreach ( $watch as $w ) {
				if ( false !== stripos( $p, $w ) ) {
					$flagged[] = $p;
				}
			}
		}
		$line( 'flagged plugins (redirect/security/cache/builder)', array_values( array_unique( $flagged ) ) ?: '(none)' );

		/* ── F. Template interception (hyp 3, 7) ──────────────────────── */
		$head( 'F. TEMPLATE INTERCEPTION' );
		$line( 'template_include has hooks', has_filter( 'template_include' ) ? 'yes' : 'NO' );
		$line( 'shortcode registered', shortcode_exists( 'two_step_secure_login' ) );
		$line( 'login-template.php exists', defined( 'TSSL_PLUGIN_DIR' ) && file_exists( TSSL_PLUGIN_DIR . 'includes/views/login-template.php' ) );

		$out[] = '';
		$out[] = '===== END =====';
		return implode( "\n", $out );
	}
}

/* ── Drop-in mu-plugin mode: render for admins at ?tssl_diag=1 ─────────── */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	add_action(
		'init',
		function () {
			if ( empty( $_GET['tssl_diag'] ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$slug = isset( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( $_GET['slug'] ) ) : '';
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			echo tssl_diag_report( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		},
		1
	);
}

/* ── WP-CLI mode: print immediately ───────────────────────────────────── */
if ( PHP_SAPI === 'cli' || defined( 'WP_CLI' ) ) {
	echo tssl_diag_report() . "\n";
}
