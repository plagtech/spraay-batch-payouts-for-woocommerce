<?php
/**
 * HTTP bridge to the Spraay REST gateway.
 *
 * All blockchain logic lives in the gateway; this class only shapes requests,
 * handles transport errors/retries and normalizes responses.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spraay gateway client.
 */
class Spraay_Gateway_Client {

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 20;

	/**
	 * Retries after the first attempt for retry-safe requests.
	 */
	const MAX_RETRIES = 2;

	/**
	 * Gateway base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Optional API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Plugin settings; defaults to saved settings.
	 */
	public function __construct( $settings = null ) {
		$settings       = is_array( $settings ) ? $settings : Spraay_Plugin::get_settings();
		$this->base_url = untrailingslashit( esc_url_raw( $settings['gateway_url'] ) );
		$this->api_key  = (string) $settings['api_key'];
	}

	/**
	 * Ask the gateway for a fee estimate (and the transaction to sign).
	 *
	 * Request body sent to POST /api/v1/batch/estimate:
	 *   chainId     int     Chain ID (8453 for Base).
	 *   token       string  Token symbol, "USDC".
	 *   sender      string  Merchant wallet, when already connected.
	 *   reference   string  Site-unique batch reference for idempotency.
	 *   recipients  array   [ { address, amount } ] with decimal-string amounts.
	 *
	 * @param array $payload Body as described above.
	 * @return array|WP_Error Normalized estimate, see normalize_estimate().
	 */
	public function estimate( array $payload ) {
		/**
		 * Filter the estimate request body before it is sent.
		 *
		 * @param array $payload Request body.
		 */
		$payload  = apply_filters( 'spraay_gateway_estimate_payload', $payload );
		$response = $this->request( 'POST', '/api/v1/batch/estimate', $payload, true );

		return is_wp_error( $response ) ? $response : $this->normalize_estimate( $response );
	}

	/**
	 * Submit a wallet-signed batch for on-chain execution.
	 *
	 * Request body sent to POST /api/v1/batch/execute:
	 *   batchId    string  Gateway batch ID from the estimate.
	 *   chainId    int     Chain ID.
	 *   sender     string  Merchant wallet that signed.
	 *   txHash     string  Hash of the transaction the wallet broadcast, or
	 *   signature  string  Signature over the gateway's typed data (relayed mode).
	 *
	 * Not retried automatically: a timed-out execute may still have been
	 * accepted, so the caller reconciles via status() instead of resubmitting.
	 *
	 * @param array $payload Body as described above.
	 * @return array|WP_Error Normalized status, see normalize_status().
	 */
	public function execute( array $payload ) {
		/**
		 * Filter the execute request body before it is sent.
		 *
		 * @param array $payload Request body.
		 */
		$payload  = apply_filters( 'spraay_gateway_execute_payload', $payload );
		$response = $this->request( 'POST', '/api/v1/batch/execute', $payload, false );

		return is_wp_error( $response ) ? $response : $this->normalize_status( $response );
	}

	/**
	 * Poll a batch's on-chain status.
	 *
	 * @param string $gateway_batch_id Gateway batch ID.
	 * @return array|WP_Error Normalized status, see normalize_status().
	 */
	public function status( $gateway_batch_id ) {
		$response = $this->request( 'GET', '/api/v1/batch/' . rawurlencode( (string) $gateway_batch_id ) . '/status', null, true );

		return is_wp_error( $response ) ? $response : $this->normalize_status( $response );
	}

	/**
	 * Perform a JSON request.
	 *
	 * @param string     $method     GET or POST.
	 * @param string     $path       Path beginning with /.
	 * @param array|null $body       JSON body for POST.
	 * @param bool       $retryable  Retry on timeouts, 429 and 5xx.
	 * @return array|WP_Error Decoded JSON body.
	 */
	public function request( $method, $path, $body = null, $retryable = true ) {
		if ( '' === $this->base_url || 0 !== strpos( $this->base_url, 'https://' ) ) {
			return new WP_Error( 'spraay_gateway_url', __( 'The Spraay gateway URL must be an https:// URL. Check the plugin settings.', 'spraay-batch-payouts' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'SpraayBatchPayouts/' . SPRAAY_BP_VERSION . '; ' . home_url( '/' ),
			),
		);
		if ( '' !== $this->api_key ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
		}
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		/**
		 * Filter the wp_remote_request() arguments for gateway calls.
		 *
		 * @param array  $args HTTP args.
		 * @param string $path Gateway path.
		 */
		$args = apply_filters( 'spraay_gateway_request_args', $args, $path );
		$url  = $this->base_url . $path;

		$attempts = $retryable ? self::MAX_RETRIES + 1 : 1;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( ! $this->should_retry( $response ) || $attempt === $attempts ) {
				break;
			}

			// Short linear backoff; admin requests shouldn't block for long.
			sleep( $attempt );
		}

		return $this->parse_response( $response );
	}

	/**
	 * Whether a response is a transient failure.
	 *
	 * @param array|WP_Error $response wp_remote_request() result.
	 * @return bool
	 */
	private function should_retry( $response ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return 429 === $code || $code >= 500;
	}

