<?php
/**
 * LiqPay payment status vocabulary.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sorts the statuses LiqPay can report into groups a shop can act on.
 */
class PGLP_Statuses {

	/**
	 * Every documented status, grouped by what it means for the order.
	 *
	 * The list is the union of the values published on LiqPay's callback page and on its payment
	 * status page; the two pages do not carry quite the same set.
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			// The money is in. wait_lc is a protected payment: the card was charged and the funds
			// are released to the shop once the buyer confirms delivery.
			'paid'         => array( 'success', 'sandbox', 'wait_compensation', 'wait_lc' ),

			// Blocked on the card by a two-step payment, not taken yet.
			'held'         => array( 'hold_wait' ),

			// Charged, but LiqPay is still checking something on its side.
			'review'       => array( 'wait_secure', 'wait_accept' ),

			// A refund request is holding the funds.
			'reserved'     => array( 'wait_reserve' ),

			// The customer still has something to do.
			'pending'      => array(
				'3ds_verify',
				'captcha_verify',
				'cash_wait',
				'cvv_verify',
				'invoice_wait',
				'ivr_verify',
				'mp_verify',
				'otp_verify',
				'p24_verify',
				'password_verify',
				'phone_verify',
				'pin_verify',
				'prepared',
				'processing',
				'senderapp_verify',
				'wait_card',
				'wait_qr',
				'wait_sender',
			),

			'failed'       => array( 'error', 'failure', 'try_again', 'expired' ),
			'reversed'     => array( 'reversed' ),
			'subscription' => array( 'subscribed', 'unsubscribed' ),
		);
	}

	/**
	 * Sorts a status into its group.
	 *
	 * @param string $status Raw LiqPay status.
	 * @return string Group name, or unknown for a value LiqPay has never documented.
	 */
	public static function classify( $status ) {
		$status = (string) $status;

		foreach ( self::groups() as $group => $statuses ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $group;
			}
		}

