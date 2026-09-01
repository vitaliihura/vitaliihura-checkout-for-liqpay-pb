<?php
/**
 * Plugin Name:       VitaliiHura Checkout for LiqPay
 * Description:       Accept payments in WooCommerce through LiqPay: hosted checkout, refunds and status recovery.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Vitalii Hura
 * Author URI:        https://github.com/vitaliihura
 * Plugin URI:        https://github.com/vitaliihura/vitaliihura-checkout-for-liqpay-pb
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vitaliihura-checkout-for-liqpay
 * Domain Path:       /languages
 *
 * WC requires at least: 8.2
 * WC tested up to:      11.0
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

define( 'PGLP_VERSION', '1.0.0' );
define( 'PGLP_PLUGIN_FILE', __FILE__ );
define( 'PGLP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PGLP_URL', plugin_dir_url( __FILE__ ) );
define( 'PGLP_GATEWAY_ID', 'pglp_liqpay' );

require_once PGLP_PATH . 'includes/class-pglp-plugin.php';

PGLP_Plugin::instance();
