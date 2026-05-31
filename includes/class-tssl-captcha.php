<?php
/**
 * CAPTCHA rendering and server-side verification for Cloudflare Turnstile,
 * Google reCAPTCHA v2 (checkbox), and Google reCAPTCHA v3 (score-based).
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless wrapper around the supported CAPTCHA providers.
 */
class TSSL_Captcha {

	const TURNSTILE_API_URL    = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const RECAPTCHA_API_URL    = 'https://www.google.com/recaptcha/api.js';
	const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	const ACTION_USERNAME_STEP = 'tssl_username_step';
	const ACTION_PASSWORD_STEP = 'tssl_password_step';

	/**
	 * @var TSSL_Settings
	 */
	private TSSL_Settings $settings;

	/**
	 * @var TSSL_Security
	 */
	private TSSL_Security $security;

	/**
	 * Transient key + TTL for the rate-limited "CAPTCHA verification skipped
	 * due to missing keys" log line. We want admins to be able to spot the
	 * misconfiguration in error.log without flooding it with one entry per
	 * login attempt during an attack.
	 */
	const MISCONFIG_LOG_TRANSIENT = 'tssl_captcha_misconfig_logged';
	const MISCONFIG_LOG_TTL       = 60;

	public function __construct( TSSL_Settings $settings, TSSL_Security $security ) {
		$this->settings = $settings;
		$this->security = $security;
	}

