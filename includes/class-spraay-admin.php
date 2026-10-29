<?php
/**
 * Admin menu, pages, settings and form handlers.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI controller.
 */
class Spraay_Admin {

	/**
	 * Capability required for every plugin screen and action.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * ethers.js version loaded from cdnjs.
	 */
	const ETHERS_VERSION = '6.13.5';

	/**
	 * Subresource-integrity hash of the cdnjs ethers.umd.min.js build above.
	 */
	const ETHERS_SRI = 'sha384-NRAZj94DQk3dgtsOZzVYHbYVV1DFkF5QhL5RRxF0ILZLi6OQ7CsMlun748D42JbO';

	/**
	 * Page slugs.
	 */
	const PAGE_DASHBOARD  = 'spraay-payouts';
	const PAGE_RECIPIENTS = 'spraay-recipients';
	const PAGE_NEW_BATCH  = 'spraay-new-batch';
	const PAGE_HISTORY    = 'spraay-batch-history';
	const PAGE_SETTINGS   = 'spraay-settings';

	/**
	 * Hook suffixes of plugin pages, keyed by slug.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_flash_notice' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_ethers_sri' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( SPRAAY_BP_FILE ), array( $this, 'plugin_action_links' ) );

		$actions = array(
			'spraay_save_recipient'    => 'handle_save_recipient',
			'spraay_delete_recipient'  => 'handle_delete_recipient',
			'spraay_import_recipients' => 'handle_import_recipients',
			'spraay_export_recipients' => 'handle_export_recipients',
			'spraay_export_batches'    => 'handle_export_batches',
			'spraay_refresh_batch'     => 'handle_refresh_batch',
			'spraay_delete_batch'      => 'handle_delete_batch',
		);
		foreach ( $actions as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Top-level "Spraay Payouts" menu placed directly below WooCommerce.
	 */
	public function register_menu() {
		$this->hooks[ self::PAGE_DASHBOARD ] = add_menu_page(
			__( 'Spraay Payouts', 'spraay-batch-payouts' ),
			__( 'Spraay Payouts', 'spraay-batch-payouts' ),
			self::CAPABILITY,
			self::PAGE_DASHBOARD,
			array( $this, 'render_dashboard' ),
			$this->menu_icon(),
			'55.6' // WooCommerce sits at 55.5.
		);

		$pages = array(
			self::PAGE_DASHBOARD  => array( __( 'Dashboard', 'spraay-batch-payouts' ), 'render_dashboard' ),
			self::PAGE_RECIPIENTS => array( __( 'Recipients', 'spraay-batch-payouts' ), 'render_recipients' ),
			self::PAGE_NEW_BATCH  => array( __( 'New Batch', 'spraay-batch-payouts' ), 'render_new_batch' ),
			self::PAGE_HISTORY    => array( __( 'Batch History', 'spraay-batch-payouts' ), 'render_history' ),
			self::PAGE_SETTINGS   => array( __( 'Settings', 'spraay-batch-payouts' ), 'render_settings' ),
		);
		foreach ( $pages as $slug => $page ) {
			$this->hooks[ $slug ] = add_submenu_page( self::PAGE_DASHBOARD, $page[0], $page[0], self::CAPABILITY, $slug, array( $this, $page[1] ) );
		}

		// List-table bulk actions post back to the page itself (their "action"
		// field would clash with admin-post.php routing), so handle them before output.
		add_action( 'load-' . $this->hooks[ self::PAGE_RECIPIENTS ], array( $this, 'handle_bulk_recipients' ) );
	}

