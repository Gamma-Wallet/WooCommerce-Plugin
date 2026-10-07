<?php
/**
 * The "Gamma Wallet" menu in the WordPress administration: the access token, the connection
 * check, and the reward settings.
 *
 * The token is stored in the options table (not autoloaded). The page never shows it again after
 * it is saved: only its first and last characters, as Gamma Business shows them.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

class Gamma_Wallet_Settings {

	const OPTION            = 'gamma_wallet_settings';
	const CONNECTION_OPTION = 'gamma_wallet_connection';
	const PAGE              = 'gamma-wallet';
	const TOKEN_PATTERN     = '/^GWINT_[A-Za-z0-9_-]{43}$/';

	/** The statuses a paid order is in. */
	const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * Ways of paying where no payment has been made when the order is placed: the customer pays
	 * later, outside the shop. These orders never earn a reward.
	 */
	const PAY_LATER_METHODS = array( 'cod', 'bacs', 'cheque' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_gamma_wallet_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_gamma_wallet_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'refresh_if_stale' ) );
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'check_connection' ) );
	}

	const REFRESH_HOOK = 'gamma_wallet_refresh_connection';

	/** True when the last connection check is older than an hour (or there is none). */
	private static function is_stale(): bool {
		$connection = self::connection();
		return ! $connection || (int) ( $connection['checkedOn'] ?? 0 ) < time() - HOUR_IN_SECONDS;
	}

	/**
	 * Re-checks the connection every hour while someone uses the administration, so the expiry
	 * reminder and the Reward-service check stay true.
	 */
	public static function refresh_if_stale(): void {
		if ( wp_doing_ajax() || '' === self::token() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::is_stale() ) {
			self::check_connection();
		}
	}

	/**
	 * True while the business's active Gamma service is a Reward service: the only kind whose
	 * rewards customers can collect. The plugin does nothing for customers otherwise.
	 *
	 * Uses the last check; when that is over an hour old, a fresh one is queued in the
	 * background, so a customer's checkout never waits for Gamma.
	 */
	public static function reward_service_active(): bool {
		if ( '' === self::token() ) {
			return false;
		}
		if ( self::is_stale() && function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::REFRESH_HOOK ) ) {
			as_enqueue_async_action( self::REFRESH_HOOK, array(), 'gamma-wallet' );
		}
		$connection = self::connection();
		return ! empty( $connection['canClaim'] );
	}

	// ------------------------------------------------------------------ reading the settings

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'token'           => '',
				'rewards_enabled' => 'yes',
				'no_reward_methods' => self::PAY_LATER_METHODS,
				'reward_email'    => 'yes',
			)
		);
	}

	public static function token(): string {
		return (string) self::all()['token'];
	}

	public static function rewards_enabled(): bool {
		return 'yes' === self::all()['rewards_enabled'] && '' !== self::token();
	}

	/**
	 * True when an order paid with this payment method earns a reward: the shop ticked it in the
	 * settings. Online methods are ticked by default; cash on delivery, bank transfer and cheque
	 * are not, and when ticked they earn the reward only once the order is marked Completed.
	 */
	public static function method_earns_reward( string $method ): bool {
		if ( '' === $method || Gamma_Wallet_Credits_Gateway::ID === $method ) {
			return false;
		}
		$excluded = self::all()['no_reward_methods'];
		return ! ( is_array( $excluded ) && in_array( $method, $excluded, true ) );
	}

	/** Paid outside the shop, after the order is placed: cash on delivery, bank transfer, cheque. */
	public static function is_pay_later( string $method ): bool {
		return in_array( $method, self::PAY_LATER_METHODS, true );
	}

	public static function reward_in_email(): bool {
		return 'yes' === self::all()['reward_email'];
	}

	/** What Gamma said the last time the connection was checked, or null when never checked. */
	public static function connection(): ?array {
		$connection = get_option( self::CONNECTION_OPTION );
		return is_array( $connection ) ? $connection : null;
	}

	/** The business's currency as Gamma knows it, or null when unknown. */
	public static function business_currency(): ?string {
		$connection = self::connection();
		return $connection['currencyCode'] ?? null;
	}

	/** True when the shop's currency is the business's, or when that cannot be known yet. */
	public static function currency_matches(): bool {
		$business = self::business_currency();
		return null === $business || strtoupper( $business ) === strtoupper( get_woocommerce_currency() );
	}

	// ------------------------------------------------------------------ the page

	public static function menu(): void {
		add_menu_page(
			__( 'Gamma Wallet', 'gamma-wallet-for-woocommerce' ),
			__( 'Gamma Wallet', 'gamma-wallet-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' ),
			GAMMA_WALLET_URL . 'assets/images/gamma-mark-20.png',
			56
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings   = self::all();
		$token      = (string) $settings['token'];
		$connection = self::connection();
		$gateway    = get_option( 'woocommerce_' . Gamma_Wallet_Credits_Gateway::ID . '_settings', array() );
		$credits_on = 'yes' === ( $gateway['enabled'] ?? 'no' );
		$message    = isset( $_GET['gamma-message'] ) ? sanitize_key( wp_unslash( $_GET['gamma-message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap gamma-wallet-admin">
			<h1 class="gamma-wallet-admin-title">
				<img src="<?php echo esc_url( GAMMA_WALLET_URL . 'assets/images/gamma-logo.png' ); ?>" alt="<?php esc_attr_e( 'Gamma Wallet', 'gamma-wallet-for-woocommerce' ); ?>" height="36" style="height:36px;width:auto;vertical-align:middle">
			</h1>
			<p><?php esc_html_e( 'Your customers earn a reward for every paid order and can settle an order with the store credits they hold at your shop, by scanning a QR code with the Gamma Wallet app.', 'gamma-wallet-for-woocommerce' ); ?></p>

			<?php self::render_message( $message ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gamma_wallet_save">
				<?php wp_nonce_field( 'gamma_wallet_save' ); ?>

				<h2><?php esc_html_e( 'Connection', 'gamma-wallet-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gamma-token"><?php esc_html_e( 'Integration token', 'gamma-wallet-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="gamma-token" name="gamma_token" class="regular-text" autocomplete="off" spellcheck="false"
								placeholder="<?php echo esc_attr( '' === $token ? 'GWINT_…' : self::masked( $token ) ); ?>">
							<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to Gamma Business */
										__( 'Create it in %s → Integrations, as the business owner. It starts with GWINT_ and is shown only once.', 'gamma-wallet-for-woocommerce' ),
										'<a href="https://business.gamma-wallet.com" target="_blank" rel="noopener noreferrer">Gamma Business</a>'
									),
									array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
								);
								?>
								<?php if ( '' !== $token ) : ?>
									<br><?php esc_html_e( 'A token is saved. Leave the field empty to keep it.', 'gamma-wallet-for-woocommerce' ); ?>
								<?php endif; ?>
							</p>
							<?php if ( '' !== $token ) : ?>
								<label><input type="checkbox" name="gamma_remove_token" value="1"> <?php esc_html_e( 'Remove the saved token (disconnects the shop)', 'gamma-wallet-for-woocommerce' ); ?></label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'gamma-wallet-for-woocommerce' ); ?></th>
						<td><?php self::render_connection( $token, $connection ); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Rewards for paid orders', 'gamma-wallet-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rewards', 'gamma-wallet-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="gamma_rewards_enabled" value="yes" <?php checked( 'yes', $settings['rewards_enabled'] ); ?>>
								<?php esc_html_e( 'Give customers a QR code to collect their reward for each paid order', 'gamma-wallet-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment methods that earn a reward', 'gamma-wallet-for-woocommerce' ); ?></th>
						<td>
							<?php self::render_methods(); ?>
							<p class="description"><?php esc_html_e( 'Paid at checkout (card and the like): the reward is given the moment the payment is confirmed, and its QR code is on the order confirmation page and in the order email.', 'gamma-wallet-for-woocommerce' ); ?></p>
							<p class="description"><?php esc_html_e( 'Paid later (cash on delivery, bank transfer, cheque): nothing is paid when the order is placed, so the reward is given only when you mark the order Completed. The customer then receives an email of its own with the QR code, also if they bought as a guest. You can send it again from the order screen.', 'gamma-wallet-for-woocommerce' ); ?></p>
							<p class="description"><?php esc_html_e( 'Orders settled with store credits never earn a reward.', 'gamma-wallet-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'gamma-wallet-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="gamma_reward_email" value="yes" <?php checked( 'yes', $settings['reward_email'] ); ?>>
								<?php esc_html_e( 'Put the reward QR code in the order email to the customer', 'gamma-wallet-for-woocommerce' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Store credits at checkout', 'gamma-wallet-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Use Store Credits with Gamma', 'gamma-wallet-for-woocommerce' ); ?></th>
						<td>
							<p><strong><?php echo $credits_on ? esc_html__( 'On', 'gamma-wallet-for-woocommerce' ) : esc_html__( 'Off', 'gamma-wallet-for-woocommerce' ); ?></strong> —
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gamma_Wallet_Credits_Gateway::ID ) ); ?>"><?php esc_html_e( 'Turn on or off in WooCommerce → Settings → Payments', 'gamma-wallet-for-woocommerce' ); ?></a></p>
							<p class="description"><?php esc_html_e( 'At checkout, the customer scans a QR code with Gamma Wallet and the whole order is settled from their store credits. The code is valid for 60 seconds. An order settled this way earns no reward.', 'gamma-wallet-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save and check the connection', 'gamma-wallet-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/** One checkbox per payment method the shop has turned on. */
	private static function render_methods(): void {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$shown    = 0;
		foreach ( $gateways as $id => $gateway ) {
			if ( Gamma_Wallet_Credits_Gateway::ID === $id || 'yes' !== $gateway->enabled ) {
				continue;
			}
			$pay_later = self::is_pay_later( $id );
			$title     = wp_strip_all_tags( $gateway->get_method_title() ? $gateway->get_method_title() : $gateway->get_title() );
			printf(
				'<label style="display:block;margin:0 0 .5em"><input type="checkbox" name="gamma_reward_methods[]" value="%1$s" %2$s> <strong>%3$s</strong> <span class="description">— %4$s</span></label>',
				esc_attr( $id ),
				checked( self::method_earns_reward( $id ), true, false ),
				esc_html( $title ),
				$pay_later
					? esc_html__( 'paid later: the reward is emailed when you mark the order Completed', 'gamma-wallet-for-woocommerce' )
					: esc_html__( 'paid at checkout: the reward is given when the payment is confirmed', 'gamma-wallet-for-woocommerce' )
			);
			++$shown;
		}
		if ( 0 === $shown ) {
			echo '<p>' . esc_html__( 'No payment method is turned on in WooCommerce yet.', 'gamma-wallet-for-woocommerce' ) . '</p>';
		}
	}

	private static function render_connection( string $token, ?array $connection ): void {
		if ( '' === $token ) {
			echo '<span style="color:#b32d2e">' . esc_html__( 'Not connected. Paste your integration token above.', 'gamma-wallet-for-woocommerce' ) . '</span>';
			return;
		}
		if ( ! $connection ) {
			echo esc_html__( 'Not checked yet.', 'gamma-wallet-for-woocommerce' );
		} elseif ( ! empty( $connection['error'] ) ) {
			echo '<span style="color:#b32d2e">' . esc_html( $connection['error'] ) . '</span>';
		} else {
			$days = (int) ( $connection['token']['daysLeft'] ?? 0 );
			echo '<span style="color:#00a32a">&#10003; ' . esc_html__( 'Connected', 'gamma-wallet-for-woocommerce' ) . '</span><br>';
			printf(
				/* translators: 1: business name, 2: currency code */
				esc_html__( 'Business: %1$s · Currency: %2$s', 'gamma-wallet-for-woocommerce' ),
				'<strong>' . esc_html( $connection['businessName'] ?? '' ) . '</strong>',
				'<strong>' . esc_html( $connection['currencyCode'] ?? '' ) . '</strong>'
			);
			echo '<br>';
			printf(
				/* translators: 1: token as shown in Gamma Business, 2: days left */
				esc_html__( 'Token %1$s, %2$d day(s) left', 'gamma-wallet-for-woocommerce' ),
				'<code>' . esc_html( self::masked( $token ) ) . '</code>',
				(int) $days
			);
			if ( empty( $connection['canClaim'] ) ) {
				echo '<br><span style="color:#b32d2e">' . esc_html( self::no_reward_service_text() ) . '</span>';
			}
			if ( ! self::currency_matches() ) {
				echo '<br><span style="color:#b32d2e">' . esc_html(
					sprintf(
						/* translators: 1: shop currency, 2: business currency */
						__( 'Your shop sells in %1$s but your Gamma business uses %2$s. Orders cannot be sent to Gamma until they match.', 'gamma-wallet-for-woocommerce' ),
						get_woocommerce_currency(),
						self::business_currency()
					)
				) . '</span>';
			}
			if ( ! empty( $connection['checkedOn'] ) ) {
				echo '<br><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: date and time */
						__( 'Checked %s', 'gamma-wallet-for-woocommerce' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $connection['checkedOn'] )
					)
				) . '</span>';
			}
		}
		echo ' <a class="button button-small" style="margin-left:.5em" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gamma_wallet_test' ), 'gamma_wallet_test' ) ) . '">' . esc_html__( 'Check again', 'gamma-wallet-for-woocommerce' ) . '</a>';
	}

	private static function render_message( string $message ): void {
		$messages = array(
			'saved'         => array( 'success', __( 'Settings saved.', 'gamma-wallet-for-woocommerce' ) ),
			'invalid-token' => array( 'error', __( 'That is not an integration token. It starts with GWINT_ and is 49 characters long. Nothing was changed.', 'gamma-wallet-for-woocommerce' ) ),
			'removed'       => array( 'success', __( 'The token was removed. The shop is no longer connected to Gamma.', 'gamma-wallet-for-woocommerce' ) ),
		);
		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $message ][0] ), esc_html( $messages[ $message ][1] ) );
		}
	}

	// ------------------------------------------------------------------ saving

	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'gamma-wallet-for-woocommerce' ) );
		}
		check_admin_referer( 'gamma_wallet_save' );

		$settings = self::all();
		$message  = 'saved';

		$new_token = isset( $_POST['gamma_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gamma_token'] ) ) ) : '';
		if ( ! empty( $_POST['gamma_remove_token'] ) ) {
			$settings['token'] = '';
			delete_option( self::CONNECTION_OPTION );
			$message = 'removed';
		} elseif ( '' !== $new_token ) {
			if ( ! preg_match( self::TOKEN_PATTERN, $new_token ) ) {
				wp_safe_redirect( add_query_arg( 'gamma-message', 'invalid-token', admin_url( 'admin.php?page=' . self::PAGE ) ) );
				exit;
			}
			$settings['token'] = $new_token;
		}

		$settings['rewards_enabled'] = empty( $_POST['gamma_rewards_enabled'] ) ? 'no' : 'yes';
		$settings['reward_email']    = empty( $_POST['gamma_reward_email'] ) ? 'no' : 'yes';
		// Every method the shop has turned on but left unticked earns no reward. A pay-later method
		// the shop has not turned on stays excluded, so it is off by default if turned on later.
		$ticked   = isset( $_POST['gamma_reward_methods'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['gamma_reward_methods'] ) ) : array();
		$enabled  = array();
		foreach ( ( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() ) as $id => $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$enabled[] = $id;
			}
		}
		$not_shown = array_diff( self::PAY_LATER_METHODS, $enabled );
		$settings['no_reward_methods'] = array_values( array_unique( array_merge( array_diff( $enabled, $ticked ), $not_shown ) ) );
		unset( $settings['pay_later_rewards'] );
		unset( $settings['reward_statuses'] );

		update_option( self::OPTION, $settings, false );
		if ( '' !== $settings['token'] ) {
			self::check_connection();
		}

		wp_safe_redirect( add_query_arg( 'gamma-message', $message, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'gamma-wallet-for-woocommerce' ) );
		}
		check_admin_referer( 'gamma_wallet_test' );
		self::check_connection();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** Asks Gamma who the token belongs to, and keeps the answer for the page and the currency check. */
	public static function check_connection(): ?array {
		$api = Gamma_Wallet_Api::from_settings();
		if ( ! $api ) {
			return null;
		}
		try {
			$connection              = $api->connection();
			$connection['checkedOn'] = time();
		} catch ( Gamma_Wallet_Api_Error $e ) {
			$connection = array(
				'error'     => self::explain( $e ),
				'checkedOn' => time(),
			);
			// Gamma briefly out of reach: keep what it said last time, so the shop keeps working.
			$previous = self::connection();
			if ( $e->is_retryable() && $previous && isset( $previous['canClaim'] ) ) {
				$connection['canClaim'] = $previous['canClaim'];
			}
		}
		update_option( self::CONNECTION_OPTION, $connection, false );
		return $connection;
	}

	/** A sentence the shop owner can act on. */
	public static function explain( Gamma_Wallet_Api_Error $e ): string {
		switch ( $e->identifier ) {
			case '0388':
				return __( 'Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.', 'gamma-wallet-for-woocommerce' );
			case '0389':
				return __( 'This token was disabled or replaced. Create a new one in Gamma Business → Integrations.', 'gamma-wallet-for-woocommerce' );
			case '0390':
				return __( 'This token has expired. Create a new one in Gamma Business → Integrations.', 'gamma-wallet-for-woocommerce' );
			case '0393':
				return __( 'The business this token belongs to is not available in Gamma.', 'gamma-wallet-for-woocommerce' );
		}
		if ( 0 === $e->status ) {
			return __( 'Gamma could not be reached. Check that this server can make outgoing HTTPS connections.', 'gamma-wallet-for-woocommerce' );
		}
		if ( 429 === $e->status ) {
			return __( 'Too many requests to Gamma. Try again in a minute.', 'gamma-wallet-for-woocommerce' );
		}
		return $e->getMessage();
	}

	/** What the shop owner reads when the active Gamma service is not a Reward service. */
	public static function no_reward_service_text(): string {
		return __( 'Gamma Wallet for WooCommerce works only with a Reward service. Your business has no Reward service active in Gamma, so customers get no reward QR code and store credits are not offered at checkout. Activate a Reward service in Gamma Business.', 'gamma-wallet-for-woocommerce' );
	}

	// ------------------------------------------------------------------ reminders

	public static function notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Only where it matters: the Dashboard, the Plugins page and WooCommerce's own screens.
		// The settings page shows the same state itself.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$wc_screens = function_exists( 'wc_get_screen_ids' ) ? wc_get_screen_ids() : array();
		if ( ! $screen || ! in_array( $screen->id, array_merge( array( 'dashboard', 'plugins' ), $wc_screens ), true ) ) {
			return;
		}
		$link       = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Gamma Wallet settings', 'gamma-wallet-for-woocommerce' ) . '</a>';
		$connection = self::connection();
		if ( '' !== self::token() && $connection && ! empty( $connection['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $connection['error'] ) . ' ' . wp_kses_post( $link ) . '</p></div>';
		} elseif ( '' !== self::token() && $connection && empty( $connection['canClaim'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::no_reward_service_text() ) . ' ' . wp_kses_post( $link ) . '</p></div>';
		} elseif ( $connection && isset( $connection['token']['daysLeft'] ) && (int) $connection['token']['daysLeft'] < 14 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %d: days left */
					__( 'Your Gamma integration token stops working in %d day(s). Create a new one in Gamma Business → Integrations and save it in the settings.', 'gamma-wallet-for-woocommerce' ),
					(int) $connection['token']['daysLeft']
				)
			) . ' ' . wp_kses_post( $link ) . '</p></div>';
		}
	}

	/** "GWINT_Ab12Cd3…x9Yz": enough to recognise a token, never enough to use it. */
	public static function masked( string $token ): string {
		return strlen( $token ) > 17 ? substr( $token, 0, 13 ) . '…' . substr( $token, -4 ) : '…';
	}
}
