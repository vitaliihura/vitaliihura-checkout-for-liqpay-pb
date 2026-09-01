<?php
/**
 * Logging helper.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under a single source, with debug output gated by a setting.
 */
class PGLP_Logger {

	const SOURCE = 'vitaliihura-checkout-for-liqpay';

	/**
	 * Cached logger instance.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Whether verbose logging is switched on in the gateway settings.
	 *
	 * @return bool
	 */
	public static function debug_enabled() {
		$settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );

		return isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}

	/**
	 * Records a message.
	 *
	 * Everything below the error level is dropped unless debug logging is enabled, so a busy
	 * production shop does not fill its log directory with routine traffic.
	 *
	 * @param string $level   One of the PSR-3 levels supported by WooCommerce.
	 * @param string $message Message body.
	 * @param array  $context Extra structured data.
	 */
	public static function log( $level, $message, $context = array() ) {
		$always = array( 'error', 'critical', 'alert', 'emergency' );

		if ( ! in_array( $level, $always, true ) && ! self::debug_enabled() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->log( $level, $message, array_merge( array( 'source' => self::SOURCE ), $context ) );
	}

	/**
	 * Shorthand for debug level.
	 *
	 * @param string $message Message body.
	 * @param array  $context Extra structured data.
	 */
	public static function debug( $message, $context = array() ) {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Shorthand for error level.
	 *
	 * @param string $message Message body.
	 * @param array  $context Extra structured data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Strips values that must never reach a log file.
	 *
	 * @param array $payload Decoded LiqPay payload.
	 * @return array
	 */
	public static function scrub( $payload ) {
		$hidden = array(
			'card',
			'card_cvv',
			'card_exp_month',
			'card_exp_year',
			'card_token',
			'sender_phone',
			'confirm_phone',
			'phone',
			'sender_iban',
			'apay_token',
			'gpay_token',
		);

		foreach ( $hidden as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$payload[ $key ] = '***';
			}
		}

		return $payload;
	}
}
