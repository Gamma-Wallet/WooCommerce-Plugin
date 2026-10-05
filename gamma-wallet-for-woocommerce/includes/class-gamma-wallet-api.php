<?php
/**
 * The only place that talks to the Gamma Integration API.
 *
 * Every call carries the shop's access token (GWINT_…), which never leaves the server: it is not
 * printed in any page, sent to any browser or written to any log.
 *
 * @package GammaWallet
 */

defined( 'ABSPATH' ) || exit;

/** An answer other than success. `identifier` is Gamma's stable error number. */
class Gamma_Wallet_Api_Error extends Exception {

	public int $status;
	public ?string $identifier;
	public ?string $error_name;

	public function __construct( int $status, ?string $identifier, ?string $error_name ) {
		$this->status     = $status;
		$this->identifier = $identifier;
		$this->error_name = $error_name;
		parent::__construct( sprintf( 'Gamma answered HTTP %d: %s%s', $status, $error_name ?? 'no details', $identifier ? " ($identifier)" : '' ) );
	}

	/** The token no longer works: the shop owner must create a new one. */
	public function is_token_problem(): bool {
		return 401 === $this->status;
	}

	/** Worth trying again later. */
	public function is_retryable(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}
}

class Gamma_Wallet_Api {

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	/** The client for the token saved in the settings, or null when none is saved. */
	public static function from_settings(): ?self {
		$token = Gamma_Wallet_Settings::token();
		return '' === $token ? null : new self( $token );
	}

	/** Which business the token belongs to, its currency, and how long the token has left. */
	public function connection(): array {
		return $this->send( 'GET', '/api/Connection/Me' );
	}

	/** Declares a paid order. Safe to repeat with the same reference. */
	public function create_bill( array $bill ): array {
		return $this->send( 'POST', '/api/Bill/Create', $bill );
	}

	public function get_bill( string $bill_id ): array {
		return $this->send( 'GET', '/api/Bill/Get/' . rawurlencode( $bill_id ) );
	}

	/** Starts a store-credit request for the whole order. Its QR code is valid for 60 seconds. */
	public function start_credit( array $order ): array {
		return $this->send( 'POST', '/api/Credit/Start', $order );
	}

	public function check_credit( string $credit_request ): array {
		return $this->send( 'POST', '/api/Credit/Check', array( 'creditRequest' => $credit_request ) );
	}

	private function send( string $method, string $path, ?array $body = null ): array {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
				'User-Agent'    => 'gamma-wallet-woocommerce/' . GAMMA_WALLET_VERSION . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body, JSON_PRESERVE_ZERO_FRACTION );
		}

		$response = wp_remote_request( rtrim( GAMMA_WALLET_API_URL, '/' ) . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new Gamma_Wallet_Api_Error( 0, null, 'Gamma could not be reached: ' . $response->get_error_message() );
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$envelope = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $envelope['result'] ?? null ) ) {
			return $envelope['result'];
		}
		$error = is_array( $envelope['error'] ?? null ) ? $envelope['error'] : array();
		throw new Gamma_Wallet_Api_Error( $status, $error['identifier'] ?? null, $error['message'] ?? null );
	}

	/** Writes to WooCommerce → Status → Logs (source "gamma-wallet"). Never pass the token. */
	public static function log( string $message, string $level = 'warning' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'gamma-wallet' ) );
		}
	}
}
