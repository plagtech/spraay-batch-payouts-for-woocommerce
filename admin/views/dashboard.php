<?php
/**
 * Dashboard view.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array  $counts          Batches per status.
 * @var string $total_paid      Confirmed payout total.
 * @var int    $recipient_count Number of recipients.
 * @var array  $recent          Recent batch rows.
 * @var string $vendor_plugin   Detected vendor plugin slug.
 * @var array  $settings        Plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spraay_chain = Spraay_Plugin::get_chain( $settings['chain_id'] );
?>
<div class="wrap spraay-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Spraay Payouts', 'spraay-batch-payouts' ); ?></h1>
	<a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_NEW_BATCH ) ); ?>" class="page-title-action"><?php esc_html_e( 'New batch', 'spraay-batch-payouts' ); ?></a>
	<hr class="wp-header-end" />

	<p class="spraay-lede">
		<?php esc_html_e( 'Pay suppliers, vendors, affiliates and contractors in USDC on Base, in one transaction signed from your own wallet. Spraay never holds your funds.', 'spraay-batch-payouts' ); ?>
	</p>

	<div class="spraay-cards">
		<div class="spraay-card">
			<span class="spraay-card__label"><?php esc_html_e( 'Paid out (confirmed)', 'spraay-batch-payouts' ); ?></span>
			<span class="spraay-card__value"><?php echo esc_html( Spraay_Amount::format( $total_paid ) ); ?> <small>USDC</small></span>
		</div>
		<div class="spraay-card">
			<span class="spraay-card__label"><?php esc_html_e( 'Confirmed batches', 'spraay-batch-payouts' ); ?></span>
			<span class="spraay-card__value"><?php echo esc_html( number_format_i18n( $counts[ Spraay_Batch::STATUS_CONFIRMED ] ) ); ?></span>
		</div>
		<div class="spraay-card">
			<span class="spraay-card__label"><?php esc_html_e( 'Awaiting confirmation', 'spraay-batch-payouts' ); ?></span>
			<span class="spraay-card__value"><?php echo esc_html( number_format_i18n( $counts[ Spraay_Batch::STATUS_PENDING ] ) ); ?></span>
		</div>
		<div class="spraay-card">
			<span class="spraay-card__label"><?php esc_html_e( 'Recipients', 'spraay-batch-payouts' ); ?></span>
			<span class="spraay-card__value"><?php echo esc_html( number_format_i18n( $recipient_count ) ); ?></span>
		</div>
	</div>

	<div class="spraay-columns">
		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Recent batches', 'spraay-batch-payouts' ); ?></h2>
			<?php if ( ! $recent ) : ?>
				<p><?php esc_html_e( 'No batches yet.', 'spraay-batch-payouts' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Batch', 'spraay-batch-payouts' ); ?></th>
							<th><?php esc_html_e( 'Status', 'spraay-batch-payouts' ); ?></th>
							<th><?php esc_html_e( 'Payouts', 'spraay-batch-payouts' ); ?></th>
							<th><?php esc_html_e( 'Recipients', 'spraay-batch-payouts' ); ?></th>
							<th><?php esc_html_e( 'Created', 'spraay-batch-payouts' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $spraay_batch ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY, array( 'batch_id' => $spraay_batch['id'] ) ) ); ?>">
										<?php /* translators: %d: batch ID. */ echo esc_html( sprintf( __( 'Batch #%d', 'spraay-batch-payouts' ), $spraay_batch['id'] ) ); ?>
									</a>
								</td>
								<td><?php echo Spraay_Batches_List_Table::status_badge( $spraay_batch['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge(). ?></td>
								<td><?php echo esc_html( Spraay_Amount::format( $spraay_batch['total_amount'] ) ); ?> USDC</td>
								<td><?php echo esc_html( number_format_i18n( $spraay_batch['recipient_count'] ) ); ?></td>
								<td><?php echo esc_html( get_date_from_gmt( $spraay_batch['created_at'], get_option( 'date_format' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY ) ); ?>"><?php esc_html_e( 'View all batches →', 'spraay-batch-payouts' ); ?></a></p>
			<?php endif; ?>
		</div>

		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Configuration', 'spraay-batch-payouts' ); ?></h2>
			<table class="spraay-kv">
				<tr>
					<th><?php esc_html_e( 'Network', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( $spraay_chain ? $spraay_chain['name'] . ' (' . $settings['chain_id'] . ')' : (string) $settings['chain_id'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Token', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( $settings['token'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Gateway', 'spraay-batch-payouts' ); ?></th>
					<td><code><?php echo esc_html( $settings['gateway_url'] ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Spraay fee', 'spraay-batch-payouts' ); ?></th>
					<td>0.3%</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Vendor plugin', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( Spraay_WC_Integration::vendor_plugin_label( $vendor_plugin ) ); ?></td>
				</tr>
			</table>
			<p><a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_SETTINGS ) ); ?>"><?php esc_html_e( 'Edit settings →', 'spraay-batch-payouts' ); ?></a></p>
		</div>
	</div>
</div>
