<?php
/**
 * Recovers payments whose notification never arrived.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asks LiqPay for the state of orders that are still waiting.
 *
 * A notification can be lost to a firewall, a maintenance window or a plain network failure, and
 * LiqPay publishes no retry schedule. Polling the payment status API closes that gap.
 */
class PGLP_Reconciler {

	const HOOK                  = 'pglp_reconcile_payments';
	const BATCH_MAX             = 30;
	const RETURN_CHECK_INTERVAL = 20;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . PGLP_GATEWAY_ID, array( __CLASS__, 'schedule' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'check_on_return' ) );
	}

	/**
	 * Keeps the hourly event in step with the setting.
	 */
	public static function schedule() {
		$settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );
		$wanted   = ! isset( $settings['reconcile'] ) || 'yes' === $settings['reconcile'];
		$existing = wp_next_scheduled( self::HOOK );

		if ( $wanted && ! $existing ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );

			return;
		}

		if ( ! $wanted && $existing ) {
			wp_unschedule_event( $existing, self::HOOK );
		}
	}

	/**
	 * Settles the order while the customer is still looking at the order received page.
	 *
	 * The notification travels server to server and can be lost to a firewall, a closed port or a
	 * staging site that is not reachable from outside. The customer, meanwhile, is already back on
	 * the shop, so the shop asks LiqPay itself instead of leaving the order pending until the
	 * hourly sweep.
	 *
	 * The page itself is found rather than hooked into: woocommerce_thankyou only fires on the
	 * classic order received template, and a shop on the checkout block would never reach it.
	 */
	public static function check_on_return() {
		if ( is_admin() || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || PGLP_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		// The order key travels in the address WooCommerce itself built. Without it this would be
		// an open invitation to have the shop query LiqPay about any order number in turn.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the key WooCommerce put in the return address, not accepting input.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
			return;
		}

		// The page is often reloaded, and a payment that is still being authorised will not settle
		// any faster for being asked twice a second.
		$lock = 'pglp_return_check_' . $order->get_id();

		if ( get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, self::RETURN_CHECK_INTERVAL );

		$gateway = PGLP_Callback::gateway();

		if ( ! $gateway ) {
			return;
		}

		self::check( $order, $gateway );
	}

	/**
	 * Checks the unfinished orders.
	 */
	public static function run() {
		$gateway = PGLP_Callback::gateway();

		if ( ! $gateway || 'yes' !== $gateway->get_option( 'enabled', 'no' ) ) {
			return;
		}

		$hours = absint( $gateway->get_option( 'reconcile_window', 72 ) );
		$hours = $hours > 0 ? $hours : 72;

		$orders = wc_get_orders(
			array(
				'limit'          => self::BATCH_MAX,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'payment_method' => PGLP_GATEWAY_ID,
				'status'         => array( 'pending', 'on-hold', 'failed' ),
				'date_created'   => '>' . ( time() - ( $hours * HOUR_IN_SECONDS ) ),
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			self::check( $order, $gateway );
		}
	}

	/**
	 * Queries one order and applies whatever LiqPay reports.
	 *
	 * @param WC_Order     $order   Order.
	 * @param PGLP_Gateway $gateway Gateway instance.
	 * @return bool Whether the order changed.
	 */
	public static function check( $order, $gateway ) {
		if ( $order->is_paid() && ! PGLP_Order_Handler::has_open_hold( $order ) ) {
			return false;
		}

		$api = $gateway->api_for_order( $order );

		if ( ! $api ) {
			return false;
		}

		$response = $api->get_status( PGLP_Order_Handler::get_reference( $order ) );

		if ( is_wp_error( $response ) || empty( $response['status'] ) ) {
			return false;
		}

		if ( PGLP_Order_Handler::is_unknown_to_liqpay( $order, $response ) ) {
			return false;
		}

		return PGLP_Order_Handler::apply( $order, $response, $gateway, 'status check' );
	}
}
