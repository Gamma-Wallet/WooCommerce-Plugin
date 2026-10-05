<?php
/**
 * "Use Store Credits with Gamma" in the block-based checkout (the default in new shops).
 * The classic checkout needs nothing extra: it lists every enabled gateway.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Gamma_Wallet_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = Gamma_Wallet_Credits_Gateway::ID;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Gamma_Wallet_Credits_Gateway::ID . '_settings', array() );
	}

	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ Gamma_Wallet_Credits_Gateway::ID ] ) && $gateways[ Gamma_Wallet_Credits_Gateway::ID ]->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'gamma-wallet-blocks',
			GAMMA_WALLET_URL . 'assets/js/blocks-credits.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			GAMMA_WALLET_VERSION,
			true
		);
		return array( 'gamma-wallet-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Use Store Credits with Gamma', 'gamma-wallet' ) ),
			'description' => $this->get_setting( 'description', '' ),
			'supports'    => array( 'products' ),
			'icon'        => GAMMA_WALLET_URL . 'assets/images/gamma-mark-64.png',
		);
	}
}
