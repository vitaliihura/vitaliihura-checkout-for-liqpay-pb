<?php
/**
 * Builds the LiqPay request for a WooCommerce order.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translates an order and the gateway settings into LiqPay checkout parameters.
 */
class PGLP_Request_Builder {

	/**
	 * Gateway instance the parameters are built for.
	 *
	 * @var PGLP_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param PGLP_Gateway $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Builds the full parameter set for a checkout request.
	 *
	 * @param WC_Order $order     Order being paid.
	 * @param string   $reference Reference sent to LiqPay as order_id.
	 * @return array
	 */
	public function build( $order, $reference ) {
		$params = array(
			'action'      => $this->gateway->get_payment_action( $order ),
			'amount'      => PGLP_API::format_amount( $order->get_total() ),
			'currency'    => $order->get_currency(),
			'description' => $this->purpose( $order ),
			'order_id'    => $reference,
			'language'    => $this->language( $order ),
			'result_url'  => $this->gateway->get_return_url( $order ),
			'server_url'  => PGLP_Callback::endpoint_url(),
		);

		$paytypes = $this->gateway->get_paytypes();

		if ( ! empty( $paytypes ) ) {
			$params['paytypes'] = implode( ',', $paytypes );
		}

		$expiry = $this->expiry();

		if ( '' !== $expiry ) {
			$params['expired_date'] = $expiry;
		}

		if ( 'yes' === $this->gateway->get_option( 'send_customer_details', 'no' ) ) {
			$params = array_merge( $params, $this->sender( $order ) );
		}

		if ( 'yes' === $this->gateway->get_option( 'send_product_details', 'no' ) ) {
			$params = array_merge( $params, $this->product( $order ) );
		}

		$split = apply_filters( 'pglp_split_rules', array(), $order, $this->gateway );

		if ( ! empty( $split ) && is_array( $split ) ) {
			$params['split_rules'] = wp_json_encode( array_values( $split ), JSON_UNESCAPED_UNICODE );
		}

		$info = apply_filters( 'pglp_payment_info', '', $order, $this->gateway );

		if ( '' !== $info ) {
			$params['info'] = is_scalar( $info ) ? (string) $info : (string) wp_json_encode( $info, JSON_UNESCAPED_UNICODE );
		}

		/**
		 * Filters the complete parameter set before it is signed and sent to LiqPay.
		 *
		 * @param array        $params  Checkout parameters.
		 * @param WC_Order     $order   Order being paid.
		 * @param PGLP_Gateway $gateway Gateway instance.
		 */
		return apply_filters( 'pglp_checkout_params', $params, $order, $this->gateway );
	}

