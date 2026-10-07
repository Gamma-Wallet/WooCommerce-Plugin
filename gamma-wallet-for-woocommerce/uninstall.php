<?php
/**
 * Removes what the plugin stored when it is deleted from the Plugins screen: the settings (with
 * the integration token), the last connection check and any queued background jobs. Orders keep
 * their Gamma notes.
 *
 * @package GammaWallet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gamma_wallet_settings' );
delete_option( 'gamma_wallet_connection' );
delete_option( 'woocommerce_gamma_wallet_credits_settings' );
delete_option( 'gamma_wallet_installed_on' );

// Queued retries and connection checks (WooCommerce's Action Scheduler).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'gamma-wallet' );
}