	/**
	 * Menu icon as an SVG data URI (WordPress recolors it to match the admin scheme).
	 *
	 * @return string
	 */
	private function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5c-.3 0-.5.1-.7.4C7.6 4.3 4.5 8.6 4.5 11.8 4.5 15 7 17.5 10 17.5s5.5-2.5 5.5-5.7c0-3.2-3.1-7.5-4.8-9.9-.2-.3-.4-.4-.7-.4zm-2.6 10.6c.4 0 .7.3.7.7 0 1 .8 1.8 1.8 1.8.4 0 .7.3.7.7s-.3.7-.7.7c-1.8 0-3.2-1.4-3.2-3.2 0-.4.3-.7.7-.7zM2.2 4.5a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4zm15.6 0a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4zM3.5 1a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8zm13 0a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url( self::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'spraay-batch-payouts' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of a plugin admin page.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function page_url( $slug, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Enqueue CSS/JS on plugin pages only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		$slug = array_search( $hook_suffix, $this->hooks, true );
		if ( false === $slug ) {
			return;
		}

		wp_enqueue_style( 'spraay-admin', SPRAAY_BP_URL . 'admin/css/spraay-admin.css', array(), SPRAAY_BP_VERSION );

		if ( self::PAGE_NEW_BATCH !== $slug ) {
			// Only the delete-confirmation helper is needed here.
			wp_enqueue_script( 'spraay-admin', SPRAAY_BP_URL . 'admin/js/spraay-admin.js', array(), SPRAAY_BP_VERSION, true );
			return;
		}

		/**
		 * Filter the ethers.js v6 UMD script URL. Point this at a locally
		 * hosted copy to avoid loading from the CDN.
		 *
		 * @param string $url Script URL.
		 */
		$ethers_url = apply_filters( 'spraay_ethers_script_url', 'https://cdnjs.cloudflare.com/ajax/libs/ethers/' . self::ETHERS_VERSION . '/ethers.umd.min.js' );

		wp_enqueue_script( 'spraay-ethers', $ethers_url, array(), self::ETHERS_VERSION, true );
		wp_enqueue_script( 'spraay-wallet', SPRAAY_BP_URL . 'admin/js/spraay-wallet.js', array( 'spraay-ethers' ), SPRAAY_BP_VERSION, true );
		wp_enqueue_script( 'spraay-admin', SPRAAY_BP_URL . 'admin/js/spraay-admin.js', array( 'spraay-wallet', 'wp-api-fetch', 'wp-i18n' ), SPRAAY_BP_VERSION, true );
		wp_set_script_translations( 'spraay-admin', 'spraay-batch-payouts', SPRAAY_BP_PATH . 'languages' );

		wp_add_inline_script( 'spraay-admin', 'window.spraayConfig = ' . wp_json_encode( $this->builder_config() ) . ';', 'before' );
	}

	/**
	 * Configuration passed to the batch builder JS.
	 *
	 * @return array
	 */
	private function builder_config() {
		$settings = Spraay_Plugin::get_settings();
		$chain    = Spraay_Plugin::get_chain( $settings['chain_id'] );
		$token    = $chain && isset( $chain['tokens'][ $settings['token'] ] ) ? $chain['tokens'][ $settings['token'] ] : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page parameter.
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		$batch    = $batch_id ? Spraay_Batch::get( $batch_id ) : null;

		return array(
			'restPath'    => '/' . Spraay_Rest_Api::NAMESPACE_V1,
			'historyUrl'  => self::page_url( self::PAGE_HISTORY ),
			'builderUrl'  => self::page_url( self::PAGE_NEW_BATCH ),
			'chain'       => array(
				'id'       => (int) $settings['chain_id'],
				'name'     => $chain ? $chain['name'] : '',
				'rpcUrl'   => $chain ? $chain['rpc_url'] : '',
				'explorer' => $chain ? $chain['explorer'] : '',
				'contract' => $chain ? $chain['spraay_contract'] : '',
			),
			'token'       => array(
				'symbol'   => $settings['token'],
				'address'  => $token ? $token['address'] : '',
				'decimals' => $token ? (int) $token['decimals'] : 6,
			),
			'feeBps'      => Spraay_Amount::FEE_BPS,
			'approvalCeilingBps' => 200, // Reject approvals above confirmed payouts + 2%.
			'feeDisplay'  => (bool) $settings['fee_display'],
			'autoImport'  => (bool) $settings['auto_import_wc'],
			'sources'     => Spraay_WC_Integration::available_sources(),
			'maxItems'    => Spraay_Batch::MAX_RECIPIENTS,
			'batchId'     => $batch ? (int) $batch['id'] : 0,
			'batchStatus' => $batch ? $batch['status'] : '',
		);
	}

	/**
	 * Add SRI attributes to the default CDN ethers script tag.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function add_ethers_sri( $tag, $handle, $src ) {
		if ( 'spraay-ethers' !== $handle || false === strpos( $src, 'cdnjs.cloudflare.com/ajax/libs/ethers/' . self::ETHERS_VERSION . '/' ) ) {
			return $tag;
		}
		return str_replace( ' src=', ' integrity="' . esc_attr( self::ETHERS_SRI ) . '" crossorigin="anonymous" referrerpolicy="no-referrer" src=', $tag );
	}

	/**
	 * Register the settings option.
	 */
	public function register_settings() {
		register_setting(
			'spraay_settings_group',
			Spraay_Plugin::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Spraay_Plugin::default_settings(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$current = Spraay_Plugin::get_settings();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		$url = isset( $input['gateway_url'] ) ? esc_url_raw( trim( $input['gateway_url'] ), array( 'https' ) ) : '';
		if ( '' !== $url ) {
			$clean['gateway_url'] = untrailingslashit( $url );
		} else {
			add_settings_error( Spraay_Plugin::OPTION_SETTINGS, 'gateway_url', __( 'The gateway URL must be a valid https:// URL. The previous value was kept.', 'spraay-batch-payouts' ) );
		}

		$chain_id = isset( $input['chain_id'] ) ? absint( $input['chain_id'] ) : 0;
		$chain    = Spraay_Plugin::get_chain( $chain_id );
		if ( $chain ) {
			$clean['chain_id'] = $chain_id;
		}

		$token          = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '';
		$active_chain   = Spraay_Plugin::get_chain( $clean['chain_id'] );
		$clean['token'] = isset( $active_chain['tokens'][ $token ] ) ? $token : 'USDC';

		$clean['fee_display']    = ! empty( $input['fee_display'] );
		$clean['auto_import_wc'] = ! empty( $input['auto_import_wc'] );

		// A blank key field keeps the saved key unless "clear" is ticked.
		if ( ! empty( $input['api_key_clear'] ) ) {
			$clean['api_key'] = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$clean['api_key'] = sanitize_text_field( trim( $input['api_key'] ) );
		}

		return $clean;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Page renderers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Dashboard page.
	 */
	public function render_dashboard() {
		$this->assert_capability();
		$this->view(
			'dashboard',
			array(
				'counts'          => Spraay_Batch::count_by_status(),
				'total_paid'      => Spraay_Batch::total_paid(),
				'recipient_count' => Spraay_Recipient::count(),
				'recent'          => Spraay_Batch::query( array( 'per_page' => 5 ) )['items'],
				'vendor_plugin'   => Spraay_WC_Integration::detect_vendor_plugin(),
				'settings'        => Spraay_Plugin::get_settings(),
			)
		);
	}

	/**
	 * Recipients page: list, add/edit form, CSV import/export.
	 */
	public function render_recipients() {
		$this->assert_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$action    = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$edit_id   = isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0;
		// phpcs:enable
		$recipient = $edit_id ? Spraay_Recipient::get( $edit_id ) : null;

		$table = null;
		if ( 'edit' !== $action && 'new' !== $action ) {
			$table = new Spraay_Recipients_List_Table();
			$table->prepare_items();
		}

		$this->view(
			'recipients',
			array(
				'action'    => $action,
				'recipient' => $recipient,
				'table'     => $table,
			)
		);
	}

	/**
	 * Batch builder page. The interactive UI is rendered by spraay-admin.js.
	 */
	public function render_new_batch() {
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		$batch    = $batch_id ? Spraay_Batch::get( $batch_id ) : null;

		if ( $batch && ! Spraay_Batch::is_editable( $batch ) ) {
			$this->view( 'batch-locked', array( 'batch' => $batch ) );
			return;
		}

		$settings = Spraay_Plugin::get_settings();
		$this->view(
			'new-batch',
			array(
				'batch'    => $batch,
				'settings' => $settings,
				'chain'    => Spraay_Plugin::get_chain( $settings['chain_id'] ),
				'sources'  => Spraay_WC_Integration::available_sources(),
			)
		);
	}

	/**
	 * Batch history list or single-batch detail.
	 */
	public function render_history() {
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		if ( $batch_id ) {
			$batch = Spraay_Batch::get( $batch_id );
			if ( ! $batch ) {
				wp_die( esc_html__( 'Batch not found.', 'spraay-batch-payouts' ) );
			}
			$this->view(
				'batch-detail',
				array(
					'batch'    => $batch,
					'items'    => Spraay_Batch::get_items( $batch_id ),
					'creator'  => get_userdata( (int) $batch['created_by'] ),
					'approver' => $batch['approved_by'] ? get_userdata( (int) $batch['approved_by'] ) : null,
				)
			);
			return;
		}

		$table = new Spraay_Batches_List_Table();
		$table->prepare_items();
		$this->view( 'batch-history', array( 'table' => $table ) );
	}

	/**
	 * Settings page.
	 */
	public function render_settings() {
		$this->assert_capability();
		$this->view(
			'settings',
			array(
				'settings' => Spraay_Plugin::get_settings(),
				'chains'   => Spraay_Plugin::supported_chains(),
			)
		);
	}

	/**
	 * Include a view from admin/views with extracted variables.
	 *
	 * @param string $name View name without .php.
	 * @param array  $vars Variables for the view.
	 */
	private function view( $name, array $vars = array() ) {
		$file = SPRAAY_BP_PATH . 'admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			$file = SPRAAY_BP_PATH . 'admin/views/partials/' . $name . '.php';
		}
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled view variables.
		include $file;
	}

	/*
	 * ---------------------------------------------------------------------
	 * admin-post.php handlers. Each verifies capability and nonce first.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Create or update a recipient.
	 */
	public function handle_save_recipient() {
		$this->assert_capability();
		check_admin_referer( 'spraay_save_recipient' );

		$id   = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		$data = array();
		foreach ( array( 'label', 'wallet_address', 'email', 'default_amount', 'wc_vendor_id' ) as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$data['notes'] = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$result = $id ? Spraay_Recipient::update( $id, $data ) : Spraay_Recipient::create( $data );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			$args = $id ? array(
				'action'       => 'edit',
				'recipient_id' => $id,
			) : array( 'action' => 'new' );
			wp_safe_redirect( self::page_url( self::PAGE_RECIPIENTS, $args ) );
			exit;
		}

		self::flash( 'success', $id ? __( 'Recipient updated.', 'spraay-batch-payouts' ) : __( 'Recipient added.', 'spraay-batch-payouts' ) );
		wp_safe_redirect( self::page_url( self::PAGE_RECIPIENTS ) );
		exit;
	}

	/**
	 * Delete a single recipient (row action link).
	 */
	public function handle_delete_recipient() {
		$this->assert_capability();
		$id = isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0;
		check_admin_referer( 'spraay_delete_recipient_' . $id );

		$result = Spraay_Recipient::delete( $id );
		self::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Recipient deleted.', 'spraay-batch-payouts' ) );
		wp_safe_redirect( self::page_url( self::PAGE_RECIPIENTS ) );
		exit;
	}

	/**
	 * Bulk actions from the recipients list table.
	 */
	public function handle_bulk_recipients() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below once a bulk action is present.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['recipient'] ) ) {
			return;
		}
		$this->assert_capability();
		check_admin_referer( 'bulk-recipients' );

		$action = isset( $_POST['action'] ) && '-1' !== $_POST['action'] ? sanitize_key( $_POST['action'] ) : ( isset( $_POST['action2'] ) ? sanitize_key( $_POST['action2'] ) : '' );
		$ids    = array_map( 'absint', (array) wp_unslash( $_POST['recipient'] ) );

		if ( 'spraay_bulk_delete' === $action && $ids ) {
			$deleted = 0;
			$errors  = array();
			foreach ( $ids as $id ) {
				$result = Spraay_Recipient::delete( $id );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					++$deleted;
				}
			}
			/* translators: %d: number of recipients deleted. */
			$message = sprintf( _n( '%d recipient deleted.', '%d recipients deleted.', $deleted, 'spraay-batch-payouts' ), $deleted );
			if ( $errors ) {
				/* translators: %d: number of recipients kept. */
				$message .= ' ' . sprintf( _n( '%d was kept because it is part of a submitted batch.', '%d were kept because they are part of submitted batches.', count( $errors ), 'spraay-batch-payouts' ), count( $errors ) );
			}
			self::flash( $errors ? 'warning' : 'success', $message );
		}