	/**
	 * Builds the payment purpose shown to the customer.
	 *
	 * Public because a payment made with a stored card never goes through build(), and the
	 * merchant's wording has to reach the card statement there too.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	public function purpose( $order ) {
		$template = trim( (string) $this->gateway->get_localized( 'description_template', $order ) );

		if ( '' === $template ) {
			return self::default_description( $order );
		}

		$replacements = array(
			'{order_number}'       => $order->get_order_number(),
			'{order_id}'           => (string) $order->get_id(),
			'{site_title}'         => get_bloginfo( 'name' ),
			'{billing_first_name}' => $order->get_billing_first_name(),
			'{billing_last_name}'  => $order->get_billing_last_name(),
			'{billing_email}'      => $order->get_billing_email(),
			'{total}'              => (string) PGLP_API::format_amount( $order->get_total() ),
			'{currency}'           => $order->get_currency(),
		);

		$description = strtr( $template, $replacements );

		// LiqPay shows this on the payment page and on the card statement; keep it sane.
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $description, 0, 250 );
		}

		return substr( $description, 0, 250 );
	}

	/**
	 * The default payment purpose, worded in the language of the order.
	 *
	 * The text is printed on the customer's card statement, so it has to follow the order rather
	 * than whoever happens to be looking at the screen.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	private static function default_description( $order ) {
		$locale   = PGLP_I18n::current_locale( $order );
		$switched = determine_locale() !== $locale ? switch_to_locale( $locale ) : false;

		$text = sprintf(
			/* translators: %s: order number. */
			__( 'Payment for order %s', 'vitaliihura-checkout-for-liqpay' ),
			$order->get_order_number()
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		return $text;
	}

	/**
	 * Picks the language for the LiqPay payment page.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	private function language( $order ) {
		$setting = $this->gateway->get_option( 'language', 'auto' );

		if ( 'auto' !== $setting ) {
			return $setting;
		}

		return PGLP_I18n::liqpay_language( PGLP_I18n::current_locale( $order ) );
	}

	/**
	 * Builds the moment the payment link stops working.
	 *
	 * @return string Empty string when no limit is configured.
	 */
	private function expiry() {
		$minutes = absint( $this->gateway->get_option( 'expiry_minutes', 0 ) );

		if ( 0 === $minutes ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Billing details LiqPay uses for anti-fraud scoring.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array
	 */
	private function sender( $order ) {
		$fields = array(
			'sender_first_name'  => $order->get_billing_first_name(),
			'sender_last_name'   => $order->get_billing_last_name(),
			'sender_address'     => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'sender_city'        => $order->get_billing_city(),
			'sender_postal_code' => $order->get_billing_postcode(),
		);

		$country = self::country_numeric( $order->get_billing_country() );

		if ( '' !== $country ) {
			$fields['sender_country_code'] = $country;
		}

		return array_filter( $fields );
	}

	/**
	 * Product details LiqPay shows on the payment page.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array
	 */
	private function product( $order ) {
		$names = array();

		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}

		$name = implode( ', ', $names );

		$fields = array(
			'product_name'        => self::clamp( $name, 100 ),
			'product_description' => self::clamp( $name, 500 ),
			'product_url'         => self::clamp( $order->get_view_order_url(), 2000 ),
		);

		$category = (string) $this->gateway->get_option( 'product_category', '' );

		if ( '' !== $category ) {
			$fields['product_category'] = self::clamp( $category, 25 );
		}

		return array_filter( $fields );
	}

	/**
	 * Shortens a string to a byte-safe length.
	 *
	 * @param string $value  Source text.
	 * @param int    $length Maximum characters.
	 * @return string
	 */
	private static function clamp( $value, $length ) {
		$value = wp_strip_all_tags( (string) $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Converts a two letter country code into the numeric ISO 3166-1 code LiqPay expects.
	 *
	 * @param string $code Two letter country code.
	 * @return string Empty string when the country is not in the list.
	 */
	public static function country_numeric( $code ) {
		$map = array(
			'AT' => '040',
			'AU' => '036',
			'AZ' => '031',
			'BE' => '056',
			'BG' => '100',
			'BY' => '112',
			'CA' => '124',
			'CH' => '756',
			'CN' => '156',
			'CY' => '196',
			'CZ' => '203',
			'DE' => '276',
			'DK' => '208',
			'EE' => '233',
			'ES' => '724',
			'FI' => '246',
			'FR' => '250',
			'GB' => '826',
			'GE' => '268',
			'GR' => '300',
			'HR' => '191',
			'HU' => '348',
			'IE' => '372',
			'IL' => '376',
			'IT' => '380',
			'JP' => '392',
			'KZ' => '398',
			'LT' => '440',
			'LU' => '442',
			'LV' => '428',
			'MD' => '498',
			'NL' => '528',
			'NO' => '578',
			'NZ' => '554',
			'PL' => '616',
			'PT' => '620',
			'RO' => '642',
			'SE' => '752',
			'SI' => '705',
			'SK' => '703',
			'TR' => '792',
			'UA' => '804',
			'US' => '840',
		);

		$code = strtoupper( (string) $code );

		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}
}
