<?php
/**
 * HTTP bridge to the Spraay REST gateway.
 *
 * The gateway estimates batches and builds the unsigned batch transaction;
 * the merchant's browser wallet signs and broadcasts it. The gateway has no
 * status endpoint — confirmation is read from the chain (see
 * Spraay_Reconciliation).
 *
 * Amount format: batch endpoints take and return RAW BASE UNITS as decimal
 * strings (USDC has 6 decimals, so 250 USDC = "250000000"). Recipients and
 * amounts are parallel arrays, not objects.
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
	 * Estimate endpoint (free tier, no auth). As deployed it is a GET taking
	 * only the recipient count and returns the protocol fee rate (bps) and
	 * whether the chain can settle — not per-batch amounts. The paid
	 * POST /api/v1/batch/estimate is x402-gated, so the fee is computed locally
	 * from the reported rate; the execute response remains authoritative.
	 */
	const PATH_ESTIMATE = '/free/estimate-batch';

	/**
	 * Execute endpoint: returns the unsigned batch transaction (x402-paid).
	 */
	const PATH_EXECUTE = '/api/v1/batch/execute';

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
	 * Build the request body shared by estimate and execute.
	 *
	 * @param string   $chain      Gateway chain name ("base").
	 * @param string   $token      ERC-20 contract address.
	 * @param string   $sender     Merchant wallet, or '' if not yet connected.
	 * @param string[] $recipients Recipient addresses.
	 * @param string[] $amounts    Base-unit amount strings, same order as $recipients.
	 * @return array|WP_Error
	 */
	public static function batch_body( $chain, $token, $sender, array $recipients, array $amounts ) {
		$recipients = array_values( $recipients );
		$amounts    = array_values( array_map( 'strval', $amounts ) );

		if ( ! $recipients || count( $recipients ) !== count( $amounts ) ) {
			return new WP_Error( 'spraay_gateway_body', __( 'Recipients and amounts must be non-empty lists of the same length.', 'spraay-batch-payouts' ) );
		}
		foreach ( $amounts as $amount ) {
			if ( ! preg_match( '/^[1-9]\d*$/', $amount ) ) {
				return new WP_Error( 'spraay_gateway_body', __( 'Gateway amounts must be positive whole base-unit strings.', 'spraay-batch-payouts' ) );
			}
		}

		$body = array(
			'chain'      => $chain,
			'token'      => $token,
			'recipients' => $recipients,
			'amounts'    => $amounts,
		);
		if ( '' !== $sender ) {
			$body['sender'] = $sender;
		}
		return $body;
	}

	/**
	 * Check that a batch can settle and get the protocol fee rate.
	 *
	 * @param int    $recipient_count Recipients in the batch.
	 * @param string $chain           Gateway chain name ("base").
	 * @return array|WP_Error {
	 *     @type bool       $supported Whether the chain can actually settle.
	 *     @type int        $fee_bps   Protocol fee in basis points.
	 *     @type float|null $gas_usd   Rough gas estimate in USD.
	 *     @type array      $raw       Untouched response.
	 * }
	 */
	public function estimate( $recipient_count, $chain ) {
		$query = array(
			'recipients' => max( 1, (int) $recipient_count ),
			'chain'      => (string) $chain,
		);

		/**
		 * Filter the estimate query parameters before they are sent.
		 *
		 * @param array $query Query parameters.
		 */
		$query    = apply_filters( 'spraay_gateway_estimate_payload', $query );
		$response = $this->request( 'GET', self::PATH_ESTIMATE . '?' . http_build_query( $query, '', '&' ), null, true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$estimate = isset( $response['estimate'] ) && is_array( $response['estimate'] ) ? $response['estimate'] : $response;
		$bps      = isset( $estimate['protocolFeeBps'] ) ? filter_var( $estimate['protocolFeeBps'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => 1000 ) ) ) : false;
		if ( false === $bps ) {
			return new WP_Error( 'spraay_gateway_invalid', __( 'The gateway estimate did not include a valid protocol fee rate.', 'spraay-batch-payouts' ), array( 'status' => 502 ) );
		}

		$normalized = array(
			'supported' => ! empty( $estimate['supported'] ),
			'fee_bps'   => (int) $bps,
			'gas_usd'   => isset( $estimate['estimatedGasUSD'] ) && is_numeric( $estimate['estimatedGasUSD'] ) ? (float) $estimate['estimatedGasUSD'] : null,
			'raw'       => $response,
		);

		/**
		 * Filter a normalized gateway estimate.
		 *
		 * @param array $normalized Normalized estimate.
		 * @param array $response   Raw response.
		 */
		return apply_filters( 'spraay_gateway_estimate_response', $normalized, $response );
	}

	/**
	 * Ask the gateway to build the unsigned batch transaction.
	 *
	 * Not retried automatically: the endpoint is paid and the gateway rejects
	 * quick repeats of the same batch with HTTP 409 duplicate_payment_detected.
	 *
	 * @param array $body From batch_body(); sender is required.
	 * @return array|WP_Error {
	 *     @type array      $transaction { to, data, value, chain_id, gas_limit } — gas_limit is hex.
	 *     @type array|null $approval    { token, spender, amount } when an ERC-20 approve is needed first.
	 *     @type array      $batch       { recipient_count, total_amount, fee, total_with_fee } as human-decimal strings (e.g. "1.5").
	 *     @type array      $raw         Untouched response.
	 * }
	 */
	public function execute( array $body ) {
		if ( empty( $body['sender'] ) ) {
			return new WP_Error( 'spraay_gateway_body', __( 'A connected wallet is required to prepare the transaction.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		/**
		 * Filter the execute request body before it is sent.
		 *
		 * @param array $body Request body.
		 */
		$body     = apply_filters( 'spraay_gateway_execute_payload', $body );
		$response = $this->request( 'POST', self::PATH_EXECUTE, $body, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$tx = isset( $response['transaction'] ) && is_array( $response['transaction'] ) ? $response['transaction'] : array();
		if ( empty( $tx['to'] ) || empty( $tx['data'] ) ) {
			return new WP_Error( 'spraay_gateway_invalid', __( 'The gateway did not return a transaction to sign.', 'spraay-batch-payouts' ), array( 'status' => 502 ) );
		}

		$approval = null;
		if ( ! empty( $response['approvalRequired'] ) && is_array( $response['approvalRequired'] ) ) {
			$a        = $response['approvalRequired'];
			$approval = array(
				'token'   => isset( $a['token'] ) ? (string) $a['token'] : '',
				'spender' => isset( $a['spender'] ) ? (string) $a['spender'] : '',
				'amount'  => isset( $a['amount'] ) ? (string) $a['amount'] : '',
			);
		}

		$b          = isset( $response['batch'] ) && is_array( $response['batch'] ) ? $response['batch'] : array();
		$normalized = array(
			'transaction' => array(
				'to'        => (string) $tx['to'],
				'data'      => (string) $tx['data'],
				'value'     => isset( $tx['value'] ) ? (string) $tx['value'] : '0',
				'chain_id'  => isset( $tx['chainId'] ) ? (int) $tx['chainId'] : 0,
				'gas_limit' => isset( $tx['gasLimit'] ) ? (string) $tx['gasLimit'] : '',
			),
			'approval'    => $approval,
			'batch'       => array(
				'recipient_count' => isset( $b['recipientCount'] ) ? (int) $b['recipientCount'] : 0,
				'total_amount'    => isset( $b['totalAmount'] ) ? (string) $b['totalAmount'] : '',
				'fee'             => isset( $b['fee'] ) ? (string) $b['fee'] : '',
				'total_with_fee'  => isset( $b['totalWithFee'] ) ? (string) $b['totalWithFee'] : '',
			),
			'raw'         => $response,
		);

		/**
		 * Filter a normalized execute response.
		 *
		 * @param array $normalized Normalized response.
		 * @param array $response   Raw response.
		 */
		return apply_filters( 'spraay_gateway_execute_response', $normalized, $response );
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
			$args['headers']['X-API-Key'] = $this->api_key;
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
			$message = (string) $message;
			$data    = array(
				'status' => $code,
				'body'   => $data,
			);

			if ( 409 === $code && 'duplicate_payment_detected' === $message ) {
				return new WP_Error( 'spraay_duplicate_payment', __( 'This batch was already submitted to the gateway moments ago. Check your wallet and Batch History before trying again.', 'spraay-batch-payouts' ), $data );
			}
			if ( 402 === $code ) {
				// Paid endpoints answer 402 (x402) without a valid API key.
				return new WP_Error( 'spraay_payment_required', __( 'API key required — configure your Spraay API key in Settings', 'spraay-batch-payouts' ), $data );
			}

			return new WP_Error(
				'spraay_gateway_http_' . $code,
				/* translators: 1: HTTP status code, 2: gateway error message. */
				sprintf( __( 'Spraay gateway returned HTTP %1$d. %2$s', 'spraay-batch-payouts' ), $code, sanitize_text_field( $message ) ),
				$data
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'spraay_gateway_invalid_json', __( 'The Spraay gateway returned an unreadable response.', 'spraay-batch-payouts' ) );
		}

		// Unwrap { data: {...} } envelopes.
		return ( isset( $data['data'] ) && is_array( $data['data'] ) ) ? $data['data'] : $data;
	}
}
