<?php
/**
 * Plugin Name:       Stealth Access
 * Plugin URI:        https://tralync.com/stealth-access
 * Description:       Adds a secure two-step login flow, optional CAPTCHA protection, hidden login URLs, password reset controls, and custom login branding.
 * Version:           1.0.0
 * Author:            Tralync LLC
 * Author URI:        https://tralync.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stealth-access
 * Domain Path:       /languages
 * Requires PHP:      8.1
 * Requires at least: 6.8
 *
 * Stealth Access is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version.
 *
 * Stealth Access is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * The `tssl_settings` option key, the
 * `TSSL_` PHP prefixes, and the plugin folder slug are kept as-is during the
 * 0.x development series for backward compatibility with existing installs.
 * Future releases will migrate them.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

define( 'TSSL_VERSION', '1.0.0' );
define( 'TSSL_PLUGIN_FILE', __FILE__ );
define( 'TSSL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TSSL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-security.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-settings.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-captcha.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-page-manager.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-login-flow.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-login-hider.php';
require_once TSSL_PLUGIN_DIR . 'includes/class-tssl-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		$plugin = new TSSL_Plugin();
		$plugin->init();
	}
);

register_activation_hook( __FILE__, array( 'TSSL_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TSSL_Plugin', 'deactivate' ) );
