<?php
/**
 * Privacy: what the shop owner has to tell customers, and what an erasure request has to remove.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feeds the WordPress privacy tools and the WooCommerce erasers.
 */
class PGLP_Privacy {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', array( __CLASS__, 'erasable_meta' ) );
	}

	/**
	 * Adds suggested wording to the privacy policy guide.
	 *
	 * The readme says the same thing to the shop owner; this says it where WordPress expects to
	 * find it, so the text can be pasted into the shop's own policy.
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__( 'This wording covers what LiqPay receives and what stays on the order. Adjust it to how long your shop keeps orders.', 'vitaliihura-checkout-for-liqpay' )
			. '</p><p>'
			. esc_html__( 'When a customer pays with LiqPay, we send the payment service the order reference, the amount, the currency, the payment purpose and the addresses it should return the customer to. If the matching settings are enabled, we also send the billing name and address and the names of the products ordered.', 'vitaliihura-checkout-for-liqpay' )
			. '</p><p>'
			. esc_html__( 'LiqPay sends back, and we keep on the order, the payment status and identifiers, the method used, a masked card number, the card brand and issuing bank, the acquirer identifier, the authorisation code and the commission. Card numbers, expiry dates and security codes are entered on the LiqPay page and never reach this site.', 'vitaliihura-checkout-for-liqpay' )
			. '</p><p>'
			. esc_html__( 'This data is kept with the order for as long as the shop keeps its orders, because it is part of the payment record.', 'vitaliihura-checkout-for-liqpay' )
			. '</p>';

		wp_add_privacy_policy_content( 'VitaliiHura Checkout for LiqPay', wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Adds the payment fields to what WooCommerce clears on an erasure request.
	 *
	 * WooCommerce only touches the keys named in this filter, so without it a masked card number
	 * and the issuing bank would survive a request to remove the customer's data. The transaction
	 * identifiers are left alone: they carry no personal data and the shop needs them to settle
	 * disputes with the payment service.
	 *
	 * @param array $meta Meta keys mapped to the anonymisation type WooCommerce should apply.
	 * @return array
	 */
	public static function erasable_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$meta[ PGLP_Order_Handler::META_CARD_MASK ] = 'text';
		$meta[ PGLP_Order_Handler::META_CARD_BANK ] = 'text';

		return $meta;
	}
}
