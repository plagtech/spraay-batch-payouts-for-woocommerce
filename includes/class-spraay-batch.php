<?php
/**
 * Batch creation, estimation and execution.
 *
 * Lifecycle: draft -> estimated -> (merchant approval) -> pending -> confirmed | failed.
 * Editing an estimated batch returns it to draft and discards the estimate.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch model and workflow.
 */
class Spraay_Batch {

	const STATUS_DRAFT     = 'draft';
	const STATUS_ESTIMATED = 'estimated';
	const STATUS_PENDING   = 'pending';
	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_FAILED    = 'failed';

	/**
	 * Maximum recipients per batch.
	 */
	const MAX_RECIPIENTS = 200;

	/**
	 * All statuses with labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			self::STATUS_DRAFT     => __( 'Draft', 'spraay-batch-payouts' ),
			self::STATUS_ESTIMATED => __( 'Estimated', 'spraay-batch-payouts' ),
			self::STATUS_PENDING   => __( 'Pending', 'spraay-batch-payouts' ),
			self::STATUS_CONFIRMED => __( 'Confirmed', 'spraay-batch-payouts' ),
			self::STATUS_FAILED    => __( 'Failed', 'spraay-batch-payouts' ),
		);
	}

	/**
	 * Get a batch row.
	 *
	 * @param int $id Batch ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Spraay_Plugin::table( 'batches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );
	}

	/**
	 * Items of a batch with recipient label and wallet.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array[]
	 */
	public static function get_items( $batch_id ) {
		global $wpdb;
		$items      = Spraay_Plugin::table( 'batch_items' );
		$recipients = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, r.label, r.wallet_address FROM {$items} i LEFT JOIN {$recipients} r ON r.id = i.recipient_id WHERE i.batch_id = %d ORDER BY i.id ASC",
				absint( $batch_id )
			),
			ARRAY_A
		);

		// Cast before iterating: foreach by reference over a cast expression
		// writes to a temporary copy, so the decoded IDs would be lost.
		$rows = (array) $rows;
		foreach ( $rows as &$row ) {
			$decoded             = json_decode( (string) $row['wc_order_ids'], true );
			$row['wc_order_ids'] = is_array( $decoded ) ? array_map( 'absint', $decoded ) : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Paginated list of batches.
	 *
	 * @param array $args {
	 *     @type string $status    Status filter.
	 *     @type string $date_from Y-m-d, inclusive (UTC).
	 *     @type string $date_to   Y-m-d, inclusive (UTC).
	 *     @type string $orderby   id|created_at|total_amount|recipient_count|status.
	 *     @type string $order     ASC|DESC.
	 *     @type int    $per_page  Rows per page; 0 for all.
	 *     @type int    $page      1-based page.
	 * }
	 * @return array { items: array[], total: int }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'date_from' => '',
				'date_to'   => '',
				'orderby'   => 'created_at',
				'order'     => 'DESC',
				'per_page'  => 20,
				'page'      => 1,
			)
		);

		$table   = Spraay_Plugin::table( 'batches' );
		$orderby = in_array( $args['orderby'], array( 'id', 'created_at', 'total_amount', 'recipient_count', 'status' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$params = array();
		if ( isset( self::statuses()[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( self::is_date( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( self::is_date( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		$where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby/$order are whitelisted.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC";
		if ( absint( $args['per_page'] ) > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = absint( $args['per_page'] );
			$params[] = ( max( 1, absint( $args['page'] ) ) - 1 ) * absint( $args['per_page'] );
		}
		$items = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Count batches per status.
	 *
	 * @return array status => count
	 */
	public static function count_by_status() {
		global $wpdb;
		$table = Spraay_Plugin::table( 'batches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Sum of confirmed payouts.
	 *
	 * @return string Decimal amount.
	 */
	public static function total_paid() {
		global $wpdb;
		$table = Spraay_Plugin::table( 'batches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sum = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount), 0) FROM {$table} WHERE status = %s", self::STATUS_CONFIRMED ) );
		return (string) $sum;
	}

	/**
	 * Create or replace a draft batch.
	 *
	 * @param array[] $raw_items [ { recipient_id, amount, wc_order_ids? } ].
	 * @param int     $batch_id  Existing draft/estimated batch to replace, or 0.
	 * @param int     $user_id   Creating user.
	 * @return int|WP_Error Batch ID.
	 */
	public static function save_draft( array $raw_items, $batch_id = 0, $user_id = 0 ) {
		global $wpdb;

		$batch_id = absint( $batch_id );
		if ( $batch_id ) {
			$batch = self::get( $batch_id );
			if ( ! $batch ) {
				return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts' ), array( 'status' => 404 ) );
			}
			if ( ! self::is_editable( $batch ) ) {
				return new WP_Error( 'spraay_locked', __( 'This batch has been submitted and can no longer be edited.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
			}
		}

		$items = self::validate_items( $raw_items, $batch_id );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$settings = Spraay_Plugin::get_settings();
		$batches  = Spraay_Plugin::table( 'batches' );
		$table    = Spraay_Plugin::table( 'batch_items' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		if ( $batch_id ) {
			$wpdb->update(
				$batches,
				array(
					'status'           => self::STATUS_DRAFT,
					'gateway_batch_id' => null,
					'gateway_response' => null,
					'fee_amount'       => 0,
					'approved_by'      => null,
					'chain_id'         => (int) $settings['chain_id'],
				),
				array( 'id' => $batch_id )
			);
			$wpdb->delete( $table, array( 'batch_id' => $batch_id ), array( '%d' ) );
		} else {
			$wpdb->insert(
				$batches,
				array(
					'status'     => self::STATUS_DRAFT,
					'chain_id'   => (int) $settings['chain_id'],
					'created_by' => absint( $user_id ),
					'created_at' => current_time( 'mysql', true ),
				)
			);
			$batch_id = (int) $wpdb->insert_id;
		}

		if ( ! $batch_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'spraay_db_error', __( 'Could not save the batch.', 'spraay-batch-payouts' ) );
		}

		foreach ( $items as $item ) {
			$wpdb->insert(
				$table,
				array(
					'batch_id'     => $batch_id,
					'recipient_id' => $item['recipient_id'],
					'amount'       => $item['amount'],
					'currency'     => 'USDC',
					'wc_order_ids' => $item['wc_order_ids'] ? wp_json_encode( $item['wc_order_ids'] ) : null,
					'status'       => self::STATUS_DRAFT,
				)
			);
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		self::recalculate( $batch_id );

		/**
		 * Fires after a draft batch is saved.
		 *
		 * @param int $batch_id Batch ID.
		 */
		do_action( 'spraay_batch_draft_saved', $batch_id );

		return $batch_id;
	}

	/**
	 * Validate and normalize draft items.
	 *
	 * @param array $raw_items Raw items.
	 * @param int   $batch_id  Batch being edited (its own orders don't count as duplicates).
	 * @return array|WP_Error
	 */
	private static function validate_items( array $raw_items, $batch_id ) {
		if ( ! $raw_items ) {
			return new WP_Error( 'spraay_empty_batch', __( 'Add at least one recipient to the batch.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		if ( count( $raw_items ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: maximum recipients. */
			return new WP_Error( 'spraay_batch_too_large', sprintf( __( 'A batch can contain at most %d recipients.', 'spraay-batch-payouts' ), self::MAX_RECIPIENTS ), array( 'status' => 400 ) );
		}

		$recipients = Spraay_Recipient::get_many( wp_list_pluck( $raw_items, 'recipient_id' ) );
		$paid       = Spraay_WC_Integration::get_settled_order_ids( $batch_id );
		$seen       = array();
		$items      = array();

		foreach ( $raw_items as $raw ) {
			$recipient_id = isset( $raw['recipient_id'] ) ? absint( $raw['recipient_id'] ) : 0;
			if ( ! isset( $recipients[ $recipient_id ] ) ) {
				return new WP_Error( 'spraay_unknown_recipient', __( 'One of the selected recipients no longer exists.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
			}
			$label = $recipients[ $recipient_id ]['label'];

			if ( isset( $seen[ $recipient_id ] ) ) {
				/* translators: %s: recipient label. */
				return new WP_Error( 'spraay_duplicate_recipient', sprintf( __( '%s appears more than once. Combine their amounts into one line.', 'spraay-batch-payouts' ), $label ), array( 'status' => 400 ) );
			}
			$seen[ $recipient_id ] = true;

			$amount = Spraay_Amount::normalize( isset( $raw['amount'] ) ? (string) $raw['amount'] : '' );
			if ( false === $amount || Spraay_Amount::to_units( $amount ) <= 0 ) {
				/* translators: %s: recipient label. */
				return new WP_Error( 'spraay_invalid_amount', sprintf( __( 'Enter a positive amount (max 6 decimals) for %s.', 'spraay-batch-payouts' ), $label ), array( 'status' => 400 ) );
			}

			$order_ids = isset( $raw['wc_order_ids'] ) && is_array( $raw['wc_order_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $raw['wc_order_ids'] ) ) ) ) : array();
			$already   = array_intersect( $order_ids, $paid );
			if ( $already ) {
				return new WP_Error(
					'spraay_order_already_paid',
					/* translators: 1: comma-separated order numbers, 2: recipient label. */
					sprintf( __( 'Order(s) %1$s for %2$s are already in a pending or confirmed batch.', 'spraay-batch-payouts' ), implode( ', ', $already ), $label ),
					array( 'status' => 409 )
				);
			}

			$items[] = array(
				'recipient_id' => $recipient_id,
				'amount'       => $amount,
				'wc_order_ids' => $order_ids,
			);
		}

		return $items;
	}

	/**
	 * Recompute total_amount and recipient_count from items.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public static function recalculate( $batch_id ) {
		global $wpdb;
		$units = 0;
		$items = self::get_items( $batch_id );
		foreach ( $items as $item ) {
			$units += Spraay_Amount::to_units( $item['amount'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Spraay_Plugin::table( 'batches' ),
			array(
				'total_amount'    => Spraay_Amount::from_units( $units ),
				'recipient_count' => count( $items ),
			),
			array( 'id' => absint( $batch_id ) )
		);
	}

	/**
	 * Request a gateway estimate and move the batch to "estimated".
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $sender   Merchant wallet address, if connected.
	 * @return array|WP_Error Estimate for the review screen (decimal strings).
	 */
	public static function estimate( $batch_id, $sender = '' ) {
		global $wpdb;

		$batch = self::get( $batch_id );
		if ( ! $batch ) {
			return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_editable( $batch ) ) {
			return new WP_Error( 'spraay_locked', __( 'This batch has already been submitted.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}
		if ( '' !== $sender && ! Spraay_Address::is_valid( $sender ) ) {
			return new WP_Error( 'spraay_invalid_wallet', __( 'Connected wallet address is invalid.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		// Always estimate on the currently configured network, which is what the wallet will use.
		$settings          = Spraay_Plugin::get_settings();
		$batch['chain_id'] = (int) $settings['chain_id'];

		// Validates the batch can be expressed as a gateway request before asking.
		$body = self::gateway_body( $batch_id, $sender );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$estimate = ( new Spraay_Gateway_Client() )->estimate( count( $body['recipients'] ), $body['chain'] );
		if ( is_wp_error( $estimate ) ) {
			return $estimate;
		}
		if ( ! $estimate['supported'] ) {
			return new WP_Error( 'spraay_chain_unsupported', __( 'The Spraay gateway reports that it cannot currently settle batches on this network.', 'spraay-batch-payouts' ), array( 'status' => 502 ) );
		}

		// The fee is added on top of the payouts at the gateway's reported rate.
		// The approval amount from execute is checked against this before signing.
		$subtotal  = Spraay_Amount::to_units( $batch['total_amount'] );
		$fee_units = Spraay_Amount::fee_units( $subtotal, $estimate['fee_bps'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Spraay_Plugin::table( 'batches' ),
			array(
				'status'           => self::STATUS_ESTIMATED,
				'chain_id'         => (int) $batch['chain_id'],
				'fee_amount'       => Spraay_Amount::from_units( $fee_units ),
				'gateway_response' => wp_json_encode( array( 'estimate' => $estimate['raw'] ) ),
				'approved_by'      => null,
			),
			array( 'id' => absint( $batch_id ) )
		);
		self::set_item_status( $batch_id, self::STATUS_ESTIMATED );

		return array(
			'batch_id' => absint( $batch_id ),
			'chain_id' => (int) $batch['chain_id'],
			'subtotal' => Spraay_Amount::from_units( $subtotal ),
			'fee'      => Spraay_Amount::from_units( $fee_units ),
			'total'    => Spraay_Amount::from_units( $subtotal + $fee_units ),
		);
	}

	/**
	 * Record explicit merchant approval of an estimated batch.
	 *
	 * @param int $batch_id Batch ID.
	 * @param int $user_id  Approving user.
	 * @return true|WP_Error
	 */
	public static function approve( $batch_id, $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			Spraay_Plugin::table( 'batches' ),
			array( 'approved_by' => absint( $user_id ) ),
			array(
				'id'     => absint( $batch_id ),
				'status' => self::STATUS_ESTIMATED,
			)
		);
		if ( ! $updated && ! self::is_approved( $batch_id ) ) {
			return new WP_Error( 'spraay_not_estimated', __( 'Only estimated batches can be approved. Re-run the estimate.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}

		do_action( 'spraay_batch_approved', absint( $batch_id ), absint( $user_id ) );

		return true;
	}

	/**
	 * Ask the gateway for the unsigned batch transaction for an approved batch.
	 *
	 * Nothing is broadcast here and the batch stays "estimated"; the wallet
	 * signs the returned transaction and record_submission() stores its hash.
	 * The browser re-verifies the calldata against `expected` before signing.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $sender   Merchant wallet that will sign.
	 * @return array|WP_Error { transaction, approval, batch, expected: { token, contract, chain_id, recipients[], amounts[], subtotal, fee } }
	 */
	public static function prepare_execution( $batch_id, $sender ) {
		$batch_id = absint( $batch_id );

		if ( ! Spraay_Address::is_valid( $sender ) ) {
			return new WP_Error( 'spraay_invalid_wallet', __( 'Connected wallet address is invalid.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_approved( $batch_id ) ) {
			return new WP_Error( 'spraay_not_approved', __( 'The batch must be reviewed and approved before execution.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}
		$conflicts = Spraay_WC_Integration::find_conflicting_orders( $batch_id );
		if ( $conflicts ) {
			return new WP_Error(
				'spraay_order_already_paid',
				/* translators: %s: comma-separated order numbers. */
				sprintf( __( 'Order(s) %s were paid by another batch since this one was built. Edit the batch and remove them.', 'spraay-batch-payouts' ), implode( ', ', $conflicts ) ),
				array( 'status' => 409 )
			);
		}

		$batch = self::get( $batch_id );
		$chain = Spraay_Plugin::get_chain( $batch['chain_id'] );
		$body  = self::gateway_body( $batch_id, $sender );
		if ( ! $chain || is_wp_error( $body ) ) {
			return is_wp_error( $body ) ? $body : new WP_Error( 'spraay_chain_unsupported', __( 'This batch is on an unsupported network.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$result = ( new Spraay_Gateway_Client() )->execute( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Server-side sanity checks; the wallet JS repeats these and also decodes the calldata.
		$tx       = $result['transaction'];
		$approval = $result['approval'];
		$subtotal = Spraay_Amount::to_units( $batch['total_amount'] );
		$problems = array();
		if ( strtolower( $tx['to'] ) !== strtolower( $chain['spraay_contract'] ) ) {
			$problems[] = __( 'transaction is not addressed to the Spraay contract', 'spraay-batch-payouts' );
		}
		if ( (int) $tx['chain_id'] !== (int) $batch['chain_id'] ) {
			$problems[] = __( 'transaction is for a different network', 'spraay-batch-payouts' );
		}
		if ( '' !== ltrim( preg_replace( '/^0x/i', '', $tx['value'] ), '0' ) ) {
			$problems[] = __( 'transaction asks for ETH', 'spraay-batch-payouts' );
		}
		if ( $approval && ( strtolower( $approval['spender'] ) !== strtolower( $chain['spraay_contract'] ) || strtolower( $approval['token'] ) !== strtolower( $body['token'] ) ) ) {
			$problems[] = __( 'approval is for an unexpected token or spender', 'spraay-batch-payouts' );
		}
		if ( '' !== $result['batch']['total_amount'] && (int) $result['batch']['total_amount'] !== $subtotal ) {
			$problems[] = __( 'payout total differs from this batch', 'spraay-batch-payouts' );
		}
		if ( $problems ) {
			return new WP_Error(
				'spraay_unsafe_transaction',
				/* translators: %s: list of problems. */
				sprintf( __( 'The gateway transaction was rejected (%s). Nothing was signed.', 'spraay-batch-payouts' ), implode( '; ', $problems ) ),
				array( 'status' => 502 )
			);
		}

		self::store_gateway_response( $batch_id, 'execute', $result['raw'] );

		return array(
			'transaction' => $tx,
			'approval'    => $approval,
			'batch'       => $result['batch'],
			'expected'    => array(
				'token'      => $body['token'],
				'contract'   => $chain['spraay_contract'],
				'chain_id'   => (int) $batch['chain_id'],
				'recipients' => $body['recipients'],
				'amounts'    => $body['amounts'],
				'subtotal'   => (string) $subtotal,
				'fee'        => (string) Spraay_Amount::to_units( $batch['fee_amount'] ),
			),
		);
	}

	/**
	 * Record the hash of the transaction the wallet broadcast and mark the
	 * batch pending. Idempotent for the same hash, so the browser can retry.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $tx_hash  Transaction hash.
	 * @param string $sender   Wallet that sent it.
	 * @return array|WP_Error { status, tx_hash }
	 */
	public static function record_submission( $batch_id, $tx_hash, $sender ) {
		global $wpdb;

		$batch_id = absint( $batch_id );
		$tx_hash  = strtolower( (string) $tx_hash );

		if ( ! preg_match( '/^0x[0-9a-f]{64}$/', $tx_hash ) ) {
			return new WP_Error( 'spraay_invalid_tx', __( 'Transaction hash is invalid.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$batch = self::get( $batch_id );
		if ( $batch && self::STATUS_ESTIMATED !== $batch['status'] ) {
			if ( $tx_hash === strtolower( (string) $batch['tx_hash'] ) ) {
				return array(
					'status'  => $batch['status'],
					'tx_hash' => $tx_hash,
				);
			}
			return new WP_Error( 'spraay_already_submitted', __( 'This batch was already submitted with a different transaction.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}
		if ( ! self::is_approved( $batch_id ) ) {
			return new WP_Error( 'spraay_not_approved', __( 'The batch must be reviewed and approved before execution.', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}

		// Atomically claim the batch so concurrent requests can't record two transactions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->update(
			Spraay_Plugin::table( 'batches' ),
			array(
				'status'  => self::STATUS_PENDING,
				'tx_hash' => $tx_hash,
			),
			array(
				'id'     => $batch_id,
				'status' => self::STATUS_ESTIMATED,
			)
		);
		if ( ! $claimed ) {
			return new WP_Error( 'spraay_already_submitted', __( 'This batch is not awaiting execution (it may already have been submitted).', 'spraay-batch-payouts' ), array( 'status' => 409 ) );
		}
		self::set_item_status( $batch_id, self::STATUS_PENDING );
		self::store_gateway_response(
			$batch_id,
			'submitted',
			array(
				'sender'  => Spraay_Address::is_valid( $sender ) ? Spraay_Address::to_checksum( $sender ) : '',
				'tx_hash' => $tx_hash,
			)
		);

		/**
		 * Fires when a batch transaction has been broadcast and recorded.
		 *
		 * @param int $batch_id Batch ID.
		 */
		do_action( 'spraay_batch_submitted', $batch_id );

		return array(
			'status'  => self::STATUS_PENDING,
			'tx_hash' => $tx_hash,
		);
	}

	/**
	 * Gateway request body (parallel recipient/amount arrays in base units).
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $sender   Merchant wallet or ''.
	 * @return array|WP_Error
	 */
	private static function gateway_body( $batch_id, $sender ) {
		$settings = Spraay_Plugin::get_settings();
		$chain    = Spraay_Plugin::get_chain( $settings['chain_id'] );
		if ( ! $chain || ! isset( $chain['tokens'][ $settings['token'] ] ) ) {
			return new WP_Error( 'spraay_chain_unsupported', __( 'The configured network or token is not supported.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$recipients = array();
		$amounts    = array();
		foreach ( self::get_items( $batch_id ) as $item ) {
			if ( empty( $item['wallet_address'] ) ) {
				return new WP_Error( 'spraay_unknown_recipient', __( 'A recipient in this batch was deleted. Edit the batch and remove it.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
			}
			$recipients[] = $item['wallet_address'];
			$amounts[]    = (string) Spraay_Amount::to_units( $item['amount'] );
		}

		return Spraay_Gateway_Client::batch_body(
			$chain['gateway_chain'],
			$chain['tokens'][ $settings['token'] ]['address'],
			'' !== $sender ? Spraay_Address::to_checksum( $sender ) : '',
			$recipients,
			$amounts
		);
	}

	/**
	 * Apply a normalized gateway status to a pending batch.
	 *
	 * @param int   $batch_id Batch ID.
	 * @param array $status   From Spraay_Reconciliation (on-chain receipt check).
	 * @return string Resulting batch status.
	 */
	public static function apply_status( $batch_id, array $status ) {
		global $wpdb;

		$batch = self::get( $batch_id );
		if ( ! $batch || self::STATUS_PENDING !== $batch['status'] ) {
			return $batch ? $batch['status'] : '';
		}

		$data = array();
		if ( ! empty( $status['tx_hash'] ) ) {
			$data['tx_hash'] = $status['tx_hash'];
		}

		if ( self::STATUS_CONFIRMED === $status['status'] ) {
			$data['status']       = self::STATUS_CONFIRMED;
			$data['confirmed_at'] = ! empty( $status['confirmed_at'] ) ? $status['confirmed_at'] : current_time( 'mysql', true );
		} elseif ( self::STATUS_FAILED === $status['status'] ) {
			$data['status'] = self::STATUS_FAILED;
		}

		if ( $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Spraay_Plugin::table( 'batches' ), $data, array( 'id' => absint( $batch_id ) ) );
		}
		if ( isset( $status['raw'] ) ) {
			self::store_gateway_response( $batch_id, 'status', $status['raw'] );
		}

		if ( isset( $data['status'] ) ) {
			self::set_item_status( $batch_id, $data['status'] );

			if ( self::STATUS_CONFIRMED === $data['status'] ) {
				/**
				 * Fires when a batch is confirmed on-chain.
				 *
				 * @param int $batch_id Batch ID.
				 */
				do_action( 'spraay_batch_confirmed', absint( $batch_id ) );
			} else {
				/**
				 * Fires when a batch fails.
				 *
				 * @param int    $batch_id Batch ID.
				 * @param string $error    Gateway error message, if any.
				 */
				do_action( 'spraay_batch_failed', absint( $batch_id ), isset( $status['error'] ) ? $status['error'] : '' );
			}
			return $data['status'];
		}

		return self::STATUS_PENDING;
	}

	/**
	 * Delete a draft or estimated batch.
	 *
	 * @param int $batch_id Batch ID.
	 * @return true|WP_Error
	 */
	public static function delete( $batch_id ) {
		global $wpdb;

		$batch = self::get( $batch_id );
		if ( ! $batch ) {
			return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts' ) );
		}
		if ( ! self::is_editable( $batch ) ) {
			return new WP_Error( 'spraay_locked', __( 'Submitted batches are kept as payout records and cannot be deleted.', 'spraay-batch-payouts' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Spraay_Plugin::table( 'batch_items' ), array( 'batch_id' => absint( $batch_id ) ), array( '%d' ) );
		$wpdb->delete( Spraay_Plugin::table( 'batches' ), array( 'id' => absint( $batch_id ) ), array( '%d' ) );
		// phpcs:enable

		return true;
	}

	/**
	 * Whether a batch can still be edited (not yet submitted).
	 *
	 * @param array $batch Batch row.
	 * @return bool
	 */
	public static function is_editable( array $batch ) {
		return in_array( $batch['status'], array( self::STATUS_DRAFT, self::STATUS_ESTIMATED ), true );
	}

	/**
	 * Whether an estimated batch has been approved.
	 *
	 * @param int $batch_id Batch ID.
	 * @return bool
	 */
	public static function is_approved( $batch_id ) {
		$batch = self::get( $batch_id );
		return $batch && self::STATUS_ESTIMATED === $batch['status'] && ! empty( $batch['approved_by'] );
	}

	/**
	 * Merge a gateway response into the batch's stored responses.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $key      Response type (estimate, execute, status...).
	 * @param mixed  $value    Response data.
	 */
	private static function store_gateway_response( $batch_id, $key, $value ) {
		global $wpdb;
		$batch    = self::get( $batch_id );
		$stored   = $batch ? json_decode( (string) $batch['gateway_response'], true ) : array();
		$stored   = is_array( $stored ) ? $stored : array();
		$stored[ $key ] = $value;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Spraay_Plugin::table( 'batches' ), array( 'gateway_response' => wp_json_encode( $stored ) ), array( 'id' => absint( $batch_id ) ) );
	}

	/**
	 * Set the status of all items in a batch.
	 *
	 * @param int    $batch_id Batch ID.
	 * @param string $status   Status.
	 */
	private static function set_item_status( $batch_id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( Spraay_Plugin::table( 'batch_items' ), array( 'status' => $status ), array( 'batch_id' => absint( $batch_id ) ) );
	}

	/**
	 * Whether a string is a Y-m-d date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}
}
