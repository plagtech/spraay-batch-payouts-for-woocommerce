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
 * Polls the gateway for pending batches, both on demand and via WP-Cron.
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
	 * Pending batches older than this are marked failed if the gateway still
	 * doesn't know about them (seconds).
	 */
	const STALE_AFTER = 2 * DAY_IN_SECONDS;

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
			'display'  => __( 'Every five minutes (Spraay reconciliation)', 'spraay-batch-payouts' ),
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
	 * Refresh one batch from the gateway.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array|WP_Error { status, tx_hash, confirmed_at, explorer_url }
	 */
	public static function reconcile_batch( $batch_id ) {
		$batch = Spraay_Batch::get( $batch_id );
		if ( ! $batch ) {
			return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts' ), array( 'status' => 404 ) );
		}

		if ( Spraay_Batch::STATUS_PENDING === $batch['status'] && ! empty( $batch['gateway_batch_id'] ) ) {
			$status = ( new Spraay_Gateway_Client() )->status( $batch['gateway_batch_id'] );

			if ( is_wp_error( $status ) ) {
				$data = $status->get_error_data();
				$age  = time() - strtotime( $batch['created_at'] . ' UTC' );
				// A gateway that has never heard of an old batch won't settle it.
				if ( is_array( $data ) && isset( $data['status'] ) && 404 === $data['status'] && $age > self::STALE_AFTER && empty( $batch['tx_hash'] ) ) {
					Spraay_Batch::apply_status(
						$batch_id,
						array(
							'status' => Spraay_Batch::STATUS_FAILED,
							'error'  => $status->get_error_message(),
						)
					);
				}
				return $status;
			}

			Spraay_Batch::apply_status( $batch_id, $status );
			$batch = Spraay_Batch::get( $batch_id );
		}

		return array(
			'status'       => $batch['status'],
			'tx_hash'      => (string) $batch['tx_hash'],
			'confirmed_at' => $batch['confirmed_at'],
			'explorer_url' => Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] ),
		);
	}
}
