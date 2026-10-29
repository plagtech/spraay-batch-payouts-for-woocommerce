<?php
/**
 * Recipients list table.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for spraay_recipients.
 */
class Spraay_Recipients_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'recipient',
				'plural'   => 'recipients',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'             => '<input type="checkbox" />',
			'label'          => __( 'Label', 'spraay-batch-payouts' ),
			'wallet_address' => __( 'Wallet', 'spraay-batch-payouts' ),
			'email'          => __( 'Email', 'spraay-batch-payouts' ),
			'default_amount' => __( 'Default amount', 'spraay-batch-payouts' ),
			'wc_vendor_id'   => __( 'WC vendor', 'spraay-batch-payouts' ),
			'created_at'     => __( 'Added', 'spraay-batch-payouts' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'label'          => array( 'label', false ),
			'email'          => array( 'email', false ),
			'default_amount' => array( 'default_amount', false ),
			'created_at'     => array( 'created_at', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'spraay_bulk_delete' => __( 'Delete', 'spraay-batch-payouts' ) );
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
		$result = Spraay_Recipient::query(
			array(
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'label',
				'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'asc',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'label' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="recipient[]" value="' . esc_attr( $item['id'] ) . '" />';
	}

	/**
	 * Label column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_label( $item ) {
		$edit_url   = Spraay_Admin::page_url(
			Spraay_Admin::PAGE_RECIPIENTS,
			array(
				'action'       => 'edit',
				'recipient_id' => $item['id'],
			)
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'spraay_delete_recipient',
					'recipient_id' => $item['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'spraay_delete_recipient_' . $item['id']
		);

		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'spraay-batch-payouts' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" class="spraay-confirm" data-confirm="' . esc_attr__( 'Delete this recipient?', 'spraay-batch-payouts' ) . '">' . esc_html__( 'Delete', 'spraay-batch-payouts' ) . '</a>',
		);

		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item['label'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Wallet column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_wallet_address( $item ) {
		return '<code class="spraay-address" title="' . esc_attr( $item['wallet_address'] ) . '">' . esc_html( $item['wallet_address'] ) . '</code>';
	}

	/**
	 * Default amount column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_default_amount( $item ) {
		return null === $item['default_amount'] ? '&mdash;' : esc_html( Spraay_Amount::format( $item['default_amount'] ) . ' ' . $item['currency'] );
	}

	/**
	 * Vendor column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_wc_vendor_id( $item ) {
		// Shown as a raw ID: depending on the vendor plugin it is a user ID or a term ID.
		return empty( $item['wc_vendor_id'] ) ? '&mdash;' : esc_html( '#' . $item['wc_vendor_id'] );
	}

	/**
	 * Created column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) ) );
	}

	/**
	 * Fallback column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) && '' !== $item[ $column_name ] ? esc_html( $item[ $column_name ] ) : '&mdash;';
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No recipients yet. Add one or import a CSV.', 'spraay-batch-payouts' );
	}
}
