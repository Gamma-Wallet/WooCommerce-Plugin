<?php
/**
 * Flow 1: a reward for a paid order.
 *
 * The first time an order reaches a status the shop counts as paid, it is declared to Gamma
 * (Bill/Create). Gamma answers with a QR code, which the customer sees on the thank-you page, in
 * their order email and in My Account. They scan it with Gamma Wallet to collect the reward.
 *
 * An order settled with store credits is never declared: it earns no reward.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

class Gamma_Wallet_Rewards {

	const META_BILL_ID    = '_gamma_bill_id';
	const META_CODE       = '_gamma_bill_code';
	const META_LINK       = '_gamma_bill_link';
	const META_QR_URL     = '_gamma_bill_qr_url';
	const META_STATUS     = '_gamma_bill_status';
	const META_CLAIMED_ON = '_gamma_bill_claimed_on';
	const META_ERROR      = '_gamma_bill_error';
	const META_ATTEMPTS   = '_gamma_bill_attempts';
	const META_EMAILED    = '_gamma_reward_emailed';

	const RETRY_HOOK   = 'gamma_wallet_retry_bill';
	const MAX_ATTEMPTS = 5;

	public static function init(): void {
		// A payment provider confirms payment with payment_complete(), which records the date
		// paid and then moves the order on; the status-specific actions run before WooCommerce
		// sends that status's emails, so the QR code is ready when the email is built.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_status' ), 5 );
		foreach ( Gamma_Wallet_Settings::PAID_STATUSES as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_status' ), 5, 2 );
		}
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ) );

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thank_you' ), 5 );
		add_action( 'woocommerce_view_order', array( __CLASS__, 'view_order' ), 5 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'email' ), 25, 4 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );

		// "Send the Gamma reward QR to the customer" in the order screen's Order actions.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_gamma_wallet_send_reward', array( __CLASS__, 'send_reward_email' ) );
	}

	// ------------------------------------------------------------------ declaring the bill

	public static function on_status( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order && self::qualifies( $order ) && self::ensure_bill( $order ) ) {
			self::email_pay_later_once( $order );
		}
	}

	/**
	 * Cash on delivery and the like: the customer has just paid on delivery and the shop marked
	 * the order Completed. WooCommerce's order emails were written for an order not yet paid
	 * ("your order is on its way"), so the reward gets an email of its own, sent once.
	 */
	private static function email_pay_later_once( WC_Order $order ): void {
		if ( ! Gamma_Wallet_Settings::is_pay_later( (string) $order->get_payment_method() ) || $order->get_meta( self::META_EMAILED ) ) {
			return;
		}
		self::send_reward_email( $order );
	}

	public static function retry( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && self::qualifies( $order ) && self::ensure_bill( $order ) ) {
			self::email_pay_later_once( $order );
		}
	}

	/**
	 * True when this order should have a reward QR code: it was paid at checkout, with a payment
	 * method that earns rewards. Cash on delivery, bank transfer and cheque never qualify, nor
	 * does an order settled with store credits.
	 */
	public static function qualifies( WC_Order $order ): bool {
		$method = (string) $order->get_payment_method();
		if ( ! Gamma_Wallet_Settings::rewards_enabled() || (float) $order->get_total() <= 0 || ! Gamma_Wallet_Settings::reward_service_active() ) {
			return false;
		}
		// Paid later, outside the shop: only once the shop says the money is in, by completing it.
		if ( Gamma_Wallet_Settings::is_pay_later( $method ) ) {
			return Gamma_Wallet_Settings::method_earns_reward( $method ) && 'completed' === $order->get_status();
		}
		return Gamma_Wallet_Settings::method_earns_reward( $method )
			&& null !== $order->get_date_paid()
			&& in_array( $order->get_status(), Gamma_Wallet_Settings::PAID_STATUSES, true );
	}

	/** True when this order can ever earn a reward, now or once it is completed. */
	public static function may_earn( WC_Order $order ): bool {
		$method = (string) $order->get_payment_method();
		return Gamma_Wallet_Settings::rewards_enabled() && Gamma_Wallet_Settings::reward_service_active() && Gamma_Wallet_Settings::method_earns_reward( $method );
	}

	/**
	 * Declares the order to Gamma once. Returns true when the order has a bill afterwards.
	 * Safe to call any number of times: Gamma returns the same bill for the same order number.
	 */
	public static function ensure_bill( WC_Order $order ): bool {
		if ( $order->get_meta( self::META_BILL_ID ) ) {
			return true;
		}
		if ( ! self::qualifies( $order ) ) {
			return false;
		}
		if ( ! Gamma_Wallet_Settings::currency_matches() ) {
			self::fail( $order, __( 'Not sent to Gamma Wallet: the order currency is not the currency of your Gamma business.', 'gamma-wallet' ), false );
			return false;
		}
		$api = Gamma_Wallet_Api::from_settings();
		if ( ! $api ) {
			return false;
		}

		$paid_on = $order->get_date_paid() ?? $order->get_date_created();
		try {
			$bill = $api->create_bill(
				array(
					'reference'     => (string) $order->get_order_number(),
					'total'         => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
					'currencyCode'  => $order->get_currency(),
					'issuedOn'      => $paid_on ? $paid_on->format( DATE_ATOM ) : null,
					'platform'      => 'woocommerce',
					'pluginVersion' => GAMMA_WALLET_VERSION,
				)
			);
		} catch ( Gamma_Wallet_Api_Error $e ) {
			self::fail( $order, Gamma_Wallet_Settings::explain( $e ), $e->is_retryable() );
			return false;
		}

		$order->update_meta_data( self::META_BILL_ID, $bill['billId'] );
		$order->update_meta_data( self::META_CODE, $bill['code'] );
		$order->update_meta_data( self::META_LINK, $bill['link'] );
		$order->update_meta_data( self::META_QR_URL, $bill['qrImageUrl'] );
		$order->update_meta_data( self::META_STATUS, $bill['status'] );
		$order->delete_meta_data( self::META_ERROR );
		$order->add_order_note( __( 'Sent to Gamma Wallet. The customer can collect the reward for this order with its QR code.', 'gamma-wallet' ) );
		$order->save();
		if ( 'Claimed' === $bill['status'] ) {
			self::mark_claimed( $order, $bill['claimedOn'] ?? null );
		}
		return true;
	}

	private static function fail( WC_Order $order, string $reason, bool $retry ): void {
		$attempts = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $attempts );
		// One note per distinct reason, so a shop is not flooded.
		if ( $order->get_meta( self::META_ERROR ) !== $reason ) {
			$order->add_order_note( $reason );
			$order->update_meta_data( self::META_ERROR, $reason );
		}
		$order->save();
		Gamma_Wallet_Api::log( sprintf( 'Order %s was not sent to Gamma: %s', $order->get_order_number(), $reason ) );

		if ( $retry && $attempts < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS * $attempts, self::RETRY_HOOK, array( $order->get_id() ), 'gamma-wallet' );
		}
	}

	/**
	 * Asks Gamma whether the reward was collected, and records it once on the order.
	 * Returns "Waiting" or "Claimed".
	 */
	public static function refresh_status( WC_Order $order ): string {
		$status = (string) $order->get_meta( self::META_STATUS );
		if ( 'Claimed' === $status ) {
			return $status;
		}
		$api = Gamma_Wallet_Api::from_settings();
		if ( ! $api ) {
			return 'Waiting';
		}
		$bill = $api->get_bill( (string) $order->get_meta( self::META_BILL_ID ) );
		if ( 'Claimed' === $bill['status'] ) {
			self::mark_claimed( $order, $bill['claimedOn'] ?? null );
		}
		return $bill['status'];
	}

	private static function mark_claimed( WC_Order $order, ?string $claimed_on ): void {
		$order->update_meta_data( self::META_STATUS, 'Claimed' );
		$order->update_meta_data( self::META_CLAIMED_ON, $claimed_on );
		$order->add_order_note( __( 'The customer collected the reward for this order in Gamma Wallet.', 'gamma-wallet' ) );
		$order->save();
	}

	// ------------------------------------------------------------------ showing the QR code

	public static function thank_you( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::may_earn( $order ) ) {
			return;
		}
		if ( self::ensure_bill( $order ) ) {
			Gamma_Wallet_Frontend::reward_box( $order, true );
		} elseif ( Gamma_Wallet_Settings::is_pay_later( (string) $order->get_payment_method() ) ) {
			// Cash on delivery and the like: the QR code follows by email once the money is in.
			Gamma_Wallet_Frontend::reward_after_payment_note();
		} elseif ( null === $order->get_date_paid() && $order->needs_payment() ) {
			// A payment provider that confirms a little later (the customer is still being sent back).
			Gamma_Wallet_Frontend::reward_later_note();
		}
	}

	public static function view_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && $order->get_meta( self::META_BILL_ID ) ) {
			Gamma_Wallet_Frontend::reward_box( $order, 'Claimed' !== $order->get_meta( self::META_STATUS ) );
		}
	}

	/** The reward QR code in the customer's order emails. */
	public static function email( $order, $sent_to_admin, $plain_text, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! Gamma_Wallet_Settings::reward_in_email() ) {
			return;
		}
		// A pay-later order gets its own reward email instead (see email_pay_later_once).
		if ( Gamma_Wallet_Settings::is_pay_later( (string) $order->get_payment_method() ) ) {
			return;
		}
		if ( ! self::ensure_bill( $order ) || 'Claimed' === $order->get_meta( self::META_STATUS ) ) {
			return;
		}
		self::email_section( $order, (bool) $plain_text );
	}

	/** The QR code section, as it appears in an order email. */
	public static function email_section( WC_Order $order, bool $plain_text ): void {
		$link   = (string) $order->get_meta( self::META_LINK );
		$qr_url = (string) $order->get_meta( self::META_QR_URL );

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Collect your reward with Gamma Wallet', 'gamma-wallet' ) . "\n";
			echo esc_html__( 'Open this link on the phone where Gamma Wallet is installed:', 'gamma-wallet' ) . "\n" . esc_url_raw( $link ) . "\n\n";
			return;
		}
		?>
		<div style="margin:0 0 32px;padding:16px;border:1px solid #e5e5e5;border-radius:8px;text-align:center">
			<p style="margin:0 0 12px"><img src="<?php echo esc_url( GAMMA_WALLET_URL . 'assets/images/gamma-logo.png' ); ?>" alt="<?php esc_attr_e( 'Gamma Wallet', 'gamma-wallet' ); ?>" width="110" height="33" style="display:inline-block;width:110px;height:auto"></p>
			<h2 style="margin:0 0 8px"><?php esc_html_e( 'Collect your reward with Gamma Wallet', 'gamma-wallet' ); ?></h2>
			<p style="margin:0 0 12px"><?php esc_html_e( 'Scan this code with the Gamma Wallet app to add the reward for this order to your wallet.', 'gamma-wallet' ); ?></p>
			<p style="margin:0 0 12px"><img src="<?php echo esc_url( $qr_url ); ?>" width="200" height="200" alt="<?php esc_attr_e( 'Reward QR code', 'gamma-wallet' ); ?>" style="display:inline-block"></p>
			<p style="margin:0"><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'On your phone? Open it in Gamma Wallet', 'gamma-wallet' ); ?></a></p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------ sending it again

	public static function order_actions( $actions, $order = null ): array {
		$order = $order instanceof WC_Order ? $order : ( isset( $GLOBALS['theorder'] ) ? $GLOBALS['theorder'] : null );
		if ( $order instanceof WC_Order && $order->get_billing_email() && ( $order->get_meta( self::META_BILL_ID ) || self::qualifies( $order ) )
			&& 'Claimed' !== $order->get_meta( self::META_STATUS ) ) {
			$actions['gamma_wallet_send_reward'] = __( 'Send the Gamma reward QR code to the customer', 'gamma-wallet' );
		}
		return (array) $actions;
	}

	/**
	 * Emails the reward QR code to the address on the order. For a guest who lost the email, or a
	 * cash-on-delivery order where the shop wants to send it now.
	 */
	public static function send_reward_email( WC_Order $order ): void {
		if ( ! self::ensure_bill( $order ) ) {
			$order->add_order_note( __( 'The Gamma reward QR code could not be sent: the order is not eligible for a reward yet, or Gamma could not be reached.', 'gamma-wallet' ) );
			return;
		}
		$mailer  = WC()->mailer();
		$subject = sprintf(
			/* translators: 1: shop name, 2: order number */
			__( 'Your reward from %1$s (order %2$s)', 'gamma-wallet' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$order->get_order_number()
		);
		ob_start();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: customer first name */
				__( 'Hi %s,', 'gamma-wallet' ),
				$order->get_billing_first_name() ? $order->get_billing_first_name() : __( 'there', 'gamma-wallet' )
			)
		) . '</p>';
		echo '<p>' . esc_html__( 'Thank you, we have received your payment. Your order earned a reward: scan the code below with the Gamma Wallet app to add it to your wallet.', 'gamma-wallet' ) . '</p>';
		self::email_section( $order, false );
		$body = $mailer->wrap_message( __( 'Your reward is ready', 'gamma-wallet' ), ob_get_clean() );

		$sent = $mailer->send( $order->get_billing_email(), $subject, $body );
		if ( $sent ) {
			$order->update_meta_data( self::META_EMAILED, time() );
			$order->save();
		}
		$order->add_order_note(
			$sent
				? sprintf(
					/* translators: %s: email address */
					__( 'Gamma reward QR code sent to %s.', 'gamma-wallet' ),
					$order->get_billing_email()
				)
				: __( 'The Gamma reward QR code email could not be sent. Check that this site can send email.', 'gamma-wallet' )
		);
	}

	// ------------------------------------------------------------------ the order screen

	public static function meta_box(): void {
		$screen = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box( 'gamma-wallet', __( 'Gamma Wallet', 'gamma-wallet' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		if ( Gamma_Wallet_Credits_Gateway::ID === $order->get_payment_method() ) {
			$request = (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_REQUEST_ID );
			echo '<p>' . ( $order->is_paid()
				? esc_html__( 'Settled with store credits through Gamma Wallet.', 'gamma-wallet' )
				: esc_html__( 'Waiting for the customer to settle it with store credits.', 'gamma-wallet' ) ) . '</p>';
			if ( $request ) {
				echo '<p class="description">' . esc_html__( 'Request', 'gamma-wallet' ) . ': <code>' . esc_html( $request ) . '</code></p>';
			}
			echo '<p class="description">' . esc_html__( 'No reward is given for an order settled with store credits.', 'gamma-wallet' ) . '</p>';
			return;
		}
		$bill_id = (string) $order->get_meta( self::META_BILL_ID );
		if ( ! $bill_id ) {
			$error = (string) $order->get_meta( self::META_ERROR );
			if ( $error ) {
				echo '<p>' . esc_html( $error ) . '</p>';
			} elseif ( ! Gamma_Wallet_Settings::reward_service_active() ) {
				echo '<p>' . esc_html__( 'No reward: your business has no Reward service active in Gamma.', 'gamma-wallet' ) . '</p>';
			} elseif ( ! self::may_earn( $order ) ) {
				echo '<p>' . esc_html__( 'This order earns no reward (its payment method does not earn one).', 'gamma-wallet' ) . '</p>';
			} elseif ( Gamma_Wallet_Settings::is_pay_later( (string) $order->get_payment_method() ) ) {
				echo '<p>' . esc_html__( 'Paid later: when you mark the order Completed, the reward QR code is created and the customer receives it in an email of its own.', 'gamma-wallet' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Not sent to Gamma Wallet yet. It is sent when the payment is confirmed.', 'gamma-wallet' ) . '</p>';
			}
			return;
		}
		$claimed = 'Claimed' === $order->get_meta( self::META_STATUS );
		echo '<p><strong>' . ( $claimed ? esc_html__( 'Reward collected', 'gamma-wallet' ) : esc_html__( 'Waiting for the customer to collect the reward', 'gamma-wallet' ) ) . '</strong></p>';
		echo '<p><img src="' . esc_url( (string) $order->get_meta( self::META_QR_URL ) ) . '" width="120" height="120" alt=""></p>';
		echo '<p class="description">' . esc_html__( 'Bill', 'gamma-wallet' ) . ': <code>' . esc_html( $bill_id ) . '</code></p>';
	}
}
