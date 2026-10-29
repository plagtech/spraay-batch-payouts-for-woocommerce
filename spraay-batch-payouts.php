<?php
/**
 * Plugin Name:          Spraay Batch Payouts
 * Plugin URI:           https://spraay.app
 * Description:          Batch-pay suppliers, vendors, affiliates and contractors in USDC on Base through the Spraay Protocol gateway. A payout tool — it does not touch customer checkout.
 * Version:              0.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Plagtech
 * Author URI:           https://plagtech.net
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          spraay-batch-payouts
 * Domain Path:          /languages
 * WC requires at least: 7.0
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPRAAY_BP_VERSION', '0.1.0' );
define( 'SPRAAY_BP_DB_VERSION', '1' );
define( 'SPRAAY_BP_FILE', __FILE__ );
define( 'SPRAAY_BP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPRAAY_BP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload Spraay_* classes from includes/ using WordPress file naming
 * (Spraay_Gateway_Client => includes/class-spraay-gateway-client.php).
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Spraay_' ) ) {
			return;
		}
		$file = SPRAAY_BP_PATH . 'includes/class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Spraay_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Spraay_Plugin', 'deactivate' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Spraay_Plugin', 'instance' ) );
