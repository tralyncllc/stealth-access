<?php
/**
 * Plugin bootstrap.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components together using lightweight dependency
 * injection. Each focused class can be tested or swapped independently.
 */
class TSSL_Plugin {

	/**
	 * @var TSSL_Settings
	 */
	private TSSL_Settings $settings;

	/**
	 * @var TSSL_Security
	 */
	private TSSL_Security $security;

	/**
	 * @var TSSL_Captcha
	 */
	private TSSL_Captcha $captcha;

	/**
	 * @var TSSL_Login_Flow
	 */
	private TSSL_Login_Flow $login_flow;

	/**
	 * @var TSSL_Login_Hider
	 */
	private TSSL_Login_Hider $login_hider;

	/**
	 * @var TSSL_Page_Manager
	 */
	private TSSL_Page_Manager $page_manager;

	/**
	 * Build the dependency graph.
	 */
	public function __construct() {
		$this->security     = new TSSL_Security();
		$this->settings     = new TSSL_Settings();
		$this->captcha      = new TSSL_Captcha( $this->settings, $this->security );
		$this->page_manager = new TSSL_Page_Manager( $this->settings );
		$this->login_flow   = new TSSL_Login_Flow( $this->settings, $this->security, $this->captcha );
		$this->login_hider  = new TSSL_Login_Hider( $this->settings );
	}

	/**
	 * Register hooks for all components.
	 */
	public function init(): void {
		load_plugin_textdomain(
			'stealth-access',
			false,
			dirname( plugin_basename( TSSL_PLUGIN_FILE ) ) . '/languages'
		);

		$this->settings->register();
		$this->page_manager->register();
		$this->login_flow->register();
		$this->login_hider->register();
	}

	/**
	 * Activation: seed defaults and create the login page if requested.
	 */
	public static function activate(): void {
		$settings = new TSSL_Settings();
		$settings->ensure_defaults();

		$page_manager = new TSSL_Page_Manager( $settings );
		$page_manager->maybe_create_login_page();
	}

	/**
	 * Deactivation: intentionally a no-op so settings survive a deactivate /
	 * reactivate cycle. Permanent removal happens in uninstall.php.
	 */
	public static function deactivate(): void {
		// No-op by design.
	}
}
