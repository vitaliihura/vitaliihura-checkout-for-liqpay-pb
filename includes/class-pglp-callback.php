<?php
/**
 * Receives payment notifications from LiqPay.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies and processes the server to server notification LiqPay sends to server_url.
 *
 * The notification is exposed twice: as a REST route, which can answer with a proper HTTP status
 * code, and as the classic WooCommerce API path, which is the URL shape merchants and LiqPay
 * support staff recognise.
 */
class PGLP_Callback {

	const REST_NAMESPACE = 'pglp-liqpay/v1';
	const REST_ROUTE     = '/callback';
	const LOCK_PREFIX    = 'pglp_lock_';
	const LOCK_TIMEOUT   = 180;

	/**
	 * Registers the endpoints.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'woocommerce_api_' . PGLP_GATEWAY_ID, array( __CLASS__, 'handle_legacy' ) );
	}

	/**
	 * Registers the REST route.
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rest' ),
				// Public by design: LiqPay cannot authenticate, so every request is instead
				// checked against the signature made with the merchant's private key.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The URL sent to LiqPay as server_url.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		$settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );
		$route    = isset( $settings['callback_route'] ) ? $settings['callback_route'] : 'rest';

		$url = 'legacy' === $route
			? WC()->api_request_url( PGLP_GATEWAY_ID )
			: rest_url( self::REST_NAMESPACE . self::REST_ROUTE );

		/**
		 * Filters the notification URL handed to LiqPay.
		 *
		 * @param string $url Callback URL.
		 */
		return apply_filters( 'pglp_callback_url', $url );
	}

	/**
	 * Handles the REST request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_rest( $request ) {
		$result = self::process(
			(string) $request->get_param( 'data' ),
			(string) $request->get_param( 'signature' )
		);

		return new WP_REST_Response( array( 'result' => $result['message'] ), $result['code'] );
	}

	/**
	 * Handles the request arriving on the WooCommerce API path.
	 */
	public static function handle_legacy() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticated by the LiqPay signature.
		$data      = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : '';
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = self::process( $data, $signature );

		// WooCommerce buffers and discards anything printed by this hook, so the response has to
		// be written and the request ended here.
		status_header( $result['code'] );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $result['message'] );
		exit;
	}

	/**
	 * Validates and applies a notification.
	 *
	 * @param string $data      Base64 payload as received.
	 * @param string $signature Signature as received.
	 * @return array Response code and message.
	 */
	public static function process( $data, $signature ) {
		if ( '' === $data || '' === $signature ) {
			return self::response( 400, 'missing payload' );
		}

		$payload = PGLP_API::decode( $data );

		if ( empty( $payload ) ) {
			return self::response( 400, 'unreadable payload' );
		}

		$gateway = self::gateway();

		if ( ! $gateway ) {
			return self::response( 503, 'gateway unavailable' );
		}

		$public_key = isset( $payload['public_key'] ) ? (string) $payload['public_key'] : '';
		$reference  = isset( $payload['order_id'] ) ? $payload['order_id'] : '';

		// Only the cheap lookup runs before the signature is checked. The meta table scan below
		// is kept behind authentication so the endpoint cannot be used to make a site work.
		$order = PGLP_Order_Handler::find_order( $reference, false );

		// The key pair is chosen from the mode the order was placed in, never from the key the
		// caller presents, so a sandbox key cannot settle a live order.
		$api = $order ? $gateway->api_for_order( $order ) : $gateway->api_for_public_key( $public_key );

		$authentic = $api
			&& hash_equals( $api->get_public_key(), $public_key )
			&& $api->verify( $data, $signature );

		if ( ! $authentic ) {
			// One answer for a wrong key, a wrong signature and an unknown order alike, so the
			// endpoint reveals nothing about which orders exist.
			PGLP_Logger::error(
				'Notification could not be authenticated.',
				array( 'reference' => $reference )
			);

			return self::response( 403, 'invalid signature' );
		}

		if ( ! $order ) {
			$order = PGLP_Order_Handler::find_order( $reference );
		}

		if ( ! $order ) {
			PGLP_Logger::error( 'Notification referenced an order that does not exist.', array( 'reference' => $reference ) );

			// Answered with 200 on purpose: retrying will not make the order appear.
			return self::response( 200, 'order not found' );
		}

		if ( PGLP_GATEWAY_ID !== $order->get_payment_method() ) {
			return self::response( 200, 'order belongs to another payment method' );
		}

		PGLP_Logger::debug( 'Notification accepted.', array( 'payload' => PGLP_Logger::scrub( $payload ) ) );

		$problem = PGLP_Order_Handler::validate_amount( $order, $payload );

		if ( '' !== $problem ) {
			PGLP_Logger::error(
				'Notification rejected because it did not match the order.',
				array(
					'order_id' => $order->get_id(),
					'reason'   => $problem,
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: short explanation of the mismatch. */
					__( 'A LiqPay notification for this order was rejected: %s', 'vitaliihura-checkout-for-liqpay' ),
					$problem
				)
			);

			return self::response( 400, 'amount mismatch' );
		}

		return self::locked( $order, $payload, $data, $gateway );
	}

	/**
	 * Applies the payload while holding a per-order lock.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array        $payload Decoded payload.
	 * @param string       $data    Raw payload, used as the event fingerprint.
	 * @param PGLP_Gateway $gateway Gateway instance.
	 * @return array
	 */
	private static function locked( $order, $payload, $data, $gateway ) {
		$lock  = self::LOCK_PREFIX . $order->get_id();
		$owner = self::acquire( $lock );

		if ( '' === $owner ) {
			// Another worker is handling a notification for this order right now.
			return self::response( 409, 'busy' );
		}

		try {
			// Re-read the order so the lock protects a current copy.
			$order = wc_get_order( $order->get_id() );

			if ( ! $order ) {
				return self::response( 200, 'order not found' );
			}

			if ( ! PGLP_Order_Handler::claim_event( $order, $data ) ) {
				return self::response( 200, 'already processed' );
			}

			update_option( 'pglp_last_callback', time(), false );

			PGLP_Order_Handler::apply( $order, $payload, $gateway, 'callback' );
		} catch ( Exception $e ) {
			PGLP_Logger::error(
				'Notification handling threw an exception.',
				array(
					'order_id' => $order ? $order->get_id() : 0,
					'message'  => $e->getMessage(),
				)
			);

			return self::response( 500, 'processing error' );
		} finally {
			self::release( $lock, $owner );
		}

		return self::response( 200, 'ok' );
	}

	/**
	 * Takes an exclusive lock, stealing it when the previous holder is clearly gone.
	 *
	 * The options API cannot be used here: add_option() consults the object cache before it
	 * writes, so two workers can both believe they created the row. INSERT IGNORE settles the
	 * race in the database, which is the only place both workers can see each other.
	 *
	 * @param string $lock Option name.
	 * @return string The owner token, or an empty string when the lock is held elsewhere.
	 */
	private static function acquire( $lock ) {
		global $wpdb;

		$owner = wp_generate_uuid4();
		$value = $owner . '|' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cached read cannot settle a race between concurrent requests.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock,
				$value
			)
		);

		if ( 1 === (int) $inserted ) {
			return $owner;
		}

		if ( false === $inserted ) {
			PGLP_Logger::error( 'Could not write the order lock. Check the database user permissions.', array( 'lock' => $lock ) );

			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) );

		if ( '' === $held ) {
			// The previous holder finished between the insert and this read, so the lock is free.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
			$retry = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$lock,
					$value
				)
			);

			return 1 === (int) $retry ? $owner : '';
		}

		$parts = explode( '|', $held );
		$since = isset( $parts[1] ) ? (int) $parts[1] : 0;

		// A value with no readable timestamp is left over from an interrupted request.
		if ( $since > 0 && ( time() - $since ) < self::LOCK_TIMEOUT ) {
			return '';
		}

		// Replace the abandoned lock only if nobody else got there first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$stolen = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$lock,
				$held
			)
		);

		return 1 === (int) $stolen ? $owner : '';
	}

	/**
	 * Releases a lock, but only the one this worker is holding.
	 *
	 * @param string $lock  Option name.
	 * @param string $owner Owner token returned by acquire().
	 */
	private static function release( $lock, $owner ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Matches the direct insert in acquire().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $lock, $wpdb->esc_like( $owner . '|' ) . '%' ) );
	}

	/**
	 * The configured gateway instance.
	 *
	 * @return PGLP_Gateway|false
	 */
	public static function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ PGLP_GATEWAY_ID ] ) && $gateways[ PGLP_GATEWAY_ID ] instanceof PGLP_Gateway ) {
			return $gateways[ PGLP_GATEWAY_ID ];
		}

		return class_exists( 'PGLP_Gateway' ) ? new PGLP_Gateway() : false;
	}

	/**
	 * Builds a response tuple.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Short machine readable message.
	 * @return array
	 */
	private static function response( $code, $message ) {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