	/**
	 * Turn an HTTP response into decoded JSON or a WP_Error.
	 *
	 * @param array|WP_Error $response wp_remote_request() result.
	 * @return array|WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'spraay_gateway_unreachable',
				/* translators: %s: transport error message. */
				sprintf( __( 'Could not reach the Spraay gateway: %s', 'spraay-batch-payouts' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : ( is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : '' );
			if ( is_array( $message ) ) {
				$message = isset( $message['message'] ) ? $message['message'] : wp_json_encode( $message );
			}
			return new WP_Error(
				'spraay_gateway_http_' . $code,
				/* translators: 1: HTTP status code, 2: gateway error message. */
				sprintf( __( 'Spraay gateway returned HTTP %1$d. %2$s', 'spraay-batch-payouts' ), $code, sanitize_text_field( (string) $message ) ),
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'spraay_gateway_invalid_json', __( 'The Spraay gateway returned an unreadable response.', 'spraay-batch-payouts' ) );
		}

		// Unwrap { data: {...} } envelopes.
		return ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : $data;
	}

	/**
	 * Normalize an estimate response.
	 *
	 * @param array $data Decoded gateway response.
	 * @return array {
	 *     @type string     $batch_id     Gateway batch ID.
	 *     @type string     $subtotal     Sum of payouts.
	 *     @type string     $fee          Spraay fee.
	 *     @type string     $total        Subtotal + fee (what leaves the wallet).
	 *     @type array|null $transaction  { to, data, value } for the wallet to send.
	 *     @type array|null $approval     { token, spender, amount } ERC-20 allowance needed first.
	 *     @type array|null $typed_data   EIP-712 payload to sign in relayed mode.
	 *     @type array      $raw          Untouched response.
	 * }
	 */
	private function normalize_estimate( array $data ) {
		$pick = function ( array $keys ) use ( $data ) {
			foreach ( $keys as $key ) {
				if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
					return $data[ $key ];
				}
			}
			return null;
		};

		$normalized = array(
			'batch_id'    => (string) $pick( array( 'batchId', 'batch_id', 'id' ) ),
			'subtotal'    => (string) $pick( array( 'subtotal', 'amount', 'totalAmount' ) ),
			'fee'         => (string) $pick( array( 'fee', 'feeAmount', 'fee_amount' ) ),
			'total'       => (string) $pick( array( 'total', 'totalWithFee', 'total_amount' ) ),
			'transaction' => is_array( $pick( array( 'transaction', 'tx' ) ) ) ? $pick( array( 'transaction', 'tx' ) ) : null,
			'approval'    => is_array( $pick( array( 'approval', 'allowance' ) ) ) ? $pick( array( 'approval', 'allowance' ) ) : null,
			'typed_data'  => is_array( $pick( array( 'typedData', 'typed_data' ) ) ) ? $pick( array( 'typedData', 'typed_data' ) ) : null,
			'raw'         => $data,
		);

		/**
		 * Filter a normalized gateway estimate.
		 *
		 * @param array $normalized Normalized estimate.
		 * @param array $data       Raw response.
		 */
		return apply_filters( 'spraay_gateway_estimate_response', $normalized, $data );
	}

	/**
	 * Normalize an execute/status response.
	 *
	 * @param array $data Decoded gateway response.
	 * @return array { status: pending|confirmed|failed, tx_hash: string, confirmed_at: string|null, error: string, raw: array }
	 */
	private function normalize_status( array $data ) {
		$raw_status = strtolower( (string) ( isset( $data['status'] ) ? $data['status'] : '' ) );
		$map        = array(
			'confirmed' => Spraay_Batch::STATUS_CONFIRMED,
			'success'   => Spraay_Batch::STATUS_CONFIRMED,
			'completed' => Spraay_Batch::STATUS_CONFIRMED,
			'settled'   => Spraay_Batch::STATUS_CONFIRMED,
			'failed'    => Spraay_Batch::STATUS_FAILED,
			'reverted'  => Spraay_Batch::STATUS_FAILED,
			'error'     => Spraay_Batch::STATUS_FAILED,
		);

		$tx_hash = '';
		foreach ( array( 'txHash', 'tx_hash', 'transactionHash' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && preg_match( '/^0x[0-9a-fA-F]{64}$/', (string) $data[ $key ] ) ) {
				$tx_hash = strtolower( $data[ $key ] );
				break;
			}
		}

		$confirmed_at = null;
		foreach ( array( 'confirmedAt', 'confirmed_at' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && false !== strtotime( (string) $data[ $key ] ) ) {
				$confirmed_at = gmdate( 'Y-m-d H:i:s', strtotime( (string) $data[ $key ] ) );
				break;
			}
		}

		$normalized = array(
			'status'       => isset( $map[ $raw_status ] ) ? $map[ $raw_status ] : Spraay_Batch::STATUS_PENDING,
			'tx_hash'      => $tx_hash,
			'confirmed_at' => $confirmed_at,
			'error'        => isset( $data['error'] ) && is_string( $data['error'] ) ? sanitize_text_field( $data['error'] ) : '',
			'raw'          => $data,
		);

		/**
		 * Filter a normalized gateway status.
		 *
		 * @param array $normalized Normalized status.
		 * @param array $data       Raw response.
		 */
		return apply_filters( 'spraay_gateway_status_response', $normalized, $data );
	}
}
