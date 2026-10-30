<?php
/**
 * Batch builder view. Markup only; behaviour lives in admin/js/spraay-admin.js.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var array|null $batch    Batch being edited.
 * @var array      $settings Plugin settings.
 * @var array|null $chain    Active chain config.
 * @var array      $sources  Available WooCommerce obligation sources.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spraay_chain_name = $chain ? $chain['name'] : __( 'Unsupported network', 'spraay-batch-payouts-for-woocommerce' );
?>
<div class="wrap spraay-wrap" id="spraay-builder">
	<h1>
		<?php
		if ( $batch ) {
			/* translators: %d: batch ID. */
			echo esc_html( sprintf( __( 'Batch #%d', 'spraay-batch-payouts-for-woocommerce' ), $batch['id'] ) );
		} else {
			esc_html_e( 'New payout batch', 'spraay-batch-payouts-for-woocommerce' );
		}
		?>
	</h1>

	<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The batch builder requires JavaScript.', 'spraay-batch-payouts-for-woocommerce' ); ?></p></div></noscript>

	<div class="spraay-notice-area" data-spraay="notices" aria-live="polite"></div>

	<ol class="spraay-steps" data-spraay="steps">
		<li class="is-active" data-step="build"><?php esc_html_e( 'Build', 'spraay-batch-payouts-for-woocommerce' ); ?></li>
		<li data-step="review"><?php esc_html_e( 'Review & approve', 'spraay-batch-payouts-for-woocommerce' ); ?></li>
		<li data-step="submit"><?php esc_html_e( 'Sign & submit', 'spraay-batch-payouts-for-woocommerce' ); ?></li>
	</ol>

	<!-- Step 1: build -->
	<section class="spraay-step" data-spraay-step="build">
		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Add recipients', 'spraay-batch-payouts-for-woocommerce' ); ?></h2>
			<div class="spraay-picker">
				<label class="screen-reader-text" for="spraay-recipient-search"><?php esc_html_e( 'Search recipients', 'spraay-batch-payouts-for-woocommerce' ); ?></label>
				<input type="search" id="spraay-recipient-search" class="regular-text" data-spraay="search" placeholder="<?php esc_attr_e( 'Search saved recipients by label, wallet or email…', 'spraay-batch-payouts-for-woocommerce' ); ?>" autocomplete="off" />
				<ul class="spraay-picker__results" data-spraay="search-results" role="listbox" hidden></ul>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the recipients page. */
					esc_html__( 'Only saved recipients can be paid. %s', 'spraay-batch-payouts-for-woocommerce' ),
					'<a href="' . esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_RECIPIENTS, array( 'action' => 'new' ) ) ) . '" target="_blank">' . esc_html__( 'Add a recipient', 'spraay-batch-payouts-for-woocommerce' ) . '</a>'
				);
				?>
			</p>

			<details class="spraay-import">
				<summary><?php esc_html_e( 'Import from WooCommerce', 'spraay-batch-payouts-for-woocommerce' ); ?></summary>
				<div class="spraay-inline-form">
					<label>
						<?php esc_html_e( 'Source', 'spraay-batch-payouts-for-woocommerce' ); ?>
						<select data-spraay="wc-source">
							<?php foreach ( $sources as $spraay_slug => $spraay_label ) : ?>
								<option value="<?php echo esc_attr( $spraay_slug ); ?>"><?php echo esc_html( $spraay_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Completed from', 'spraay-batch-payouts-for-woocommerce' ); ?>
						<input type="date" data-spraay="wc-from" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>" />
					</label>
					<label>
						<?php esc_html_e( 'to', 'spraay-batch-payouts-for-woocommerce' ); ?>
						<input type="date" data-spraay="wc-to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
					</label>
					<button type="button" class="button" data-spraay="wc-load"><?php esc_html_e( 'Load obligations', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
				</div>
				<p class="description">
					<?php
					$spraay_keys = Spraay_WC_Integration::order_meta_keys();
					printf(
						/* translators: 1: wallet meta key, 2: amount meta key. */
						esc_html__( '"Order supplier fields" reads the %1$s and %2$s meta on completed orders. Orders already in a pending or confirmed batch are skipped.', 'spraay-batch-payouts-for-woocommerce' ),
						'<code>' . esc_html( $spraay_keys['wallet'] ) . '</code>',
						'<code>' . esc_html( $spraay_keys['amount'] ) . '</code>'
					);
					?>
				</p>
			</details>

			<details class="spraay-import">
				<summary><?php esc_html_e( 'Import from CSV', 'spraay-batch-payouts-for-woocommerce' ); ?></summary>
				<div class="spraay-inline-form">
					<label>
						<?php esc_html_e( 'Format', 'spraay-batch-payouts-for-woocommerce' ); ?>
						<select data-spraay="csv-type">
							<option value="recipients"><?php esc_html_e( 'Recipients + amounts (label, wallet_address, amount, email)', 'spraay-batch-payouts-for-woocommerce' ); ?></option>
							<option value="commissions"><?php esc_html_e( 'Product Vendors commission export', 'spraay-batch-payouts-for-woocommerce' ); ?></option>
						</select>
					</label>
					<input type="file" accept=".csv,text/csv" data-spraay="csv-file" />
					<button type="button" class="button" data-spraay="csv-load"><?php esc_html_e( 'Import', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'New wallets in a recipients CSV are saved as recipients. Commission exports are matched to recipients by vendor name.', 'spraay-batch-payouts-for-woocommerce' ); ?></p>
			</details>
		</div>

		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Payouts', 'spraay-batch-payouts-for-woocommerce' ); ?></h2>
			<table class="widefat spraay-rows">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Recipient', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Wallet', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th class="spraay-num"><?php esc_html_e( 'Amount (USDC)', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'spraay-batch-payouts-for-woocommerce' ); ?></span></th>
					</tr>
				</thead>
				<tbody data-spraay="rows">
					<tr class="spraay-empty"><td colspan="5"><?php esc_html_e( 'No recipients added yet.', 'spraay-batch-payouts-for-woocommerce' ); ?></td></tr>
				</tbody>
			</table>
			<div data-spraay="unmatched"></div>

			<dl class="spraay-totals">
				<dt><?php esc_html_e( 'Recipients', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd data-spraay="count">0</dd>
				<dt><?php esc_html_e( 'Payouts', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd data-spraay="subtotal">0.00</dd>
				<?php if ( $settings['fee_display'] ) : ?>
					<dt><?php esc_html_e( 'Spraay fee (0.3%, estimated)', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
					<dd data-spraay="fee">0.00</dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Network', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd><?php echo esc_html( $spraay_chain_name . ' · ' . $settings['token'] ); ?></dd>
			</dl>

			<p class="spraay-actions">
				<button type="button" class="button" data-spraay="save"><?php esc_html_e( 'Save draft', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
				<button type="button" class="button button-primary" data-spraay="estimate"><?php esc_html_e( 'Save & get estimate', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
			</p>
		</div>
	</section>

	<!-- Step 2: review & approve -->
	<section class="spraay-step" data-spraay-step="review" hidden>
		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Review', 'spraay-batch-payouts-for-woocommerce' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Recipient', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Wallet', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
						<th class="spraay-num"><?php esc_html_e( 'Amount (USDC)', 'spraay-batch-payouts-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody data-spraay="review-rows"></tbody>
			</table>

			<dl class="spraay-totals spraay-totals--review">
				<dt><?php esc_html_e( 'Payouts', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd data-spraay="review-subtotal"></dd>
				<dt><?php esc_html_e( 'Spraay fee (0.3%)', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd data-spraay="review-fee"></dd>
				<dt class="spraay-totals__grand"><?php esc_html_e( 'Total debited from your wallet', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd class="spraay-totals__grand" data-spraay="review-total"></dd>
				<dt><?php esc_html_e( 'Network', 'spraay-batch-payouts-for-woocommerce' ); ?></dt>
				<dd data-spraay="review-chain"></dd>
			</dl>

			<p>
				<label>
					<input type="checkbox" data-spraay="confirm" />
					<?php esc_html_e( 'I have checked every wallet address and amount. On-chain payments cannot be reversed.', 'spraay-batch-payouts-for-woocommerce' ); ?>
				</label>
			</p>
		</div>

		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Wallet', 'spraay-batch-payouts-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'You sign with your own browser wallet (MetaMask, Coinbase Wallet, etc.). Your keys never leave the wallet and are never sent to this site or to Spraay.', 'spraay-batch-payouts-for-woocommerce' ); ?></p>
			<div class="spraay-wallet" data-spraay="wallet-status">
				<span data-spraay="wallet-address"><?php esc_html_e( 'Not connected', 'spraay-batch-payouts-for-woocommerce' ); ?></span>
				<span data-spraay="wallet-balance"></span>
			</div>
			<p class="spraay-actions">
				<button type="button" class="button" data-spraay="back"><?php esc_html_e( '← Edit batch', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
				<button type="button" class="button" data-spraay="connect"><?php esc_html_e( 'Connect wallet', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
				<button type="button" class="button button-primary" data-spraay="sign" disabled><?php esc_html_e( 'Approve & sign', 'spraay-batch-payouts-for-woocommerce' ); ?></button>
			</p>
		</div>
	</section>

	<!-- Step 3: submit / track -->
	<section class="spraay-step" data-spraay-step="submit" hidden>
		<div class="spraay-panel">
			<h2><?php esc_html_e( 'Submission', 'spraay-batch-payouts-for-woocommerce' ); ?></h2>
			<ol class="spraay-log" data-spraay="log"></ol>
			<p data-spraay="tx"></p>
			<p class="spraay-actions">
				<a class="button" data-spraay="history-link" href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY ) ); ?>"><?php esc_html_e( 'View batch record', 'spraay-batch-payouts-for-woocommerce' ); ?></a>
			</p>
		</div>
	</section>
</div>
