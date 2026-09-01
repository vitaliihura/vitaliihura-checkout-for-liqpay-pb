<?php
/**
 * LiqPay API client.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds signed LiqPay requests and talks to the LiqPay endpoints.
 *
 * Every call carries two form fields: "data" is the request parameters as JSON run through
 * base64, and "signature" is that same base64 string wrapped in the private key and hashed.
 * Both are LiqPay's transport format, described at https://www.liqpay.ua/en/doc/api/callback.
 */
class PGLP_API {

	const CHECKOUT_URL = 'https://www.liqpay.ua/api/3/checkout';
	const REQUEST_URL  = 'https://www.liqpay.ua/api/request';

	/**
	 * Currencies LiqPay accepts.
	 */
	const CURRENCIES = array( 'UAH', 'USD', 'EUR' );

	/**
	 * Merchant public key.
	 *
	 * @var string
	 */
	private $public_key;

	/**
	 * Merchant private key.
	 *
	 * @var string
	 */
	private $private_key;

	/**
	 * Constructor.
	 *
	 * @param string $public_key  Merchant public key.
	 * @param string $private_key Merchant private key.
	 */
	public function __construct( $public_key, $private_key ) {
		$this->public_key  = (string) $public_key;
		$this->private_key = (string) $private_key;
	}

	/**
	 * The API version sent with every request.
	 *
	 * LiqPay's reference tables state 7 while every code sample they publish, and the checkout
	 * endpoint itself, still use 3. Version 3 is what the service has accepted for years, so it is
	 * the default; the filter is here for merchants who are asked by LiqPay support to move up.
	 *
	 * @return int
	 */
	public static function version() {
		return (int) apply_filters( 'pglp_api_version', 3 );
	}

	/**
	 * Whether both keys are present.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->public_key && '' !== $this->private_key;
	}

	/**
	 * The public key these credentials belong to.
	 *
	 * @return string
	 */
	public function get_public_key() {
		return $this->public_key;
	}

