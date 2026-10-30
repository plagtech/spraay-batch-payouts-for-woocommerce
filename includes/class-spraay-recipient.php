<?php
/**
 * Recipient records.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the spraay_recipients table.
 */
class Spraay_Recipient {

	/**
	 * Columns callers may sort by.
	 *
	 * @var string[]
	 */
	const SORTABLE = array( 'id', 'label', 'wallet_address', 'email', 'default_amount', 'created_at' );

	/**
	 * Get a recipient.
	 *
	 * @param int $id Recipient ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );
	}

	/**
	 * Get several recipients keyed by ID.
	 *
	 * @param int[] $ids Recipient IDs.
	 * @return array
	 */
	public static function get_many( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table        = Spraay_Plugin::table( 'recipients' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids ), ARRAY_A );
		return array_column( (array) $rows, null, 'id' );
	}

	/**
	 * Find a recipient by wallet address (case-insensitive).
	 *
	 * @param string $wallet Wallet address.
	 * @return array|null
	 */
	public static function find_by_wallet( $wallet ) {
		global $wpdb;
		$table = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE LOWER(wallet_address) = %s", strtolower( (string) $wallet ) ), ARRAY_A );
	}

	/**
	 * Find a recipient by exact label (case-insensitive).
	 *
	 * @param string $label Label.
	 * @return array|null
	 */
	public static function find_by_label( $label ) {
		global $wpdb;
		$table = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE LOWER(label) = %s ORDER BY id ASC LIMIT 1", strtolower( (string) $label ) ), ARRAY_A );
	}

	/**
	 * Find the recipient linked to a WooCommerce vendor/user ID.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array|null
	 */
	public static function find_by_vendor( $vendor_id ) {
		global $wpdb;
		$table = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_vendor_id = %d ORDER BY id ASC LIMIT 1", absint( $vendor_id ) ), ARRAY_A );
	}

	/**
	 * Paginated, searchable list.
	 *
	 * @param array $args {
	 *     @type string $search   Matches label, wallet or email.
	 *     @type string $orderby  Column from self::SORTABLE.
	 *     @type string $order    ASC|DESC.
	 *     @type int    $per_page Rows per page.
	 *     @type int    $page     1-based page.
	 * }
	 * @return array { items: array[], total: int }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'orderby'  => 'label',
				'order'    => 'ASC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$table   = Spraay_Plugin::table( 'recipients' );
		$orderby = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'label';
		$order   = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$limit   = max( 1, min( 500, absint( $args['per_page'] ) ) );
		$offset  = ( max( 1, absint( $args['page'] ) ) - 1 ) * $limit;

		$where  = '1=1';
		$params = array();
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (label LIKE %s OR wallet_address LIKE %s OR email LIKE %s)';
			$params   = array( $like, $like, $like );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby/$order are whitelisted.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Total number of recipients.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = Spraay_Plugin::table( 'recipients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Sanitize and validate raw recipient input.
	 *
	 * @param array $data Raw input.
	 * @return array|WP_Error Clean column values.
	 */
	public static function sanitize( array $data ) {
		$wallet = isset( $data['wallet_address'] ) ? trim( sanitize_text_field( $data['wallet_address'] ) ) : '';
		if ( ! Spraay_Address::is_valid( $wallet ) ) {
			return new WP_Error( 'spraay_invalid_wallet', __( 'Wallet address is not a valid EVM address (check the 0x prefix, length and checksum).', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( isset( $data['email'] ) && '' !== trim( $data['email'] ) && ! is_email( $email ) ) {
			return new WP_Error( 'spraay_invalid_email', __( 'Email address is not valid.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$amount = null;
		if ( isset( $data['default_amount'] ) && '' !== trim( (string) $data['default_amount'] ) ) {
			$amount = Spraay_Amount::normalize( sanitize_text_field( (string) $data['default_amount'] ) );
			if ( false === $amount ) {
				return new WP_Error( 'spraay_invalid_amount', __( 'Default amount must be a positive number with at most 6 decimal places.', 'spraay-batch-payouts-for-woocommerce' ) );
			}
		}

		$label = isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';

		return array(
			'label'          => '' !== $label ? $label : Spraay_Address::to_checksum( $wallet ),
			'wallet_address' => Spraay_Address::to_checksum( $wallet ),
			'email'          => $email,
			'wc_vendor_id'   => ! empty( $data['wc_vendor_id'] ) ? absint( $data['wc_vendor_id'] ) : null,
			'default_amount' => $amount,
			'currency'       => 'USDC',
			'notes'          => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
		);
	}

	/**
	 * Create a recipient.
	 *
	 * @param array $data Raw input.
	 * @return int|WP_Error New ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$clean = self::sanitize( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( self::find_by_wallet( $clean['wallet_address'] ) ) {
			return new WP_Error( 'spraay_duplicate_wallet', __( 'A recipient with this wallet address already exists.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$now                 = current_time( 'mysql', true );
		$clean['created_at'] = $now;
		$clean['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->insert( Spraay_Plugin::table( 'recipients' ), $clean ) ) {
			return new WP_Error( 'spraay_db_error', __( 'Could not save the recipient.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a recipient is created.
		 *
		 * @param int   $id    Recipient ID.
		 * @param array $clean Saved values.
		 */
		do_action( 'spraay_recipient_created', $id, $clean );

		return $id;
	}

	/**
	 * Update a recipient.
	 *
	 * @param int   $id   Recipient ID.
	 * @param array $data Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! self::get( $id ) ) {
			return new WP_Error( 'spraay_not_found', __( 'Recipient not found.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$clean = self::sanitize( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$existing = self::find_by_wallet( $clean['wallet_address'] );
		if ( $existing && (int) $existing['id'] !== $id ) {
			return new WP_Error( 'spraay_duplicate_wallet', __( 'Another recipient already uses this wallet address.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$clean['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( Spraay_Plugin::table( 'recipients' ), $clean, array( 'id' => $id ) ) ) {
			return new WP_Error( 'spraay_db_error', __( 'Could not update the recipient.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		do_action( 'spraay_recipient_updated', $id, $clean );

		return true;
	}

	/**
	 * Delete a recipient. Recipients that appear in a non-draft batch are kept
	 * so payout history stays intact.
	 *
	 * @param int $id Recipient ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id      = absint( $id );
		$items   = Spraay_Plugin::table( 'batch_items' );
		$batches = Spraay_Plugin::table( 'batches' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$in_use = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$items} i INNER JOIN {$batches} b ON b.id = i.batch_id WHERE i.recipient_id = %d AND b.status <> %s",
				$id,
				Spraay_Batch::STATUS_DRAFT
			)
		);
		if ( $in_use ) {
			return new WP_Error( 'spraay_recipient_in_use', __( 'This recipient is part of an estimated or executed batch and cannot be deleted.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		$drafts = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT batch_id FROM {$items} WHERE recipient_id = %d", $id ) );
		$wpdb->delete( $items, array( 'recipient_id' => $id ), array( '%d' ) );
		$deleted = $wpdb->delete( Spraay_Plugin::table( 'recipients' ), array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable

		if ( ! $deleted ) {
			return new WP_Error( 'spraay_not_found', __( 'Recipient not found.', 'spraay-batch-payouts-for-woocommerce' ) );
		}

		foreach ( $drafts as $batch_id ) {
			Spraay_Batch::recalculate( $batch_id );
		}

		do_action( 'spraay_recipient_deleted', $id );

		return true;
	}
}