		return 'unknown';
	}

	/**
	 * A human readable label for a raw status.
	 *
	 * @param string $status Raw LiqPay status.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = array(
			'3ds_verify'        => __( 'Waiting for 3-D Secure confirmation', 'vitaliihura-checkout-for-liqpay' ),
			'captcha_verify'    => __( 'Waiting for captcha confirmation', 'vitaliihura-checkout-for-liqpay' ),
			'cash_wait'         => __( 'Waiting for cash payment at a self-service terminal', 'vitaliihura-checkout-for-liqpay' ),
			'cvv_verify'        => __( 'Waiting for card CVV confirmation', 'vitaliihura-checkout-for-liqpay' ),
			'error'             => __( 'Payment failed because the data was rejected', 'vitaliihura-checkout-for-liqpay' ),
			'expired'           => __( 'Payment expired', 'vitaliihura-checkout-for-liqpay' ),
			'failure'           => __( 'Payment failed', 'vitaliihura-checkout-for-liqpay' ),
			'hold_wait'         => __( 'Amount blocked on the card, awaiting capture', 'vitaliihura-checkout-for-liqpay' ),
			'invoice_wait'      => __( 'Invoice issued, waiting for payment', 'vitaliihura-checkout-for-liqpay' ),
			'ivr_verify'        => __( 'Waiting for confirmation by phone call', 'vitaliihura-checkout-for-liqpay' ),
			'mp_verify'         => __( 'Waiting for confirmation in the wallet', 'vitaliihura-checkout-for-liqpay' ),
			'otp_verify'        => __( 'Waiting for the one-time password', 'vitaliihura-checkout-for-liqpay' ),
			'p24_verify'        => __( 'Waiting for confirmation in Privat24', 'vitaliihura-checkout-for-liqpay' ),
			'password_verify'   => __( 'Waiting for confirmation in Privat24', 'vitaliihura-checkout-for-liqpay' ),
			'phone_verify'      => __( 'Waiting for the customer to enter a phone number', 'vitaliihura-checkout-for-liqpay' ),
			'pin_verify'        => __( 'Waiting for PIN confirmation', 'vitaliihura-checkout-for-liqpay' ),
			'prepared'          => __( 'Payment created, waiting for the customer', 'vitaliihura-checkout-for-liqpay' ),
			'processing'        => __( 'Payment is being processed', 'vitaliihura-checkout-for-liqpay' ),
			'reversed'          => __( 'Payment refunded', 'vitaliihura-checkout-for-liqpay' ),
			'sandbox'           => __( 'Test payment completed', 'vitaliihura-checkout-for-liqpay' ),
			'senderapp_verify'  => __( 'Waiting for confirmation in the mobile app', 'vitaliihura-checkout-for-liqpay' ),
			'subscribed'        => __( 'Subscription created', 'vitaliihura-checkout-for-liqpay' ),
			'success'           => __( 'Payment successful', 'vitaliihura-checkout-for-liqpay' ),
			'try_again'         => __( 'Payment unsuccessful, the customer can try again', 'vitaliihura-checkout-for-liqpay' ),
			'unsubscribed'      => __( 'Subscription cancelled', 'vitaliihura-checkout-for-liqpay' ),
			'wait_accept'       => __( 'Money withdrawn, waiting for the shop to be verified by LiqPay', 'vitaliihura-checkout-for-liqpay' ),
			'wait_card'         => __( 'Waiting for the recipient to choose a payout method', 'vitaliihura-checkout-for-liqpay' ),
			'wait_compensation' => __( 'Payment successful, awaiting the daily settlement', 'vitaliihura-checkout-for-liqpay' ),
			'wait_lc'           => __( 'Protected payment charged, waiting for delivery confirmation', 'vitaliihura-checkout-for-liqpay' ),
			'wait_qr'           => __( 'Waiting for the customer to scan the QR code', 'vitaliihura-checkout-for-liqpay' ),
			'wait_reserve'      => __( 'Funds reserved against a refund request', 'vitaliihura-checkout-for-liqpay' ),
			'wait_secure'       => __( 'Payment is being verified by LiqPay', 'vitaliihura-checkout-for-liqpay' ),
			'wait_sender'       => __( 'Waiting for confirmation in Privat24 or SENDER', 'vitaliihura-checkout-for-liqpay' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * One word for a status, for places where a full sentence does not fit.
	 *
	 * @param string $status Raw LiqPay status.
	 * @return string
	 */
	public static function short_label( $status ) {
		$labels = array(
			'paid'     => __( 'Paid', 'vitaliihura-checkout-for-liqpay' ),
			'held'     => __( 'Blocked', 'vitaliihura-checkout-for-liqpay' ),
			'review'   => __( 'Being verified', 'vitaliihura-checkout-for-liqpay' ),
			'reserved' => __( 'Reserved', 'vitaliihura-checkout-for-liqpay' ),
			'pending'  => __( 'Waiting', 'vitaliihura-checkout-for-liqpay' ),
			'failed'   => __( 'Declined', 'vitaliihura-checkout-for-liqpay' ),
			'reversed' => __( 'Refunded', 'vitaliihura-checkout-for-liqpay' ),
		);

		$group = self::classify( $status );

		return isset( $labels[ $group ] ) ? $labels[ $group ] : self::label( $status );
	}

	/**
	 * The payment methods a merchant can offer on the LiqPay checkout page.
	 *
	 * @return array
	 */
	public static function paytypes() {
		return array(
			'card'        => __( 'Bank card', 'vitaliihura-checkout-for-liqpay' ),
			'apay'        => __( 'Apple Pay', 'vitaliihura-checkout-for-liqpay' ),
			'gpay'        => __( 'Google Pay', 'vitaliihura-checkout-for-liqpay' ),
			'privat24'    => __( 'Privat24', 'vitaliihura-checkout-for-liqpay' ),
			'moment_part' => __( 'Instalments', 'vitaliihura-checkout-for-liqpay' ),
			'paypart'     => __( 'Payment in parts', 'vitaliihura-checkout-for-liqpay' ),
			'qr'          => __( 'QR code', 'vitaliihura-checkout-for-liqpay' ),
			'cash'        => __( 'Cash at a self-service terminal', 'vitaliihura-checkout-for-liqpay' ),
			'invoice'     => __( 'Invoice by email', 'vitaliihura-checkout-for-liqpay' ),
		);
	}
}
