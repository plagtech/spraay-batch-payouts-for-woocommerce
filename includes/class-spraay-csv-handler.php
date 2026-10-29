<?php
/**
 * CSV import and export.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads uploaded CSVs and streams CSV downloads.
 */
class Spraay_CSV_Handler {

	/**
	 * Largest accepted upload in bytes.
	 */
	const MAX_UPLOAD_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Largest number of data rows read from one file.
	 */
	const MAX_ROWS = 5000;

	/**
	 * Header aliases mapped to canonical column names.
	 *
	 * @var array
	 */
	private static $aliases = array(
		'wallet_address' => array( 'wallet_address', 'wallet', 'address', 'evm_address' ),
		'label'          => array( 'label', 'name', 'recipient' ),
		'amount'         => array( 'amount', 'default_amount', 'payout' ),
		'email'          => array( 'email', 'e-mail', 'email_address' ),
		'order_id'       => array( 'order_id', 'order', 'order_#', 'order_number' ),
		'vendor'         => array( 'vendor_name', 'vendor' ),
		'commission'     => array( 'total_commission_amount', 'total_commission', 'commission_amount', 'commission' ),
		'status'         => array( 'commission_status', 'status' ),
	);

	/**
	 * Validate an uploaded CSV from $_FILES.
	 *
	 * @param array $file A single $_FILES entry.
	 * @return string|WP_Error Temp file path.
	 */
	public static function validate_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'spraay_upload_failed', __( 'The file did not upload. Please try again.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'spraay_upload_invalid', __( 'Invalid upload.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error( 'spraay_upload_too_large', __( 'CSV files must be 2 MB or smaller.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'spraay_upload_type', __( 'Please upload a .csv file.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}
		return $file['tmp_name'];
	}

	/**
	 * Read a CSV with a header row into associative rows keyed by canonical
	 * column names.
	 *
	 * @param string $path File path.
	 * @return array[]|WP_Error Rows, each with a '_line' number.
	 */
	public static function read( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a validated upload.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'spraay_csv_unreadable', __( 'Could not read the CSV file.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'spraay_csv_empty', __( 'The CSV file is empty.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$columns = array();
		foreach ( $header as $index => $name ) {
			$name = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $name ) ) );
			$name = str_replace( ' ', '_', $name );
			foreach ( self::$aliases as $canonical => $names ) {
				if ( in_array( $name, $names, true ) && ! in_array( $canonical, $columns, true ) ) {
					$columns[ $index ] = $canonical;
					break;
				}
			}
		}

