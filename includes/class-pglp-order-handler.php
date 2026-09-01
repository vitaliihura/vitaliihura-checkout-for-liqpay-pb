<?php
/**
 * Applies LiqPay payment data to WooCommerce orders.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a decoded LiqPay payload into order status changes, notes and stored metadata.
 */
class PGLP_Order_Handler {

	const META_REFERENCE   = '_pglp_reference';
	const META_ATTEMPT     = '_pglp_attempt';
	const META_STATUS      = '_pglp_status';
	const META_ACTION      = '_pglp_action';
	const META_PAYMENT_ID  = '_pglp_payment_id';
	const META_ORDER_ID    = '_pglp_liqpay_order_id';
	const META_CARD_MASK   = '_pglp_card_mask';
	const META_CARD_TYPE   = '_pglp_card_type';
	const META_CARD_BANK   = '_pglp_card_bank';
	const META_PAYTYPE     = '_pglp_paytype';
	const META_ACQ_ID      = '_pglp_acq_id';
	const META_AMOUNT      = '_pglp_amount_debit';
	const META_COMMISSION  = '_pglp_commission';
	const META_RRN         = '_pglp_rrn';
	const META_AUTHCODE    = '_pglp_authcode';
	const META_IS_3DS      = '_pglp_is_3ds';
	const META_CARD_TOKEN  = '_pglp_card_token';
	const META_HOLD        = '_pglp_hold';
	const META_SEEN        = '_pglp_seen_events';
	const META_SANDBOX     = '_pglp_sandbox';
	const META_RELEASED    = '_pglp_released';

	/**
	 * Builds the reference sent to LiqPay for an order.
	 *
	 * LiqPay treats order_id as unique, so a retry after a declined card needs a fresh value or
	 * the service answers with an "order already exists" error.
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param string   $prefix  Optional merchant prefix.
	 * @param bool     $renew   Whether to start a new attempt.
	 * @return string
	 */
	public static function build_reference( $order, $prefix = '', $renew = false ) {
		$attempt = absint( $order->get_meta( self::META_ATTEMPT ) );

		if ( $renew || $attempt < 1 ) {
			++$attempt;
			$order->update_meta_data( self::META_ATTEMPT, $attempt );
		}

		$reference = $prefix . $order->get_id();

		if ( $attempt > 1 ) {
			$reference .= '-' . $attempt;
		}

		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->save();

		return $reference;
	}

	/**
	 * The reference currently associated with an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function get_reference( $order ) {
		$reference = (string) $order->get_meta( self::META_REFERENCE );

		return '' !== $reference ? $reference : (string) $order->get_id();
	}

	/**
	 * Finds the order a LiqPay reference belongs to.
	 *
	 * @param string $reference Value LiqPay reported as order_id.
	 * @param bool   $deep      Whether to fall back to a meta lookup, which scans the order meta
	 *                          table and should only run for an authenticated request.
	 * @return WC_Order|false
	 */
	public static function find_order( $reference, $deep = true ) {
		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return false;
		}

		// The cheap lookup reads an order id out of the reference and then makes the order prove
		// the reference is its own. Reading alone is never enough: a prefix that ends in digits
		// makes "<prefix><id>" and "<base>-<attempt>" look exactly alike.
		foreach ( self::candidate_ids( $reference ) as $candidate ) {
			$order = wc_get_order( $candidate );

			if ( $order && self::reference_belongs_to( $reference, $order ) ) {
				return $order;
			}
		}

