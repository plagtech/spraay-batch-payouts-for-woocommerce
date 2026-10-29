<?php
/**
 * Single batch detail view.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array         $batch    Batch row.
 * @var array[]       $items    Batch items.
 * @var WP_User|false $creator  Creating user.
 * @var WP_User|null  $approver Approving user.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spraay_chain    = Spraay_Plugin::get_chain( $batch['chain_id'] );
$spraay_explorer = Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] );
$spraay_fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$spraay_grand    = Spraay_Amount::from_units( Spraay_Amount::to_units( $batch['total_amount'] ) + Spraay_Amount::to_units( $batch['fee_amount'] ) );
?>
<div class="wrap spraay-wrap">
	<h1 class="wp-heading-inline">
		<?php /* translators: %d: batch ID. */ echo esc_html( sprintf( __( 'Batch #%d', 'spraay-batch-payouts' ), $batch['id'] ) ); ?>
	</h1>
	<?php echo Spraay_Batches_List_Table::status_badge( $batch['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge(). ?>
	<hr class="wp-header-end" />

	<p><a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY ) ); ?>"><?php esc_html_e( '← All batches', 'spraay-batch-payouts' ); ?></a></p>

	<div class="spraay-columns">
		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Summary', 'spraay-batch-payouts' ); ?></h2>
			<table class="spraay-kv">
				<tr>
					<th><?php esc_html_e( 'Payouts', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( Spraay_Amount::format( $batch['total_amount'] ) ); ?> USDC</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Spraay fee', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( Spraay_Amount::format( $batch['fee_amount'] ) ); ?> USDC</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total', 'spraay-batch-payouts' ); ?></th>
					<td><strong><?php echo esc_html( Spraay_Amount::format( $spraay_grand ) ); ?> USDC</strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Recipients', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $batch['recipient_count'] ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Network', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo esc_html( ( $spraay_chain ? $spraay_chain['name'] : '' ) . ' (' . $batch['chain_id'] . ')' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Transaction', 'spraay-batch-payouts' ); ?></th>
					<td>
						<?php if ( $spraay_explorer ) : ?>
							<a href="<?php echo esc_url( $spraay_explorer ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $batch['tx_hash'] ); ?></code></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Gateway reference', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo $batch['gateway_batch_id'] ? '<code>' . esc_html( $batch['gateway_batch_id'] ) . '</code>' : '&mdash;'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Created', 'spraay-batch-payouts' ); ?></th>
					<td>
						<?php
						echo esc_html( get_date_from_gmt( $batch['created_at'], $spraay_fmt ) );
						if ( $creator ) {
							/* translators: %s: user display name. */
							echo ' ' . esc_html( sprintf( __( 'by %s', 'spraay-batch-payouts' ), $creator->display_name ) );
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Approved by', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo $approver ? esc_html( $approver->display_name ) : '&mdash;'; ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Confirmed', 'spraay-batch-payouts' ); ?></th>
					<td><?php echo $batch['confirmed_at'] ? esc_html( get_date_from_gmt( $batch['confirmed_at'], $spraay_fmt ) ) : '&mdash;'; ?></td>
				</tr>
			</table>

			<p class="spraay-actions">
				<?php if ( Spraay_Batch::STATUS_PENDING === $batch['status'] ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'spraay_refresh_batch', 'batch_id' => $batch['id'] ), admin_url( 'admin-post.php' ) ), 'spraay_refresh_batch_' . $batch['id'] ) ); ?>"><?php esc_html_e( 'Refresh status', 'spraay-batch-payouts' ); ?></a>
				<?php endif; ?>
				<?php if ( Spraay_Batch::is_editable( $batch ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_NEW_BATCH, array( 'batch_id' => $batch['id'] ) ) ); ?>"><?php esc_html_e( 'Continue in builder', 'spraay-batch-payouts' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	</div>

	<div class="spraay-panel">
		<h2><?php esc_html_e( 'Recipients', 'spraay-batch-payouts' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Recipient', 'spraay-batch-payouts' ); ?></th>
					<th><?php esc_html_e( 'Wallet', 'spraay-batch-payouts' ); ?></th>
					<th><?php esc_html_e( 'Orders', 'spraay-batch-payouts' ); ?></th>
					<th class="spraay-num"><?php esc_html_e( 'Amount', 'spraay-batch-payouts' ); ?></th>
					<th><?php esc_html_e( 'Status', 'spraay-batch-payouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $spraay_item ) : ?>
					<tr>
						<td><?php echo esc_html( $spraay_item['label'] ? $spraay_item['label'] : __( '(deleted recipient)', 'spraay-batch-payouts' ) ); ?></td>
						<td><code class="spraay-address"><?php echo esc_html( (string) $spraay_item['wallet_address'] ); ?></code></td>
						<td>
							<?php
							if ( $spraay_item['wc_order_ids'] ) {
								$spraay_links = array();
								foreach ( $spraay_item['wc_order_ids'] as $spraay_order_id ) {
									$spraay_order   = wc_get_order( $spraay_order_id );
									$spraay_links[] = $spraay_order
										? '<a href="' . esc_url( $spraay_order->get_edit_order_url() ) . '">#' . esc_html( $spraay_order->get_order_number() ) . '</a>'
										: '#' . esc_html( $spraay_order_id );
								}
								echo implode( ', ', $spraay_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td class="spraay-num"><?php echo esc_html( Spraay_Amount::format( $spraay_item['amount'] ) . ' ' . $spraay_item['currency'] ); ?></td>
						<td><?php echo Spraay_Batches_List_Table::status_badge( $spraay_item['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge(). ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