		wp_safe_redirect( self::page_url( self::PAGE_RECIPIENTS ) );
		exit;
	}

	/**
	 * Import recipients from an uploaded CSV.
	 */
	public function handle_import_recipients() {
		$this->assert_capability();
		check_admin_referer( 'spraay_import_recipients' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Spraay_CSV_Handler::validate_upload().
		$path   = Spraay_CSV_Handler::validate_upload( isset( $_FILES['spraay_csv'] ) ? $_FILES['spraay_csv'] : null );
		$result = is_wp_error( $path ) ? $path : Spraay_CSV_Handler::import_recipients( $path );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			/* translators: 1: created count, 2: updated count. */
			$message = sprintf( __( 'Import complete: %1$d added, %2$d updated.', 'spraay-batch-payouts' ), $result['created'], $result['updated'] );
			self::flash( $result['errors'] ? 'warning' : 'success', $message, array_slice( $result['errors'], 0, 20 ) );
		}

		wp_safe_redirect( self::page_url( self::PAGE_RECIPIENTS ) );
		exit;
	}

	/**
	 * Download recipients as CSV.
	 */
	public function handle_export_recipients() {
		$this->assert_capability();
		check_admin_referer( 'spraay_export_recipients' );
		Spraay_CSV_Handler::export_recipients();
	}

	/**
	 * Download batch history as CSV, honoring the list filters.
	 */
	public function handle_export_batches() {
		$this->assert_capability();
		check_admin_referer( 'spraay_export_batches' );

		Spraay_CSV_Handler::export_batches(
			array(
				'status'    => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
				'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
				'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			)
		);
	}

	/**
	 * Re-check a pending batch with the gateway.
	 */
	public function handle_refresh_batch() {
		$this->assert_capability();
		$id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		check_admin_referer( 'spraay_refresh_batch_' . $id );

		$result = Spraay_Reconciliation::reconcile_batch( $id );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			$statuses = Spraay_Batch::statuses();
			/* translators: %s: batch status. */
			self::flash( 'success', sprintf( __( 'Status refreshed: %s.', 'spraay-batch-payouts' ), $statuses[ $result['status'] ] ) );
		}

		wp_safe_redirect( self::page_url( self::PAGE_HISTORY, array( 'batch_id' => $id ) ) );
		exit;
	}

	/**
	 * Delete a draft/estimated batch.
	 */
	public function handle_delete_batch() {
		$this->assert_capability();
		$id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		check_admin_referer( 'spraay_delete_batch_' . $id );

		$result = Spraay_Batch::delete( $id );
		self::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Draft batch deleted.', 'spraay-batch-payouts' ) );
		wp_safe_redirect( self::page_url( self::PAGE_HISTORY ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Stop unless the user may manage payouts.
	 */
	private function assert_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Spraay payouts.', 'spraay-batch-payouts' ), 403 );
		}
	}

	/**
	 * Queue a notice for the current user's next admin page load.
	 *
	 * @param string   $type    success|error|warning|info.
	 * @param string   $message Message.
	 * @param string[] $details Optional list of detail lines.
	 */
	public static function flash( $type, $message, array $details = array() ) {
		set_transient(
			'spraay_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => $details,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and clear the queued notice on plugin pages.
	 */
	public function render_flash_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, $this->hooks, true ) ) {
			return;
		}
		$key    = 'spraay_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p>';
		if ( ! empty( $notice['details'] ) ) {
			echo '<ul class="spraay-notice-details">';
			foreach ( $notice['details'] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
