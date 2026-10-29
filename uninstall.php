<?php
/**
 * Uninstall: remove plugin tables, options, transients and scheduled events.
 *
 * Payout history is deleted with the tables. To keep it, define
 * SPRAAY_KEEP_DATA_ON_UNINSTALL as true in wp-config.php before uninstalling.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'spraay_reconcile_pending' );

if ( defined( 'SPRAAY_KEEP_DATA_ON_UNINSTALL' ) && SPRAAY_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;

foreach ( array( 'spraay_batch_items', 'spraay_batches', 'spraay_recipients' ) as $spraay_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $spraay_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup of our own tables.
}

delete_option( 'spraay_settings' );
delete_option( 'spraay_db_version' );

// Per-user admin notices.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_spraay_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spraay_notice_' ) . '%'
	)
);
