<?php
/**
 * Cart and Checkout block support.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Describes the payment method to the block based checkout.
 */
class PGLP_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method identifier, matching the gateway id.
	 *
	 * @var string
	 */
	protected $name = PGLP_GATEWAY_ID;

	/**
	 * Gateway instance.
	 *
	 * @var PGLP_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Loads the stored settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Whether the method should appear.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->gateway();

		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Registers the front end script.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'pglp-blocks',
			PGLP_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PGLP_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'pglp-blocks', 'vitaliihura-checkout-for-liqpay', PGLP_PATH . 'languages' );
		}

		return array( 'pglp-blocks' );
	}

	/**
	 * The features the block checkout should honour.
	 *
	 * @return array
	 */
	public function get_supported_features() {
		$gateway = $this->gateway();

		return $gateway ? array_values( $gateway->supports ) : array( 'products' );
	}

	/**
	 * Data handed to the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();

		return array(
			'title'          => $gateway ? $gateway->get_title() : __( 'LiqPay', 'vitaliihura-checkout-for-liqpay' ),
			'description'    => $gateway ? $gateway->get_description() : '',
			'iconUrl'        => $this->icon_url(),
			'supports'       => $this->get_supported_features(),
			'testMode'       => $gateway ? $gateway->is_test_mode() : false,
			'testModeNotice' => __( 'Test mode is on. No money will be taken.', 'vitaliihura-checkout-for-liqpay' ),
		);
	}

	/**
	 * The icon shown next to the method name.
	 *
	 * @return string
	 */
	private function icon_url() {
		$style = $this->get_setting( 'icon_style', 'card' );

		if ( 'none' === $style ) {
			return '';
		}

		if ( 'custom' === $style ) {
			return esc_url_raw( (string) $this->get_setting( 'icon_url', '' ) );
		}

		return PGLP_URL . 'assets/images/payment-card.svg';
	}

	/**
	 * Resolves the gateway instance.
	 *
	 * @return PGLP_Gateway|false
	 */
	private function gateway() {
		if ( null === $this->gateway ) {
			$gateways      = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			$this->gateway = isset( $gateways[ PGLP_GATEWAY_ID ] ) ? $gateways[ PGLP_GATEWAY_ID ] : false;
		}

		return $this->gateway;
	}
}
