<?php
/**
 * Uninstall handler.
 *
 * Removes the `tssl_settings` option and any leftover login-flow transients.
 * Does NOT remove the auto-created login page, user accounts, or any other
 * site content.
 *
 * @package SecureLoginShield
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tssl_settings' );

global $wpdb;
$prefix         = $wpdb->esc_like( '_transient_tssl_login_' );
$prefix_timeout = $wpdb->esc_like( '_transient_timeout_tssl_login_' );
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$prefix . '%',
		$prefix_timeout . '%'
	)
);
