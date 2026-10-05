<?php
/**
 * What the customer sees: the reward QR code, and the store-credit QR code with its countdown.
 *
 * The boxes carry the address of this shop's own status route (never Gamma's) and the order key;
 * assets/js/gamma-wallet.js asks it every 5 seconds.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

class Gamma_Wallet_Frontend {

	/** The Gamma Wallet wordmark at the top of each box. */
	private static function logo(): void {
		echo '<p class="gamma-wallet-logo"><img src="' . esc_url( GAMMA_WALLET_URL . 'assets/images/gamma-logo.png' ) . '" alt="' . esc_attr__( 'Gamma Wallet', 'gamma-wallet' ) . '" height="22"></p>';
	}

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
			return;
		}
		wp_enqueue_style( 'gamma-wallet', GAMMA_WALLET_URL . 'assets/css/gamma-wallet.css', array(), GAMMA_WALLET_VERSION );
		wp_enqueue_script( 'gamma-wallet', GAMMA_WALLET_URL . 'assets/js/gamma-wallet.js', array(), GAMMA_WALLET_VERSION, true );
		wp_localize_script(
			'gamma-wallet',
			'gammaWalletText',
			array(
				'secondsLeft' => __( '%d s left', 'gamma-wallet' ),
				/* translators: %s: minutes and seconds, such as 4:59 */
				'timeLeft'    => __( '%s left', 'gamma-wallet' ),
				'expired'     => __( 'This code has expired.', 'gamma-wallet' ),
				'newCode'     => __( 'Show a new code', 'gamma-wallet' ),
				'settled'     => __( 'Done! Your order is settled with your store credits.', 'gamma-wallet' ),
				'claimed'     => __( 'Reward collected. Thank you!', 'gamma-wallet' ),
				'unavailable' => __( 'Gamma cannot be reached right now. Please try again in a moment.', 'gamma-wallet' ),
			)
		);
	}

	private static function status_url( WC_Order $order ): string {
		return add_query_arg( 'key', $order->get_order_key(), rest_url( Gamma_Wallet_Rest::NS . '/orders/' . $order->get_id() . '/status' ) );
	}

	/** The reward QR code. $poll: keep asking until the reward is collected. */
	public static function reward_box( WC_Order $order, bool $poll ): void {
		$claimed = 'Claimed' === $order->get_meta( Gamma_Wallet_Rewards::META_STATUS );
		$link    = (string) $order->get_meta( Gamma_Wallet_Rewards::META_LINK );
		?>
		<section class="gamma-wallet-box" data-kind="reward" data-status-url="<?php echo esc_url( self::status_url( $order ) ); ?>" data-poll="<?php echo $poll && ! $claimed ? '1' : '0'; ?>">
			<div class="gw-card<?php echo $claimed ? ' gw-is-done' : ''; ?>">
				<div class="gw-qr-col">
					<div class="gamma-wallet-qr gw-qr-tile">
						<img src="<?php echo esc_url( (string) $order->get_meta( Gamma_Wallet_Rewards::META_QR_URL ) ); ?>" width="200" height="200" alt="<?php esc_attr_e( 'Reward QR code', 'gamma-wallet' ); ?>">
					</div>
					<div class="gw-check" aria-hidden="true">&#10003;</div>
				</div>
				<div class="gw-body">
					<?php self::logo(); ?>
					<h2 class="gamma-wallet-title"><?php esc_html_e( 'Collect your reward', 'gamma-wallet' ); ?></h2>
					<p class="gw-lead gw-when-waiting"><?php esc_html_e( 'This order earns you a reward. Add it to your Gamma Wallet in a few seconds.', 'gamma-wallet' ); ?></p>
					<ol class="gw-steps gw-when-waiting">
						<li><?php esc_html_e( 'Open the Gamma Wallet app', 'gamma-wallet' ); ?></li>
						<li><?php esc_html_e( 'Scan this code', 'gamma-wallet' ); ?></li>
						<li><?php esc_html_e( 'The reward is added to your wallet', 'gamma-wallet' ); ?></li>
					</ol>
					<a class="gamma-wallet-open gw-button gw-when-waiting" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'On your phone? Open in Gamma Wallet', 'gamma-wallet' ); ?></a>
					<p class="gamma-wallet-done gw-done"<?php echo $claimed ? '' : ' hidden'; ?>><?php esc_html_e( 'Reward collected. Thank you!', 'gamma-wallet' ); ?></p>
				</div>
			</div>
		</section>
		<?php
	}

	public static function reward_after_payment_note(): void {
		echo '<p class="gamma-wallet-note">' . esc_html__( 'This order earns a Gamma Wallet reward. Once your payment is received, we will email you a QR code to collect it.', 'gamma-wallet' ) . '</p>';
	}

	public static function reward_later_note(): void {
		echo '<p class="gamma-wallet-note">' . esc_html__( 'As soon as your payment is confirmed, you will receive a QR code by email to collect your reward with Gamma Wallet.', 'gamma-wallet' ) . '</p>';
	}

	/** The store-credit QR code, with the countdown, for an order not settled yet. */
	public static function credit_box( WC_Order $order ): void {
		$qr       = (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_QR );
		$link     = (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_LINK );
		$expires  = strtotime( (string) $order->get_meta( Gamma_Wallet_Credits_Gateway::META_EXPIRES_ON ) );
		$seconds  = $expires ? max( 0, $expires - time() ) : 0;
		$new_code = add_query_arg( 'key', $order->get_order_key(), rest_url( Gamma_Wallet_Rest::NS . '/orders/' . $order->get_id() . '/new-code' ) );
		?>
		<section class="gamma-wallet-box gamma-wallet-credit" data-kind="credit" data-status-url="<?php echo esc_url( self::status_url( $order ) ); ?>" data-new-code-url="<?php echo esc_url( $new_code ); ?>" data-seconds-left="<?php echo (int) $seconds; ?>" data-poll="1">
			<div class="gw-card">
				<div class="gw-qr-col">
					<div class="gamma-wallet-qr gw-qr-tile">
						<img class="gamma-wallet-qr-img" src="<?php echo esc_attr( $qr ? 'data:image/png;base64,' . $qr : '' ); ?>" width="220" height="220" alt="<?php esc_attr_e( 'Store-credit QR code', 'gamma-wallet' ); ?>"<?php echo $qr && $seconds > 0 ? '' : ' hidden'; ?>>
					</div>
					<div class="gw-timer" aria-hidden="true"><span class="gw-timer-bar"></span></div>
					<p class="gamma-wallet-countdown gw-countdown" aria-live="polite"></p>
					<div class="gw-check" aria-hidden="true">&#10003;</div>
				</div>
				<div class="gw-body">
					<?php self::logo(); ?>
					<h2 class="gamma-wallet-title"><?php esc_html_e( 'Use your store credits', 'gamma-wallet' ); ?></h2>
					<p class="gw-lead">
						<?php
						printf(
							/* translators: %s: order total */
							esc_html__( 'Settle the whole order (%s) with the store credits you hold at our shop.', 'gamma-wallet' ),
							'<strong>' . wp_kses_post( $order->get_formatted_order_total() ) . '</strong>'
						);
						?>
					</p>
					<ol class="gw-steps gw-when-waiting">
						<li><?php esc_html_e( 'Open the Gamma Wallet app', 'gamma-wallet' ); ?></li>
						<li><?php esc_html_e( 'Scan this code before the time runs out', 'gamma-wallet' ); ?></li>
						<li><?php esc_html_e( 'Confirm, and this page updates by itself', 'gamma-wallet' ); ?></li>
					</ol>
					<a class="gamma-wallet-open gw-button gw-when-waiting" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'On your phone? Open in Gamma Wallet', 'gamma-wallet' ); ?></a>
					<button type="button" class="gamma-wallet-new-code gw-button" hidden><?php esc_html_e( 'Show a new code', 'gamma-wallet' ); ?></button>
					<p class="gamma-wallet-done gw-done" hidden></p>
				</div>
			</div>
		</section>
		<?php
	}

	public static function credit_settled_note(): void {
		echo '<p class="gamma-wallet-note gamma-wallet-done">' . esc_html__( 'Your order is settled with your store credits through Gamma Wallet.', 'gamma-wallet' ) . '</p>';
	}
}
