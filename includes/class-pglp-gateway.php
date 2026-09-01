<?php
/**
 * The LiqPay payment gateway.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends customers to the LiqPay payment page and reconciles the result.
 */
class PGLP_Gateway extends WC_Payment_Gateway {

	/**
	 * Sets the gateway up.
	 */
	public function __construct() {
		$this->id                 = PGLP_GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'LiqPay', 'vitaliihura-checkout-for-liqpay' );
		$this->method_description = __( 'Accept card, Apple Pay, Google Pay and Privat24 payments through LiqPay.', 'vitaliihura-checkout-for-liqpay' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_localized( 'title' );
		$this->description = $this->get_localized( 'description' );
		$this->icon        = $this->resolve_icon();

		$this->supports = array( 'products', 'refunds' );

		/**
		 * Filters the features the gateway declares to WooCommerce.
		 *
		 * @param array        $supports Feature list.
		 * @param PGLP_Gateway $gateway  Gateway instance.
		 */
		$this->supports = apply_filters( 'pglp_gateway_supports', $this->supports, $this );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_availability' ) );
	}

	/**
	 * Loads the settings definition.
	 */
	public function init_form_fields() {
		$this->form_fields = PGLP_Gateway_Settings::fields();
	}

	/* ------------------------------------------------------------ values --- */

	/**
	 * A setting that can differ per site language.
	 *
	 * @param string        $key   Setting key.
	 * @param WC_Order|null $order Order whose language should be used, when there is one.
	 * @return string
	 */
	public function get_localized( $key, $order = null ) {
		$default      = (string) $this->get_option( $key, '' );
		$translations = $this->get_option( $key . '_i18n' );

		return PGLP_I18n::resolve(
			$default,
			is_array( $translations ) ? $translations : array(),
			PGLP_I18n::current_locale( $order )
		);
	}

	/* ------------------------------------------------------- availability --- */

	/**
	 * Whether the gateway can be offered on this cart.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! $this->api()->has_credentials() ) {
			return false;
		}

		return in_array( get_woocommerce_currency(), PGLP_API::CURRENCIES, true );
	}

	/**
	 * Hides the method from customers while the shop is testing on a live site.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public function filter_availability( $gateways ) {
		if ( ! isset( $gateways[ $this->id ] ) ) {
			return $gateways;
		}

		$restricted = 'yes' === $this->get_option( 'test_mode_for_admins', 'no' ) && $this->is_test_mode();

		if ( $restricted && ! current_user_can( 'manage_woocommerce' ) ) {
			unset( $gateways[ $this->id ] );
		}

		return $gateways;
	}

	/* ---------------------------------------------------------- checkout --- */

