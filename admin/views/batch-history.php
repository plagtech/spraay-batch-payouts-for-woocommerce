<?php
/**
 * Batch history view.
 *
 * @package Spraay_Batch_Payouts
 *
 * @var Spraay_Batches_List_Table $table List table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap spraay-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Batch history', 'spraay-batch-payouts' ); ?></h1>
	<a href="<?php echo esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_NEW_BATCH ) ); ?>" class="page-title-action"><?php esc_html_e( 'New batch', 'spraay-batch-payouts' ); ?></a>
	<hr class="wp-header-end" />

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Spraay_Admin::PAGE_HISTORY ); ?>" />
		<?php
		$spraay_filters = $table->get_filters();
		if ( isset( $spraay_filters['status'] ) ) :
			?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $spraay_filters['status'] ); ?>" />
		<?php endif; ?>
		<?php $table->display(); ?>
	</form>
</div>
