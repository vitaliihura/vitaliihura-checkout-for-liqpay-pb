<?php
/**
 * Plugin bootstrap.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 */
final class PGLP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PGLP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return PGLP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the hooks needed before WooCommerce boots.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Tells WooCommerce which of its opt-in features this plugin supports.
	 */
	public function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PGLP_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PGLP_PLUGIN_FILE, true );
	}

	/**
	 * Loads the plugin once WooCommerce is known to be present.
	 */
	public function load() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );

			return;
		}

		$this->includes();
		$this->hooks();
	}

	/**
	 * Loads the class files.
	 */
	private function includes() {
		require_once PGLP_PATH . 'includes/class-pglp-logger.php';
		require_once PGLP_PATH . 'includes/i18n/class-pglp-i18n.php';
		require_once PGLP_PATH . 'includes/ui/class-pglp-ui.php';
		require_once PGLP_PATH . 'includes/class-pglp-api.php';
		require_once PGLP_PATH . 'includes/class-pglp-statuses.php';
		require_once PGLP_PATH . 'includes/class-pglp-request-builder.php';
		require_once PGLP_PATH . 'includes/class-pglp-order-handler.php';
		require_once PGLP_PATH . 'includes/class-pglp-callback.php';
		require_once PGLP_PATH . 'includes/class-pglp-reconciler.php';
		require_once PGLP_PATH . 'includes/class-pglp-admin.php';
		require_once PGLP_PATH . 'includes/class-pglp-privacy.php';
		require_once PGLP_PATH . 'includes/class-pglp-gateway-settings.php';
		require_once PGLP_PATH . 'includes/class-pglp-gateway.php';
	}

	/**
	 * Registers the runtime hooks.
	 */
	private function hooks() {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PGLP_PLUGIN_FILE ), array( $this, 'settings_link' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );

		PGLP_Callback::init();
		PGLP_Reconciler::init();
		PGLP_Admin::init();
		PGLP_Privacy::init();
	}

	/**
	 * Adds the gateway to the WooCommerce gateway list.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'PGLP_Gateway';

		return $gateways;
	}

	/**
	 * Adds a settings shortcut to the plugin row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function settings_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . PGLP_GATEWAY_ID );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vitaliihura-checkout-for-liqpay' ) . '</a>' );

		return $links;
	}

	/**
	 * Registers the Cart and Checkout block integration.
	 */
	public function register_blocks_support() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		require_once PGLP_PATH . 'includes/class-pglp-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new PGLP_Blocks() );
			}
		);
	}

	/**
	 * Warns the site owner when WooCommerce is unavailable.
	 */
	public function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'VitaliiHura Checkout for LiqPay requires WooCommerce to be installed and active.', 'vitaliihura-checkout-for-liqpay' );
		echo '</p></div>';
	}
}
