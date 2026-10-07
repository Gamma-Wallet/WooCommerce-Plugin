<?php
/**
 * What the customer's browser asks this shop while a QR code is on screen. The browser never
 * talks to Gamma: it asks these routes, and the shop's server asks Gamma with its token.
 *
 *   GET  /wp-json/gamma-wallet/v1/orders/{id}/status?key={order key}
 *   POST /wp-json/gamma-wallet/v1/orders/{id}/new-code?key={order key}
 *
 * The order key is the one in the thank-you page's address, so only someone who saw that page
 * can ask about the order. Answers are kept for a few seconds, so several open tabs do not
 * multiply the calls to Gamma.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

class Gamma_Wallet_Rest {

	const NS        = 'gamma-wallet/v1';
	const CACHE_FOR = 4;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$args = array(
			'id'  => array(
				'type'     => 'integer',
				'required' => true,
			),
			'key' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'has_order_key' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/new-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'new_code' ),
				'permission_callback' => array( __CLASS__, 'has_order_key' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Allowed only with the order's key, as on WooCommerce's own order pages: the customer who
	 * placed the order, with or without an account.
	 */
	public static function has_order_key( WP_REST_Request $request ): bool {
		return null !== self::order( $request );
	}

	/** The order, only for someone who has its key. */
	private static function order( WP_REST_Request $request ): ?WC_Order {
		$order = wc_get_order( (int) $request['id'] );
		$key   = (string) $request['key'];
		return $order && '' !== $key && hash_equals( $order->get_order_key(), $key ) ? $order : null;
	}

	private static function reply( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function status( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order ) {
			return self::reply( array( 'error' => 'not_found' ), 404 );
		}

		$cache_key = 'gamma_wallet_status_' . $order->get_id();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return self::reply( $cached );
		}

		try {
			$body = Gamma_Wallet_Credits_Gateway::ID === $order->get_payment_method()
				? self::credit_status( $order )
				: self::reward_status( $order );
		} catch ( Gamma_Wallet_Api_Error $e ) {
			// A passing problem: the page keeps asking. The reason stays on the server.
			Gamma_Wallet_Api::log( sprintf( 'Status of order %s could not be checked: %s', $order->get_order_number(), $e->getMessage() ) );
			return self::reply( array( 'status' => 'Unknown' ), 503 );
		}
		set_transient( $cache_key, $body, self::CACHE_FOR );
		return self::reply( $body );
	}

	private static function reward_status( WC_Order $order ): array {
		if ( ! $order->get_meta( Gamma_Wallet_Rewards::META_BILL_ID ) ) {
			return array(
				'kind'   => 'reward',
				'status' => 'None',
			);
		}
		return array(
			'kind'   => 'reward',
			'status' => Gamma_Wallet_Rewards::refresh_status( $order ),
		);
	}

	private static function credit_status( WC_Order $order ): array {
		$done = array(
			'kind'     => 'credit',
			'status'   => 'Paid',
			'redirect' => $order->get_checkout_order_received_url(),
		);
		if ( $order->is_paid() ) {
			return $done;
		}
		$checked = Gamma_Wallet_Credits_Gateway::check( $order );
		if ( 'Paid' === $checked['status'] ) {
			delete_transient( 'gamma_wallet_status_' . $order->get_id() );
			return $done;
		}
		return array(
			'kind'        => 'credit',
			'status'      => $checked['status'],
			'secondsLeft' => (int) ( $checked['secondsLeft'] ?? 0 ),
		);
	}

	/** A new QR code for an order whose previous one expired unused. */
	public static function new_code( WP_REST_Request $request ): WP_REST_Response {
		// One request at a time per order: a second click or tab waits, then sees the first one's code.
		$lock = 'code_' . (int) $request['id'];
		if ( ! Gamma_Wallet_Settings::lock( $lock ) ) {
			return self::reply( array( 'error' => 'still_valid' ), 409 );
		}
		try {
			return self::new_code_locked( $request );
		} finally {
			Gamma_Wallet_Settings::unlock( $lock );
		}
	}

	private static function new_code_locked( WP_REST_Request $request ): WP_REST_Response {
		$order = self::order( $request );
		if ( ! $order || Gamma_Wallet_Credits_Gateway::ID !== $order->get_payment_method() ) {
			return self::reply( array( 'error' => 'not_found' ), 404 );
		}
		if ( $order->is_paid() ) {
			return self::reply(
				array(
					'status'   => 'Paid',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}
		if ( ! $order->needs_payment() ) {
			return self::reply( array( 'error' => 'not_payable' ), 409 );
		}

		// The customer may have settled the current code a moment ago, before this page asked
		// Gamma. Ask first, so a settled order is completed instead of getting a new code.
		if ( '' !== (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_REQUEST ) ) {
			try {
				$checked = Gamma_Wallet_Credits_Gateway::check( $order );
			} catch ( Gamma_Wallet_Api_Error $e ) {
				Gamma_Wallet_Api::log( sprintf( 'Checking the store-credit code of order %s failed: %s', $order->get_order_number(), $e->getMessage() ) );
				return self::reply( array( 'error' => 'unavailable' ), 503 );
			}
			if ( 'Paid' === $checked['status'] ) {
				delete_transient( 'gamma_wallet_status_' . $order->get_id() );
				return self::reply(
					array(
						'status'   => 'Paid',
						'redirect' => $order->get_checkout_order_received_url(),
					)
				);
			}
		}

		// Never two live codes for one order, or the customer could settle it twice. Gamma still
		// accepts a code a few seconds past its time (clock differences), so wait those out too.
		$expires = strtotime( (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_EXPIRES_ON ) );
		if ( $expires && time() < $expires + 15 ) {
			return self::reply( array( 'error' => 'still_valid' ), 409 );
		}

		try {
			$started = Gamma_Wallet_Credits_Gateway::start_request( $order );
		} catch ( Gamma_Wallet_Api_Error $e ) {
			Gamma_Wallet_Api::log( sprintf( 'New store-credit code for order %s failed: %s', $order->get_order_number(), $e->getMessage() ) );
			return self::reply( array( 'error' => 'unavailable' ), 503 );
		}
		delete_transient( 'gamma_wallet_status_' . $order->get_id() );
		return self::reply(
			array(
				'status'      => 'Waiting',
				'secondsLeft' => (int) $started['secondsLeft'],
				'link'        => $started['link'],
				'qr'          => 'data:image/png;base64,' . ( $started['qrPngBase64'] ?? '' ),
			)
		);
	}
}
