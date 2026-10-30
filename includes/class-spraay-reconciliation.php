<?php
/**
 * On-chain status tracking for submitted batches.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polls the chain's JSON-RPC (eth_getTransactionReceipt) for pending batches,
 * both on demand and via WP-Cron. The Spraay gateway has no status endpoint.
 */
class Spraay_Reconciliation {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'spraay_reconcile_pending';

	/**
	 * Custom cron interval name.
	 */
	const CRON_SCHEDULE = 'spraay_five_minutes';

	/**
	 * Pending batches older than this are marked failed if the node no longer
	 * knows their transaction at all (dropped or replaced), in seconds.
	 */
	const STALE_AFTER = 2 * DAY_IN_SECONDS;

	/**
	 * JSON-RPC timeout in seconds.
	 */
	const RPC_TIMEOUT = 15;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( $this, 'reconcile_pending' ) );

		// Re-create the event if it went missing (e.g. after a cron table reset).
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Add the five-minute interval.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Spraay reconciliation)', 'spraay-batch-payouts-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the recurring check.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// The custom interval may not be registered yet during activation.
			$recurrence = array_key_exists( self::CRON_SCHEDULE, wp_get_schedules() ) ? self::CRON_SCHEDULE : 'hourly';
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the recurring check.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Check every pending batch.
	 *
	 * @return int Number of batches checked.
	 */
	public function reconcile_pending() {
		$pending = Spraay_Batch::query(
			array(
				'status'   => Spraay_Batch::STATUS_PENDING,
				'per_page' => 50,
				'orderby'  => 'created_at',
				'order'    => 'ASC',
			)
		);

		foreach ( $pending['items'] as $batch ) {
			self::reconcile_batch( (int) $batch['id'] );
		}

		return count( $pending['items'] );
	}

	/**
	 * Refresh one batch from the chain.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array|WP_Error { status, tx_hash, confirmed_at, explorer_url }
	 */
	public static function reconcile_batch( $batch_id ) {
		$batch = Spraay_Batch::get( $batch_id );
		if ( ! $batch ) {
			return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts-for-woocommerce' ), array( 'status' => 404 ) );
		}

		if ( Spraay_Batch::STATUS_PENDING === $batch['status'] && ! empty( $batch['tx_hash'] ) ) {
			$status = self::check_transaction( $batch );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			if ( $status ) {
				Spraay_Batch::apply_status( $batch_id, $status );
				$batch = Spraay_Batch::get( $batch_id );
			}
		}

		return array(
			'status'       => $batch['status'],
			'tx_hash'      => (string) $batch['tx_hash'],
			'confirmed_at' => $batch['confirmed_at'],
			'explorer_url' => Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] ),
		);
	}

	/**
	 * Read a pending batch's transaction receipt.
	 *
	 * @param array $batch Batch row with tx_hash.
	 * @return array|null|WP_Error Status for Spraay_Batch::apply_status(), null while
	 *                             still pending, or WP_Error if the RPC failed.
	 */
	private static function check_transaction( array $batch ) {
		$chain = Spraay_Plugin::get_chain( $batch['chain_id'] );
		if ( ! $chain ) {
			return new WP_Error( 'spraay_chain_unsupported', __( 'This batch is on an unsupported network.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$receipt = self::rpc( $chain, 'eth_getTransactionReceipt', array( $batch['tx_hash'] ) );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}

		if ( null === $receipt ) {
			// Not mined yet. If it's old and the node doesn't know the tx at all, it was dropped.
			$age = time() - strtotime( $batch['created_at'] . ' UTC' );
			if ( $age > self::STALE_AFTER ) {
				$tx = self::rpc( $chain, 'eth_getTransactionByHash', array( $batch['tx_hash'] ) );
				if ( null === $tx ) {
					return array(
						'status' => Spraay_Batch::STATUS_FAILED,
						'error'  => __( 'The transaction was never mined (dropped or replaced in the wallet).', 'spraay-batch-payouts-for-woocommerce' ),
					);
				}
			}
			return null;
		}

		$raw = array(
			'status'      => isset( $receipt['status'] ) ? $receipt['status'] : null,
			'blockNumber' => isset( $receipt['blockNumber'] ) ? $receipt['blockNumber'] : null,
			'to'          => isset( $receipt['to'] ) ? $receipt['to'] : null,
			'gasUsed'     => isset( $receipt['gasUsed'] ) ? $receipt['gasUsed'] : null,
			'logCount'    => isset( $receipt['logs'] ) && is_array( $receipt['logs'] ) ? count( $receipt['logs'] ) : 0,
		);

		if ( '0x1' !== strtolower( (string) $raw['status'] ) ) {
			return array(
				'status' => Spraay_Batch::STATUS_FAILED,
				'error'  => __( 'The transaction reverted on-chain. No funds were sent.', 'spraay-batch-payouts-for-woocommerce' ),
				'raw'    => $raw,
			);
		}

		if ( strtolower( (string) $raw['to'] ) !== strtolower( $chain['spraay_contract'] ) ) {
			return array(
				'status' => Spraay_Batch::STATUS_FAILED,
				'error'  => __( 'The recorded transaction did not call the Spraay contract.', 'spraay-batch-payouts-for-woocommerce' ),
				'raw'    => $raw,
			);
		}

		// Use the block timestamp as the settlement time.
		$confirmed_at = null;
		$block        = self::rpc( $chain, 'eth_getBlockByNumber', array( $raw['blockNumber'], false ) );
		if ( is_array( $block ) && ! empty( $block['timestamp'] ) ) {
			$confirmed_at = gmdate( 'Y-m-d H:i:s', (int) hexdec( $block['timestamp'] ) );
		}

		return array(
			'status'       => Spraay_Batch::STATUS_CONFIRMED,
			'confirmed_at' => $confirmed_at,
			'raw'          => $raw,
		);
	}

	/**
	 * Minimal JSON-RPC call.
	 *
	 * @param array  $chain  Chain config with rpc_url.
	 * @param string $method RPC method.
	 * @param array  $params RPC params.
	 * @return mixed|WP_Error The `result` (null when the node has no data).
	 */
	private static function rpc( array $chain, $method, array $params ) {
		/**
		 * Filter the JSON-RPC endpoint used for reconciliation (e.g. a
		 * dedicated provider instead of the public, rate-limited one).
		 *
		 * @param string $url   RPC URL.
		 * @param array  $chain Chain config.
		 */
		$url = apply_filters( 'spraay_rpc_url', $chain['rpc_url'], $chain );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::RPC_TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => $method,
						'params'  => $params,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: transport error. */
			return new WP_Error( 'spraay_rpc_unreachable', sprintf( __( 'Could not reach the network RPC: %s', 'spraay-batch-payouts-for-woocommerce' ), $response->get_error_message() ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || isset( $data['error'] ) || ! array_key_exists( 'result', $data ) ) {
			return new WP_Error( 'spraay_rpc_error', __( 'The network RPC returned an error. Status will be checked again shortly.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		return $data['result'];
	}
}
