<?php
/**
 * Shown when the builder is opened for a batch that was already submitted.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array $batch Batch row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap spraay-wrap">
	<h1><?php /* translators: %d: batch ID. */ echo esc_html( sprintf( __( 'Batch #%d', 'spraay-batch-payouts' ), $batch['id'] ) ); ?></h1>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'This batch has been submitted for execution and can no longer be edited.', 'spraay-batch-payouts' ); ?></p>
	</div>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY, array( 'batch_id' => $batch['id'] ) ) ); ?>"><?php esc_html_e( 'View batch record', 'spraay-batch-payouts' ); ?></a>
		<a class="button" href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_NEW_BATCH ) ); ?>"><?php esc_html_e( 'Start a new batch', 'spraay-batch-payouts' ); ?></a>
	</p>
</div>