		$rows = array();
		$line = 1;
		while ( false !== ( $data = fgetcsv( $handle ) ) && count( $rows ) < self::MAX_ROWS ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( array( null ) === $data ) {
				continue; // Blank line.
			}
			$row = array( '_line' => $line );
			foreach ( $columns as $index => $canonical ) {
				$row[ $canonical ] = isset( $data[ $index ] ) ? trim( (string) $data[ $index ] ) : '';
			}
			$rows[] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Import recipients (label, wallet_address, amount, email). Existing
	 * wallets are updated with any non-empty values from the file.
	 *
	 * @param string $path CSV path.
	 * @return array|WP_Error { created, updated, errors: string[], rows: array[] }
	 *                        rows: { recipient_id, label, wallet_address, amount } for each valid line.
	 */
	public static function import_recipients( $path ) {
		$rows = self::read( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( $rows && ! array_key_exists( 'wallet_address', $rows[0] ) ) {
			return new WP_Error( 'spraay_csv_columns', __( 'The CSV needs a wallet_address column (plus optional label, amount, email).', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$result = array(
			'created' => 0,
			'updated' => 0,
			'errors'  => array(),
			'rows'    => array(),
		);

		foreach ( $rows as $row ) {
			$data = array(
				'wallet_address' => $row['wallet_address'],
				'label'          => isset( $row['label'] ) ? $row['label'] : '',
				'email'          => isset( $row['email'] ) ? $row['email'] : '',
				'default_amount' => isset( $row['amount'] ) ? $row['amount'] : '',
			);

			$existing = Spraay_Address::is_valid( $data['wallet_address'] ) ? Spraay_Recipient::find_by_wallet( $data['wallet_address'] ) : null;
			if ( $existing ) {
				$merged = array(
					'wallet_address' => $existing['wallet_address'],
					'label'          => '' !== $data['label'] ? $data['label'] : $existing['label'],
					'email'          => '' !== $data['email'] ? $data['email'] : $existing['email'],
					'default_amount' => '' !== $data['default_amount'] ? $data['default_amount'] : (string) $existing['default_amount'],
					'wc_vendor_id'   => $existing['wc_vendor_id'],
					'notes'          => $existing['notes'],
				);
				$saved  = Spraay_Recipient::update( $existing['id'], $merged );
				$id     = (int) $existing['id'];
				if ( ! is_wp_error( $saved ) ) {
					++$result['updated'];
				}
			} else {
				$saved = Spraay_Recipient::create( $data );
				$id    = is_wp_error( $saved ) ? 0 : (int) $saved;
				if ( $id ) {
					++$result['created'];
				}
			}

			if ( is_wp_error( $saved ) ) {
				/* translators: 1: CSV line number, 2: error message. */
				$result['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'spraay-batch-payouts' ), $row['_line'], $saved->get_error_message() );
				continue;
			}

			$recipient        = Spraay_Recipient::get( $id );
			$result['rows'][] = array(
				'recipient_id'   => $id,
				'label'          => $recipient['label'],
				'wallet_address' => $recipient['wallet_address'],
				'amount'         => '' !== $data['default_amount'] ? (string) Spraay_Amount::normalize( $data['default_amount'] ) : '',
			);
		}

		return $result;
	}

	/**
	 * Read a WooCommerce Product Vendors commission export and group unpaid
	 * commissions per vendor. Vendors are matched to recipients by label.
	 *
	 * @param string $path CSV path.
	 * @return array|WP_Error { rows: array[], errors: string[] } rows shaped like Spraay_WC_Integration obligations.
	 */
	public static function import_vendor_commissions( $path ) {
		$rows = self::read( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( $rows && ( ! array_key_exists( 'vendor', $rows[0] ) || ! array_key_exists( 'commission', $rows[0] ) ) ) {
			return new WP_Error( 'spraay_csv_columns', __( 'The commission export needs Vendor Name and Total Commission Amount columns.', 'spraay-batch-payouts' ), array( 'status' => 400 ) );
		}

		$settled = Spraay_WC_Integration::get_settled_order_ids();
		$grouped = array();
		$errors  = array();

		foreach ( $rows as $row ) {
			if ( isset( $row['status'] ) && 'paid' === strtolower( $row['status'] ) ) {
				continue;
			}
			$order_id = isset( $row['order_id'] ) ? absint( preg_replace( '/\D/', '', $row['order_id'] ) ) : 0;
			if ( $order_id && in_array( $order_id, $settled, true ) ) {
				continue;
			}
			$amount = Spraay_Amount::normalize( preg_replace( '/[^\d.]/', '', $row['commission'] ) );
			if ( false === $amount || '' === $row['vendor'] ) {
				/* translators: %d: CSV line number. */
				$errors[] = sprintf( __( 'Line %d: missing vendor or invalid commission amount.', 'spraay-batch-payouts' ), $row['_line'] );
				continue;
			}

			$key = strtolower( $row['vendor'] );
			if ( ! isset( $grouped[ $key ] ) ) {
				$recipient       = Spraay_Recipient::find_by_label( $row['vendor'] );
				$grouped[ $key ] = array(
					'key'            => 'vendor-name:' . $key,
					'label'          => $recipient ? $recipient['label'] : sanitize_text_field( $row['vendor'] ),
					'wallet_address' => $recipient ? $recipient['wallet_address'] : '',
					'vendor_id'      => 0,
					'recipient_id'   => $recipient ? (int) $recipient['id'] : 0,
					'units'          => 0,
					'wc_order_ids'   => array(),
				);
			}
			$grouped[ $key ]['units'] += Spraay_Amount::to_units( $amount );
			if ( $order_id ) {
				$grouped[ $key ]['wc_order_ids'][] = $order_id;
			}
		}

		$out = array();
		foreach ( $grouped as $row ) {
			$row['amount']       = Spraay_Amount::from_units( $row['units'] );
			$row['wc_order_ids'] = array_values( array_unique( $row['wc_order_ids'] ) );
			unset( $row['units'] );
			$out[] = $row;
		}

		return array(
			'rows'   => $out,
			'errors' => $errors,
		);
	}

	/**
	 * Stream all recipients as CSV and exit.
	 */
	public static function export_recipients() {
		$all  = Spraay_Recipient::query(
			array(
				'per_page' => 500,
				'page'     => 1,
			)
		);
		$rows = array();
		$page = 1;
		while ( $all['items'] ) {
			foreach ( $all['items'] as $r ) {
				$rows[] = array( $r['label'], $r['wallet_address'], (string) $r['default_amount'], $r['email'], (string) $r['wc_vendor_id'], $r['notes'] );
			}
			++$page;
			$all = Spraay_Recipient::query(
				array(
					'per_page' => 500,
					'page'     => $page,
				)
			);
		}

		self::send( 'spraay-recipients-' . gmdate( 'Y-m-d' ) . '.csv', array( 'label', 'wallet_address', 'amount', 'email', 'wc_vendor_id', 'notes' ), $rows );
	}

	/**
	 * Stream batch history as CSV (one line per batch item) and exit.
	 *
	 * @param array $filters Spraay_Batch::query() filters.
	 */
	public static function export_batches( array $filters ) {
		$filters['per_page'] = 0;
		$batches             = Spraay_Batch::query( $filters );
		$statuses            = Spraay_Batch::statuses();
		$rows                = array();

		foreach ( $batches['items'] as $batch ) {
			$explorer = Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] );
			foreach ( Spraay_Batch::get_items( $batch['id'] ) as $item ) {
				$rows[] = array(
					$batch['id'],
					$statuses[ $batch['status'] ],
					$batch['created_at'],
					(string) $batch['confirmed_at'],
					$batch['chain_id'],
					$item['label'],
					$item['wallet_address'],
					$item['amount'],
					$item['currency'],
					implode( ' ', $item['wc_order_ids'] ),
					$batch['total_amount'],
					$batch['fee_amount'],
					(string) $batch['tx_hash'],
					$explorer,
				);
			}
		}

		self::send(
			'spraay-batches-' . gmdate( 'Y-m-d' ) . '.csv',
			array( 'batch_id', 'status', 'created_at_utc', 'confirmed_at_utc', 'chain_id', 'recipient', 'wallet_address', 'amount', 'currency', 'wc_order_ids', 'batch_total', 'batch_fee', 'tx_hash', 'explorer_url' ),
			$rows
		);
	}

	/**
	 * Send a CSV download and exit.
	 *
	 * @param string  $filename File name.
	 * @param array   $header   Header row.
	 * @param array[] $rows     Data rows.
	 */
	private static function send( $filename, array $header, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'escape_cell' ), $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function escape_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