		if ( ! $deep ) {
			return false;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_REFERENCE,
						'value' => $reference,
					),
				),
			)
		);

		if ( ! empty( $orders ) ) {
			return wc_get_order( $orders[0] );
		}

		return false;
	}

	/**
	 * The order ids a reference could be pointing at.
	 *
	 * A reference is "<prefix><order id>" plus "-<attempt>" from the second attempt on, and the
	 * prefix is free text that may itself end in digits or contain hyphens. Every reading of the
	 * string is offered here; reference_belongs_to() decides which one is real, so a wrong guess
	 * costs one lookup by primary key and nothing else.
	 *
	 * @param string $reference Value LiqPay reported as order_id.
	 * @return int[]
	 */
	private static function candidate_ids( $reference ) {
		$ids    = array();
		$prefix = self::configured_prefix();

		// The prefix in force today, which is the one most references carry.
		if ( '' !== $prefix && 0 === strpos( $reference, $prefix ) ) {
			$rest = substr( $reference, strlen( $prefix ) );

			if ( preg_match( '/^(\d+)(?:-\d+)?$/', $rest, $matches ) ) {
				$ids[] = absint( $matches[1] );
			}
		}

		// The trailing digits, which is the whole reference on a first attempt.
		if ( preg_match( '/(\d+)$/', $reference, $matches ) ) {
			$ids[] = absint( $matches[1] );
		}

		// The digits before a trailing "-<attempt>".
		if ( preg_match( '/(\d+)-\d+$/', $reference, $matches ) ) {
			$ids[] = absint( $matches[1] );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether a reference names this order, whichever attempt it was issued for.
	 *
	 * @param string   $reference Value LiqPay reported as order_id.
	 * @param WC_Order $order     Candidate order.
	 * @return bool
	 */
	private static function reference_belongs_to( $reference, $order ) {
		$base = self::base_reference( $order );

		if ( '' === $base ) {
			return false;
		}

		if ( $reference === $base ) {
			return true;
		}

		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '-\d+$/', $reference );
	}

	/**
	 * An order's reference without the attempt counter.
	 *
	 * The counter is cut by the number the order itself carries rather than by the shape of the
	 * string. Cutting the last "-<digits>" blindly is what let a payment land on a stranger's
	 * order: with the prefix "A85-", order 85 stores "A85-85", and trimming that leaves "A85",
	 * which every reference under this prefix starts with.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function base_reference( $order ) {
		$reference = self::get_reference( $order );
		$attempt   = absint( $order->get_meta( self::META_ATTEMPT ) );

		if ( $attempt > 1 ) {
			$suffix = '-' . $attempt;

			if ( substr( $reference, -strlen( $suffix ) ) === $suffix ) {
				$reference = substr( $reference, 0, -strlen( $suffix ) );
			}
		}

		return $reference;
	}

	/**
	 * The reference prefix the gateway is configured with right now.
	 *
	 * Read from the option rather than the gateway so the notification endpoint can use it
	 * before WooCommerce has built its payment methods.
	 *
	 * @return string
	 */
	private static function configured_prefix() {
		$settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );

		return isset( $settings['reference_prefix'] ) ? (string) $settings['reference_prefix'] : '';
	}

	/**
	 * Whether LiqPay blocked an amount for this order that has not been settled yet.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function has_open_hold( $order ) {
		return 'yes' === $order->get_meta( self::META_HOLD );
	}

	/**
	 * Drops the blocked-amount flag once the block is over.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function clear_hold( $order ) {
		if ( ! self::has_open_hold( $order ) ) {
			return;
		}

		$order->delete_meta_data( self::META_HOLD );
		$order->save();
	}

	/**
	 * Stores the descriptive fields LiqPay returns about a payment.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Decoded LiqPay payload.
	 */
	public static function store_payment_data( $order, $payload ) {
		$simple = array(
			'status'          => self::META_STATUS,
			'action'          => self::META_ACTION,
			'payment_id'      => self::META_PAYMENT_ID,
			'liqpay_order_id' => self::META_ORDER_ID,
			'paytype'         => self::META_PAYTYPE,
			'acq_id'          => self::META_ACQ_ID,
			'amount_debit'    => self::META_AMOUNT,
			'rrn_debit'       => self::META_RRN,
			'authcode_debit'  => self::META_AUTHCODE,
			'sender_card_mask2' => self::META_CARD_MASK,
			'sender_card_type'  => self::META_CARD_TYPE,
			'sender_card_bank'  => self::META_CARD_BANK,
		);

		foreach ( $simple as $field => $meta_key ) {
			if ( isset( $payload[ $field ] ) && '' !== $payload[ $field ] ) {
				$order->update_meta_data( $meta_key, sanitize_text_field( (string) $payload[ $field ] ) );
			}
		}

		if ( isset( $payload['is_3ds'] ) ) {
			$order->update_meta_data( self::META_IS_3DS, wc_bool_to_string( wc_string_to_bool( (string) $payload['is_3ds'] ) ) );
		}

		$commission = 0;

		foreach ( array( 'sender_commission', 'receiver_commission', 'commission_debit' ) as $field ) {
			if ( isset( $payload[ $field ] ) && (float) $payload[ $field ] > 0 ) {
				$commission = (float) $payload[ $field ];
				break;
			}
		}

		if ( $commission > 0 ) {
			$order->update_meta_data( self::META_COMMISSION, $commission );
		}

		if ( ! empty( $payload['card_token'] ) ) {
			$order->update_meta_data( self::META_CARD_TOKEN, sanitize_text_field( (string) $payload['card_token'] ) );
		}

		$order->save();
	}

	/**
	 * Checks that a payload describes the money the shop is actually owed.
	 *
	 * A notification that omits the amount or the currency is not a valid confirmation, so a
	 * missing field is treated the same as a wrong one.
	 *
	 * @param WC_Order   $order    Order.
	 * @param array      $payload  Decoded LiqPay payload.
	 * @param float|null $expected Amount the shop is expecting, when it is known.
	 * @return string Empty string when the payload can be trusted.
	 */
	public static function validate_amount( $order, $payload, $expected = null ) {
		$status = isset( $payload['status'] ) ? (string) $payload['status'] : '';

		if ( ! in_array( PGLP_Statuses::classify( $status ), array( 'paid', 'held', 'review' ), true ) ) {
			return '';
		}

		if ( null === $expected ) {
			$expected = (float) $order->get_total();
		}

		if ( empty( $payload['currency'] ) ) {
			return __( 'the notification did not say which currency was paid.', 'vitaliihura-checkout-for-liqpay' );
		}

		if ( strtoupper( (string) $payload['currency'] ) !== strtoupper( $order->get_currency() ) ) {
			return __( 'the currency does not match the order.', 'vitaliihura-checkout-for-liqpay' );
		}

		if ( ! isset( $payload['amount'] ) || '' === $payload['amount'] ) {
			return __( 'the notification did not say how much was paid.', 'vitaliihura-checkout-for-liqpay' );
		}

		// One cent of slack absorbs rounding differences between the shop and the processor.
		if ( (float) $payload['amount'] + 0.01 < $expected ) {
			return __( 'the amount is lower than the order total.', 'vitaliihura-checkout-for-liqpay' );
		}

		return '';
	}

	/**
	 * Whether a payload for a status LiqPay never sent us should be ignored.
	 *
	 * LiqPay answers a status query with "error" both for a declined payment and for a reference
	 * it has never seen, which is the normal answer for an order the customer abandoned before
	 * reaching the payment page.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Decoded LiqPay payload.
	 * @return bool
	 */
	public static function is_unknown_to_liqpay( $order, $payload ) {
		$status = isset( $payload['status'] ) ? (string) $payload['status'] : '';

		return 'error' === $status && '' === (string) $order->get_meta( self::META_STATUS );
	}

	/**
	 * Applies a LiqPay payload to an order.
	 *
	 * @param WC_Order     $order    Order.
	 * @param array        $payload  Decoded LiqPay payload.
	 * @param PGLP_Gateway $gateway  Gateway instance.
	 * @param string       $origin   Where the payload came from, for the order note.
	 * @param float|null   $expected Amount the shop is expecting, when it is known.
	 * @return bool Whether the order was changed.
	 */
	public static function apply( $order, $payload, $gateway, $origin = 'callback', $expected = null ) {
		$status = isset( $payload['status'] ) ? sanitize_text_field( (string) $payload['status'] ) : '';

		if ( '' === $status ) {
			return false;
		}

		// The sandbox status may only ever settle an order that was itself placed in test mode.
		if ( 'sandbox' === $status && 'yes' !== $order->get_meta( self::META_SANDBOX ) ) {
			PGLP_Logger::error(
				'Refused a test payment status on a live order.',
				array( 'order_id' => $order->get_id() )
			);

			return false;
		}

		$problem = self::validate_amount( $order, $payload, $expected );

		if ( '' !== $problem ) {
			PGLP_Logger::error(
				'Refused a payment confirmation that did not match the order.',
				array(
					'order_id' => $order->get_id(),
					'reason'   => $problem,
					'origin'   => $origin,
				)
			);

			$order->add_order_note(
				sprintf(
					/* translators: %s: short explanation of why the confirmation was refused. */
					__( 'A LiqPay payment confirmation for this order was refused: %s', 'vitaliihura-checkout-for-liqpay' ),
					$problem
				)
			);

			return false;
		}

		$previous = (string) $order->get_meta( self::META_STATUS );

		self::store_payment_data( $order, $payload );

		if ( $previous === $status && 'reversed' !== $status ) {
			// Nothing new to act on, but the stored fields above may have been refreshed.
			return false;
		}

		$group = PGLP_Statuses::classify( $status );

		/**
		 * Fires before the order status is changed in response to a LiqPay payload.
		 *
		 * @param WC_Order $order   Order.
		 * @param array    $payload Decoded LiqPay payload.
		 * @param string   $group   Status group.
		 */
		do_action( 'pglp_before_apply_status', $order, $payload, $group );

		// A blocked amount is settled once the money has moved one way or the other, so the flag
		// goes before the status is applied rather than inside any one branch.
		if ( in_array( $group, array( 'paid', 'failed', 'reversed' ), true ) ) {
			self::clear_hold( $order );
		}

		switch ( $group ) {
			case 'paid':
				self::mark_paid( $order, $payload, $gateway );
				break;

			case 'held':
				self::mark_held( $order, $payload, $gateway );
				break;

			case 'review':
				self::mark_review( $order, $payload, $gateway );
				break;

			case 'failed':
				self::mark_failed( $order, $payload );
				break;

			case 'reversed':
				self::mark_reversed( $order, $payload );
				break;

			case 'reserved':
				// The money is still with LiqPay while it works through a refund request. The
				// order is left as it is; the refund itself arrives later as a reversal.
				self::note( $order, $payload, __( 'LiqPay has reserved the funds against a refund request.', 'vitaliihura-checkout-for-liqpay' ) );
				break;

			default:
				self::note( $order, $payload, PGLP_Statuses::label( $status ) );
				break;
		}

		PGLP_Logger::log(
			'info',
			'Applied LiqPay status to order.',
			array(
				'order_id' => $order->get_id(),
				'status'   => $status,
				'group'    => $group,
				'origin'   => $origin,
			)
		);

		/**
		 * Fires after an order has been updated from a LiqPay payload.
		 *
		 * @param WC_Order $order   Order.
		 * @param array    $payload Decoded LiqPay payload.
		 * @param string   $group   Status group.
		 */
		do_action( 'pglp_payment_processed', $order, $payload, $group );

		return true;
	}

	/**
	 * Completes payment for an order.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array        $payload Decoded LiqPay payload.
	 * @param PGLP_Gateway $gateway Gateway instance.
	 */
	private static function mark_paid( $order, $payload, $gateway ) {
		if ( $order->is_paid() ) {
			return;
		}

		self::note( $order, $payload, __( 'LiqPay confirmed the payment.', 'vitaliihura-checkout-for-liqpay' ) );

		$transaction_id = '';

		foreach ( array( 'liqpay_order_id', 'payment_id', 'transaction_id' ) as $field ) {
			if ( ! empty( $payload[ $field ] ) ) {
				$transaction_id = sanitize_text_field( (string) $payload[ $field ] );
				break;
			}
		}

		$order->payment_complete( $transaction_id );

		$target = (string) $gateway->get_option( 'status_paid', '' );

		if ( '' !== $target && 'default' !== $target && ! $order->has_status( $target ) ) {
			$order->update_status( $target );
		}

		if ( 'yes' === $gateway->get_option( 'email_receipt', 'no' ) ) {
			$gateway->request_receipt( $order );
		}

		$order->save();
	}

	/**
	 * Marks an order as having a blocked amount waiting to be captured.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array        $payload Decoded LiqPay payload.
	 * @param PGLP_Gateway $gateway Gateway instance.
	 */
	private static function mark_held( $order, $payload, $gateway ) {
		if ( $order->is_paid() ) {
			// A block notification that arrives after the money was taken would otherwise put a
			// settled order back on hold and offer to refund it.
			self::note( $order, $payload, __( 'LiqPay reported a card block for an order that is already paid. No change was made.', 'vitaliihura-checkout-for-liqpay' ) );

			return;
		}

		$order->update_meta_data( self::META_HOLD, 'yes' );
		$order->save();

		self::note(
			$order,
			$payload,
			__( 'LiqPay blocked the amount on the card. Capture or release it in your LiqPay account.', 'vitaliihura-checkout-for-liqpay' )
		);

		$target = (string) $gateway->get_option( 'status_hold', 'on-hold' );

		if ( ! $order->has_status( $target ) ) {
			$order->update_status( $target );
		}
	}

	/**
	 * Marks an order that LiqPay is still verifying.
	 *
	 * @param WC_Order     $order   Order.
	 * @param array        $payload Decoded LiqPay payload.
	 * @param PGLP_Gateway $gateway Gateway instance.
	 */
	private static function mark_review( $order, $payload, $gateway ) {
		if ( $order->is_paid() ) {
			return;
		}

		self::note( $order, $payload, PGLP_Statuses::label( $payload['status'] ) );

		$target = (string) $gateway->get_option( 'status_review', 'on-hold' );

		if ( '' !== $target && ! $order->has_status( $target ) ) {
			$order->update_status( $target );
		}
	}

	/**
	 * Marks an order as failed.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Decoded LiqPay payload.
	 */
	private static function mark_failed( $order, $payload ) {
		if ( $order->is_paid() ) {
			// A later failure notification must never undo a completed payment.
			self::note( $order, $payload, __( 'LiqPay reported a failure for an order that is already paid. No change was made.', 'vitaliihura-checkout-for-liqpay' ) );

			return;
		}

		$reason = self::error_text( $payload );
		$note   = __( 'LiqPay declined the payment.', 'vitaliihura-checkout-for-liqpay' );

		if ( '' !== $reason ) {
			$note .= ' ' . $reason;
		}

		if ( ! $order->has_status( 'failed' ) ) {
			$order->update_status( 'failed', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Records a refund that was made outside WooCommerce.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Decoded LiqPay payload.
	 */
	private static function mark_reversed( $order, $payload ) {
		$amount = 0.0;

		foreach ( array( 'refund_amount', 'amount_debit', 'amount' ) as $field ) {
			if ( isset( $payload[ $field ] ) && (float) $payload[ $field ] > 0 ) {
				$amount = (float) $payload[ $field ];
				break;
			}
		}

		if ( $amount <= 0 ) {
			// LiqPay does not always say how much came back, and guessing the full total would
			// overstate the refund on the order.
			self::note( $order, $payload, __( 'LiqPay reported a refund without an amount. Check the payment in your LiqPay account and record the refund by hand.', 'vitaliihura-checkout-for-liqpay' ) );

			return;
		}

		// Money released by a partial capture is already booked as a refund, but it was never
		// captured, so it must not be counted against what LiqPay is returning now.
		$released = (float) $order->get_meta( self::META_RELEASED );
		$captured = (float) $order->get_total() - $released;
		$already  = max( 0.0, (float) $order->get_total_refunded() - $released );

		$outstanding = min( $amount - $already, $captured - $already );

		if ( $outstanding <= 0 ) {
			self::note(
				$order,
				$payload,
				__( 'LiqPay reported a refund that is already recorded on this order, so nothing was changed.', 'vitaliihura-checkout-for-liqpay' )
			);

			return;
		}

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $outstanding,
				'reason'   => __( 'Refunded in LiqPay.', 'vitaliihura-checkout-for-liqpay' ),
			)
		);

		if ( is_wp_error( $refund ) ) {
			self::note( $order, $payload, __( 'LiqPay reported a refund but WooCommerce could not record it.', 'vitaliihura-checkout-for-liqpay' ) );

			return;
		}

		self::note(
			$order,
			$payload,
			sprintf(
				/* translators: %s: refunded amount with currency. */
				__( 'LiqPay refunded %s.', 'vitaliihura-checkout-for-liqpay' ),
				wp_strip_all_tags( wc_price( $outstanding, array( 'currency' => $order->get_currency() ) ) )
			)
		);
	}

	/**
	 * Adds an order note describing the LiqPay payload.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Decoded LiqPay payload.
	 * @param string   $summary Leading sentence.
	 */
	public static function note( $order, $payload, $summary ) {
		$parts = array( $summary );

		if ( ! empty( $payload['status'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: LiqPay payment status. */
				__( 'Status: %s.', 'vitaliihura-checkout-for-liqpay' ),
				sanitize_text_field( (string) $payload['status'] )
			);
		}

		if ( ! empty( $payload['liqpay_order_id'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: identifier of the payment inside LiqPay. */
				__( 'LiqPay reference: %s.', 'vitaliihura-checkout-for-liqpay' ),
				sanitize_text_field( (string) $payload['liqpay_order_id'] )
			);
		}

		if ( ! empty( $payload['paytype'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: payment method used by the customer. */
				__( 'Method: %s.', 'vitaliihura-checkout-for-liqpay' ),
				sanitize_text_field( (string) $payload['paytype'] )
			);
		}

		if ( ! empty( $payload['sender_card_mask2'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: masked card number. */
				__( 'Card: %s.', 'vitaliihura-checkout-for-liqpay' ),
				sanitize_text_field( (string) $payload['sender_card_mask2'] )
			);
		}

		$order->add_order_note( implode( ' ', $parts ) );
	}

	/**
	 * Extracts a readable error message from a payload.
	 *
	 * @param array $payload Decoded LiqPay payload.
	 * @return string
	 */
	public static function error_text( $payload ) {
		$text = '';

		if ( ! empty( $payload['err_description'] ) ) {
			$text = sanitize_text_field( (string) $payload['err_description'] );
		}

		$code = '';

		foreach ( array( 'err_code', 'err_erc' ) as $field ) {
			if ( ! empty( $payload[ $field ] ) ) {
				$code = sanitize_text_field( (string) $payload[ $field ] );
				break;
			}
		}

		if ( '' !== $code ) {
			$text = '' !== $text ? $text . ' (' . $code . ')' : $code;
		}

		return $text;
	}

	/**
	 * Records a payload so a repeated delivery can be ignored.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $data  Raw base64 payload.
	 * @return bool False when this exact payload was already handled.
	 */
	public static function claim_event( $order, $data ) {
		$fingerprint = md5( (string) $data );
		$seen        = $order->get_meta( self::META_SEEN );
		$seen        = is_array( $seen ) ? $seen : array();

		if ( in_array( $fingerprint, $seen, true ) ) {
			return false;
		}

		$seen[] = $fingerprint;

		$order->update_meta_data( self::META_SEEN, array_slice( $seen, -20 ) );
		$order->save();

		return true;
	}
}
