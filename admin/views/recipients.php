<?php
/**
 * Recipients view.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var string                            $action    '', 'new' or 'edit'.
 * @var array|null                        $recipient Recipient being edited.
 * @var Spraay_Recipients_List_Table|null $table     List table (list mode).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'new' === $action || 'edit' === $action ) {
	include __DIR__ . '/partials/recipient-form.php';
	return;
}

$spraay_export_url = wp_nonce_url( add_query_arg( 'action', 'spraay_export_recipients', admin_url( 'admin-post.php' ) ), 'spraay_export_recipients' );
?>
<div class="wrap spraay-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Recipients', 'spraay-batch-payouts-for-woocommerce' ); ?></h1>
	<a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_RECIPIENTS, array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add recipient', 'spraay-batch-payouts-for-woocommerce' ); ?></a>
	<a href="<?php echo esc_url( $spraay_export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'spraay-batch-payouts-for-woocommerce' ); ?></a>
	<hr class="wp-header-end" />

	<details class="spraay-panel spraay-import">
		<summary><?php esc_html_e( 'Import recipients from CSV', 'spraay-batch-payouts-for-woocommerce' ); ?></summary>
		<p>
			<?php esc_html_e( 'Columns: wallet_address (required), label, amount, email. A header row is required. Existing wallets are updated rather than duplicated.', 'spraay-batch-payouts-for-woocommerce' ); ?>
		</p>
		<pre class="spraay-sample">label,wallet_address,amount,email
Supplier A,0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed,250.00,ap@supplier-a.example</pre>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="spraay_import_recipients" />
			<?php wp_nonce_field( 'spraay_import_recipients' ); ?>
			<input type="file" name="spraay_csv" accept=".csv,text/csv" required />
			<?php submit_button( __( 'Import', 'spraay-batch-payouts-for-woocommerce' ), 'secondary', 'submit', false ); ?>
		</form>
	</details>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Spraay_Admin::PAGE_RECIPIENTS ); ?>" />
		<?php $table->search_box( __( 'Search recipients', 'spraay-batch-payouts-for-woocommerce' ), 'spraay-recipient' ); ?>
	</form>

	<form method="post">
		<?php $table->display(); ?>
	</form>
</div>
