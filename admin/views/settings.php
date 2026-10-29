<?php
/**
 * Settings view.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array $settings Current settings.
 * @var array $chains   Supported chains keyed by ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spraay_opt   = Spraay_Plugin::OPTION_SETTINGS;
$spraay_chain = isset( $chains[ $settings['chain_id'] ] ) ? $chains[ $settings['chain_id'] ] : reset( $chains );
?>
<div class="wrap spraay-wrap">
	<h1><?php esc_html_e( 'Spraay Payouts settings', 'spraay-batch-payouts' ); ?></h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php" class="spraay-form">
		<?php settings_fields( 'spraay_settings_group' ); ?>

		<h2 class="title"><?php esc_html_e( 'Gateway', 'spraay-batch-payouts' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="spraay-gateway-url"><?php esc_html_e( 'Gateway URL', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<input type="url" id="spraay-gateway-url" name="<?php echo esc_attr( $spraay_opt ); ?>[gateway_url]" class="regular-text code" value="<?php echo esc_attr( $settings['gateway_url'] ); ?>" required />
					<p class="description"><?php esc_html_e( 'Spraay REST gateway base URL. Must use https://. Default: https://gateway.spraay.app', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-api-key"><?php esc_html_e( 'API key', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<input type="password" id="spraay-api-key" name="<?php echo esc_attr( $spraay_opt ); ?>[api_key]" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $settings['api_key'] ? esc_attr__( 'Saved — leave blank to keep', 'spraay-batch-payouts' ) : ''; ?>" />
					<?php if ( $settings['api_key'] ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $spraay_opt ); ?>[api_key_clear]" value="1" /> <?php esc_html_e( 'Remove saved key', 'spraay-batch-payouts' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Only if your Spraay gateway requires one. This is a gateway API key, not a wallet key — never enter a private key or seed phrase anywhere in WordPress.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Payouts', 'spraay-batch-payouts' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="spraay-chain"><?php esc_html_e( 'Network', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<select id="spraay-chain" name="<?php echo esc_attr( $spraay_opt ); ?>[chain_id]">
						<?php foreach ( $chains as $spraay_id => $spraay_c ) : ?>
							<option value="<?php echo esc_attr( $spraay_id ); ?>" <?php selected( (int) $settings['chain_id'], (int) $spraay_id ); ?>><?php echo esc_html( $spraay_c['name'] . ' (' . $spraay_id . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Use Base for real payouts. Base Sepolia is for testing with testnet USDC.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spraay-token"><?php esc_html_e( 'Payout token', 'spraay-batch-payouts' ); ?></label></th>
				<td>
					<select id="spraay-token" name="<?php echo esc_attr( $spraay_opt ); ?>[token]">
						<?php foreach ( array_keys( $spraay_chain['tokens'] ) as $spraay_symbol ) : ?>
							<option value="<?php echo esc_attr( $spraay_symbol ); ?>" <?php selected( $settings['token'], $spraay_symbol ); ?>><?php echo esc_html( $spraay_symbol ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fee breakdown', 'spraay-batch-payouts' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $spraay_opt ); ?>[fee_display]" value="1" <?php checked( $settings['fee_display'] ); ?> /> <?php esc_html_e( 'Show the estimated 0.3% Spraay fee while building a batch', 'spraay-batch-payouts' ); ?></label>
					<p class="description"><?php esc_html_e( 'The review screen always shows the gateway fee before you sign.', 'spraay-batch-payouts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WooCommerce import', 'spraay-batch-payouts' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $spraay_opt ); ?>[auto_import_wc]" value="1" <?php checked( $settings['auto_import_wc'] ); ?> /> <?php esc_html_e( 'Load unpaid WooCommerce obligations automatically when starting a new batch', 'spraay-batch-payouts' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