	/**
	 * Encodes request parameters into the transport string LiqPay expects.
	 *
	 * @param array $params Request parameters.
	 * @return string
	 */
	public function encode( $params ) {
		return base64_encode( (string) wp_json_encode( $params, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Decodes a transport string back into parameters.
	 *
	 * @param string $data Base64 payload.
	 * @return array
	 */
	public static function decode( $data ) {
		$json = base64_decode( (string) $data, true );

		if ( false === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Signs a payload with SHA-1, which is what LiqPay's own SDKs and samples use.
	 *
	 * @param string $data Base64 payload.
	 * @return string
	 */
	public function sign( $data ) {
		return base64_encode( sha1( $this->private_key . $data . $this->private_key, true ) );
	}

	/**
	 * Signs a payload with SHA3-256, the algorithm named in LiqPay's current written spec.
	 *
	 * Their documentation prose and their code samples disagree, so callbacks are accepted under
	 * either scheme. Returns an empty string when the PHP build has no SHA3 support.
	 *
	 * @param string $data Base64 payload.
	 * @return string
	 */
	public function sign_sha3( $data ) {
		if ( ! in_array( 'sha3-256', hash_algos(), true ) ) {
			return '';
		}

		return base64_encode( hash( 'sha3-256', $this->private_key . $data . $this->private_key, true ) );
	}

	/**
	 * Verifies a signature received from LiqPay.
	 *
	 * @param string $data      Base64 payload exactly as received.
	 * @param string $signature Signature exactly as received.
	 * @return bool
	 */
	public function verify( $data, $signature ) {
		$signature = (string) $signature;

		if ( '' === $signature || ! $this->has_credentials() ) {
			return false;
		}

		if ( hash_equals( $this->sign( $data ), $signature ) ) {
			return true;
		}

		$sha3 = $this->sign_sha3( $data );

		return '' !== $sha3 && hash_equals( $sha3, $signature );
	}

	/**
	 * Adds the parameters every request carries and validates the mandatory ones.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error
	 */
	public function prepare( $params ) {
		if ( ! $this->has_credentials() ) {
			return new WP_Error( 'pglp_no_credentials', __( 'LiqPay API keys are not configured.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		$params['version']    = self::version();
		$params['public_key'] = $this->public_key;

		if ( empty( $params['action'] ) ) {
			return new WP_Error( 'pglp_no_action', __( 'The request is missing an action.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		if ( isset( $params['currency'] ) && ! in_array( $params['currency'], self::CURRENCIES, true ) ) {
			return new WP_Error(
				'pglp_currency',
				sprintf(
					/* translators: %s: currency code, for example UAH. */
					__( 'LiqPay does not accept payments in %s.', 'vitaliihura-checkout-for-liqpay' ),
					$params['currency']
				)
			);
		}

		return array_filter(
			$params,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Builds the hidden fields for a checkout form.
	 *
	 * @param array $params Checkout parameters.
	 * @return array|WP_Error Array with url, data and signature keys.
	 */
	public function checkout_form( $params ) {
		$params = $this->prepare( $params );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$data = $this->encode( $params );

		return array(
			'url'       => self::CHECKOUT_URL,
			'data'      => $data,
			'signature' => $this->sign( $data ),
		);
	}

	/**
	 * Sends a server to server request and returns the decoded response.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error
	 */
	public function request( $params ) {
		$params = $this->prepare( $params );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$action = $params['action'];
		$data   = $this->encode( $params );

		$response = wp_remote_post(
			self::REQUEST_URL,
			array(
				'timeout'    => 45,
				'user-agent' => 'WooCommerce/' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '0' ) . '; ' . home_url( '/' ),
				'headers'    => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'       => array(
					'data'      => $data,
					'signature' => $this->sign( $data ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			PGLP_Logger::error(
				'Request to LiqPay failed.',
				array(
					'action' => $action,
					'error'  => $response->get_error_message(),
				)
			);

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			PGLP_Logger::error(
				'LiqPay returned a response that could not be read.',
				array(
					'action' => $action,
					'code'   => $code,
				)
			);

			return new WP_Error( 'pglp_bad_response', __( 'LiqPay returned an unreadable response.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		PGLP_Logger::debug(
			'LiqPay response received.',
			array(
				'action'   => $action,
				'code'     => $code,
				'response' => PGLP_Logger::scrub( $json ),
			)
		);

		return $json;
	}

	/**
	 * Turns an API response into a WP_Error when LiqPay reported a problem.
	 *
	 * @param array|WP_Error $response Response from request().
	 * @return WP_Error|null Null when the response is usable.
	 */
	public static function response_error( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! is_array( $response ) ) {
			return new WP_Error( 'pglp_bad_response', __( 'LiqPay returned an unreadable response.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		$failed = isset( $response['result'] ) && 'error' === $response['result'];
		$failed = $failed || ( isset( $response['status'] ) && in_array( $response['status'], array( 'error', 'failure' ), true ) );

		if ( ! $failed ) {
			return null;
		}

		$message = '';

		if ( ! empty( $response['err_description'] ) ) {
			$message = (string) $response['err_description'];
		} elseif ( ! empty( $response['description'] ) ) {
			$message = (string) $response['description'];
		}

		$code = '';

		if ( ! empty( $response['err_code'] ) ) {
			$code = (string) $response['err_code'];
		} elseif ( ! empty( $response['code'] ) ) {
			$code = (string) $response['code'];
		}

		if ( '' === $message ) {
			$message = '' !== $code
				? sprintf(
					/* translators: %s: error code returned by LiqPay. */
					__( 'LiqPay refused the request (%s).', 'vitaliihura-checkout-for-liqpay' ),
					$code
				)
				: __( 'LiqPay refused the request.', 'vitaliihura-checkout-for-liqpay' );
		}

		return new WP_Error( 'pglp_api_error', $message, array( 'code' => $code ) );
	}

	/**
	 * Queries the current state of a payment.
	 *
	 * @param string $order_reference Reference sent to LiqPay as order_id.
	 * @return array|WP_Error
	 */
	public function get_status( $order_reference ) {
		return $this->request(
			array(
				'action'   => 'status',
				'order_id' => $order_reference,
			)
		);
	}

	/**
	 * Refunds all or part of a payment.
	 *
	 * @param string $order_reference Reference sent to LiqPay as order_id.
	 * @param float  $amount          Amount to return.
	 * @return array|WP_Error
	 */
	public function refund( $order_reference, $amount ) {
		return $this->request(
			array(
				'action'   => 'refund',
				'order_id' => $order_reference,
				'amount'   => self::format_amount( $amount ),
			)
		);
	}

	/**
	 * Asks LiqPay to email a receipt for a completed payment.
	 *
	 * @param string $order_reference Reference sent to LiqPay as order_id.
	 * @param string $email           Recipient address.
	 * @param string $language        Receipt language.
	 * @return array|WP_Error
	 */
	public function send_receipt( $order_reference, $email, $language = 'uk' ) {
		return $this->request(
			array(
				'action'   => 'ticket',
				'order_id' => $order_reference,
				'email'    => $email,
				'language' => $language,
			)
		);
	}

	/**
	 * Formats an amount the way LiqPay expects it.
	 *
	 * @param float $amount Raw amount.
	 * @return float
	 */
	public static function format_amount( $amount ) {
		return round( (float) $amount, 2 );
	}
}
