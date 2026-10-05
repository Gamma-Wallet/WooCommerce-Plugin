<?php
/**
 * Removes what the plugin stored when it is deleted from the Plugins screen: the settings (with
 * the integration token) and the last connection check. Orders keep their Gamma notes.
 *
 * @package GammaWallet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gamma_wallet_settings' );
delete_option( 'gamma_wallet_connection' );
delete_option( 'woocommerce_gamma_wallet_credits_settings' );
