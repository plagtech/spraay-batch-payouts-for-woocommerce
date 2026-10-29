<?php
/**
 * Main plugin class.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the plugin, owns settings and schema.
 */
final class Spraay_Plugin {

	/**
	 * Option key holding all plugin settings.
	 */
	const OPTION_SETTINGS = 'spraay_settings';

	/**
	 * Option key holding the installed schema version.
	 */
	const OPTION_DB_VERSION = 'spraay_db_version';

	/**
	 * Singleton instance.
	 *
	 * @var Spraay_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin UI controller.
	 *
	 * @var Spraay_Admin|null
	 */
	public $admin = null;

	/**
	 * Get (and on first call, boot) the plugin.
	 *
	 * @return Spraay_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. WooCommerce must be active for anything to load.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( ! self::is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		self::maybe_upgrade_schema();

		( new Spraay_Rest_Api() )->register();
		( new Spraay_Reconciliation() )->register();
		( new Spraay_WC_Integration() )->register();

		if ( is_admin() ) {
			$this->admin = new Spraay_Admin();
			$this->admin->register();
		}

		/**
		 * Fires once Spraay Batch Payouts has finished loading.
		 *
		 * @param Spraay_Plugin $plugin Plugin instance.
		 */
		do_action( 'spraay_loaded', $this );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'spraay-batch-payouts', false, dirname( plugin_basename( SPRAAY_BP_FILE ) ) . '/languages' );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public function notice_missing_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Spraay Batch Payouts requires WooCommerce to be installed and active.', 'spraay-batch-payouts' ) . '</p></div>';
	}

	/**
	 * Activation: create tables, seed settings, schedule reconciliation.
	 */
	public static function activate() {
		self::create_tables();
		add_option( self::OPTION_SETTINGS, self::default_settings() );
		Spraay_Reconciliation::schedule();
	}

	/**
	 * Deactivation: stop scheduled work. Data is kept until uninstall.
	 */
	public static function deactivate() {
		Spraay_Reconciliation::unschedule();
	}

	/**
	 * Re-run dbDelta when the schema version changes (covers updates that
	 * don't trigger the activation hook).
	 */
	private static function maybe_upgrade_schema() {
		if ( get_option( self::OPTION_DB_VERSION ) !== SPRAAY_BP_DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Create or update plugin tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$recipients      = self::table( 'recipients' );
		$batches         = self::table( 'batches' );
		$items           = self::table( 'batch_items' );

		// dbDelta is whitespace-sensitive: one column per line, two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$recipients} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  label varchar(255) NOT NULL DEFAULT '',
  wallet_address varchar(42) NOT NULL,
  email varchar(255) NOT NULL DEFAULT '',
  wc_vendor_id bigint(20) unsigned NULL DEFAULT NULL,
  default_amount decimal(18,6) NULL DEFAULT NULL,
  currency varchar(10) NOT NULL DEFAULT 'USDC',
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY wallet_address (wallet_address),
  KEY wc_vendor_id (wc_vendor_id)
) {$charset_collate};
CREATE TABLE {$batches} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'draft',
  total_amount decimal(18,6) NOT NULL DEFAULT 0,
  fee_amount decimal(18,6) NOT NULL DEFAULT 0,
  recipient_count int(11) NOT NULL DEFAULT 0,
  tx_hash varchar(66) NULL DEFAULT NULL,
  chain_id int(11) NOT NULL DEFAULT 8453,
  gateway_batch_id varchar(255) NULL DEFAULT NULL,
  gateway_response longtext NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  approved_by bigint(20) unsigned NULL DEFAULT NULL,
  created_at datetime NOT NULL,
  confirmed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset_collate};
CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id bigint(20) unsigned NOT NULL,
  recipient_id bigint(20) unsigned NOT NULL,
  amount decimal(18,6) NOT NULL DEFAULT 0,
  currency varchar(10) NOT NULL DEFAULT 'USDC',
  wc_order_ids text NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY recipient_id (recipient_id)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::OPTION_DB_VERSION, SPRAAY_BP_DB_VERSION );
	}

	/**
	 * Full table name for a plugin table.
	 *
	 * @param string $name One of recipients, batches, batch_items.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'spraay_' . $name;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'gateway_url'    => 'https://gateway.spraay.app',
			'chain_id'       => 8453,
			'token'          => 'USDC',
			'fee_display'    => true,
			'auto_import_wc' => false,
			'api_key'        => '',
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Chains the plugin can pay out on, keyed by chain ID.
	 *
	 * @return array
	 */
	public static function supported_chains() {
		$chains = array(
			8453  => array(
				'name'     => 'Base',
				'rpc_url'  => 'https://mainnet.base.org',
				'explorer' => 'https://basescan.org',
				'tokens'   => array(
					'USDC' => array(
						'address'  => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
						'decimals' => 6,
					),
				),
			),
			84532 => array(
				'name'     => 'Base Sepolia (testnet)',
				'rpc_url'  => 'https://sepolia.base.org',
				'explorer' => 'https://sepolia.basescan.org',
				'tokens'   => array(
					'USDC' => array(
						'address'  => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
						'decimals' => 6,
					),
				),
			),
		);

		/**
		 * Filter the chains available for payouts.
		 *
		 * @param array $chains Chain config keyed by chain ID.
		 */
		return apply_filters( 'spraay_supported_chains', $chains );
	}

	/**
	 * Config for one chain, or null if unsupported.
	 *
	 * @param int $chain_id Chain ID.
	 * @return array|null
	 */
	public static function get_chain( $chain_id ) {
		$chains = self::supported_chains();
		return isset( $chains[ (int) $chain_id ] ) ? $chains[ (int) $chain_id ] : null;
	}

	/**
	 * Block-explorer URL for a transaction hash.
	 *
	 * @param string $tx_hash  Transaction hash.
	 * @param int    $chain_id Chain ID.
	 * @return string Empty string if unknown.
	 */
	public static function explorer_tx_url( $tx_hash, $chain_id ) {
		$chain = self::get_chain( $chain_id );
		if ( ! $chain || ! preg_match( '/^0x[0-9a-fA-F]{64}$/', (string) $tx_hash ) ) {
			return '';
		}
		return trailingslashit( $chain['explorer'] ) . 'tx/' . $tx_hash;
	}
}
