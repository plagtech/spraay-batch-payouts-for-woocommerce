<?php
/**
 * Add/edit recipient form.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array|null $recipient Recipient being edited, null when adding.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spraay_r = wp_parse_args(
	is_array( $recipient ) ? $recipient : array(),
	array(
		'id'             => 0,
		'label'          => '',
		'wallet_address' => '',
		'email'          => '',
		'wc_vendor_id'   => '',
		'default_amount' => '',
		'notes'          => '',
	)
);
?>
<div class="wrap spraay-wrap">
	<h1><?php echo $spraay_r['id'] ? esc_html__( 'Edit recipient', 'spraay-batch-payouts' ) : esc_html__( 'Add recipient', 'spraay-batch-payouts' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spraay-form">
		<input type="hidden" name="action" value="spraay_save_recipient" />
		<input type="hidden" name="recipient_id" value="<?php echo esc_attr( $spraay_r['id'] ); ?>" />
		<?php wp_nonce_field( 'spraay_save_recipient' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="spraay-label"><?php esc_html_e( 'Label', 'spraay-batch-payouts' ); ?></label></th>
				<td><input type="text" id="spraay-label" name="label" class="regular-text" value="<?php echo esc_attr( $spraay_r['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Supplier A', 'spraay-batch-payouts' ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-wallet"><?php esc_html_e( 'Wallet address', 'spraay-batch-payouts' ); ?></label> <span class="required">*</span></th>
				<td>
					<input type="text" id="spraay-wallet" name="wallet_address" class="regular-text code" required pattern="^0x[0-9a-fA-F]{40}$" maxlength="42" value="<?php echo esc_attr( $spraay_r['wallet_address'] ); ?>" placeholder="0x…" autocomplete="off" spellcheck="false" />
					<p class="description"><?php esc_html_e( 'An EVM address on Base. Mixed-case addresses must have a valid checksum. Double-check it: on-chain payments cannot be reversed.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-email"><?php esc_html_e( 'Email', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<input type="email" id="spraay-email" name="email" class="regular-text" value="<?php echo esc_attr( $spraay_r['email'] ); ?>" />
					<p class="description"><?php esc_html_e( 'For your reference only; never sent to the gateway.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-amount"><?php esc_html_e( 'Default amount (USDC)', 'spraay-batch-payouts' ); ?></label></th>
				<td><input type="text" inputmode="decimal" id="spraay-amount" name="default_amount" class="small-text" value="<?php echo esc_attr( null === $spraay_r['default_amount'] ? '' : $spraay_r['default_amount'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-vendor"><?php esc_html_e( 'WooCommerce vendor ID', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<input type="number" min="0" id="spraay-vendor" name="wc_vendor_id" class="small-text" value="<?php echo esc_attr( (string) $spraay_r['wc_vendor_id'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. The vendor user ID (Dokan, WC Vendors) or vendor term ID (Product Vendors) so imported commissions match this recipient.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-notes"><?php esc_html_e( 'Notes', 'spraay-batch-payouts' ); ?></label></th>
				<td><textarea id="spraay-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( (string) $spraay_r['notes'] ); ?></textarea></td>
			</tr>
		</table>

		<?php submit_button( $spraay_r['id'] ? __( 'Update recipient', 'spraay-batch-payouts' ) : __( 'Add recipient', 'spraay-batch-payouts' ) ); ?>
		<a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_RECIPIENTS ) ); ?>"><?php esc_html_e( 'Cancel', 'spraay-batch-payouts' ); ?></a>
	</form>
</div>
