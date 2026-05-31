<?php
/**
 * Plugin settings: registration, defaults, sanitization, admin UI.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single `tssl_settings` option. All other components read settings
 * through this class so the option key and shape stay in one place.
 */
class TSSL_Settings {

	const OPTION_KEY     = 'tssl_settings';
	const OPTION_GROUP   = 'tssl_settings_group';
	const PAGE_SLUG      = 'tssl-settings';     // Settings submenu slug — preserved across the 0.x line.
	const DASHBOARD_SLUG = 'stealth-access';    // Top-level menu + Dashboard submenu slug.

	/**
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Hook the legacy redirect on admin_menu (not admin_init) because
		// wp-admin/includes/menu.php wp_die()s with "you are not allowed to
		// access this page" at line ~384 — BEFORE admin.php fires admin_init.
		// admin_menu fires earlier in that same file (line ~168), giving us a
		// chance to redirect before the access check kills the request.
		add_action( 'admin_menu', array( $this, 'maybe_redirect_legacy_settings_url' ), 1 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TSSL_PLUGIN_FILE ), array( $this, 'filter_plugin_action_links' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_defaults(): array {
		return array(
			'enable_two_step'            => 1,
			'disable_password_reset'     => 0,
			'hide_lost_password'         => 1,
			'hide_default_login_urls'    => 0,
			'custom_login_slug'          => 'secure-login',
			'auto_create_login_page'     => 1,
			'hide_login_page_from_lists' => 1,
			'login_page_id'              => 0,
			'captcha_provider'           => 'cloudflare_turnstile',
			'captcha_location'           => 'username_step',
			'recaptcha_mode'             => 'checkbox',
			'turnstile_site_key'         => '',
			'turnstile_secret_key'       => '',
			'recaptcha_site_key'         => '',
			'recaptcha_secret_key'       => '',
			'recaptcha_v3_site_key'      => '',
			'recaptcha_v3_secret_key'    => '',
			'recaptcha_v3_threshold'     => 0.5,
			'blocked_login_behavior'     => 'show_404',
			'blocked_login_custom_url'   => '',
			'enable_login_branding'      => 0,
			'login_logo_id'              => 0,
			'login_logo_max_width'       => '220px',
			'login_logo_alt'             => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = wp_parse_args( $stored, $this->get_defaults() );

		// Backward-compat normalization. The dropdown now exposes only
		// Cloudflare Turnstile and Google reCAPTCHA, with a separate Mode
		// selector for Google (Checkbox v2 / Score v3). Any stored value
		// from an older release is mapped to the new pair.
		// Note: an already-canonical `google_recaptcha` doesn't need mapping —
		// its `recaptcha_mode` is read from the merged value (or the default
		// `checkbox` that wp_parse_args supplied for 0.1.0 sites that never
		// stored a mode).
		if ( isset( $merged['captcha_provider'] ) ) {
			switch ( $merged['captcha_provider'] ) {
				case 'google_recaptcha_v2': // 0.1.1+ split into v2/v3
					$merged['captcha_provider'] = 'google_recaptcha';
					$merged['recaptcha_mode']   = 'checkbox';
					break;
				case 'google_recaptcha_v3':
					$merged['captcha_provider'] = 'google_recaptcha';
					$merged['recaptcha_mode']   = 'score';
					break;
				case 'none':
					$merged['captcha_provider'] = 'cloudflare_turnstile';
					break;
			}
		}
		if ( ! isset( $merged['recaptcha_mode'] ) || ! in_array( $merged['recaptcha_mode'], array( 'checkbox', 'score' ), true ) ) {
			$merged['recaptcha_mode'] = 'checkbox';
		}

		$this->cache = $merged;
		return $this->cache;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value if the key is unset.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function update( string $key, $value ): void {
		$all         = $this->get_all();
		$all[ $key ] = $value;
		update_option( self::OPTION_KEY, $all );
		$this->cache = $all;
	}

	public function ensure_defaults(): void {
		$existing = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $existing ) ) {
			update_option( self::OPTION_KEY, $this->get_defaults() );
		} else {
			update_option( self::OPTION_KEY, wp_parse_args( $existing, $this->get_defaults() ) );
		}
		$this->cache = null;
	}

	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->get_defaults(),
			)
		);
	}

	/**
	 * Register the top-level "Stealth Access" admin menu plus its two
	 * submenus (Dashboard + Settings). The Dashboard submenu intentionally
	 * shares the parent slug so it replaces WordPress's auto-generated
	 * duplicate entry — this is the standard WP convention.
	 */
	public function add_settings_page(): void {
		add_menu_page(
			__( 'Stealth Access', 'stealth-access' ),
			__( 'Stealth Access', 'stealth-access' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-shield',
			81
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Stealth Access Dashboard', 'stealth-access' ),
			__( 'Dashboard', 'stealth-access' ),
			'manage_options',
			self::DASHBOARD_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::DASHBOARD_SLUG,
			__( 'Stealth Access Settings', 'stealth-access' ),
			__( 'Settings', 'stealth-access' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Anyone hitting the old `Settings → Stealth Access` URL (the page used
	 * to be registered via add_options_page) gets a clean redirect to the
	 * new top-level entry. Preserves bookmarks and existing links.
	 */
	public function maybe_redirect_legacy_settings_url(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'options-general.php' !== $script ) {
			return;
		}
		$qs = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $qs['page'] );
		$target = add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $qs ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Prepend a "Settings" link to the plugin's row actions on the Plugins
	 * page, so it renders as "Settings | Deactivate".
	 *
	 * @param array<int,string>|string $links
	 * @return array<int,string>|string
	 */
	public function filter_plugin_action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'stealth-access' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ): void {
		$allowed = array(
			'toplevel_page_' . self::DASHBOARD_SLUG,                                  // Dashboard
			self::DASHBOARD_SLUG . '_page_' . self::DASHBOARD_SLUG,                   // Dashboard via submenu
			self::DASHBOARD_SLUG . '_page_' . self::PAGE_SLUG,                        // Settings (under the new top-level menu)
			'settings_page_' . self::PAGE_SLUG,                                       // Legacy hook (kept for safety)
		);
		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'tssl-admin', TSSL_PLUGIN_URL . 'assets/css/admin.css', array(), TSSL_VERSION );
		wp_enqueue_script( 'tssl-admin', TSSL_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TSSL_VERSION, true );
		wp_localize_script(
			'tssl-admin',
			'TSSLAdmin',
			array(
				'mediaTitle'  => __( 'Select Login Logo', 'stealth-access' ),
				'mediaButton' => __( 'Use this image', 'stealth-access' ),
			)
		);
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input Raw input from the form.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$defaults = $this->get_defaults();
		$clean    = array();

		$bool_keys = array(
			'enable_two_step',
			'disable_password_reset',
			'hide_lost_password',
			'hide_default_login_urls',
			'auto_create_login_page',
			'hide_login_page_from_lists',
			'enable_login_branding',
		);
		foreach ( $bool_keys as $k ) {
			$clean[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
		}

		// Slug.
		$slug = isset( $input['custom_login_slug'] ) ? sanitize_title( (string) $input['custom_login_slug'] ) : $defaults['custom_login_slug'];
		if ( '' === $slug ) {
			$slug = $defaults['custom_login_slug'];
		}
		$reserved = array( 'wp-admin', 'wp-login', 'wp-login-php', 'admin', 'wp', 'login' );
		if ( in_array( $slug, $reserved, true ) ) {
			$slug = $defaults['custom_login_slug'];
			add_settings_error(
				self::OPTION_KEY,
				'tssl_slug_reserved',
				__( 'That custom login slug is reserved and could conflict with WordPress. Reset to "secure-login".', 'stealth-access' )
			);
		}
		$clean['custom_login_slug'] = $slug;

		// CAPTCHA provider — only two are exposed in the UI. Legacy stored
		// values (none, google_recaptcha, google_recaptcha_v2/v3) are
		// migrated to the new pair without losing the user's stored keys
		// or threshold.
		$providers      = array( 'cloudflare_turnstile', 'google_recaptcha' );
		$provider       = isset( $input['captcha_provider'] ) ? (string) $input['captcha_provider'] : 'cloudflare_turnstile';
		$inferred_mode  = null;
		switch ( $provider ) {
			case 'google_recaptcha_v2':
				$provider      = 'google_recaptcha';
				$inferred_mode = 'checkbox';
				break;
			case 'google_recaptcha_v3':
				$provider      = 'google_recaptcha';
				$inferred_mode = 'score';
				break;
			case 'none':
				$provider = 'cloudflare_turnstile';
				break;
		}
		$clean['captcha_provider'] = in_array( $provider, $providers, true ) ? $provider : 'cloudflare_turnstile';

		// reCAPTCHA mode. Explicit form value wins; otherwise honor a
		// legacy-slug inference; otherwise default to checkbox.
		$modes                    = array( 'checkbox', 'score' );
		$mode                     = isset( $input['recaptcha_mode'] ) ? (string) $input['recaptcha_mode'] : ( $inferred_mode ?? 'checkbox' );
		$clean['recaptcha_mode']  = in_array( $mode, $modes, true ) ? $mode : 'checkbox';

		// CAPTCHA location.
		$locations                 = array( 'username_step', 'password_step', 'both' );
		$location                  = isset( $input['captcha_location'] ) ? (string) $input['captcha_location'] : 'username_step';
		$clean['captcha_location'] = in_array( $location, $locations, true ) ? $location : 'username_step';

		// Site keys: simple sanitize.
		foreach ( array( 'turnstile_site_key', 'recaptcha_site_key', 'recaptcha_v3_site_key' ) as $k ) {
			$clean[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( wp_unslash( $input[ $k ] ) ) : '';
		}

		// Secret keys: blank submission preserves the existing saved value so
		// we never render saved secrets back into the admin form (see the
		// render_secret_field() helper).
		$existing_for_secrets = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing_for_secrets ) ) {
			$existing_for_secrets = array();
		}
		foreach ( array( 'turnstile_secret_key', 'recaptcha_secret_key', 'recaptcha_v3_secret_key' ) as $k ) {
			$submitted = isset( $input[ $k ] ) ? sanitize_text_field( wp_unslash( $input[ $k ] ) ) : '';
			if ( '' === $submitted ) {
				$clean[ $k ] = isset( $existing_for_secrets[ $k ] ) ? (string) $existing_for_secrets[ $k ] : '';
			} else {
				$clean[ $k ] = $submitted;
			}
		}

		// reCAPTCHA v3 score threshold — float clamped to [0.0, 1.0].
		$threshold_raw = isset( $input['recaptcha_v3_threshold'] ) ? $input['recaptcha_v3_threshold'] : $defaults['recaptcha_v3_threshold'];
		if ( is_numeric( $threshold_raw ) ) {
			$threshold = (float) $threshold_raw;
			if ( $threshold < 0.0 ) {
				$threshold = 0.0;
			}
			if ( $threshold > 1.0 ) {
				$threshold = 1.0;
			}
			$clean['recaptcha_v3_threshold'] = $threshold;
		} else {
			$clean['recaptcha_v3_threshold'] = (float) $defaults['recaptcha_v3_threshold'];
		}

		// Blocked behavior.
		$behaviors                       = array( 'show_404', 'redirect_home', 'redirect_custom_url' );
		$behavior                        = isset( $input['blocked_login_behavior'] ) ? (string) $input['blocked_login_behavior'] : 'show_404';
		$clean['blocked_login_behavior'] = in_array( $behavior, $behaviors, true ) ? $behavior : 'show_404';

		$clean['blocked_login_custom_url'] = ! empty( $input['blocked_login_custom_url'] )
			? esc_url_raw( wp_unslash( $input['blocked_login_custom_url'] ) )
			: '';

		// Branding.
		$clean['login_logo_id']        = isset( $input['login_logo_id'] ) ? absint( $input['login_logo_id'] ) : 0;
		$clean['login_logo_max_width'] = isset( $input['login_logo_max_width'] )
			? $this->sanitize_css_size( (string) $input['login_logo_max_width'] )
			: $defaults['login_logo_max_width'];
		$clean['login_logo_alt']       = isset( $input['login_logo_alt'] )
			? sanitize_text_field( wp_unslash( $input['login_logo_alt'] ) )
			: '';

		// Preserve internal values that aren't in the form.
		$existing               = get_option( self::OPTION_KEY, array() );
		$clean['login_page_id'] = is_array( $existing ) && ! empty( $existing['login_page_id'] )
			? absint( $existing['login_page_id'] )
			: 0;

		$this->cache = null;
		return $clean;
	}

	/**
	 * @param string $value Raw input.
	 * @return string A safe CSS dimension or the default.
	 */
	private function sanitize_css_size( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '220px';
		}
		if ( preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|%)?$/i', $value ) ) {
			if ( preg_match( '/^\d+(?:\.\d+)?$/', $value ) ) {
				$value .= 'px';
			}
			return strtolower( $value );
		}
		return '220px';
	}


	/**
	 * Render the settings page.
	 */
	/**
	 * Render the Stealth Access → Dashboard page. Re-uses the existing
	 * status card so the dashboard and the settings page never drift apart.
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts        = $this->get_all();
		$login_url   = home_url( '/' . trim( (string) $opts['custom_login_slug'], '/' ) . '/' );
		$settings_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap tssl-wrap tssl-settings tssl-dashboard">
			<div class="tssl-plugin-header">
				<span class="tssl-plugin-icon" aria-hidden="true">
					<img class="tssl-plugin-mark"
						src="<?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon.png' ); ?>"
						srcset="<?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon.png' ); ?> 1x, <?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon@2x.png' ); ?> 2x"
						width="40" height="40" alt="" />
				</span>
				<div class="tssl-plugin-titles">
					<h1 class="tssl-plugin-title">
						<?php esc_html_e( 'Stealth Access', 'stealth-access' ); ?>
						<span class="tssl-version-badge">v<?php echo esc_html( TSSL_VERSION ); ?></span>
					</h1>
					<p class="tssl-plugin-subtitle"><?php esc_html_e( 'Login hardening for WordPress.', 'stealth-access' ); ?></p>
				</div>
			</div>

			<?php $this->render_summary_panel( $opts, $login_url ); ?>

			<p class="tssl-dashboard-cta">
				<?php
				printf(
					/* translators: %s: link to the settings page */
					esc_html__( 'Configure CAPTCHA, branding, recovery and more on the %s page.', 'stealth-access' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'stealth-access' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts      = $this->get_all();
		$logo_url  = $opts['login_logo_id'] ? (string) ( wp_get_attachment_image_url( (int) $opts['login_logo_id'], 'medium' ) ?: '' ) : '';
		$key       = self::OPTION_KEY;
		$login_url = home_url( '/' . trim( $opts['custom_login_slug'], '/' ) . '/' );
		?>
		<div class="wrap tssl-wrap tssl-settings">
			<?php
			// Top-level admin pages don't auto-render settings errors the way
			// options-general.php does, so emit them explicitly here.
			settings_errors( self::OPTION_KEY );
			?>
			<div class="tssl-plugin-header">
				<span class="tssl-plugin-icon" aria-hidden="true">
					<img class="tssl-plugin-mark"
						src="<?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon.png' ); ?>"
						srcset="<?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon.png' ); ?> 1x, <?php echo esc_url( TSSL_PLUGIN_URL . 'assets/img/stealth-access-icon@2x.png' ); ?> 2x"
						width="40" height="40" alt="" />
				</span>
				<div class="tssl-plugin-titles">
					<h1 class="tssl-plugin-title">
						<?php esc_html_e( 'Stealth Access', 'stealth-access' ); ?>
						<span class="tssl-version-badge">v<?php echo esc_html( TSSL_VERSION ); ?></span>
					</h1>
					<p class="tssl-plugin-subtitle"><?php esc_html_e( 'Login hardening for WordPress.', 'stealth-access' ); ?></p>
				</div>
			</div>

			<?php $this->render_summary_panel( $opts, $login_url ); ?>

			<form method="post" action="options.php" class="tssl-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<?php $this->render_card_general( $opts, $key ); ?>
				<?php $this->render_card_custom_login( $opts, $key, $login_url ); ?>
				<?php $this->render_card_captcha( $opts, $key ); ?>
				<?php $this->render_card_branding( $opts, $key, $logo_url ); ?>
				<?php $this->render_card_recovery( $opts, $key ); ?>

				<div class="tssl-submit-row">
					<?php submit_button( __( 'Save Changes', 'stealth-access' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Top status / summary panel — title + 4 status cells + large URL field
	 * + Copy / Open action buttons.
	 *
	 * @param array<string,mixed> $opts      Merged settings.
	 * @param string              $login_url Resolved custom login URL.
	 */
	private function render_summary_panel( array $opts, string $login_url ): void {
		$two_step_on    = ! empty( $opts['enable_two_step'] );
		$hide_urls_on   = ! empty( $opts['hide_default_login_urls'] );
		$reset_disabled = ! empty( $opts['disable_password_reset'] );
		$captcha_ready  = $this->captcha_is_configured( $opts );
		$captcha_label  = $captcha_ready
			? $this->provider_label( (string) $opts['captcha_provider'], (string) ( $opts['recaptcha_mode'] ?? 'checkbox' ) )
			: __( 'Not configured', 'stealth-access' );
		?>
		<section class="tssl-summary tssl-status-card" aria-labelledby="tssl-summary-title">
			<div class="tssl-status-titles">
				<h2 id="tssl-summary-title" class="tssl-status-title"><?php esc_html_e( 'Stealth Access Status', 'stealth-access' ); ?></h2>
				<p class="tssl-status-sub"><?php esc_html_e( 'Your login is protected and customized.', 'stealth-access' ); ?></p>
			</div>

			<div class="tssl-status-url-block">
				<label for="tssl-summary-login-url" class="tssl-status-url-heading"><?php esc_html_e( 'Login URL', 'stealth-access' ); ?></label>
				<div class="tssl-status-url-field">
					<input type="text" id="tssl-summary-login-url" class="tssl-status-url-input tssl-summary-url" value="<?php echo esc_attr( $login_url ); ?>" readonly />
					<button type="button" class="tssl-url-icon tssl-copy" data-tssl-copy-target="#tssl-summary-login-url" data-tssl-copy-label="" data-tssl-copied-label="<?php echo esc_attr__( 'Copied!', 'stealth-access' ); ?>" aria-label="<?php echo esc_attr__( 'Copy login URL', 'stealth-access' ); ?>">
						<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
					</button>
				</div>
			</div>

			<div class="tssl-status-actions">
				<button type="button" class="button button-primary tssl-copy" data-tssl-copy-target="#tssl-summary-login-url" data-tssl-copy-label="<?php echo esc_attr__( 'Copy URL', 'stealth-access' ); ?>" data-tssl-copied-label="<?php echo esc_attr__( 'Copied!', 'stealth-access' ); ?>"><?php esc_html_e( 'Copy URL', 'stealth-access' ); ?></button>
				<a class="button button-secondary tssl-status-open" href="<?php echo esc_url( $login_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Login Page', 'stealth-access' ); ?> &rarr;</a>
			</div>

			<hr class="tssl-status-divider" />

			<div class="tssl-status-grid">
				<div class="tssl-status-cell">
					<span class="tssl-status-cell-label"><?php esc_html_e( 'Two-step Login', 'stealth-access' ); ?></span>
					<?php echo $this->render_status_badge( $two_step_on ? 'on' : 'off', $two_step_on ? __( 'Enabled', 'stealth-access' ) : __( 'Disabled', 'stealth-access' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="tssl-status-cell">
					<span class="tssl-status-cell-label"><?php esc_html_e( 'Hidden Login URLs', 'stealth-access' ); ?></span>
					<?php echo $this->render_status_badge( $hide_urls_on ? 'on' : 'off', $hide_urls_on ? __( 'Enabled', 'stealth-access' ) : __( 'Disabled', 'stealth-access' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="tssl-status-cell">
					<span class="tssl-status-cell-label"><?php esc_html_e( 'CAPTCHA', 'stealth-access' ); ?></span>
					<a class="tssl-status-link" href="#tssl-card-captcha" aria-label="<?php echo esc_attr__( 'Jump to CAPTCHA settings', 'stealth-access' ); ?>"><?php echo $this->render_status_badge( $captcha_ready ? 'on' : 'off', $captcha_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				</div>
				<div class="tssl-status-cell">
					<span class="tssl-status-cell-label"><?php esc_html_e( 'Password Reset', 'stealth-access' ); ?></span>
					<?php echo $this->render_status_badge( $reset_disabled ? 'warn' : 'on', $reset_disabled ? __( 'Disabled', 'stealth-access' ) : __( 'Enabled', 'stealth-access' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a status badge with a state-driven color class.
	 *
	 * @param string $state One of: 'on' (green), 'off' (gray), 'warn' (yellow), 'risk' (red).
	 * @param string $label Visible label inside the badge.
	 */
	private function render_status_badge( string $state, string $label ): string {
		$class_map = array(
			'on'   => 'tssl-badge-on',
			'off'  => 'tssl-badge-off',
			'warn' => 'tssl-badge-warn',
			'risk' => 'tssl-badge-risk',
		);
		$class     = isset( $class_map[ $state ] ) ? $class_map[ $state ] : 'tssl-badge-off';
		return '<span class="tssl-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private function provider_label( string $slug, string $mode = 'checkbox' ): string {
		switch ( $slug ) {
			case 'cloudflare_turnstile':
				return __( 'Cloudflare Turnstile', 'stealth-access' );
			case 'google_recaptcha':
				return 'score' === $mode
					? __( 'Google reCAPTCHA (Score-Based)', 'stealth-access' )
					: __( 'Google reCAPTCHA (Checkbox)', 'stealth-access' );
			// Legacy slugs — still honored if status panel is called before
			// the option is re-saved.
			case 'google_recaptcha_v2':
				return __( 'Google reCAPTCHA (Checkbox)', 'stealth-access' );
			case 'google_recaptcha_v3':
				return __( 'Google reCAPTCHA (Score-Based)', 'stealth-access' );
			default:
				return __( 'None', 'stealth-access' );
		}
	}

	/**
	 * Open a two-column settings card. The left sidebar gets a colored accent
	 * (via the per-section class on the outer section), an icon, the section
	 * title and a short tagline; the right side holds the actual fields.
	 *
	 * @param string $id           DOM id (used for tests + aria-labelledby).
	 * @param string $accent_class Per-section accent class, e.g. tssl-card-general.
	 * @param string $icon         Dashicons class, e.g. 'dashicons-shield'.
	 * @param string $title        Section heading.
	 * @param string $tagline      Short paragraph under the heading.
	 */
	private function open_card( string $id, string $accent_class, string $icon, string $title, string $tagline ): void {
		printf(
			'<section id="%1$s" class="tssl-settings-card %2$s" aria-labelledby="%1$s-title"><aside class="tssl-card-sidebar"><span class="tssl-card-icon" aria-hidden="true"><span class="dashicons %3$s"></span></span><h3 id="%1$s-title" class="tssl-card-section-title">%4$s</h3><p class="tssl-card-section-desc">%5$s</p></aside><div class="tssl-card-content">',
			esc_attr( $id ),
			esc_attr( $accent_class ),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $tagline )
		);
	}

	private function close_card(): void {
		echo '</div></section>';
	}

	/**
	 * Whether the currently selected CAPTCHA provider has BOTH required
	 * keys saved. Mirrors TSSL_Captcha::is_configured().
	 *
	 * @param array<string,mixed> $opts Merged settings.
	 */
	private function captcha_is_configured( array $opts ): bool {
		switch ( (string) $opts['captcha_provider'] ) {
			case 'cloudflare_turnstile':
				return '' !== (string) $opts['turnstile_site_key'] && '' !== (string) $opts['turnstile_secret_key'];
			case 'google_recaptcha':
				if ( 'score' === (string) ( $opts['recaptcha_mode'] ?? 'checkbox' ) ) {
					return '' !== (string) $opts['recaptcha_v3_site_key'] && '' !== (string) $opts['recaptcha_v3_secret_key'];
				}
				return '' !== (string) $opts['recaptcha_site_key'] && '' !== (string) $opts['recaptcha_secret_key'];
		}
		return false;
	}

	/**
	 * Render a hover/focus info icon. The visible tooltip is built lazily by
	 * admin.js and appended to <body>, so it can't be clipped by parent
	 * containers (the cards use `overflow: hidden` for rounded corners).
	 * Screen readers still announce the text from `aria-label`.
	 *
	 * @param string $text Tooltip body.
	 */
	private function render_help_icon( string $text ): void {
		printf(
			'<span class="tssl-help" tabindex="0" role="img" aria-label="%1$s" data-tssl-tip="%1$s"><span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span>',
			esc_attr( $text )
		);
	}

	/**
	 * Secret-key input. Always rendered with `value=""` regardless of whether
	 * a secret is stored.
	 *
	 * @param string $id        DOM id.
	 * @param string $name_attr Form field name.
	 * @param bool   $has_saved Whether a non-empty secret is currently saved.
	 */
	/**
	 * Compute a masked fingerprint for a stored secret. Only the last 4
	 * characters survive verbatim; the rest are replaced one-for-one with the
	 * bullet character U+2022. Secrets of length ≤ 4 collapse to four bullets
	 * with no characters revealed.
	 *
	 * @param string $secret The full secret (handled server-side only).
	 */
	private function mask_secret( string $secret ): string {
		$bullet = "\xE2\x80\xA2"; // U+2022 BULLET
		$len    = function_exists( 'mb_strlen' ) ? mb_strlen( $secret, 'UTF-8' ) : strlen( $secret );
		if ( $len <= 4 ) {
			return str_repeat( $bullet, 4 );
		}
		$last_four = function_exists( 'mb_substr' )
			? mb_substr( $secret, -4, 4, 'UTF-8' )
			: substr( $secret, -4 );
		return str_repeat( $bullet, $len - 4 ) . $last_four;
	}

	/**
	 * Secret-key input + helper text. The `<input>` is always rendered with
	 * `value=""` so the full secret never appears in HTML, JS, or data
	 * attributes. When a secret is saved we show a masked fingerprint (last
	 * 4 characters) below the field as a usability hint.
	 *
	 * @param string $id            DOM id.
	 * @param string $name_attr     Form field name.
	 * @param string $stored_secret The currently saved secret. Used only to
	 *                              derive the masked fingerprint; never
	 *                              echoed verbatim.
	 */
	private function render_secret_field( string $id, string $name_attr, string $stored_secret ): void {
		$has_saved = '' !== $stored_secret;
		?>
		<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name_attr ); ?>" value="" class="regular-text tssl-secret-input" autocomplete="new-password" data-tssl-has-saved="<?php echo $has_saved ? '1' : '0'; ?>" />
		<p class="description tssl-secret-hint">
			<?php if ( $has_saved ) : ?>
				<?php
				$fingerprint = '<code class="tssl-secret-fingerprint">' . esc_html( $this->mask_secret( $stored_secret ) ) . '</code>';
				printf(
					/* translators: %s: masked fingerprint of the saved secret, last 4 characters shown */
					esc_html__( 'A secret key is saved: %s', 'stealth-access' ),
					$fingerprint // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — $fingerprint is constructed from esc_html()'d input wrapped in static markup.
				);
				?>
				<br />
				<?php esc_html_e( 'Leave blank to keep the existing key.', 'stealth-access' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Enter the secret key for this CAPTCHA provider.', 'stealth-access' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Row helpers
	 * --------------------------------------------------------------------*/

	/**
	 * Render a "full-width" row used by General Protection: a checkbox with
	 * its inline label on one line, optional help icon, description below.
	 *
	 * @param string $name        Form field name (without `tssl_settings[...]`).
	 * @param bool   $checked     Whether the input is currently checked.
	 * @param string $label       Inline checkbox label.
	 * @param string $help        Tooltip body.
	 * @param string $description Description shown below the label.
	 * @param string $extra_html  Raw HTML appended after the description (callouts).
	 */
	private function row_checkbox_flat( string $key, string $name, bool $checked, string $label, string $help, string $description = '', string $extra_html = '' ): void {
		?>
		<div class="tssl-setting-row tssl-row-fullwidth">
			<div class="tssl-setting-control">
				<label class="tssl-checkbox-row">
					<input type="checkbox" name="<?php echo esc_attr( $key . '[' . $name . ']' ); ?>" value="1" <?php checked( true, $checked ); ?> />
					<span class="tssl-checkbox-text"><?php echo esc_html( $label ); ?></span>
				</label>
				<?php $this->render_help_icon( $help ); ?>
				<?php if ( '' !== $description ) : ?>
					<p class="description tssl-row-desc"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<?php echo $extra_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller controls. ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Cards
	 * --------------------------------------------------------------------*/

	private function render_card_general( array $opts, string $key ): void {
		$this->open_card(
			'tssl-card-general',
			'tssl-card-general',
			'dashicons-shield',
			__( 'General Protection', 'stealth-access' ),
			__( 'Core toggles for the two-step flow and login protection.', 'stealth-access' )
		);

		$this->row_checkbox_flat(
			$key,
			'enable_two_step',
			(bool) $opts['enable_two_step'],
			__( 'Enable two-step login', 'stealth-access' ),
			__( 'Users enter username/email first, then password on a second screen. Step 1 always continues to protect against username enumeration.', 'stealth-access' ),
			__( 'Use the username-then-password flow on the custom login page. Step 1 always continues to Step 2.', 'stealth-access' )
		);

		$reset_warning = '<div class="tssl-callout tssl-callout-warning tssl-callout-warn"><strong>'
			. esc_html__( 'Lockout warning:', 'stealth-access' ) . '</strong> '
			. esc_html__( 'If you forget your password while this is on, you must reset it via WP-CLI or the database. Confirm you have a recovery path before enabling.', 'stealth-access' )
			. '</div>';

		$this->row_checkbox_flat(
			$key,
			'disable_password_reset',
			(bool) $opts['disable_password_reset'],
			__( 'Disable password reset', 'stealth-access' ),
			__( 'Blocks the "Lost your password?" link and every wp-login.php reset action. Make sure you have a non-form recovery path (WP-CLI, database, or hosting console) before enabling.', 'stealth-access' ),
			__( 'Block "Lost your password?" and all wp-login.php password-reset actions.', 'stealth-access' ),
			$reset_warning
		);

		$this->row_checkbox_flat(
			$key,
			'hide_lost_password',
			(bool) $opts['hide_lost_password'],
			__( 'Hide Lost Password link', 'stealth-access' ),
			__( 'CSS-hides the Lost Password link on the default /wp-login.php screen. Visual only — the reset flow keeps working unless Disable Password Reset is also on.', 'stealth-access' ),
			__( 'CSS-hide the link on the default WordPress login screen.', 'stealth-access' )
		);

		$hide_warning = '<div class="tssl-callout tssl-callout-warning tssl-callout-warn"><strong>'
			. esc_html__( 'Lockout warning:', 'stealth-access' ) . '</strong> '
			. esc_html__( 'Make sure your custom login slug works before enabling. If you misconfigure both this and the slug, the only recovery is to rename the plugin folder via SFTP/SSH.', 'stealth-access' )
			. '</div>';

		$this->row_checkbox_flat(
			$key,
			'hide_default_login_urls',
			(bool) $opts['hide_default_login_urls'],
			__( 'Hide default login URLs', 'stealth-access' ),
			__( 'Blocks direct access to wp-login.php and unauthenticated wp-admin requests. Keep filesystem access available in case recovery is needed.', 'stealth-access' ),
			__( 'Block direct access to /wp-login.php and bounce unauthenticated /wp-admin/ requests.', 'stealth-access' ),
			$hide_warning
		);

		$this->close_card();
	}

	private function render_card_custom_login( array $opts, string $key, string $login_url ): void {
		$this->open_card(
			'tssl-card-custom-login',
			'tssl-card-custom-login',
			'dashicons-desktop',
			__( 'Custom Login Page', 'stealth-access' ),
			__( 'Where the secure login lives and how it appears.', 'stealth-access' )
		);
		?>
		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<label for="tssl-custom-slug"><?php esc_html_e( 'Custom login slug', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'The URL path your login page lives at. Reserved slugs like wp-admin or wp-login are not allowed.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<input type="text" id="tssl-custom-slug" name="<?php echo esc_attr( $key ); ?>[custom_login_slug]" value="<?php echo esc_attr( $opts['custom_login_slug'] ); ?>" class="regular-text" />
				<p class="description">
					<?php
					printf(
						/* translators: %s: example URL */
						esc_html__( 'Resolves to: %s', 'stealth-access' ),
						'<code>' . esc_html( $login_url ) . '</code>'
					);
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Reserved slugs like "wp-admin" or "wp-login" are not allowed.', 'stealth-access' ); ?></p>
			</div>
		</div>

		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<span><?php esc_html_e( 'Automatically create login page', 'stealth-access' ); ?></span>
				<?php $this->render_help_icon( __( 'On activation, create a published page at the chosen slug that contains the two-step login shortcode. Existing pages with the same slug are adopted, never overwritten.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<label class="tssl-checkbox-row">
					<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[auto_create_login_page]" value="1" <?php checked( 1, (int) $opts['auto_create_login_page'] ); ?> />
					<span><?php esc_html_e( 'On activation, create a page at the chosen slug that contains the [two_step_secure_login] shortcode.', 'stealth-access' ); ?></span>
				</label>
				<?php
				if ( $opts['login_page_id'] ) :
					$page = get_post( (int) $opts['login_page_id'] );
					if ( $page instanceof WP_Post ) :
						?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: HTML link to login page */
								esc_html__( 'Tracked page: %s', 'stealth-access' ),
								'<a href="' . esc_url( (string) get_edit_post_link( $page->ID ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>'
							);
							?>
						</p>
						<?php
					endif;
				endif;
				?>
			</div>
		</div>

		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<span><?php esc_html_e( 'Hide login page from navigation', 'stealth-access' ); ?></span>
				<?php $this->render_help_icon( __( 'Excludes the login page from frontend page lists, classic nav menus, and the core Page List / Navigation blocks. The page stays published and reachable by URL.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<label class="tssl-checkbox-row">
					<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[hide_login_page_from_lists]" value="1" <?php checked( 1, (int) $opts['hide_login_page_from_lists'] ); ?> />
					<span><?php esc_html_e( 'Exclude this page from frontend page-lists, classic nav menus, and the core Page List / Navigation blocks.', 'stealth-access' ); ?></span>
				</label>
				<p class="description"><?php esc_html_e( 'The page stays published and reachable by URL. Admins still see it in wp-admin → Pages.', 'stealth-access' ); ?></p>
			</div>
		</div>
		<?php
		$this->close_card();
	}

	private function render_card_captcha( array $opts, string $key ): void {
		$this->open_card(
			'tssl-card-captcha',
			'tssl-card-captcha',
			'dashicons-feedback',
			__( 'CAPTCHA Protection', 'stealth-access' ),
			__( 'Optional CAPTCHA layer. Active after both the site key and the secret key are saved.', 'stealth-access' )
		);

		$provider          = (string) $opts['captcha_provider'];
		$mode              = (string) ( $opts['recaptcha_mode'] ?? 'checkbox' );
		$turnstile_active  = 'cloudflare_turnstile' === $provider;
		$google_active     = 'google_recaptcha' === $provider;
		$mode_checkbox     = 'checkbox' === $mode;
		$mode_score        = 'score' === $mode;
		$configured        = $this->captcha_is_configured( $opts );
		?>

		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<label for="tssl-captcha-provider"><?php esc_html_e( 'CAPTCHA provider', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'Choose the CAPTCHA service. CAPTCHA is only active after both site and secret keys are saved.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<select id="tssl-captcha-provider" name="<?php echo esc_attr( $key ); ?>[captcha_provider]" class="tssl-provider-select">
					<option value="cloudflare_turnstile" <?php selected( 'cloudflare_turnstile', $provider ); ?>><?php esc_html_e( 'Cloudflare Turnstile (Recommended)', 'stealth-access' ); ?></option>
					<option value="google_recaptcha" <?php selected( 'google_recaptcha', $provider ); ?>><?php esc_html_e( 'Google reCAPTCHA (Compatibility)', 'stealth-access' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Turnstile is recommended for most sites. Google reCAPTCHA is retained for sites that already have an existing integration.', 'stealth-access' ); ?></p>
				<?php if ( ! $configured ) : ?>
					<div class="tssl-callout tssl-callout-warning tssl-callout-warn tssl-captcha-status" id="tssl-captcha-status">
						<strong><?php esc_html_e( 'Selected, not configured.', 'stealth-access' ); ?></strong>
						<?php esc_html_e( 'CAPTCHA is disabled until both keys for this provider are saved.', 'stealth-access' ); ?>
					</div>
				<?php else : ?>
					<div class="tssl-callout tssl-callout-info tssl-captcha-status" id="tssl-captcha-status">
						<strong><?php esc_html_e( 'Configured.', 'stealth-access' ); ?></strong>
						<?php esc_html_e( 'CAPTCHA is active on the configured login step(s).', 'stealth-access' ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<label for="tssl-captcha-location"><?php esc_html_e( 'Where to show it', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'Which login step renders the CAPTCHA widget — Step 1 (username), Step 2 (password), or both.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<select id="tssl-captcha-location" name="<?php echo esc_attr( $key ); ?>[captcha_location]">
					<option value="username_step" <?php selected( 'username_step', $opts['captcha_location'] ); ?>><?php esc_html_e( 'Username step only', 'stealth-access' ); ?></option>
					<option value="password_step" <?php selected( 'password_step', $opts['captcha_location'] ); ?>><?php esc_html_e( 'Password step only', 'stealth-access' ); ?></option>
					<option value="both" <?php selected( 'both', $opts['captcha_location'] ); ?>><?php esc_html_e( 'Both steps', 'stealth-access' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Which login step renders the CAPTCHA widget.', 'stealth-access' ); ?></p>
			</div>
		</div>

		<section class="tssl-provider-panel tssl-provider-fields" data-provider="cloudflare_turnstile" <?php echo $turnstile_active ? '' : 'hidden style="display:none"'; ?>>
			<header class="tssl-provider-header">
				<h4 class="tssl-provider-name"><?php esc_html_e( 'Cloudflare Turnstile', 'stealth-access' ); ?></h4>
				<a class="tssl-provider-docs-link tssl-setup-link" href="https://developers.cloudflare.com/turnstile/get-started/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Setup Guide', 'stealth-access' ); ?> &rarr;</a>
			</header>
			<p class="tssl-provider-desc"><?php esc_html_e( 'Recommended for most sites. Free, privacy-friendly, and simple to configure.', 'stealth-access' ); ?></p>
			<div class="tssl-setting-row">
				<div class="tssl-setting-label">
					<label for="tssl-turnstile-site"><?php esc_html_e( 'Site key', 'stealth-access' ); ?></label>
					<?php $this->render_help_icon( __( 'Public site key from your Cloudflare Turnstile widget.', 'stealth-access' ) ); ?>
				</div>
				<div class="tssl-setting-control">
					<input type="text" id="tssl-turnstile-site" name="<?php echo esc_attr( $key ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $opts['turnstile_site_key'] ); ?>" class="regular-text" autocomplete="off" />
				</div>
			</div>
			<div class="tssl-setting-row">
				<div class="tssl-setting-label">
					<label for="tssl-turnstile-secret"><?php esc_html_e( 'Secret key', 'stealth-access' ); ?></label>
					<?php $this->render_help_icon( __( 'Server-side secret. Stored in the database and never rendered back into admin HTML.', 'stealth-access' ) ); ?>
				</div>
				<div class="tssl-setting-control">
					<?php $this->render_secret_field( 'tssl-turnstile-secret', $key . '[turnstile_secret_key]', (string) $opts['turnstile_secret_key'] ); ?>
				</div>
			</div>
		</section>

		<section class="tssl-provider-panel tssl-provider-fields" data-provider="google_recaptcha" <?php echo $google_active ? '' : 'hidden style="display:none"'; ?>>
			<header class="tssl-provider-header">
				<h4 class="tssl-provider-name"><?php esc_html_e( 'Google reCAPTCHA', 'stealth-access' ); ?></h4>
				<a class="tssl-provider-docs-link tssl-setup-link" href="https://cloud.google.com/recaptcha/docs" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Setup Guide', 'stealth-access' ); ?> &rarr;</a>
			</header>
			<p class="tssl-provider-desc"><?php esc_html_e( 'Provided for compatibility with existing Google reCAPTCHA deployments.', 'stealth-access' ); ?></p>
			<div class="tssl-callout tssl-callout-info">
				<strong><?php esc_html_e( 'Note:', 'stealth-access' ); ?></strong>
				<?php esc_html_e( 'Google has deprecated the legacy reCAPTCHA documentation in favor of reCAPTCHA Enterprise documentation. Existing integrations remain supported.', 'stealth-access' ); ?>
			</div>

			<div class="tssl-setting-row">
				<div class="tssl-setting-label">
					<label for="tssl-recaptcha-mode"><?php esc_html_e( 'Mode', 'stealth-access' ); ?></label>
					<?php $this->render_help_icon( __( 'Checkbox Challenge (v2) shows a visible widget. Score-Based (v3) runs invisibly and returns a 0.0–1.0 score that you compare against a threshold.', 'stealth-access' ) ); ?>
				</div>
				<div class="tssl-setting-control">
					<select id="tssl-recaptcha-mode" name="<?php echo esc_attr( $key ); ?>[recaptcha_mode]" class="tssl-mode-select">
						<option value="checkbox" <?php selected( 'checkbox', $mode ); ?>><?php esc_html_e( 'Checkbox Challenge (v2)', 'stealth-access' ); ?></option>
						<option value="score" <?php selected( 'score', $mode ); ?>><?php esc_html_e( 'Score-Based (v3)', 'stealth-access' ); ?></option>
					</select>
				</div>
			</div>

			<div class="tssl-mode-panel" data-mode="checkbox" <?php echo $mode_checkbox ? '' : 'hidden style="display:none"'; ?>>
				<div class="tssl-setting-row">
					<div class="tssl-setting-label">
						<label for="tssl-recaptcha-site"><?php esc_html_e( 'Site key', 'stealth-access' ); ?></label>
						<?php $this->render_help_icon( __( 'Public site key from your Google reCAPTCHA v2 admin console.', 'stealth-access' ) ); ?>
					</div>
					<div class="tssl-setting-control">
						<input type="text" id="tssl-recaptcha-site" name="<?php echo esc_attr( $key ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $opts['recaptcha_site_key'] ); ?>" class="regular-text" autocomplete="off" />
					</div>
				</div>
				<div class="tssl-setting-row">
					<div class="tssl-setting-label">
						<label for="tssl-recaptcha-secret"><?php esc_html_e( 'Secret key', 'stealth-access' ); ?></label>
						<?php $this->render_help_icon( __( 'Server-side secret. Stored in the database and never rendered back into admin HTML.', 'stealth-access' ) ); ?>
					</div>
					<div class="tssl-setting-control">
						<?php $this->render_secret_field( 'tssl-recaptcha-secret', $key . '[recaptcha_secret_key]', (string) $opts['recaptcha_secret_key'] ); ?>
					</div>
				</div>
			</div>

			<div class="tssl-mode-panel" data-mode="score" <?php echo $mode_score ? '' : 'hidden style="display:none"'; ?>>
				<div class="tssl-setting-row">
					<div class="tssl-setting-label">
						<label for="tssl-recaptcha-v3-site"><?php esc_html_e( 'Site key', 'stealth-access' ); ?></label>
						<?php $this->render_help_icon( __( 'Public site key from your Google reCAPTCHA v3 admin console.', 'stealth-access' ) ); ?>
					</div>
					<div class="tssl-setting-control">
						<input type="text" id="tssl-recaptcha-v3-site" name="<?php echo esc_attr( $key ); ?>[recaptcha_v3_site_key]" value="<?php echo esc_attr( $opts['recaptcha_v3_site_key'] ); ?>" class="regular-text" autocomplete="off" />
					</div>
				</div>
				<div class="tssl-setting-row">
					<div class="tssl-setting-label">
						<label for="tssl-recaptcha-v3-secret"><?php esc_html_e( 'Secret key', 'stealth-access' ); ?></label>
						<?php $this->render_help_icon( __( 'Server-side secret. Stored in the database and never rendered back into admin HTML.', 'stealth-access' ) ); ?>
					</div>
					<div class="tssl-setting-control">
						<?php $this->render_secret_field( 'tssl-recaptcha-v3-secret', $key . '[recaptcha_v3_secret_key]', (string) $opts['recaptcha_v3_secret_key'] ); ?>
					</div>
				</div>
				<div class="tssl-setting-row">
					<div class="tssl-setting-label">
						<label for="tssl-recaptcha-v3-threshold"><?php esc_html_e( 'Score threshold', 'stealth-access' ); ?></label>
						<?php $this->render_help_icon( __( 'Submissions with a score below this value are rejected. Google recommends starting at 0.5.', 'stealth-access' ) ); ?>
					</div>
					<div class="tssl-setting-control">
						<input type="number" id="tssl-recaptcha-v3-threshold" name="<?php echo esc_attr( $key ); ?>[recaptcha_v3_threshold]" value="<?php echo esc_attr( (string) $opts['recaptcha_v3_threshold'] ); ?>" min="0" max="1" step="0.1" class="small-text" />
						<p class="description"><?php esc_html_e( '0.0 lets everything through; 1.0 blocks almost everyone.', 'stealth-access' ); ?></p>
					</div>
				</div>
			</div>
		</section>
		<?php
		$this->close_card();
	}

	private function render_card_branding( array $opts, string $key, string $logo_url ): void {
		$this->open_card(
			'tssl-card-branding',
			'tssl-card-branding',
			'dashicons-format-image',
			__( 'Login Branding', 'stealth-access' ),
			__( 'Optional logo above the shortcode form.', 'stealth-access' )
		);
		$branding_on = ! empty( $opts['enable_login_branding'] );
		?>
		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<span><?php esc_html_e( 'Enable custom branding', 'stealth-access' ); ?></span>
				<?php $this->render_help_icon( __( 'Shows a logo above the shortcode-rendered login form. Pick the image from the Media Library; size and alt text are configurable below.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<label class="tssl-checkbox-row">
					<input type="checkbox" id="tssl-enable-branding" name="<?php echo esc_attr( $key ); ?>[enable_login_branding]" value="1" <?php checked( 1, (int) $opts['enable_login_branding'] ); ?> />
					<span><?php esc_html_e( 'Show a logo above the shortcode form.', 'stealth-access' ); ?></span>
				</label>
			</div>
		</div>

		<div class="tssl-setting-row tssl-conditional-branding" <?php echo $branding_on ? '' : 'hidden style="display:none"'; ?>>
			<div class="tssl-setting-label">
				<span><?php esc_html_e( 'Login logo', 'stealth-access' ); ?></span>
				<?php $this->render_help_icon( __( 'Pick an image from the Media Library to display above the login form.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<input type="hidden" id="tssl-login-logo-id" name="<?php echo esc_attr( $key ); ?>[login_logo_id]" value="<?php echo esc_attr( (string) $opts['login_logo_id'] ); ?>" />
				<div id="tssl-login-logo-preview" class="tssl-logo-preview">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" style="max-width:220px;height:auto;" alt="" />
					<?php endif; ?>
				</div>
				<p>
					<button type="button" class="button" id="tssl-upload-logo"><?php esc_html_e( 'Select / Upload Logo', 'stealth-access' ); ?></button>
					<button type="button" class="button" id="tssl-remove-logo" style="<?php echo $opts['login_logo_id'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'stealth-access' ); ?></button>
				</p>
			</div>
		</div>

		<div class="tssl-setting-row tssl-conditional-branding" <?php echo $branding_on ? '' : 'hidden style="display:none"'; ?>>
			<div class="tssl-setting-label">
				<label for="tssl-logo-max"><?php esc_html_e( 'Logo max width', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'Maximum displayed width of the logo. Accepts px, %, or rem.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<div class="tssl-input-with-unit">
					<input type="text" id="tssl-logo-max" name="<?php echo esc_attr( $key ); ?>[login_logo_max_width]" value="<?php echo esc_attr( (string) preg_replace( '/[a-z%]+$/i', '', (string) $opts['login_logo_max_width'] ) ); ?>" class="small-text" />
					<span class="tssl-input-unit">px</span>
				</div>
				<p class="description"><?php esc_html_e( 'Examples: 220px, 50%, 18rem. Bare numbers are treated as px.', 'stealth-access' ); ?></p>
			</div>
		</div>

		<div class="tssl-setting-row tssl-conditional-branding" <?php echo $branding_on ? '' : 'hidden style="display:none"'; ?>>
			<div class="tssl-setting-label">
				<label for="tssl-logo-alt"><?php esc_html_e( 'Logo alt text', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'Accessible alt text. Leave blank to use the site name.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<input type="text" id="tssl-logo-alt" name="<?php echo esc_attr( $key ); ?>[login_logo_alt]" value="<?php echo esc_attr( $opts['login_logo_alt'] ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Leave blank to use the site name.', 'stealth-access' ); ?></p>
			</div>
		</div>
		<?php
		$this->close_card();
	}

	private function render_card_recovery( array $opts, string $key ): void {
		$this->open_card(
			'tssl-card-recovery',
			'tssl-card-recovery',
			'dashicons-sos',
			__( 'Recovery & Safety', 'stealth-access' ),
			__( 'What happens when blocked requests occur.', 'stealth-access' )
		);
		$show_custom_url = 'redirect_custom_url' === (string) $opts['blocked_login_behavior'];
		?>
		<div class="tssl-setting-row">
			<div class="tssl-setting-label">
				<label for="tssl-blocked-behavior"><?php esc_html_e( 'When a request is blocked', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'What the plugin does when wp-login.php or unauthenticated wp-admin is hit while Hide Default Login URLs is on. Show 404 is recommended because it never discloses the new slug.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<select id="tssl-blocked-behavior" name="<?php echo esc_attr( $key ); ?>[blocked_login_behavior]">
					<option value="show_404" <?php selected( 'show_404', $opts['blocked_login_behavior'] ); ?>><?php esc_html_e( 'Show 404 (recommended — hides the custom slug)', 'stealth-access' ); ?></option>
					<option value="redirect_home" <?php selected( 'redirect_home', $opts['blocked_login_behavior'] ); ?>><?php esc_html_e( 'Redirect to home', 'stealth-access' ); ?></option>
					<option value="redirect_custom_url" <?php selected( 'redirect_custom_url', $opts['blocked_login_behavior'] ); ?>><?php esc_html_e( 'Redirect to custom URL', 'stealth-access' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'The plugin never reveals the custom login slug in a "login moved" message.', 'stealth-access' ); ?></p>
			</div>
		</div>

		<div class="tssl-setting-row tssl-conditional-redirect-custom" <?php echo $show_custom_url ? '' : 'hidden style="display:none"'; ?>>
			<div class="tssl-setting-label">
				<label for="tssl-blocked-url"><?php esc_html_e( 'Custom redirect URL', 'stealth-access' ); ?></label>
				<?php $this->render_help_icon( __( 'Used only when "Redirect to custom URL" is selected above.', 'stealth-access' ) ); ?>
			</div>
			<div class="tssl-setting-control">
				<input type="url" id="tssl-blocked-url" name="<?php echo esc_attr( $key ); ?>[blocked_login_custom_url]" value="<?php echo esc_attr( $opts['blocked_login_custom_url'] ); ?>" class="regular-text" placeholder="https://example.com/" />
				<p class="description"><?php esc_html_e( 'Used only when "Redirect to custom URL" is selected above.', 'stealth-access' ); ?></p>
			</div>
		</div>

		<div class="tssl-callout tssl-callout-info tssl-callout-recovery">
			<strong><?php esc_html_e( 'Locked out?', 'stealth-access' ); ?></strong>
			<?php esc_html_e( 'Rename the plugin folder via SFTP/SSH from "secure-login-shield" to "secure-login-shield-disabled" — WordPress will deactivate the plugin automatically and you can log in at /wp-login.php. Full recovery instructions are in README.md.', 'stealth-access' ); ?>
		</div>
		<?php
		$this->close_card();
	}
}