	/**
	 * Renders the checkout fields.
	 */
	public function payment_fields() {
		$description = $this->get_description();

		if ( $this->is_test_mode() ) {
			$description = trim( $description . ' ' . __( 'Test mode is on. No money will be taken.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		if ( '' !== $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
	}

	/**
	 * Handles a checkout submission.
	 *
	 * @param int $order_id Order identifier.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( PGLP_Order_Handler::META_SANDBOX, $this->is_test_mode() ? 'yes' : 'no' );

		// Recorded so the payment purpose, the receipt and the LiqPay page all follow the
		// language the customer was actually browsing in, not the shop default.
		$order->update_meta_data( PGLP_I18n::ORDER_LOCALE, determine_locale() );
		$order->save();

		PGLP_Order_Handler::build_reference( $order, $this->get_reference_prefix(), $order->has_status( array( 'failed', 'cancelled' ) ) );

		if ( $order->get_total() > 0 && ! $order->has_status( 'pending' ) ) {
			$order->update_status( 'pending', __( 'Waiting for the customer to pay at LiqPay.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Prints the form that hands the customer over to LiqPay.
	 *
	 * A form post is used rather than a link because the request can carry split rules, which
	 * grow well past a safe URL length.
	 *
	 * @param int $order_id Order identifier.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		wp_enqueue_style( 'pglp-checkout' );

		$api = $this->api_for_order( $order );

		echo '<div class="pc-checkout"><div class="pc-redirect">';

		if ( ! $api ) {
			printf(
				'<p class="pc-redirect__error">%s</p></div></div>',
				esc_html__( 'LiqPay is not configured, so this order cannot be paid right now.', 'vitaliihura-checkout-for-liqpay' )
			);

			return;
		}

		$builder = new PGLP_Request_Builder( $this );
		$params  = $builder->build( $order, PGLP_Order_Handler::get_reference( $order ) );
		$form    = $api->checkout_form( $params );

		if ( is_wp_error( $form ) ) {
			PGLP_Logger::error(
				'Could not build the LiqPay checkout request.',
				array(
					'order_id' => $order->get_id(),
					'error'    => $form->get_error_message(),
				)
			);

			printf( '<p class="pc-redirect__error">%s</p></div></div>', esc_html( $form->get_error_message() ) );

			return;
		}

		PGLP_Logger::debug(
			'Sending the customer to LiqPay.',
			array(
				'order_id' => $order->get_id(),
				'params'   => PGLP_Logger::scrub( $params ),
			)
		);

		wp_enqueue_script( 'pglp-redirect' );

		?>
			<span class="pc-redirect__glyph"><?php PGLP_UI::glyph( 20 ); ?></span>
			<h2 class="pc-redirect__title"><?php esc_html_e( 'Taking you to LiqPay', 'vitaliihura-checkout-for-liqpay' ); ?></h2>
			<p class="pc-redirect__note"><?php esc_html_e( 'If nothing happens in a few seconds, use the button below.', 'vitaliihura-checkout-for-liqpay' ); ?></p>

			<form id="pglp-redirect-form" method="post" action="<?php echo esc_url( $form['url'] ); ?>" accept-charset="utf-8">
				<input type="hidden" name="data" value="<?php echo esc_attr( $form['data'] ); ?>" />
				<input type="hidden" name="signature" value="<?php echo esc_attr( $form['signature'] ); ?>" />
				<span class="pc-redirect__actions">
					<button type="submit" class="pc-redirect__btn pc-redirect__btn--primary"><?php esc_html_e( 'Pay now', 'vitaliihura-checkout-for-liqpay' ); ?></button>
					<a class="pc-redirect__btn pc-redirect__btn--ghost" href="<?php echo esc_url( $order->get_cancel_order_url() ); ?>"><?php esc_html_e( 'Cancel', 'vitaliihura-checkout-for-liqpay' ); ?></a>
				</span>
			</form>
		</div></div>
		<?php
	}

	/**
	 * Refunds all or part of an order.
	 *
	 * @param int    $order_id Order identifier.
	 * @param float  $amount   Amount to refund.
	 * @param string $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'pglp_no_order', __( 'Order not found.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		$amount = null === $amount ? (float) $order->get_total() : (float) $amount;

		if ( $amount <= 0 ) {
			return new WP_Error( 'pglp_bad_amount', __( 'The refund amount must be above zero.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		$api = $this->api_for_order( $order );

		if ( ! $api ) {
			return new WP_Error( 'pglp_no_credentials', __( 'LiqPay API keys are not configured.', 'vitaliihura-checkout-for-liqpay' ) );
		}

		$response = $api->refund( PGLP_Order_Handler::get_reference( $order ), $amount );
		$error    = PGLP_API::response_error( $response );

		if ( $error ) {
			return $error;
		}

		$note = sprintf(
			/* translators: %s: refunded amount with currency. */
			__( 'LiqPay refunded %s.', 'vitaliihura-checkout-for-liqpay' ),
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) )
		);

		if ( '' !== $reason ) {
			$note .= ' ' . sprintf(
				/* translators: %s: reason entered by the shop manager. */
				__( 'Reason: %s', 'vitaliihura-checkout-for-liqpay' ),
				sanitize_text_field( $reason )
			);
		}

		// LiqPay answers wait_amount when the money will come out of future settlements rather
		// than from the merchant account, which is worth recording on the order.
		if ( isset( $response['wait_amount'] ) && wc_string_to_bool( (string) $response['wait_amount'] ) ) {
			$note .= ' ' . __( 'The amount will be taken from an upcoming settlement.', 'vitaliihura-checkout-for-liqpay' );
		}

		$order->add_order_note( $note );

