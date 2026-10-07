<?php
/**
 * Plugin Name:       Gamma Wallet for WooCommerce
 * Plugin URI:        https://github.com/Gamma-Wallet/WooCommerce-Plugin
 * Description:       Lets your customers earn a reward for every paid order, and settle an order with the store credits they hold at your shop, by scanning a QR code with Gamma Wallet.
 * Version:           1.0.5
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Gamma Wallet
 * Author URI:        https://www.gamma-wallet.com/en
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gamma-wallet-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

define( 'GAMMA_WALLET_VERSION', '1.0.5' );
define( 'GAMMA_WALLET_FILE', __FILE__ );
define( 'GAMMA_WALLET_DIR', plugin_dir_path( __FILE__ ) );
define( 'GAMMA_WALLET_URL', plugin_dir_url( __FILE__ ) );

/*
 * The Gamma Integration API. Can be overridden in wp-config.php for testing:
 *   define( 'GAMMA_WALLET_API_URL', 'https://…' );
 */
if ( ! defined( 'GAMMA_WALLET_API_URL' ) ) {
	define( 'GAMMA_WALLET_API_URL', 'https://integration.gamma-wallet.com' );
}

// No queued retries or connection checks are left behind when the plugin is turned off.
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'gamma-wallet' );
		}
	}
);

// Works with High-Performance Order Storage and with the Cart and Checkout blocks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
					if ( ! $screen || 'plugins' !== $screen->id ) {
						return;
					}
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Gamma Wallet for WooCommerce needs WooCommerce to be installed and active.', 'gamma-wallet-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}

		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-api.php';
		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-settings.php';
		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-rewards.php';
		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-credits-gateway.php';
		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-rest.php';
		require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-frontend.php';

		Gamma_Wallet_Settings::init();
		Gamma_Wallet_Rewards::init();
		Gamma_Wallet_Rest::init();
		Gamma_Wallet_Frontend::init();

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = Gamma_Wallet_Credits_Gateway::class;
				return $gateways;
			}
		);

		// The store-credit option in the block-based checkout.
		add_action(
			'woocommerce_blocks_loaded',
			static function () {
				if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
					require_once GAMMA_WALLET_DIR . 'includes/class-gamma-wallet-blocks-support.php';
					add_action(
						'woocommerce_blocks_payment_method_type_registration',
						static function ( $registry ) {
							$registry->register( new Gamma_Wallet_Blocks_Support() );
						}
					);
				}
			}
		);
	}
);

// The "G" mark at checkout is 64 px for sharp screens; shown at the size of the title.
add_filter(
	'woocommerce_gateway_icon',
	static function ( $icon, $id ) {
		if ( 'gamma_wallet_credits' !== $id ) {
			return $icon;
		}
		return '<img src="' . esc_url( GAMMA_WALLET_URL . 'assets/images/gamma-mark-64.png' ) . '" alt="" width="24" height="24" style="width:24px;height:24px;max-height:24px;vertical-align:middle;margin-left:.4em">';
	},
	10,
	2
);

// A link to the settings from the Plugins list.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=gamma-wallet' ) ) . '">' . esc_html__( 'Settings', 'gamma-wallet-for-woocommerce' ) . '</a>' );
		return $links;
	}
);
