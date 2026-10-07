<?php
/**
 * Flow 2: "Use Store Credits with Gamma" — the whole order settled with the customer's store
 * credits.
 *
 * It is a checkout option like a payment method, because it settles the whole order at once.
 * When the customer places the order, it stays pending and Gamma is asked for a store-credit
 * request (Credit/Start). The thank-you page shows its QR code with a 60-second countdown and asks
 * this shop's own server every 5 seconds whether it was settled (see Gamma_Wallet_Rest). When it
 * is, the order is marked paid. When the code expires, the customer can ask for a new one.
 *
 * Gamma handles no money: credits are value the customer earned at this shop.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

class Gamma_Wallet_Credits_Gateway extends WC_Payment_Gateway {

	const ID = 'gamma_wallet_credits';

	const META_REQUEST     = '_gamma_credit_request';
	const META_REQUEST_ID  = '_gamma_credit_request_id';
	const META_EXPIRES_ON  = '_gamma_credit_expires_on';
	const META_LINK        = '_gamma_credit_link';
	const META_QR          = '_gamma_credit_qr';
	const META_SETTLED     = '_gamma_credit_settled';

	/** The background check that settles orders paid after the customer left the page. */
	const RECONCILE_HOOK = 'gamma_wallet_reconcile_credits';

	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = GAMMA_WALLET_URL . 'assets/images/gamma-mark-64.png';
		$this->has_fields         = false;
		$this->method_title       = __( 'Use Store Credits with Gamma', 'gamma-wallet-for-woocommerce' );
		$this->method_description = __( 'Customers settle the whole order with the store credits they hold at your shop, by scanning a QR code with Gamma Wallet. The code is valid for 60 seconds. An order settled this way earns no reward.', 'gamma-wallet-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thank_you' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Turn on', 'gamma-wallet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Let customers settle orders with their Gamma store credits', 'gamma-wallet-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'gamma-wallet-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What the customer sees at checkout.', 'gamma-wallet-for-woocommerce' ),
				'default'     => __( 'Use Store Credits with Gamma', 'gamma-wallet-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'gamma-wallet-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'gamma-wallet-for-woocommerce' ),
				'default'     => __( 'After you place the order, scan the QR code with the Gamma Wallet app. The whole order is settled with the store credits you hold at our shop.', 'gamma-wallet-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Offered only when the shop is connected, the business has a Reward service active, the shop
	 * sells in the business's currency, and there is something to settle.
	 */
	public function is_available() {
		if ( ! parent::is_available() || ! Gamma_Wallet_Settings::reward_service_active() || ! Gamma_Wallet_Settings::currency_matches() ) {
			return false;
		}
		if ( WC()->cart && ! is_admin() && (float) WC()->cart->get_total( 'edit' ) <= 0 ) {
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		try {
			self::start_request( $order );
		} catch ( Gamma_Wallet_Api_Error $e ) {
			Gamma_Wallet_Api::log( sprintf( 'Store-credit request for order %s failed: %s', $order->get_order_number(), $e->getMessage() ) );
			wc_add_notice( __( 'Store credits cannot be used right now. Please choose another way to pay, or try again in a moment.', 'gamma-wallet-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Waiting for the customer to settle the order with Gamma store credits.', 'gamma-wallet-for-woocommerce' ) );
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Asks Gamma for a new store-credit request for the whole order, and keeps it on the order.
	 * Nothing is stored at Gamma until the customer settles it.
	 */
	public static function start_request( WC_Order $order ): array {
		$api = Gamma_Wallet_Api::from_settings();
		if ( ! $api ) {
			throw new Gamma_Wallet_Api_Error( 401, '0392', 'IntegrationTokenMissing' );
		}
		$request = $api->start_credit(
			array(
				'reference'    => Gamma_Wallet_Settings::reference( $order ),
				'total'        => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'currencyCode' => $order->get_currency(),
			)
		);
		$order->update_meta_data( self::META_REQUEST, $request['creditRequest'] );
		$order->update_meta_data( self::META_REQUEST_ID, $request['requestId'] );
		$order->update_meta_data( self::META_EXPIRES_ON, $request['expiresOn'] );
		$order->update_meta_data( self::META_LINK, $request['link'] );
		$order->update_meta_data( self::META_QR, $request['qrPngBase64'] ?? '' );
		$order->save();
		return $request;
	}

	/** Gamma's answer is about this order: same reference (or the one 1.0.5 sent), total and currency. */
	private static function matches( WC_Order $order, array $request ): bool {
		if ( isset( $request['reference'] ) && ! in_array( (string) $request['reference'], array( Gamma_Wallet_Settings::reference( $order ), (string) $order->get_order_number() ), true ) ) {
			return false;
		}
		if ( isset( $request['currencyCode'] ) && 0 !== strcasecmp( (string) $request['currencyCode'], (string) $order->get_currency() ) ) {
			return false;
		}
		return ! isset( $request['total'] ) || abs( (float) $request['total'] - (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) ) < 0.005;
	}

	/**
	 * Records a settled request once: the order is paid, and the QR code is no longer needed. Two
	 * requests settling the same order run one after the other, and the second one finds it done.
	 * An order that no longer waits for the credits (cancelled meanwhile, or changed by hand) keeps
	 * its status: WooCommerce would otherwise turn a cancelled order back into a paid one. A note
	 * tells the shop instead, because the customer has used their credits.
	 */
	public static function mark_settled( WC_Order $order, array $request ): void {
		$lock = 'settle_' . $order->get_id();
		if ( ! Gamma_Wallet_Settings::lock( $lock ) ) {
			return;
		}
		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order || $order->is_paid() || $order->get_meta( self::META_SETTLED ) ) {
				return;
			}
			$request_id = (string) ( $request['requestId'] ?? '' );
			if ( ! self::matches( $order, $request ) ) {
				Gamma_Wallet_Api::log( sprintf( 'The settled request %s does not match order %s (reference, total or currency); the order was not changed.', $request_id, $order->get_order_number() ), 'error' );
				return;
			}
			$order->update_meta_data( self::META_SETTLED, '' !== $request_id ? $request_id : 'settled' );
			$order->delete_meta_data( self::META_QR );
			if ( ! $order->has_status( 'pending' ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Gamma request id */
						__( 'Gamma Wallet: the customer settled this order with their store credits (request %s), but the order was no longer waiting for them, so its status was not changed. Check it.', 'gamma-wallet-for-woocommerce' ),
						$request_id
					)
				);
				$order->save();
				Gamma_Wallet_Api::log( sprintf( 'Order %s was settled with store credits after it stopped waiting (status %s); not changed.', $order->get_order_number(), $order->get_status() ), 'error' );
				return;
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: Gamma request id */
					__( 'Settled with the customer\'s store credits through Gamma Wallet (request %s).', 'gamma-wallet-for-woocommerce' ),
					$request_id
				)
			);
			$order->save();
			$order->payment_complete( $request_id );
		} finally {
			Gamma_Wallet_Settings::unlock( $lock );
		}
	}

	/** Asks Gamma about the order's current request and settles the order when it is paid. */
	public static function check( WC_Order $order ): array {
		$token = (string) $order->get_meta( self::META_REQUEST );
		$api   = Gamma_Wallet_Api::from_settings();
		if ( '' === $token || ! $api ) {
			return array( 'status' => 'Expired' );
		}
		$checked = $api->check_credit( $token );
		if ( 'Paid' === $checked['status'] ) {
			self::mark_settled( $order, $checked );
		}
		return $checked;
	}

	/**
	 * Every few minutes (Action Scheduler): store-credit orders of the last hours not settled yet are
	 * checked with Gamma. A customer who confirmed in the app and closed the page still gets their
	 * order; an order paid after WooCommerce cancelled it gets a note for the shop.
	 */
	public static function reconcile(): void {
		if ( '' === Gamma_Wallet_Settings::token() ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'payment_method' => self::ID,
				'status'         => array( 'pending', 'cancelled' ),
				'date_created'   => '>' . ( time() - 6 * HOUR_IN_SECONDS ),
				'limit'          => 50,
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order->get_meta( self::META_REQUEST ) || $order->get_meta( self::META_SETTLED ) ) {
				continue;
			}
			try {
				self::check( $order );
			} catch ( Gamma_Wallet_Api_Error $e ) {
				Gamma_Wallet_Api::log( sprintf( 'Checking the store credits of order %s failed: %s', $order->get_order_number(), $e->getMessage() ) );
			}
		}
	}

	/** The thank-you page of an order placed with this option: the QR code until it is settled. */
	public function thank_you( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->is_paid() ) {
			Gamma_Wallet_Frontend::credit_settled_note();
			return;
		}
		Gamma_Wallet_Frontend::credit_box( $order );
	}
}
