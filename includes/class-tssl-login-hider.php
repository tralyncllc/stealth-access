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
	}

	private function is_active(): bool {
		return (bool) $this->settings->get( 'hide_default_login_urls' );
	}

	private function custom_login_url(): string {
		$slug = (string) $this->settings->get( 'custom_login_slug', 'secure-login' );
		return home_url( '/' . trim( $slug, '/' ) . '/' );
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
		$always = array( 'logout', 'postpass', 'interim-login' );
		if ( in_array( $action, $always, true ) ) {
			return true;
		}
		$reset = array( 'lostpassword', 'retrievepassword', 'resetpass', 'rp' );
		if ( in_array( $action, $reset, true ) && ! $this->settings->get( 'disable_password_reset' ) ) {
			return true;
		}
		return false;
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
		unset( $redirect );
		if ( $this->settings->get( 'disable_password_reset' ) ) {
			return $this->custom_login_url();
		}
		return (string) $url;
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
