<?php
/**
 * Gateway settings definition.
 *
 * Settings are declared as sections rather than a flat list: the same structure builds the
 * section index, the cards on the screen and the field list WooCommerce saves.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the settings form shown under WooCommerce payment settings.
 */
class PGLP_Gateway_Settings {

	/**
	 * The LiqPay methods the plugin does not offer yet.
	 *
	 * They stay in the list so the labels keep working for orders already paid through them, but
	 * they cannot be picked until each one has been tested end to end.
	 *
	 * @return array
	 */
	public static function locked_paytypes() {
		return array( 'moment_part', 'paypart', 'qr', 'cash', 'invoice' );
	}

	/**
	 * The sections, in the order the questions come up.
	 *
	 * @return array
	 */
	public static function sections() {
		$statuses = self::order_statuses();

		return array(
			array(
				'id'       => 'availability',
				'icon'     => 'availability',
				'title'    => __( 'Availability', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'Name and description', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'What the customer sees when choosing how to pay.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::availability(),
			),
			array(
				'id'       => 'keys',
				'icon'     => 'keys',
				'title'    => __( 'API keys', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'Live and test, mode', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'Both key pairs are issued in your LiqPay merchant account. Test keys always begin with sandbox_.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::credentials(),
			),
			array(
				'id'       => 'payment',
				'icon'     => 'payment',
				'title'    => __( 'Payment', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'How the payment is created', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'What LiqPay is asked to do and what the customer sees on its page.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::payment(),
			),
			array(
				'id'       => 'statuses',
				'icon'     => 'statuses',
				'title'    => __( 'Order statuses', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'What to set and when', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'Override these only if your workflow needs something other than the WooCommerce defaults.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::statuses( $statuses ),
			),
			array(
				'id'       => 'appearance',
				'icon'     => 'appearance',
				'title'    => __( 'Appearance', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'Icon at checkout', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'The plugin ships neutral marks. To display the LiqPay logo, download it from the LiqPay brand book, upload it to your media library and point the icon setting at it.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::appearance(),
			),
			array(
				'id'       => 'advanced',
				'icon'     => 'advanced',
				'title'    => __( 'Advanced', 'vitaliihura-checkout-for-liqpay' ),
				'subtitle' => __( 'Notifications, recovery, log', 'vitaliihura-checkout-for-liqpay' ),
				'summary'  => __( 'Nothing here needs changing on a normal shop.', 'vitaliihura-checkout-for-liqpay' ),
				'fields'   => self::advanced(),
			),
		);
	}

	/**
	 * The flat field list WooCommerce stores.
	 *
	 * @return array
	 */
	public static function fields() {
		$fields = array();

		foreach ( self::sections() as $section ) {
			$fields = array_merge( $fields, $section['fields'] );
		}

		return $fields;
	}

	/**
	 * Order statuses without the wc- prefix.
	 *
	 * @return array
	 */
	private static function order_statuses() {
		$statuses = array();

		foreach ( wc_get_order_statuses() as $key => $label ) {
			$statuses[ str_replace( 'wc-', '', $key ) ] = $label;
		}

		return $statuses;
	}

	/**
	 * Name, description and whether the method is offered.
	 *
	 * @return array
	 */
	private static function availability() {
		return array(
			'enabled'          => array(
				'title'   => __( 'Availability', 'vitaliihura-checkout-for-liqpay' ),
				'label'   => __( 'Offer LiqPay at checkout', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'pc_i18n_text',
				'default'     => __( 'Card, Apple Pay, Google Pay', 'vitaliihura-checkout-for-liqpay' ),
				'description' => __( 'The name customers see at checkout.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'title_i18n'       => array(
				'type'    => 'pc_hidden_map',
				'default' => array(),
			),
			'description'      => array(
				'title'   => __( 'Description', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'pc_i18n_textarea',
				'default' => __( 'You will be redirected to the secure LiqPay page to complete the payment.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'description_i18n' => array(
				'type'    => 'pc_hidden_map',
				'default' => array(),
			),
		);
	}

	/**
	 * API keys and test mode.
	 *
	 * @return array
	 */
	private static function credentials() {
		return array(
			'public_key'           => array(
				'title'       => __( 'Public key', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Identifies your shop. Safe to be visible.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'private_key'          => array(
				'title'       => __( 'Private key', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'pc_secret',
				'default'     => '',
				'description' => __( 'Signs every request and verifies every notification. Never share it.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'test_mode'            => array(
				'title'   => __( 'Test mode', 'vitaliihura-checkout-for-liqpay' ),
				'label'   => __( 'Use the sandbox keys instead of the live ones', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'sandbox_public_key'   => array(
				'title'            => __( 'Test public key', 'vitaliihura-checkout-for-liqpay' ),
				'type'             => 'text',
				'default'          => '',
				'placeholder'      => 'sandbox_i...',
				'pc_depends'       => 'test_mode',
				'pc_depends_value' => '1',
			),
			'sandbox_private_key'  => array(
				'title'            => __( 'Test private key', 'vitaliihura-checkout-for-liqpay' ),
				'type'             => 'pc_secret',
				'default'          => '',
				'placeholder'      => 'sandbox_...',
				'pc_depends'       => 'test_mode',
				'pc_depends_value' => '1',
			),
			'test_mode_for_admins' => array(
				'title'            => __( 'Restrict test mode', 'vitaliihura-checkout-for-liqpay' ),
				'label'            => __( 'Hide the method from everyone except shop managers while test mode is on', 'vitaliihura-checkout-for-liqpay' ),
				'type'             => 'checkbox',
				'default'          => 'no',
				'description'      => __( 'Lets you test on a live shop without customers seeing the method.', 'vitaliihura-checkout-for-liqpay' ),
				'pc_depends'       => 'test_mode',
				'pc_depends_value' => '1',
			),
		);
	}

	/**
	 * How the payment is created.
	 *
	 * @return array
	 */
	private static function payment() {
		return array(
			'paytypes'                      => array(
				'title'          => __( 'Methods on the LiqPay page', 'vitaliihura-checkout-for-liqpay' ),
				'type'           => 'multiselect',
				'options'        => PGLP_Statuses::paytypes(),
				'default'        => array(),
				'pc_locked'      => self::locked_paytypes(),
				'pc_locked_note' => __( 'This method will be available in a future update.', 'vitaliihura-checkout-for-liqpay' ),
				'description'    => __( 'Leave empty to use the selection configured in your LiqPay account. A method still has to be enabled on the LiqPay side before it appears.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'language'                      => array(
				'title'       => __( 'Payment page language', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'select',
				'default'     => 'auto',
				'options'     => array(
					'auto' => __( 'Follow the language of the order', 'vitaliihura-checkout-for-liqpay' ),
					'uk'   => __( 'Ukrainian', 'vitaliihura-checkout-for-liqpay' ),
					'en'   => __( 'English', 'vitaliihura-checkout-for-liqpay' ),
				),
				'description' => __( 'LiqPay renders its page in Ukrainian or English only.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'description_template'          => array(
				'title'       => __( 'Payment purpose', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'pc_i18n_text',
				'default'     => '',
				'placeholder' => __( 'Payment for order {order_number}', 'vitaliihura-checkout-for-liqpay' ),
				'description' => __( 'Shown to the customer on the LiqPay page and printed on the card statement, so it follows the language of the order. Placeholders: {order_number}, {order_id}, {site_title}, {billing_first_name}, {billing_last_name}, {billing_email}, {total}, {currency}.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'description_template_i18n'     => array(
				'type'    => 'pc_hidden_map',
				'default' => array(),
			),
			'expiry_minutes'                => array(
				'title'             => __( 'Payment link lifetime', 'vitaliihura-checkout-for-liqpay' ),
				'type'              => 'number',
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'description'       => __( 'Minutes before an unpaid link stops working. Zero leaves it open.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'send_customer_details'         => array(
				'title'       => __( 'Billing details', 'vitaliihura-checkout-for-liqpay' ),
				'label'       => __( 'Send the billing name and address with the payment', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'LiqPay uses these for fraud scoring.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'send_product_details'          => array(
				'title'   => __( 'Order contents', 'vitaliihura-checkout-for-liqpay' ),
				'label'   => __( 'Send the product names and the order link with the payment', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'reference_prefix'              => array(
				'title'       => __( 'Payment reference prefix', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Useful when several shops share one LiqPay account. Changing it does not affect existing orders.', 'vitaliihura-checkout-for-liqpay' ),
			),
		);
	}

	/**
	 * Order status mapping.
	 *
	 * @param array $statuses Order statuses.
	 * @return array
	 */
	private static function statuses( $statuses ) {
		$optional = array_merge( array( '' => __( 'Leave it to WooCommerce', 'vitaliihura-checkout-for-liqpay' ) ), $statuses );

		return array(
			'status_paid'   => array(
				'title'       => __( 'After a successful payment', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'select',
				'default'     => '',
				'options'     => $optional,
				'description' => __( 'WooCommerce normally picks processing, or completed for downloadable orders.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'status_hold'   => array(
				'title'       => __( 'While an amount is blocked', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'select',
				'default'     => 'on-hold',
				'options'     => $statuses,
				'description' => __( 'LiqPay reports this state only when it blocked the amount instead of charging it.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'status_review' => array(
				'title'       => __( 'While LiqPay verifies the payment', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'select',
				'default'     => 'on-hold',
				'options'     => $statuses,
				'description' => __( 'Applies to the wait_secure and wait_accept states.', 'vitaliihura-checkout-for-liqpay' ),
			),
		);
	}

	/**
	 * Checkout appearance.
	 *
	 * @return array
	 */
	private static function appearance() {
		return array(
			'icon_style'  => array(
				'title'   => __( 'Checkout icon', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'select',
				'default' => 'card',
				'options' => array(
					'card'   => __( 'Card mark', 'vitaliihura-checkout-for-liqpay' ),
					'custom' => __( 'My own image', 'vitaliihura-checkout-for-liqpay' ),
					'none'   => __( 'No icon', 'vitaliihura-checkout-for-liqpay' ),
				),
			),
			'icon_url'    => array(
				'title'            => __( 'Icon URL', 'vitaliihura-checkout-for-liqpay' ),
				'type'             => 'text',
				'default'          => '',
				'description'      => __( 'Full address of the image to show next to the payment method title.', 'vitaliihura-checkout-for-liqpay' ),
				'pc_depends'       => 'icon_style',
				'pc_depends_value' => 'custom',
			),
			'icon_height' => array(
				'title'             => __( 'Icon height', 'vitaliihura-checkout-for-liqpay' ),
				'type'              => 'number',
				'default'           => '24',
				'custom_attributes' => array(
					'min'  => '10',
					'max'  => '80',
					'step' => '1',
				),
				'description'       => __( 'Height in pixels.', 'vitaliihura-checkout-for-liqpay' ),
			),
		);
	}

	/**
	 * Everything else.
	 *
	 * @return array
	 */
	private static function advanced() {
		return array(
			'callback_url'         => array(
				'title'       => __( 'Notification URL', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'pc_copy',
				'pc_value'    => '',
				'description' => __( 'Sent with every payment, so there is nothing to configure in LiqPay. Shown here for troubleshooting.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'reconcile'            => array(
				'title'       => __( 'Status recovery', 'vitaliihura-checkout-for-liqpay' ),
				'label'       => __( 'Ask LiqPay about unfinished orders once an hour', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
				'description' => __( 'Catches payments whose notification never reached your site.', 'vitaliihura-checkout-for-liqpay' ),
			),
			'reconcile_window'     => array(
				'title'             => __( 'How far back to look', 'vitaliihura-checkout-for-liqpay' ),
				'type'              => 'number',
				'default'           => '72',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '720',
					'step' => '1',
				),
				'description'       => __( 'Hours. Orders older than this are left alone.', 'vitaliihura-checkout-for-liqpay' ),
				'pc_depends'        => 'reconcile',
				'pc_depends_value'  => '1',
			),
			'email_receipt'        => array(
				'title'   => __( 'LiqPay receipt', 'vitaliihura-checkout-for-liqpay' ),
				'label'   => __( 'Ask LiqPay to email its own payment receipt to the customer', 'vitaliihura-checkout-for-liqpay' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'debug'                => array(
				'title'       => __( 'Logging', 'vitaliihura-checkout-for-liqpay' ),
				'label'       => __( 'Record requests and notifications in the WooCommerce log', 'vitaliihura-checkout-for-liqpay' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: link to the WooCommerce log screen. */
					__( 'Errors are always recorded. Everything else only while this is on. %s', 'vitaliihura-checkout-for-liqpay' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'View logs', 'vitaliihura-checkout-for-liqpay' ) . '</a>'
				),
			),
		);
	}
}
