<?php
/**
 * Batch history list table.
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
 * WP_List_Table for spraay_batches.
 */
class Spraay_Batches_List_Table extends WP_List_Table {

	/**
	 * Active filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'batch',
				'plural'   => 'batches',
				'ajax'     => false,
			)
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$this->filters = array(
			'status'    => isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '',
			'date_from' => isset( $_REQUEST['date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_from'] ) ) : '',
			'date_to'   => isset( $_REQUEST['date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date_to'] ) ) : '',
		);
		// phpcs:enable
	}

	/**
	 * Current filters (for the CSV export link).
	 *
	 * @return array
	 */
	public function get_filters() {
		return array_filter( $this->filters );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'              => __( 'Batch', 'spraay-batch-payouts' ),
			'status'          => __( 'Status', 'spraay-batch-payouts' ),
			'total_amount'    => __( 'Payouts', 'spraay-batch-payouts' ),
			'fee_amount'      => __( 'Spraay fee', 'spraay-batch-payouts' ),
			'recipient_count' => __( 'Recipients', 'spraay-batch-payouts' ),
			'tx_hash'         => __( 'Transaction', 'spraay-batch-payouts' ),
			'created_at'      => __( 'Created', 'spraay-batch-payouts' ),
			'confirmed_at'    => __( 'Confirmed', 'spraay-batch-payouts' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'id'              => array( 'id', true ),
			'total_amount'    => array( 'total_amount', true ),
			'recipient_count' => array( 'recipient_count', true ),
			'created_at'      => array( 'created_at', true ),
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = Spraay_Batch::count_by_status();
		$current = $this->filters['status'];
		$base    = Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY );

		$views = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'spraay-batch-payouts' ),
				array_sum( $counts )
			),
		);
		foreach ( Spraay_Batch::statuses() as $status => $label ) {
			if ( ! $counts[ $status ] ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}
		return $views;
	}

	/**
	 * Date range filter and export button above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$export_url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'spraay_export_batches' ), $this->get_filters() ), admin_url( 'admin-post.php' ) ), 'spraay_export_batches' );
		?>
		<div class="alignleft actions spraay-date-filter">
			<label for="spraay-date-from"><?php esc_html_e( 'From', 'spraay-batch-payouts' ); ?></label>
			<input type="date" id="spraay-date-from" name="date_from" value="<?php echo esc_attr( $this->filters['date_from'] ); ?>" />
			<label for="spraay-date-to"><?php esc_html_e( 'To', 'spraay-batch-payouts' ); ?></label>
			<input type="date" id="spraay-date-to" name="date_to" value="<?php echo esc_attr( $this->filters['date_to'] ); ?>" />
			<?php submit_button( __( 'Filter', 'spraay-batch-payouts' ), '', 'filter_action', false ); ?>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'spraay-batch-payouts' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
		$result = Spraay_Batch::query(
			array_merge(
				$this->filters,
				array(
					'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'created_at',
					'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc',
					'per_page' => $per_page,
					'page'     => $this->get_pagenum(),
				)
			)
		);
		// phpcs:enable

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'id' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Batch ID column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_id( $item ) {
		$view_url = Spraay_Admin::page_url( Spraay_Admin::PAGE_HISTORY, array( 'batch_id' => $item['id'] ) );
		$actions  = array(
			'view' => '<a href="' . esc_url( $view_url ) . '">' . esc_html__( 'View', 'spraay-batch-payouts' ) . '</a>',
		);

		if ( Spraay_Batch::is_editable( $item ) ) {
			$actions['edit']   = '<a href="' . esc_url( Spraay_Admin::page_url( Spraay_Admin::PAGE_NEW_BATCH, array( 'batch_id' => $item['id'] ) ) ) . '">' . esc_html__( 'Continue', 'spraay-batch-payouts' ) . '</a>';
			$delete_url        = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'spraay_delete_batch',
						'batch_id' => $item['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'spraay_delete_batch_' . $item['id']
			);
			$actions['delete'] = '<a href="' . esc_url( $delete_url ) . '" class="spraay-confirm" data-confirm="' . esc_attr__( 'Delete this unsubmitted batch?', 'spraay-batch-payouts' ) . '">' . esc_html__( 'Delete', 'spraay-batch-payouts' ) . '</a>';
		}

		/* translators: %d: batch ID. */
		return '<strong><a href="' . esc_url( $view_url ) . '">' . esc_html( sprintf( __( 'Batch #%d', 'spraay-batch-payouts' ), $item['id'] ) ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Status badge column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		return self::status_badge( $item['status'] );
	}

	/**
	 * Payout total column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_total_amount( $item ) {
		return esc_html( Spraay_Amount::format( $item['total_amount'] ) . ' USDC' );
	}

	/**
	 * Fee column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_fee_amount( $item ) {
		return Spraay_Amount::to_units( $item['fee_amount'] ) > 0 ? esc_html( Spraay_Amount::format( $item['fee_amount'] ) . ' USDC' ) : '&mdash;';
	}

	/**
	 * Transaction column with explorer link.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_tx_hash( $item ) {
		return self::tx_link( $item['tx_hash'], $item['chain_id'] );
	}

	/**
	 * Created column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * Confirmed column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_confirmed_at( $item ) {
		return $item['confirmed_at'] ? esc_html( get_date_from_gmt( $item['confirmed_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '&mdash;';
	}

	/**
	 * Fallback column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Empty-state message.
	 */
	public function no_items() {
		esc_html_e( 'No batches found.', 'spraay-batch-payouts' );
	}

	/**
	 * Status badge HTML.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_badge( $status ) {
		$labels = Spraay_Batch::statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="spraay-status spraay-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Shortened tx hash linked to the block explorer.
	 *
	 * @param string $tx_hash  Hash.
	 * @param int    $chain_id Chain ID.
	 * @return string
	 */
	public static function tx_link( $tx_hash, $chain_id ) {
		if ( empty( $tx_hash ) ) {
			return '&mdash;';
		}
		$short = substr( $tx_hash, 0, 10 ) . '…' . substr( $tx_hash, -6 );
		$url   = Spraay_Plugin::explorer_tx_url( $tx_hash, $chain_id );
		if ( ! $url ) {
			return '<code>' . esc_html( $short ) . '</code>';
		}
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $short ) . '</code></a>';
	}
}
