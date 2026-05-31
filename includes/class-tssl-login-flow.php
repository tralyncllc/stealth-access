<?php
/**
 * The two-step login flow: shortcode rendering and POST processing.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Username/email is collected in Step 1, password in Step 2. Step 1 *always*
 * advances regardless of whether the account exists — username enumeration
 * is the central threat model here.
 */
class TSSL_Login_Flow {

	const SHORTCODE     = 'two_step_secure_login';
	const NONCE_STEP1   = 'tssl_login_step1';
	const NONCE_STEP2   = 'tssl_login_step2';
	const NONCE_RESTART = 'tssl_login_restart';

	private TSSL_Settings $settings;
	private TSSL_Security $security;
	private TSSL_Captcha $captcha;

	public function __construct( TSSL_Settings $settings, TSSL_Security $security, TSSL_Captcha $captcha ) {
		$this->settings = $settings;
		$this->security = $security;
		$this->captcha  = $captcha;
	}

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'handle_form' ), 5 );

		add_action( 'login_head', array( $this, 'maybe_hide_lost_password_css' ) );

		add_filter( 'allow_password_reset', array( $this, 'maybe_block_password_reset' ), 10, 2 );
		add_action( 'login_form_lostpassword', array( $this, 'maybe_block_lost_password_action' ) );
		add_action( 'login_form_retrievepassword', array( $this, 'maybe_block_lost_password_action' ) );
		add_action( 'login_form_rp', array( $this, 'maybe_block_lost_password_action' ) );
		add_action( 'login_form_resetpass', array( $this, 'maybe_block_lost_password_action' ) );
	}

	public function handle_form(): void {
		if ( empty( $_POST['tssl_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['tssl_action'] ) );

		switch ( $action ) {
			case 'step1':
				$this->process_step1();
				break;
			case 'step2':
				$this->process_step2();
				break;
			case 'restart':
				$this->process_restart();
				break;
		}
	}

	private function process_step1(): void {
		if (
			! isset( $_POST['_tssl_step1_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tssl_step1_nonce'] ) ), self::NONCE_STEP1 )
		) {
			$this->redirect_with_error( 1, __( 'Security check failed. Please try again.', 'stealth-access' ) );
			return;
		}

		if ( $this->captcha->is_active_for( 'username_step' ) ) {
			$result = $this->captcha->verify( TSSL_Captcha::ACTION_USERNAME_STEP );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_error( 1, __( 'CAPTCHA validation failed.', 'stealth-access' ) );
				return;
			}
		}

		$identifier = isset( $_POST['tssl_identifier'] )
			? sanitize_text_field( wp_unslash( $_POST['tssl_identifier'] ) )
			: '';

		if ( '' === $identifier || strlen( $identifier ) > 200 ) {
			$this->redirect_with_error( 1, __( 'Please enter your username or email.', 'stealth-access' ) );
			return;
		}

		$this->security->store_identifier( $identifier );

		$redirect_to = isset( $_POST['redirect_to'] )
			? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
			: '';

		$url = add_query_arg( 'tssl_step', '2', $this->current_url() );
		if ( '' !== $redirect_to ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private function process_step2(): void {
		if (
			! isset( $_POST['_tssl_step2_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tssl_step2_nonce'] ) ), self::NONCE_STEP2 )
		) {
			$this->redirect_with_error( 2, __( 'Security check failed. Please try again.', 'stealth-access' ) );
			return;
		}

		if ( $this->captcha->is_active_for( 'password_step' ) ) {
			$result = $this->captcha->verify( TSSL_Captcha::ACTION_PASSWORD_STEP );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_error( 2, __( 'CAPTCHA validation failed.', 'stealth-access' ) );
				return;
			}
		}

		$identifier = $this->security->get_stored_identifier();
		if ( null === $identifier ) {
			$this->security->clear_storage();
			wp_safe_redirect( $this->current_url() );
			exit;
		}

		$password = isset( $_POST['tssl_password'] ) ? (string) wp_unslash( $_POST['tssl_password'] ) : '';
		if ( '' === $password ) {
			$this->redirect_with_error( 2, __( 'Invalid login details. Please try again.', 'stealth-access' ) );
			return;
		}

		$creds = array(
			'user_login'    => $identifier,
			'user_password' => $password,
			'remember'      => false,
		);

		if ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
			if ( $user instanceof WP_User ) {
				$creds['user_login'] = $user->user_login;
			}
		}

		$user = wp_signon( $creds, is_ssl() );
		if ( is_wp_error( $user ) ) {
			$this->redirect_with_error( 2, __( 'Invalid login details. Please try again.', 'stealth-access' ) );
			return;
		}

		$this->security->clear_storage();

		$requested = '';
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$requested = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		}

		$target = $this->security->determine_safe_redirect( '' !== $requested ? $requested : null, $user );
		wp_safe_redirect( $target );
		exit;
	}

	private function process_restart(): void {
		if (
			! isset( $_POST['_tssl_restart_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tssl_restart_nonce'] ) ), self::NONCE_RESTART )
		) {
			$this->redirect_with_error( 2, __( 'Security check failed. Please try again.', 'stealth-access' ) );
			return;
		}
		$this->security->clear_storage();
		wp_safe_redirect( $this->current_url() );
		exit;
	}

	private function redirect_with_error( int $step, string $message ): void {
		$url = add_query_arg(
			array(
				'tssl_step'  => (string) $step,
				'tssl_error' => rawurlencode( $message ),
			),
			$this->current_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function current_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path    = (string) wp_parse_url( (string) $request, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = '/';
		}
		return home_url( $path );
	}

	/**
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts = array() ): string {
		unset( $atts );

		wp_enqueue_style( 'tssl-login', TSSL_PLUGIN_URL . 'assets/css/login.css', array(), TSSL_VERSION );
		$this->captcha->enqueue_scripts();

		if ( is_user_logged_in() ) {
			return $this->render_already_logged_in();
		}

		$step        = ( isset( $_GET['tssl_step'] ) && '2' === (string) $_GET['tssl_step'] ) ? 2 : 1;
		$error       = isset( $_GET['tssl_error'] ) ? sanitize_text_field( wp_unslash( $_GET['tssl_error'] ) ) : '';
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		if ( 2 === $step && null === $this->security->get_stored_identifier() ) {
			$step = 1;
		}

		$subtitle = ( 2 === $step )
			? __( 'Enter your password to continue.', 'stealth-access' )
			: __( 'Enter your username or email to continue.', 'stealth-access' );

		ob_start();
		?>
		<section class="tssl-card" aria-labelledby="tssl-portal-title">
			<header class="tssl-card-head">
				<?php $this->render_brand(); ?>
				<h1 id="tssl-portal-title" class="tssl-portal-title"><?php esc_html_e( 'Stealth Access', 'stealth-access' ); ?></h1>
				<p class="tssl-portal-subtitle"><?php echo esc_html( $subtitle ); ?></p>
			</header>

			<div class="tssl-card-body">
				<?php if ( '' !== $error ) : ?>
					<div class="tssl-message tssl-error" role="alert"><?php echo esc_html( rawurldecode( $error ) ); ?></div>
				<?php endif; ?>
				<?php
				if ( 2 === $step ) {
					$this->render_step2_form( $redirect_to );
				} else {
					$this->render_step1_form( $redirect_to );
				}
				?>
			</div>

			<footer class="tssl-card-footer">
				<?php $this->render_mini_shield(); ?>
				<span><?php esc_html_e( 'Protected by Stealth Access', 'stealth-access' ); ?></span>
			</footer>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private function render_already_logged_in(): string {
		$user = wp_get_current_user();
		ob_start();
		?>
		<section class="tssl-card" aria-labelledby="tssl-portal-title">
			<header class="tssl-card-head">
				<?php $this->render_brand(); ?>
				<h1 id="tssl-portal-title" class="tssl-portal-title"><?php esc_html_e( 'Stealth Access', 'stealth-access' ); ?></h1>
				<p class="tssl-portal-subtitle"><?php esc_html_e( 'You are already signed in.', 'stealth-access' ); ?></p>
			</header>
			<div class="tssl-card-body">
				<p class="tssl-already-in">
					<?php
					printf(
						/* translators: %s: display name */
						esc_html__( 'You are already logged in as %s.', 'stealth-access' ),
						esc_html( $user->display_name )
					);
					?>
				</p>
			</div>
			<footer class="tssl-card-footer">
				<?php $this->render_mini_shield(); ?>
				<span><?php esc_html_e( 'Protected by Stealth Access', 'stealth-access' ); ?></span>
			</footer>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Brand area at the top of the card. Shows the configured logo when
	 * branding is enabled and a logo is set; otherwise shows a default
	 * inline shield SVG in a soft-blue tile.
	 */
	private function render_brand(): void {
		$branding_on = (bool) $this->settings->get( 'enable_login_branding' );
		$logo_id     = absint( $this->settings->get( 'login_logo_id' ) );
		if ( $branding_on && $logo_id > 0 ) {
			$alt = (string) $this->settings->get( 'login_logo_alt' );
			if ( '' === $alt ) {
				$alt = (string) get_bloginfo( 'name' );
			}
			$max   = (string) $this->settings->get( 'login_logo_max_width' );
			$image = wp_get_attachment_image(
				$logo_id,
				'medium',
				false,
				array(
					'class' => 'tssl-brand-logo',
					'alt'   => esc_attr( $alt ),
					'style' => 'max-width:' . esc_attr( $max ) . ';height:auto;',
				)
			);
			if ( $image ) {
				echo '<div class="tssl-brand tssl-brand-custom">' . $image . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return;
			}
		}
		$icon_1x = TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon.png';
		$icon_2x = TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon@2x.png';
		?>
		<div class="tssl-brand tssl-brand-default">
			<img class="tssl-default-logo"
				src="<?php echo esc_url( $icon_1x ); ?>"
				srcset="<?php echo esc_url( $icon_1x ); ?> 1x, <?php echo esc_url( $icon_2x ); ?> 2x"
				width="72" height="72" alt="" />
		</div>
		<?php
	}

	/**
	 * Mini shield SVG used in the card footer next to "Protected by …".
	 */
	private function render_mini_shield(): void {
		?>
		<svg class="tssl-shield-mini" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
			<path d="M12 2.4 4.4 5.2v6.5c0 4.6 3 7.7 7.6 9.2 4.6-1.5 7.6-4.6 7.6-9.2V5.2L12 2.4z" fill="currentColor" fill-opacity="0.18" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
		</svg>
		<?php
	}

	private function render_step1_form( string $redirect_to ): void {
		$captcha_html = $this->captcha->is_active_for( 'username_step' )
			? $this->captcha->render_widget( TSSL_Captcha::ACTION_USERNAME_STEP )
			: '';
		?>
		<form class="tssl-login-form" method="post" action="">
			<input type="hidden" name="tssl_action" value="step1" />
			<?php wp_nonce_field( self::NONCE_STEP1, '_tssl_step1_nonce' ); ?>
			<?php if ( '' !== $redirect_to ) : ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
			<?php endif; ?>
			<div class="tssl-field">
				<label class="tssl-label" for="tssl-identifier"><?php esc_html_e( 'Username or email', 'stealth-access' ); ?></label>
				<input class="tssl-input" type="text" id="tssl-identifier" name="tssl_identifier" placeholder="<?php echo esc_attr__( 'Enter your username or email', 'stealth-access' ); ?>" autocomplete="username" required />
			</div>
			<?php echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button class="tssl-button" type="submit">
				<span><?php esc_html_e( 'Continue', 'stealth-access' ); ?></span>
				<svg class="tssl-arrow" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</button>
		</form>
		<?php
	}

	private function render_step2_form( string $redirect_to ): void {
		$captcha_html = $this->captcha->is_active_for( 'password_step' )
			? $this->captcha->render_widget( TSSL_Captcha::ACTION_PASSWORD_STEP )
			: '';
		?>
		<form class="tssl-login-form" method="post" action="">
			<input type="hidden" name="tssl_action" value="step2" />
			<?php wp_nonce_field( self::NONCE_STEP2, '_tssl_step2_nonce' ); ?>
			<?php if ( '' !== $redirect_to ) : ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
			<?php endif; ?>
			<div class="tssl-field">
				<label class="tssl-label" for="tssl-password"><?php esc_html_e( 'Password', 'stealth-access' ); ?></label>
				<input class="tssl-input" type="password" id="tssl-password" name="tssl_password" placeholder="<?php echo esc_attr__( 'Enter your password', 'stealth-access' ); ?>" autocomplete="current-password" required />
			</div>
			<?php echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button class="tssl-button" type="submit">
				<span><?php esc_html_e( 'Log in', 'stealth-access' ); ?></span>
				<svg class="tssl-arrow" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</button>
		</form>
		<form class="tssl-restart-form" method="post" action="">
			<input type="hidden" name="tssl_action" value="restart" />
			<?php wp_nonce_field( self::NONCE_RESTART, '_tssl_restart_nonce' ); ?>
			<button class="tssl-link" type="submit"><?php esc_html_e( 'Use a different account', 'stealth-access' ); ?></button>
		</form>
		<?php
	}

	public function maybe_hide_lost_password_css(): void {
		if ( ! $this->settings->get( 'hide_lost_password' ) ) {
			return;
		}
		echo '<style id="tssl-hide-lost-password">#login #nav, p#nav, #login_error + p#nav { display: none !important; visibility: hidden !important; }</style>';
	}

	/**
	 * @param bool $allow
	 * @param int  $user_id
	 * @return bool
	 */
	public function maybe_block_password_reset( $allow, $user_id ) {
		unset( $user_id );
		if ( $this->settings->get( 'disable_password_reset' ) ) {
			return false;
		}
		return (bool) $allow;
	}

	public function maybe_block_lost_password_action(): void {
		if ( ! $this->settings->get( 'disable_password_reset' ) ) {
			return;
		}
		$login_page_id = (int) $this->settings->get( 'login_page_id' );
		$target        = $login_page_id ? (string) get_permalink( $login_page_id ) : home_url( '/' );
		if ( ! $target ) {
			$target = home_url( '/' );
		}
		wp_safe_redirect( $target );
		exit;
	}
}
