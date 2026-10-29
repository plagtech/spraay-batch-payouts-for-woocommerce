<?php
/**
 * Reads WooCommerce order and vendor data to surface payout obligations.
 *
 * No multi-vendor plugin is required. When one is detected its commission
 * data becomes an extra source; order metadata always works.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce data bridge.
 */
class Spraay_WC_Integration {

	/**
	 * Order meta key recording the confirmed Spraay batch that paid the order.
	 */
	const META_PAID_BATCH = '_spraay_paid_batch_id';

	/**
	 * Maximum orders scanned per obligations request.
	 */
	const ORDER_LIMIT = 500;

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'spraay_batch_confirmed', array( $this, 'mark_orders_paid' ) );
	}

	/**
	 * Detect the active multi-vendor plugin, if any.
	 *
	 * @return string Slug (product-vendors, dokan, wcfm, wc-vendors) or ''.
	 */
	public static function detect_vendor_plugin() {
		$detected = '';
		if ( class_exists( 'WC_Product_Vendors' ) ) {
			$detected = 'product-vendors';
		} elseif ( class_exists( 'WeDevs_Dokan' ) || function_exists( 'dokan' ) ) {
			$detected = 'dokan';
		} elseif ( class_exists( 'WCFMmp' ) ) {
			$detected = 'wcfm';
		} elseif ( class_exists( 'WC_Vendors' ) || class_exists( 'WCV_Vendors' ) ) {
			$detected = 'wc-vendors';
		}

		/**
		 * Filter the detected multi-vendor plugin slug.
		 *
		 * @param string $detected Slug or ''.
		 */
		return apply_filters( 'spraay_detected_vendor_plugin', $detected );
	}

	/**
	 * Human label for a vendor plugin slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function vendor_plugin_label( $slug ) {
		$labels = array(
			'product-vendors' => 'WooCommerce Product Vendors',
			'dokan'           => 'Dokan',
			'wcfm'            => 'WCFM Marketplace',
			'wc-vendors'      => 'WC Vendors',
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : __( 'None detected', 'spraay-batch-payouts' );
	}

	/**
	 * Obligation sources usable on this site.
	 *
	 * @return array slug => label
	 */
	public static function available_sources() {
		$sources = array(
			'order_meta' => __( 'Order supplier fields', 'spraay-batch-payouts' ),
		);

		$vendor = self::detect_vendor_plugin();
		if ( 'product-vendors' === $vendor && self::table_exists( 'wcpv_commissions' ) ) {
			$sources['product_vendors'] = __( 'Product Vendors unpaid commissions', 'spraay-batch-payouts' );
		}
		if ( 'dokan' === $vendor && self::table_exists( 'dokan_orders' ) ) {
			$sources['dokan'] = __( 'Dokan vendor earnings', 'spraay-batch-payouts' );
		}

		/**
		 * Filter obligation sources. Add a source here and handle it via the
		 * spraay_wc_obligations filter.
		 *
		 * @param array $sources slug => label.
		 */
		return apply_filters( 'spraay_wc_obligation_sources', $sources );
	}

	/**
	 * Order meta keys holding per-order supplier payout data.
	 *
	 * @return array { wallet, amount, label }
	 */
	public static function order_meta_keys() {
		/**
		 * Filter the order meta keys read for supplier payouts.
		 *
		 * @param array $keys { wallet, amount, label }.
		 */
		return apply_filters(
			'spraay_order_meta_keys',
			array(
				'wallet' => '_spraay_supplier_wallet',
				'amount' => '_spraay_supplier_amount',
				'label'  => '_spraay_supplier_label',
			)
		);
	}

	/**
	 * Payout obligations grouped per payee, excluding orders already in a
	 * pending or confirmed batch.
	 *
	 * @param string $source One of available_sources().
	 * @param array  $args   { date_from: Y-m-d, date_to: Y-m-d }.
	 * @return array[]|WP_Error Rows { key, label, wallet_address, vendor_id, amount, wc_order_ids, recipient_id }.
	 */
	public static function get_obligations( $source, array $args = array() ) {
		if ( ! isset( self::available_sources()[ $source ] ) ) {
			return new WP_Error( 'spraay_unknown_source', __( 'That data source is not available on this site.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'date_from' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'date_to'   => gmdate( 'Y-m-d' ),
			)
		);
		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args[ $key ] ) ) {
				return new WP_Error( 'spraay_invalid_date', __( 'Dates must be in YYYY-MM-DD format.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
			}
		}

		$settled = self::get_settled_order_ids();

		switch ( $source ) {
			case 'order_meta':
				$rows = self::obligations_from_order_meta( $args, $settled );
				break;
			case 'product_vendors':
				$rows = self::obligations_from_product_vendors( $args, $settled );
				break;
			case 'dokan':
				$rows = self::obligations_from_dokan( $args, $settled );
				break;
			default:
				$rows = array();
		}

		/**
		 * Filter payout obligations. Custom sources registered through
		 * spraay_wc_obligation_sources should populate rows here.
		 *
		 * @param array  $rows    Obligation rows.
		 * @param string $source  Source slug.
		 * @param array  $args    Date range.
		 * @param int[]  $settled Order IDs already paid or in flight.
		 */
		$rows = apply_filters( 'spraay_wc_obligations', $rows, $source, $args, $settled );

		return self::attach_recipients( $rows );
	}

	/**
	 * Obligations from per-order supplier meta on completed orders.
	 *
	 * @param array $args    Date range.
	 * @param int[] $settled Settled order IDs.
	 * @return array[]
	 */
	private static function obligations_from_order_meta( array $args, array $settled ) {
		$keys   = self::order_meta_keys();
		$orders = wc_get_orders(
			array(
				'status'         => array( 'wc-completed' ),
				'date_completed' => $args['date_from'] . '...' . $args['date_to'],
				'limit'          => self::ORDER_LIMIT,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$grouped = array();
		foreach ( $orders as $order ) {
			if ( in_array( $order->get_id(), $settled, true ) ) {
				continue;
			}
			$wallet = trim( (string) $order->get_meta( $keys['wallet'] ) );
			$amount = Spraay_Amount::normalize( (string) $order->get_meta( $keys['amount'] ) );
			if ( ! Spraay_Address::is_valid( $wallet ) || false === $amount ) {
				continue;
			}

			$key = strtolower( $wallet );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'key'            => 'wallet:' . $key,
					'label'          => sanitize_text_field( (string) $order->get_meta( $keys['label'] ) ),
					'wallet_address' => Spraay_Address::to_checksum( $wallet ),
					'vendor_id'      => 0,
					'units'          => 0,
					'wc_order_ids'   => array(),
				);
			}
			$grouped[ $key ]['units']         += Spraay_Amount::to_units( $amount );
			$grouped[ $key ]['wc_order_ids'][] = $order->get_id();
		}

		return self::finalize_units( $grouped );
	}

	/**
	 * Obligations from WooCommerce Product Vendors unpaid commissions.
	 *
	 * Product Vendors identifies vendors by taxonomy term ID, so link
	 * recipients using that term ID as their WooCommerce vendor ID.
	 *
	 * @param array $args    Date range.
	 * @param int[] $settled Settled order IDs.
	 * @return array[]
	 */
	private static function obligations_from_product_vendors( array $args, array $settled ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wcpv_commissions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vendor_id, vendor_name, order_id, total_commission_amount FROM {$table} WHERE commission_status = %s AND order_date BETWEEN %s AND %s",
				'unpaid',
				$args['date_from'] . ' 00:00:00',
				$args['date_to'] . ' 23:59:59'
			),
			ARRAY_A
		);

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$order_id = (int) $row['order_id'];
			$amount   = Spraay_Amount::normalize( number_format( (float) $row['total_commission_amount'], Spraay_Amount::DECIMALS, '.', '' ) );
			if ( in_array( $order_id, $settled, true ) || false === $amount ) {
				continue;
			}
			$vendor_id = (int) $row['vendor_id'];
			if ( ! isset( $grouped[ $vendor_id ] ) ) {
				$grouped[ $vendor_id ] = array(
					'key'            => 'vendor:' . $vendor_id,
					'label'          => sanitize_text_field( $row['vendor_name'] ),
					'wallet_address' => '',
					'vendor_id'      => $vendor_id,
					'units'          => 0,
					'wc_order_ids'   => array(),
				);
			}
			$grouped[ $vendor_id ]['units']         += Spraay_Amount::to_units( $amount );
			$grouped[ $vendor_id ]['wc_order_ids'][] = $order_id;
		}

		return self::finalize_units( $grouped );
	}

	/**
	 * Obligations from Dokan vendor net earnings on completed orders.
	 *
	 * @param array $args    Date range.
	 * @param int[] $settled Settled order IDs.
	 * @return array[]
	 */
	private static function obligations_from_dokan( array $args, array $settled ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dokan_orders';

		$order_ids = wc_get_orders(
			array(
				'status'         => array( 'wc-completed' ),
				'date_completed' => $args['date_from'] . '...' . $args['date_to'],
				'limit'          => self::ORDER_LIMIT,
				'return'         => 'ids',
			)
		);
		$order_ids = array_values( array_diff( array_map( 'intval', $order_ids ), $settled ) );
		if ( ! $order_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT seller_id, order_id, net_amount FROM {$table} WHERE order_id IN ({$placeholders})", $order_ids ), ARRAY_A );

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$seller_id = (int) $row['seller_id'];
			$amount    = Spraay_Amount::normalize( number_format( (float) $row['net_amount'], Spraay_Amount::DECIMALS, '.', '' ) );
			if ( false === $amount ) {
				continue;
			}
			if ( ! isset( $grouped[ $seller_id ] ) ) {
				$user                  = get_userdata( $seller_id );
				$grouped[ $seller_id ] = array(
					'key'            => 'vendor:' . $seller_id,
					'label'          => $user ? $user->display_name : '#' . $seller_id,
					'wallet_address' => '',
					'vendor_id'      => $seller_id,
					'units'          => 0,
					'wc_order_ids'   => array(),
				);
			}
			$grouped[ $seller_id ]['units']         += Spraay_Amount::to_units( $amount );
			$grouped[ $seller_id ]['wc_order_ids'][] = (int) $row['order_id'];
		}

		return self::finalize_units( $grouped );
	}

	/**
	 * Convert grouped unit totals to decimal amounts, dropping zero rows.
	 *
	 * @param array $grouped Rows with 'units'.
	 * @return array[]
	 */
	private static function finalize_units( array $grouped ) {
		$rows = array();
		foreach ( $grouped as $row ) {
			if ( $row['units'] <= 0 ) {
				continue;
			}
			$row['amount']       = Spraay_Amount::from_units( $row['units'] );
			$row['wc_order_ids'] = array_values( array_unique( $row['wc_order_ids'] ) );
			unset( $row['units'] );
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Match obligation rows to existing recipients by wallet, then vendor ID.
	 *
	 * @param array[] $rows Obligation rows.
	 * @return array[]
	 */
	private static function attach_recipients( array $rows ) {
		foreach ( $rows as &$row ) {
			$recipient = null;
			if ( ! empty( $row['wallet_address'] ) ) {
				$recipient = Spraay_Recipient::find_by_wallet( $row['wallet_address'] );
			}
			if ( ! $recipient && ! empty( $row['vendor_id'] ) ) {
				$recipient = Spraay_Recipient::find_by_vendor( $row['vendor_id'] );
			}
			$row['recipient_id'] = $recipient ? (int) $recipient['id'] : 0;
			if ( $recipient ) {
				$row['label']          = $recipient['label'];
				$row['wallet_address'] = $recipient['wallet_address'];
			}
		}
		return $rows;
	}

	/**
	 * Order IDs already included in a pending or confirmed batch.
	 *
	 * @param int $exclude_batch_id Batch to ignore (the one being edited).
	 * @return int[]
	 */
	public static function get_settled_order_ids( $exclude_batch_id = 0 ) {
		global $wpdb;
		$items   = Spraay_Plugin::table( 'batch_items' );
		$batches = Spraay_Plugin::table( 'batches' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lists = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.wc_order_ids FROM {$items} i INNER JOIN {$batches} b ON b.id = i.batch_id WHERE b.status IN (%s, %s) AND b.id <> %d AND i.wc_order_ids IS NOT NULL AND i.wc_order_ids <> ''",
				Spraay_Batch::STATUS_PENDING,
				Spraay_Batch::STATUS_CONFIRMED,
				absint( $exclude_batch_id )
			)
		);

		$ids = array();
		foreach ( (array) $lists as $json ) {
			$decoded = json_decode( (string) $json, true );
			if ( is_array( $decoded ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $decoded ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Order IDs in a batch that another pending/confirmed batch already covers.
	 *
	 * @param int $batch_id Batch ID.
	 * @return int[]
	 */
	public static function find_conflicting_orders( $batch_id ) {
		$settled = self::get_settled_order_ids( $batch_id );
		$mine    = array();
		foreach ( Spraay_Batch::get_items( $batch_id ) as $item ) {
			$mine = array_merge( $mine, $item['wc_order_ids'] );
		}
		return array_values( array_intersect( $mine, $settled ) );
	}

	/**
	 * Stamp orders in a confirmed batch with the batch ID and an order note.
	 *
	 * @param int $batch_id Batch ID.
	 */
	public function mark_orders_paid( $batch_id ) {
		$batch = Spraay_Batch::get( $batch_id );
		if ( ! $batch ) {
			return;
		}
		$url = Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] );

		foreach ( Spraay_Batch::get_items( $batch_id ) as $item ) {
			foreach ( $item['wc_order_ids'] as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				$order->update_meta_data( self::META_PAID_BATCH, absint( $batch_id ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1: payout amount, 2: recipient label, 3: batch ID, 4: explorer URL. */
						__( 'Spraay payout of %1$s USDC to %2$s confirmed in batch #%3$d. %4$s', 'spraay-batch-payouts' ),
						Spraay_Amount::format( $item['amount'] ),
						$item['label'],
						absint( $batch_id ),
						$url
					)
				);
				$order->save();
			}
		}
	}

	/**
	 * Whether a (prefixed) table exists.
	 *
	 * @param string $name Table name without prefix.
	 * @return bool
	 */
	private static function table_exists( $name ) {
		global $wpdb;
		$table = $wpdb->prefix . $name;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
