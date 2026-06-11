<?php
/**
 * Hides default login URLs (wp-login.php and unauthenticated wp-admin) and
 * routes login URL generation through the custom slug.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspired in part by WPS Hide Login (GPLv2+) for edge-case coverage.
 * No code copied.
 */
class TSSL_Login_Hider {

	private TSSL_Settings $settings;

	public function __construct( TSSL_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( $this, 'filter_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 2 );
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 10, 3 );
		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 10, 2 );

		// `login_init` fires inside wp-login.php after WordPress core is
		// loaded but before any login HTML output — the cleanest place to
		// short-circuit a hidden login.
		add_action( 'login_init', array( $this, 'maybe_block_wp_login' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_block_wp_admin' ) );

		// Alternative-auth surfaces. Two-step + CAPTCHA are useless if an
		// attacker can hit /xmlrpc.php or Application-Password Basic auth
		// instead. Both default to disabled; admins who need them for mobile
		// apps or integrations can opt back in.
		add_filter( 'xmlrpc_enabled', array( $this, 'maybe_disable_xmlrpc' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_block_xmlrpc_request' ) );
		add_filter( 'wp_is_application_passwords_available', array( $this, 'maybe_disable_application_passwords' ) );

		// M2 fix: when a visitor lands on the custom login page with a
		// `?action=lostpassword` style query, forward to wp-login.php so
		// the standard WP reset flow runs. The slug-based URL keeps
		// /wp-login.php out of public HTML; the forward only happens on
		// actual user click-through.
		add_action( 'template_redirect', array( $this, 'maybe_forward_reset_actions' ) );
	}

	private function is_active(): bool {
		return (bool) $this->settings->get( 'hide_default_login_urls' );
	}

	private function custom_login_url(): string {
		// Prefer the tracked page's real permalink so redirects and rewritten
		// login URLs honour the site's permalink structure (including
		// PATHINFO setups like /index.php/%postname%/). Falls back to the
		// slug-based URL only when no valid login page is tracked.
		return $this->settings->get_login_url();
	}

	private function current_path(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path    = (string) wp_parse_url( (string) $request, PHP_URL_PATH );
		return $path;
	}

	private function current_action(): string {
		if ( isset( $_REQUEST['action'] ) ) {
			return sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
		}
		return '';
	}

	private function allowed_login_action( string $action ): bool {
		// Whitelist of wp-login.php actions that stay reachable even when
		// hide_default_login_urls is on. `interim-login` is deliberately NOT
		// here: WordPress core wp-login.php (line ~509) coerces any unknown
		// action value back to the default `login` case, so allowing
		// `?action=interim-login` would let an attacker render the standard
		// login form and POST credentials — fully bypassing the two-step
		// flow. The genuine interim-login flow used by the heartbeat modal
		// in wp-admin is driven by the `interim-login=1` request parameter
		// (NOT the `action` value) and reaches wp-login.php from an already
		// authenticated context, so blocking the action name here does not
		// break it.
		$always = array( 'logout', 'postpass' );
		if ( in_array( $action, $always, true ) ) {
			return true;
		}
		$reset = array( 'lostpassword', 'retrievepassword', 'resetpass', 'rp' );
		if ( in_array( $action, $reset, true ) && ! $this->settings->get( 'disable_password_reset' ) ) {
			return true;
		}

		// Second-factor / plugin-owned login actions. A 2FA provider that hooks
		// the standard WordPress login flow (the official "Two-Factor" plugin,
		// for example) renders its challenge and posts the code back to
		// `wp-login.php?action=validate_2fa` (or `backup_2fa`, or a
		// provider-specific value). Each such action is serviced by a
		// `login_form_{$action}` handler the plugin registered, so the request
		// is processed by that plugin and does NOT fall through to
		// wp-login.php's default username/password form. That distinction is
		// what keeps this from reopening the H1 `interim-login` action-coercion
		// bypass: `interim-login` has no `login_form_` handler and decays to the
		// default login form, so it stays blocked below.
		//
		// The credential/registration actions are excluded explicitly as
		// defence in depth — even if a plugin attaches a `login_form_login`
		// listener (some add fields), `action=login` must never become a way to
		// render the standard login form around the two-step flow.
		$reserved = array( 'login', 'register', 'confirmaction' );
		if (
			'' !== $action
			&& ! in_array( $action, $reserved, true )
			&& has_action( 'login_form_' . $action )
		) {
			/**
			 * Filters whether a plugin-registered `wp-login.php?action=…` step
			 * is allowed through while hidden-login is active. Defaults to true
			 * for any action that has a `login_form_{$action}` handler, which is
			 * how WordPress 2FA plugins add their second-factor step. Return
			 * false to keep a specific action blocked.
			 *
			 * @param bool   $allow  Whether to allow the action. Default true.
			 * @param string $action The wp-login.php action value.
			 */
			return (bool) apply_filters( 'tssl_allow_login_action', true, $action );
		}

		return false;
	}

	/**
	 * Whether an authentication is currently being completed — i.e. we are
	 * inside the `wp_login` action. A 2FA plugin renders or redirects to its
	 * second-factor challenge from this hook, and to do so it builds the
	 * challenge URL from `site_url( 'wp-login.php', … )`. While the challenge
	 * is being produced we must leave wp-login.php URLs intact so the
	 * provider's form posts back to the real endpoint. The challenge is only
	 * ever shown to a visitor who has already cleared the username and
	 * password steps, so this does not expose the hidden login URL to
	 * anonymous traffic.
	 */
	private function is_authenticating(): bool {
		return (bool) doing_action( 'wp_login' );
	}

	public function maybe_block_wp_login(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		if ( $this->allowed_login_action( $this->current_action() ) ) {
			return;
		}
		$this->apply_blocked_behavior();
	}

	public function maybe_block_wp_admin(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		$path = $this->current_path();
		if ( false === strpos( $path, '/wp-admin' ) ) {
			return;
		}
		if ( substr( $path, -strlen( '/wp-admin/admin-ajax.php' ) ) === '/wp-admin/admin-ajax.php' ) {
			return;
		}
		if ( substr( $path, -strlen( '/wp-admin/admin-post.php' ) ) === '/wp-admin/admin-post.php' ) {
			return;
		}
		$this->apply_blocked_behavior();
	}

	public function filter_site_url( $url, $path = '', $scheme = null, $blog_id = null ): string {
		unset( $scheme, $blog_id );
		if ( ! $this->is_active() ) {
			return $url;
		}
		if ( false === strpos( (string) $url, 'wp-login.php' ) ) {
			return $url;
		}
		if ( $this->is_authenticating() ) {
			return (string) $url;
		}
		return $this->rewrite_login_url( (string) $url );
	}

	public function filter_wp_redirect( $location, $status ): string {
		unset( $status );
		if ( ! $this->is_active() ) {
			return (string) $location;
		}
		if ( false === strpos( (string) $location, 'wp-login.php' ) ) {
			return (string) $location;
		}
		if ( $this->is_authenticating() ) {
			return (string) $location;
		}
		return $this->rewrite_login_url( (string) $location );
	}

	public function filter_login_url( $login_url, $redirect = '', $force_reauth = false ): string {
		if ( ! $this->is_active() ) {
			return (string) $login_url;
		}
		$url = $this->custom_login_url();
		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( (string) $redirect ), $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	public function filter_lostpassword_url( $url, $redirect = '' ): string {
		// When password reset is disabled, send users to the custom login
		// URL (no reset form exists there — they hit the two-step landing).
		if ( $this->settings->get( 'disable_password_reset' ) ) {
			return $this->custom_login_url();
		}
		// Audit finding M2: when only `hide_default_login_urls` is on, the
		// previous implementation returned the raw /wp-login.php URL —
		// publishing the existence of wp-login.php in every comment form,
		// login widget, and theme that calls wp_lostpassword_url(). Rewrite
		// to a slug-bearing URL with `action=lostpassword`. The actual
		// reset form lives at wp-login.php; the template_redirect handler
		// in this class forwards visitors there on click-through so the
		// reset flow still works for end users.
		if ( ! $this->is_active() ) {
			return (string) $url;
		}
		$target = $this->custom_login_url();
		if ( '' !== (string) $redirect ) {
			$target = add_query_arg( 'redirect_to', rawurlencode( (string) $redirect ), $target );
		}
		return add_query_arg( 'action', 'lostpassword', $target );
	}

	/**
	 * M2: when a visitor lands on the auto-created login page with a
	 * password-reset action in the query string, forward them to
	 * /wp-login.php?action=<...>. The forward is the only place wp-login.php
	 * appears — public HTML no longer contains it. The action allowlist in
	 * `allowed_login_action()` permits the forward through `maybe_block_wp_login`
	 * when password reset is allowed; when reset is disabled the existing
	 * login-flow handler short-circuits earlier.
	 */
	public function maybe_forward_reset_actions(): void {
		if ( ! $this->is_active() ) {
			return;
		}
		if ( $this->settings->get( 'disable_password_reset' ) ) {
			return;
		}
		$page_id = (int) $this->settings->get( 'login_page_id' );
		if ( $page_id <= 0 ) {
			return;
		}
		if ( ! is_page( $page_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only action enum check; the redirect target is host-restricted by allowed_login_action() + filter_wp_redirect().
		if ( ! isset( $_GET['action'] ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		$allowed_reset = array( 'lostpassword', 'retrievepassword', 'resetpass', 'rp' );
		if ( ! in_array( $action, $allowed_reset, true ) ) {
			return;
		}
		// Build the wp-login.php URL via home_url() so it bypasses our own
		// `site_url` filter (which rewrites wp-login.php → custom slug).
		// Then preserve any extra query args (key, login, etc. that are
		// part of the reset link flow).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- forwarded verbatim to wp-login.php which performs its own verification.
		$qs = (array) $_GET;
		$qs['action'] = $action;
		$target = add_query_arg( $qs, home_url( '/wp-login.php' ) );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * `xmlrpc_enabled` filter callback. Disables the XML-RPC dispatcher
	 * entirely when the corresponding setting is on, so endpoints like
	 * `wp.getUsersBlogs` reject every request — including the
	 * authentication path that would otherwise let an attacker brute-force
	 * credentials around the two-step flow.
	 *
	 * @param bool $enabled Whether XML-RPC is currently enabled.
	 */
	public function maybe_disable_xmlrpc( $enabled ): bool {
		if ( $this->settings->get( 'disable_xmlrpc' ) ) {
			return false;
		}
		return (bool) $enabled;
	}

	/**
	 * Belt-and-suspenders block for /xmlrpc.php at the request layer.
	 * `xmlrpc_enabled` only stops the dispatcher; with this hook the entire
	 * request is short-circuited via the same blocked-behavior path as
	 * /wp-login.php, so /xmlrpc.php returns a 404 (or the configured
	 * redirect) and never executes any auth code.
	 */
	public function maybe_block_xmlrpc_request(): void {
		if ( ! $this->settings->get( 'disable_xmlrpc' ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'xmlrpc.php' !== $script ) {
			return;
		}
		$this->apply_blocked_behavior();
	}

	/**
	 * `wp_is_application_passwords_available` filter callback. Disables
	 * Application Passwords site-wide when the setting is on so the REST
	 * API and other Basic-auth-capable endpoints stop accepting them,
	 * eliminating a parallel-auth surface that bypasses the two-step flow.
	 *
	 * @param bool $available Whether Application Passwords are available.
	 */
	public function maybe_disable_application_passwords( $available ): bool {
		if ( $this->settings->get( 'disable_application_passwords' ) ) {
			return false;
		}
		return (bool) $available;
	}

	private function rewrite_login_url( string $url ): string {
		$parsed = wp_parse_url( $url );
		$query  = array();
		if ( ! empty( $parsed['query'] ) ) {
			wp_parse_str( $parsed['query'], $query );
		}
		$action = isset( $query['action'] ) ? (string) $query['action'] : '';
		if ( '' !== $action && $this->allowed_login_action( $action ) ) {
			return $url;
		}
		$custom = $this->custom_login_url();
		if ( ! empty( $query ) ) {
			unset( $query['action'] );
			if ( ! empty( $query ) ) {
				$custom = add_query_arg( $query, $custom );
			}
		}
		return $custom;
	}

	private function apply_blocked_behavior(): void {
		$behavior = (string) $this->settings->get( 'blocked_login_behavior', 'show_404' );

		if ( 'redirect_home' === $behavior ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( 'redirect_custom_url' === $behavior ) {
			$target = (string) $this->settings->get( 'blocked_login_custom_url', '' );
			if ( '' !== $target ) {
				$validated = wp_validate_redirect( $target, home_url( '/' ) );
				wp_safe_redirect( $validated );
				exit;
			}
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();

		$template = get_query_template( '404' );
		if ( $template ) {
			include $template;
		} else {
			wp_die(
				esc_html__( 'Not found.', 'stealth-access' ),
				'',
				array( 'response' => 404 )
			);
		}
		exit;
	}
}