		return true;
	}

	/**
	 * Asks LiqPay to email its own receipt for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function request_receipt( $order ) {
		$email = $order->get_billing_email();

		if ( ! is_email( $email ) ) {
			return;
		}

		$api = $this->api_for_order( $order );

		if ( ! $api ) {
			return;
		}

		$api->send_receipt(
			PGLP_Order_Handler::get_reference( $order ),
			$email,
			PGLP_I18n::liqpay_language( PGLP_I18n::current_locale( $order ) )
		);
	}

	/* ---------------------------------------------------------- settings --- */

	/**
	 * Renders the settings screen.
	 */
	public function admin_options() {
		global $hide_save_button;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce owns this global; it tells the settings screen to drop its own save button.
		$hide_save_button = true;

		wp_enqueue_style( 'pglp-admin' );
		wp_enqueue_script( 'pglp-admin' );

		$sections = PGLP_Gateway_Settings::sections();
		$all      = PGLP_Gateway_Settings::fields();
		$state    = $this->connection_state();

		echo '<div class="pc pc-pglp pc-layout">';

		PGLP_UI::hero(
			__( 'LiqPay', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Card, Apple Pay, Google Pay, Privat24', 'vitaliihura-checkout-for-liqpay' ),
			$state['pills']
		);

		$this->render_status_panel( $state );

		PGLP_UI::index( $sections );
		echo '<div class="pc-main">';

		foreach ( $sections as $section ) {
			printf(
				'<section class="pc-card" id="pglp-%s"><div class="pc-card__head"><h2>%s</h2><p class="pc__muted">%s</p></div>',
				esc_attr( $section['id'] ),
				esc_html( $section['title'] ),
				esc_html( $section['summary'] )
			);

			foreach ( $section['fields'] as $key => $field ) {
				if ( 'callback_url' === $key ) {
					$field['pc_value'] = PGLP_Callback::endpoint_url();
				}

				PGLP_UI::field( $this, $key, $field, $all );
			}

			echo '</section>';
		}

		?>
		<div class="pc-savebar">
			<span class="pc__muted"><?php esc_html_e( 'Changes apply as soon as they are saved.', 'vitaliihura-checkout-for-liqpay' ); ?></span>
			<span class="pc-status__actions">
				<a class="pc-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>"><?php esc_html_e( 'View logs', 'vitaliihura-checkout-for-liqpay' ); ?></a>
				<button type="submit" class="pc-btn pc-btn--primary woocommerce-save-button" name="save" value="1"><?php esc_html_e( 'Save changes', 'vitaliihura-checkout-for-liqpay' ); ?></button>
			</span>
		</div>
		<?php

		echo '</div></div>';
	}

	/**
	 * Works out what the panel above the settings should say.
	 *
	 * @return array
	 */
	private function connection_state() {
		$has_keys = $this->api()->has_credentials();
		$enabled  = 'yes' === $this->get_option( 'enabled', 'no' );
		$test     = $this->is_test_mode();
		$pills    = array();

		if ( $test ) {
			$pills[] = array(
				'label' => __( 'Test mode', 'vitaliihura-checkout-for-liqpay' ),
				'type'  => 'warn',
			);
		}

		if ( ! $has_keys ) {
			return array(
				'tone'    => 'attention',
				'title'   => __( 'Add your API keys to start', 'vitaliihura-checkout-for-liqpay' ),
				'note'    => __( 'The method stays hidden at checkout until both keys are in place.', 'vitaliihura-checkout-for-liqpay' ),
				'pills'   => $pills,
				'testable' => false,
			);
		}

		if ( ! $enabled ) {
			return array(
				'tone'    => 'attention',
				'title'   => __( 'Keys are in place, the method is switched off', 'vitaliihura-checkout-for-liqpay' ),
				'note'    => __( 'Turn on Availability below to offer LiqPay at checkout.', 'vitaliihura-checkout-for-liqpay' ),
				'pills'   => $pills,
				'testable' => true,
			);
		}

		$last = (int) get_option( 'pglp_last_callback', 0 );
		$note = $last > 0
			? sprintf(
				/* translators: %s: human readable time difference, for example "12 minutes". */
				__( 'Last notification from LiqPay %s ago.', 'vitaliihura-checkout-for-liqpay' ),
				PGLP_UI::time_ago( $last )
			)
			: __( 'No notification has arrived from LiqPay yet.', 'vitaliihura-checkout-for-liqpay' );

		array_unshift(
			$pills,
			array(
				'label' => __( 'Enabled', 'vitaliihura-checkout-for-liqpay' ),
				'type'  => 'ok',
			)
		);

		return array(
			'tone'     => 'ok',
			'title'    => __( 'Ready to take payments', 'vitaliihura-checkout-for-liqpay' ),
			'note'     => $note,
			'pills'    => $pills,
			'testable' => true,
		);
	}

	/**
	 * Prints the panel above the settings.
	 *
	 * @param array $state Result of connection_state().
	 */
	private function render_status_panel( $state ) {
		printf( '<div class="pc-status%s">', 'attention' === $state['tone'] ? ' pc-status--attention' : '' );

		printf(
			'<span class="pc-status__main"><span class="pc-status__title">%s</span><span class="pc__muted">%s</span></span>',
			esc_html( $state['title'] ),
			esc_html( $state['note'] )
		);

		echo '<span class="pc-status__actions">';

		if ( $state['testable'] ) {
			printf(
				'<button type="button" class="pc-btn pc-js-test-connection">%s</button>',
				esc_html__( 'Test connection', 'vitaliihura-checkout-for-liqpay' )
			);
		}

		echo '</span>';
		echo '<span class="pc-feedback pc-js-test-feedback" role="status" aria-live="polite"></span>';
		echo '</div>';
	}

	/* -------------------------------------------------------- validators --- */

	/**
	 * A secret is stored the same way as any other text.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_pc_secret_field( $key, $value ) {
		return $this->validate_text_field( $key, $value );
	}

	/**
	 * The default language value of a translatable text field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_pc_i18n_text_field( $key, $value ) {
		return $this->validate_text_field( $key, $value );
	}

	/**
	 * The default language value of a translatable textarea.
	 *
	 * @param string $key   Field key.
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function validate_pc_i18n_textarea_field( $key, $value ) {
		return $this->validate_textarea_field( $key, $value );
	}

	/**
	 * The per-locale values behind a translatable field.
	 *
	 * The submitted values are merged over what is already stored rather than replacing it. A
	 * language that was not on the screen this time — because the multilingual plugin is
	 * inactive, or because the site language list changed — must not lose its translation just
	 * because someone pressed Save.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return array
	 */
	public function validate_pc_hidden_map_field( $key, $value ) {
		$stored = $this->get_option( $key );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! is_array( $value ) ) {
			return $stored;
		}

		$languages = wp_list_pluck( PGLP_I18n::languages(), 'locale' );
		$default   = PGLP_I18n::default_locale();

		foreach ( $languages as $locale ) {
			if ( $locale === $default ) {
				continue;
			}

			$text = isset( $value[ $locale ] ) ? sanitize_textarea_field( wp_unslash( (string) $value[ $locale ] ) ) : '';

			if ( '' !== trim( $text ) ) {
				$stored[ $locale ] = $text;
			} else {
				unset( $stored[ $locale ] );
			}
		}

		// The default language is edited through the plain field, so a stale copy of it in the
		// map would quietly win over what the merchant just typed.
		unset( $stored[ $default ] );

		return $stored;
	}

	/**
	 * The notification URL is shown, never stored.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return string
	 */
	public function validate_pc_copy_field( $key, $value ) {
		return '';
	}

	/* ------------------------------------------------------------- state --- */

	/**
	 * The action used when creating a payment.
	 *
	 * @param WC_Order|null $order Order, when one is known.
	 * @return string
	 */
	public function get_payment_action( $order = null ) {
		$action = 'pay';

		/**
		 * Filters the LiqPay action used to create a payment.
		 *
		 * @param string        $action Either pay or hold.
		 * @param WC_Order|null $order  Order being paid.
		 */
		return apply_filters( 'pglp_payment_action', $action, $order );
	}

	/**
	 * The payment methods to show on the LiqPay page.
	 *
	 * @return array
	 */
	public function get_paytypes() {
		$selected = $this->get_option( 'paytypes', array() );

		if ( ! is_array( $selected ) ) {
			$selected = array();
		}

		$allowed = array_diff(
			array_keys( PGLP_Statuses::paytypes() ),
			PGLP_Gateway_Settings::locked_paytypes()
		);

		return array_values( array_intersect( $selected, $allowed ) );
	}

	/**
	 * Keeps the methods that are not offered yet out of the saved setting.
	 *
	 * The checkboxes are disabled in the markup, which stops the browser from sending them, but
	 * a hand-made request would not care about that.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Submitted value.
	 * @return array
	 */
	public function validate_paytypes_field( $key, $value ) {
		$value = is_array( $value ) ? array_map( 'strval', $value ) : array();

		$allowed = array_diff(
			array_keys( PGLP_Statuses::paytypes() ),
			PGLP_Gateway_Settings::locked_paytypes()
		);

		return array_values( array_intersect( $value, $allowed ) );
	}

	/**
	 * The prefix added in front of the order id when talking to LiqPay.
	 *
	 * @return string
	 */
	public function get_reference_prefix() {
		return sanitize_text_field( (string) $this->get_option( 'reference_prefix', '' ) );
	}

	/**
	 * Whether the shop is running against the sandbox keys.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'yes' === $this->get_option( 'test_mode', 'no' );
	}

	/**
	 * An API client for the currently selected mode.
	 *
	 * @return PGLP_API
	 */
	public function api() {
		return $this->is_test_mode() ? $this->sandbox_api() : $this->live_api();
	}

	/**
	 * An API client using the live keys.
	 *
	 * @return PGLP_API
	 */
	public function live_api() {
		return new PGLP_API( $this->get_option( 'public_key', '' ), $this->get_option( 'private_key', '' ) );
	}

	/**
	 * An API client using the sandbox keys.
	 *
	 * @return PGLP_API
	 */
	public function sandbox_api() {
		return new PGLP_API( $this->get_option( 'sandbox_public_key', '' ), $this->get_option( 'sandbox_private_key', '' ) );
	}

	/**
	 * The client whose keys were used when an order was paid.
	 *
	 * Test mode can be switched off after a sandbox order was placed, so the mode is taken from
	 * the order rather than from the current setting. An order that carries no mode at all falls
	 * back to the live keys: guessing "whatever mode is on right now" would let a sandbox key
	 * settle a real order.
	 *
	 * @param WC_Order $order Order.
	 * @return PGLP_API|false
	 */
	public function api_for_order( $order ) {
		$api = 'yes' === $order->get_meta( PGLP_Order_Handler::META_SANDBOX )
			? $this->sandbox_api()
			: $this->live_api();

		return $api->has_credentials() ? $api : false;
	}

	/**
	 * The client that owns a public key.
	 *
	 * @param string $public_key Public key from an incoming notification.
	 * @return PGLP_API|false
	 */
	public function api_for_public_key( $public_key ) {
		$public_key = (string) $public_key;

		if ( '' === $public_key ) {
			return false;
		}

		foreach ( array( $this->live_api(), $this->sandbox_api() ) as $api ) {
			if ( $api->has_credentials() && hash_equals( $api->get_public_key(), $public_key ) ) {
				return $api;
			}
		}

		return false;
	}

	/* -------------------------------------------------------------- icon --- */

	/**
	 * Builds the checkout icon markup.
	 *
	 * @return string
	 */
	private function resolve_icon() {
		$style = $this->get_option( 'icon_style', 'card' );

		if ( 'none' === $style ) {
			return '';
		}

		if ( 'custom' === $style ) {
			$url = trim( (string) $this->get_option( 'icon_url', '' ) );

			return '' !== $url ? esc_url( $url ) : '';
		}

		return PGLP_URL . 'assets/images/payment-card.svg';
	}

	/**
	 * Sizes the checkout icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		if ( '' === $this->icon ) {
			return parent::get_icon();
		}

		$height = absint( $this->get_option( 'icon_height', 24 ) );
		$height = $height > 0 ? $height : 24;

		$html = sprintf(
			'<img src="%1$s" alt="%2$s" class="pglp-icon" style="max-height:%3$dpx" />',
			esc_url( $this->icon ),
			esc_attr( $this->get_title() ),
			$height
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied here because this method replaces the one in the parent class.
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}
}