	/**
	 * Wire admin-side hooks. Keeping the constructor side-effect-free means
	 * the class stays unit-testable; this method is the one entry point that
	 * touches the WordPress action/filter system.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render_misconfig_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_render_misconfig_notice' ) );
	}

	/**
	 * Render a persistent red banner across admin pages when the admin has
	 * selected a CAPTCHA provider but at least one required key is empty.
	 * This is the M4 fix: prior to v0.1.14 the only signal was an inline
	 * placeholder on the login page that admins almost never saw because
	 * they are already authenticated when they hit it.
	 */
	public function maybe_render_misconfig_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->is_configured() ) {
			return;
		}
		$provider_label = 'cloudflare_turnstile' === $this->provider()
			? __( 'Cloudflare Turnstile', 'stealth-access' )
			: __( 'Google reCAPTCHA', 'stealth-access' );
		$settings_url = admin_url( 'admin.php?page=tssl-settings' );
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Stealth Access — CAPTCHA is disabled.', 'stealth-access' ),
			sprintf(
				/* translators: %s: configured provider name. */
				esc_html__( 'You selected %s as your CAPTCHA provider but the site key or secret key is missing. Logins are proceeding WITHOUT a CAPTCHA challenge. This is intentional — missing keys never block login — but you should restore the keys.', 'stealth-access' ),
				esc_html( $provider_label )
			),
			esc_url( $settings_url ),
			esc_html__( 'Open Stealth Access settings →', 'stealth-access' )
		);
	}

	/**
	 * Emit a one-off error_log entry the first time a login attempt slips
	 * past CAPTCHA due to missing keys. A transient gates re-logging so the
	 * log can't be flooded under a brute-force attack — one line per minute
	 * per provider misconfiguration is enough for an admin tailing the log
	 * to notice.
	 */
	private function log_skipped_once(): void {
		if ( false !== get_transient( self::MISCONFIG_LOG_TRANSIENT ) ) {
			return;
		}
		set_transient( self::MISCONFIG_LOG_TRANSIENT, '1', self::MISCONFIG_LOG_TTL );
		error_log(
			sprintf(
				'Stealth Access: CAPTCHA verification skipped for provider "%s" — site key or secret key is missing. Login proceeded without a CAPTCHA challenge. Restore the keys in Settings → Stealth Access.',
				$this->provider()
			)
		);
	}

	/**
	 * Canonical CAPTCHA provider. Legacy slugs from every prior release are
	 * normalized to the two values the new UI exposes.
	 *
	 * @return string `cloudflare_turnstile` | `google_recaptcha`
	 */
	public function provider(): string {
		$raw = (string) $this->settings->get( 'captcha_provider', 'cloudflare_turnstile' );
		switch ( $raw ) {
			case 'google_recaptcha':       // 0.1.0
			case 'google_recaptcha_v2':    // 0.1.1+
			case 'google_recaptcha_v3':    // 0.1.1+
				return 'google_recaptcha';
			case 'none':
				return 'cloudflare_turnstile';
		}
		return $raw;
	}

	/**
	 * For Google reCAPTCHA, the operating mode. Defaults to `checkbox`.
	 * Legacy v2 / v3 slugs imply a mode even when `recaptcha_mode` is unset.
	 *
	 * @return string `checkbox` | `score`
	 */
	public function mode(): string {
		$raw = (string) $this->settings->get( 'captcha_provider', '' );
		if ( 'google_recaptcha_v2' === $raw ) {
			return 'checkbox';
		}
		if ( 'google_recaptcha_v3' === $raw ) {
			return 'score';
		}
		$stored = (string) $this->settings->get( 'recaptcha_mode', 'checkbox' );
		return in_array( $stored, array( 'checkbox', 'score' ), true ) ? $stored : 'checkbox';
	}

	/**
	 * Whether the selected provider has BOTH a site key and a secret key
	 * saved. CAPTCHA is treated as disabled when this returns false — no
	 * widget is rendered, no scripts are enqueued, no verification runs.
	 */
	public function is_configured(): bool {
		switch ( $this->provider() ) {
			case 'cloudflare_turnstile':
				return '' !== (string) $this->settings->get( 'turnstile_site_key', '' )
					&& '' !== (string) $this->settings->get( 'turnstile_secret_key', '' );
			case 'google_recaptcha':
				if ( 'score' === $this->mode() ) {
					return '' !== (string) $this->settings->get( 'recaptcha_v3_site_key', '' )
						&& '' !== (string) $this->settings->get( 'recaptcha_v3_secret_key', '' );
				}
				return '' !== (string) $this->settings->get( 'recaptcha_site_key', '' )
					&& '' !== (string) $this->settings->get( 'recaptcha_secret_key', '' );
		}
		return false;
	}

	public function location(): string {
		return (string) $this->settings->get( 'captcha_location', 'username_step' );
	}

	public function is_active_for( string $step ): bool {
		// CAPTCHA is only active when both keys are saved. Missing keys must
		// never block login. When this short-circuits because keys are
		// missing — AND the step's normal CAPTCHA location matches — we
		// emit a rate-limited error_log so the silent fail-open is
		// auditable post-hoc.
		if ( ! $this->is_configured() ) {
			$location = $this->location();
			if ( 'both' === $location || $location === $step ) {
				$this->log_skipped_once();
			}
			return false;
		}
		$location = $this->location();
		return 'both' === $location || $location === $step;
	}

	public function enqueue_scripts(): void {
		if ( ! $this->is_configured() ) {
			return;
		}
		$provider = $this->provider();
		if ( 'cloudflare_turnstile' === $provider ) {
			wp_enqueue_script( 'tssl-turnstile', self::TURNSTILE_API_URL, array(), null, true );
			return;
		}
		if ( 'google_recaptcha' === $provider ) {
			if ( 'score' === $this->mode() ) {
				$site_key = (string) $this->settings->get( 'recaptcha_v3_site_key', '' );
				if ( '' === $site_key ) {
					return;
				}
				wp_enqueue_script(
					'tssl-recaptcha-v3',
					add_query_arg( 'render', rawurlencode( $site_key ), self::RECAPTCHA_API_URL ),
					array(),
					null,
					true
				);
				wp_enqueue_script(
					'tssl-recaptcha-v3-handler',
					TSSL_PLUGIN_URL . 'assets/js/login-recaptcha-v3.js',
					array( 'tssl-recaptcha-v3' ),
					TSSL_VERSION,
					true
				);
				return;
			}
			wp_enqueue_script( 'tssl-recaptcha-v2', self::RECAPTCHA_API_URL, array(), null, true );
		}
	}

	/**
	 * @param string $expected_action Only used by v3, ignored otherwise.
	 */
	public function render_widget( string $expected_action = '' ): string {
		$provider = $this->provider();

		if ( 'cloudflare_turnstile' === $provider ) {
			$site_key = (string) $this->settings->get( 'turnstile_site_key', '' );
			if ( '' === $site_key ) {
				return $this->key_missing_notice();
			}
			return sprintf(
				'<div class="cf-turnstile tssl-captcha" data-sitekey="%s"></div>',
				esc_attr( $site_key )
			);
		}

		if ( 'google_recaptcha' === $provider ) {
			if ( 'score' === $this->mode() ) {
				$site_key = (string) $this->settings->get( 'recaptcha_v3_site_key', '' );
				if ( '' === $site_key ) {
					return $this->key_missing_notice();
				}
				$action = '' !== $expected_action ? $expected_action : 'tssl_login';
				return sprintf(
					'<div class="tssl-captcha tssl-recaptcha-v3" data-sitekey="%s" data-action="%s">'
					. '<input type="hidden" name="g-recaptcha-response" value="" />'
					. '<p class="tssl-captcha-note">%s</p>'
					. '</div>',
					esc_attr( $site_key ),
					esc_attr( $action ),
					esc_html__( 'Protected by Google reCAPTCHA.', 'stealth-access' )
				);
			}
			$site_key = (string) $this->settings->get( 'recaptcha_site_key', '' );
			if ( '' === $site_key ) {
				return $this->key_missing_notice();
			}
			return sprintf(
				'<div class="g-recaptcha tssl-captcha" data-sitekey="%s"></div>',
				esc_attr( $site_key )
			);
		}

		return '';
	}

	/**
	 * @return true|WP_Error
	 */
	public function verify( string $expected_action = '' ) {
		$provider = $this->provider();

		if ( 'cloudflare_turnstile' === $provider ) {
			return $this->verify_turnstile();
		}
		if ( 'google_recaptcha' === $provider ) {
			if ( 'score' === $this->mode() ) {
				return $this->verify_recaptcha_v3( $expected_action );
			}
			return $this->verify_recaptcha_v2();
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function verify_turnstile() {
		$secret = (string) $this->settings->get( 'turnstile_secret_key', '' );
		$token  = isset( $_POST['cf-turnstile-response'] )
			? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
			: '';
		if ( '' === $secret || '' === $token ) {
			return new WP_Error( 'tssl_captcha_missing', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}
		return $this->remote_verify( self::TURNSTILE_VERIFY_URL, $secret, $token );
	}

	/**
	 * @return true|WP_Error
	 */
	private function verify_recaptcha_v2() {
		$secret = (string) $this->settings->get( 'recaptcha_secret_key', '' );
		$token  = isset( $_POST['g-recaptcha-response'] )
			? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) )
			: '';
		if ( '' === $secret || '' === $token ) {
			return new WP_Error( 'tssl_captcha_missing', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}
		return $this->remote_verify( self::RECAPTCHA_VERIFY_URL, $secret, $token );
	}

	/**
	 * @return true|WP_Error
	 */
	private function verify_recaptcha_v3( string $expected_action ) {
		$secret = (string) $this->settings->get( 'recaptcha_v3_secret_key', '' );
		$token  = isset( $_POST['g-recaptcha-response'] )
			? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) )
			: '';
		if ( '' === $secret || '' === $token ) {
			return new WP_Error( 'tssl_captcha_missing', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		$response = wp_remote_post(
			self::RECAPTCHA_VERIFY_URL,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $this->security->get_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tssl_captcha_http', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'tssl_captcha_http_code', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new WP_Error( 'tssl_captcha_failed', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		if ( '' !== $expected_action ) {
			$returned_action = isset( $body['action'] ) ? (string) $body['action'] : '';
			if ( $returned_action !== $expected_action ) {
				return new WP_Error( 'tssl_captcha_action', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
			}
		}

		$score     = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
		$threshold = (float) $this->settings->get( 'recaptcha_v3_threshold', 0.5 );
		if ( $score < $threshold ) {
			return new WP_Error( 'tssl_captcha_score', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		if ( array_key_exists( 'hostname', $body ) ) {
			$hostname = isset( $body['hostname'] ) ? (string) $body['hostname'] : '';
			if ( '' === $hostname ) {
				return new WP_Error( 'tssl_captcha_hostname', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
			}
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	private function remote_verify( string $endpoint, string $secret, string $token ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $this->security->get_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tssl_captcha_http', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'tssl_captcha_http_code', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new WP_Error( 'tssl_captcha_failed', __( 'CAPTCHA validation failed.', 'stealth-access' ) );
		}

		return true;
	}

	private function key_missing_notice(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="tssl-error">' .
				esc_html__( 'Stealth Access: CAPTCHA is enabled but the provider keys are not configured. Configure them under Settings → Stealth Access.', 'stealth-access' ) .
				'</p>';
		}
		return '<p class="tssl-error">' .
			esc_html__( 'Login is temporarily unavailable. Please contact the site administrator.', 'stealth-access' ) .
			'</p>';
	}
}
