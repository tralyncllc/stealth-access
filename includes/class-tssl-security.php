<?php
/**
 * Security utilities: random tokens, transient/cookie storage for the
 * two-step flow, client IP lookup, safe redirect resolution.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers used by the login flow and CAPTCHA validation.
 */
class TSSL_Security {

	const COOKIE_NAME      = 'tssl_login_token';
	const TRANSIENT_PREFIX = 'tssl_login_';
	const TTL              = 600; // 10 minutes.

	/**
	 * Persist a Step-1 identifier behind a random token so the raw
	 * username/email never lives in the cookie itself.
	 *
	 * @param string $identifier The submitted username or email.
	 * @return string The token written to the client cookie.
	 */
	public function store_identifier( string $identifier ): string {
		$this->clear_storage();

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			$token = wp_generate_password( 32, false, false );
		}

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'identifier' => $identifier,
				'created'    => time(),
			),
			self::TTL
		);

		$this->set_cookie( $token );

		return $token;
	}

	/**
	 * Retrieve the stored identifier for the current session, if any.
	 *
	 * @return string|null
	 */
	public function get_stored_identifier(): ?string {
		$token = $this->get_cookie();
		if ( null === $token ) {
			return null;
		}

		$data = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $data ) || empty( $data['identifier'] ) ) {
			return null;
		}

		return (string) $data['identifier'];
	}

	/**
	 * Clear both transient and cookie. Safe to call when nothing is stored.
	 */
	public function clear_storage(): void {
		$token = $this->get_cookie();
		if ( null !== $token ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
		}
		$this->clear_cookie();
	}

	/**
	 * Read the current client IP from headers commonly used by reverse
	 * proxies. The returned value is only used for CAPTCHA "remoteip"
	 * scoring; never trust it as the sole control on its own.
	 *
	 * @return string
	 */
	public function get_ip(): string {
		$candidates = array();

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded    = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidates[] = trim( (string) $forwarded[0] );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Determine the redirect target after a successful login.
	 *
	 * @param string|null $requested The submitted redirect_to value, if any.
	 * @param WP_User     $user      The freshly authenticated user.
	 * @return string
	 */
	public function determine_safe_redirect( ?string $requested, WP_User $user ): string {
		if ( ! empty( $requested ) ) {
			$validated = wp_validate_redirect( $requested, '' );
			if ( '' !== $validated ) {
				return $validated;
			}
		}

		if ( user_can( $user, 'read' ) ) {
			return admin_url();
		}

		return home_url( '/' );
	}

	private function set_cookie( string $token ): void {
		if ( headers_sent() ) {
			return;
		}

		$params = array(
			'expires'  => time() + self::TTL,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		setcookie( self::COOKIE_NAME, $token, $params );
		$_COOKIE[ self::COOKIE_NAME ] = $token;
	}

	private function get_cookie(): ?string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}
		$raw   = (string) wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		$clean = preg_replace( '/[^a-f0-9]/', '', $raw );
		return ( '' === $clean ) ? null : $clean;
	}

	private function clear_cookie(): void {
		if ( headers_sent() ) {
			return;
		}

		$params = array(
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		setcookie( self::COOKIE_NAME, '', $params );
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}
}
