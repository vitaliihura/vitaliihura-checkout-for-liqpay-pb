<?php
/**
 * Admin screens, assets and diagnostics.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared admin layer, the order panels and the status report section.
 */
class PGLP_Admin {

	const IMPORT_ACTION = 'pglp_import_settings';

	/**
	 * Option names other LiqPay integrations store their settings under.
	 *
	 * @return array
	 */
	private static function known_sources() {
		return array(
			'woocommerce_morkva-liqpay_settings' => __( 'LiqPay Extended', 'vitaliihura-checkout-for-liqpay' ),
			'woocommerce_liqpay_settings'        => __( 'LiqPay', 'vitaliihura-checkout-for-liqpay' ),
			'woocommerce_wc_liqpay_settings'     => __( 'WooCommerce LiqPay', 'vitaliihura-checkout-for-liqpay' ),
		);
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_frontend_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_details_box' ), 20, 2 );
		add_action( 'wp_ajax_pglp_check_status', array( __CLASS__, 'ajax_check_status' ) );
		add_action( 'wp_ajax_pglp_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'import_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'imported_notice' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'handle_import' ) );
		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'status_report' ) );
	}

	/* ------------------------------------------------------------ assets --- */

	/**
	 * Registers the admin stylesheet and script.
	 */
	public static function register_assets() {
		wp_register_style( 'pglp-tokens', PGLP_URL . 'assets/css/pc-tokens.css', array(), PGLP_VERSION );
		wp_register_style( 'pglp-brand', PGLP_URL . 'assets/css/pc-brand.css', array( 'pglp-tokens' ), PGLP_VERSION );
		wp_register_style( 'pglp-admin', PGLP_URL . 'assets/css/pc-admin.css', array( 'pglp-brand' ), PGLP_VERSION );

		wp_register_script( 'pglp-admin', PGLP_URL . 'assets/js/pc-admin.js', array(), PGLP_VERSION, true );

		wp_localize_script(
			'pglp-admin',
			'pglpAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'testNonce' => wp_create_nonce( 'pglp_test_connection' ),
				'i18n'      => array(
					'working'        => __( 'Working…', 'vitaliihura-checkout-for-liqpay' ),
					'failed'         => __( 'The request could not be completed.', 'vitaliihura-checkout-for-liqpay' ),
					'show'           => __( 'Show', 'vitaliihura-checkout-for-liqpay' ),
					'hide'           => __( 'Hide', 'vitaliihura-checkout-for-liqpay' ),
					'copied'         => __( 'Copied', 'vitaliihura-checkout-for-liqpay' ),
				),
			)
		);
	}

	/**
	 * Registers the small front end assets.
	 */
	public static function register_frontend_assets() {
		wp_register_style( 'pglp-checkout', PGLP_URL . 'assets/css/pc-checkout.css', array(), PGLP_VERSION );
		wp_register_script( 'pglp-redirect', PGLP_URL . 'assets/js/redirect.js', array(), PGLP_VERSION, true );
	}

	/* ------------------------------------------------------ order panels --- */

	/**
	 * Adds the payment details panel to the order screen.
	 *
	 * @param string $screen_id Current screen identifier.
	 * @param mixed  $post      Post or order object.
	 */
	public static function add_details_box( $screen_id, $post = null ) {
		$order = self::resolve_order( $post );

		if ( ! $order || PGLP_GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}

		add_meta_box(
			'pglp_details',
			__( 'LiqPay payment', 'vitaliihura-checkout-for-liqpay' ),
			array( __CLASS__, 'render_details_box' ),
			$screen_id,
			'side',
			'default'
		);
	}

	/**
	 * Renders the payment details panel.
	 *
	 * @param mixed $post Post or order object.
	 */
	public static function render_details_box( $post ) {
		$order = self::resolve_order( $post );

		if ( ! $order ) {
			return;
		}

		wp_enqueue_script( 'pglp-admin' );
		wp_enqueue_style( 'pglp-admin' );

		$status = (string) $order->get_meta( PGLP_Order_Handler::META_STATUS );
		$group  = PGLP_Statuses::classify( $status );

		$tones = array(
			'paid'     => 'ok',
			'held'     => 'warn',
			'review'   => 'warn',
			'reserved' => 'warn',
			'failed'   => 'err',
			'reversed' => 'idle',
			'pending'  => 'idle',
		);

		$rows = array(
			__( 'Reference', 'vitaliihura-checkout-for-liqpay' )          => PGLP_Order_Handler::get_reference( $order ),
			__( 'LiqPay payment', 'vitaliihura-checkout-for-liqpay' )     => (string) $order->get_meta( PGLP_Order_Handler::META_ORDER_ID ),
			__( 'Method', 'vitaliihura-checkout-for-liqpay' )             => (string) $order->get_meta( PGLP_Order_Handler::META_PAYTYPE ),
			__( 'Card', 'vitaliihura-checkout-for-liqpay' )               => (string) $order->get_meta( PGLP_Order_Handler::META_CARD_MASK ),
			__( 'Card type', 'vitaliihura-checkout-for-liqpay' )          => (string) $order->get_meta( PGLP_Order_Handler::META_CARD_TYPE ),
			__( 'Acquirer', 'vitaliihura-checkout-for-liqpay' )           => (string) $order->get_meta( PGLP_Order_Handler::META_ACQ_ID ),
			__( 'RRN', 'vitaliihura-checkout-for-liqpay' )                => (string) $order->get_meta( PGLP_Order_Handler::META_RRN ),
			__( 'Authorisation code', 'vitaliihura-checkout-for-liqpay' ) => (string) $order->get_meta( PGLP_Order_Handler::META_AUTHCODE ),
			__( '3-D Secure', 'vitaliihura-checkout-for-liqpay' )         => 'yes' === $order->get_meta( PGLP_Order_Handler::META_IS_3DS ) ? __( 'Yes', 'vitaliihura-checkout-for-liqpay' ) : '',
		);

		$currency = array( 'currency' => $order->get_currency() );
		$charged  = (float) $order->get_meta( PGLP_Order_Handler::META_AMOUNT );

		if ( $charged > 0 ) {
			$rows[ __( 'Charged', 'vitaliihura-checkout-for-liqpay' ) ] = wp_strip_all_tags( wc_price( $charged, $currency ) );
		}

		$commission = (float) $order->get_meta( PGLP_Order_Handler::META_COMMISSION );

		if ( $commission > 0 ) {
			$rows[ __( 'Commission', 'vitaliihura-checkout-for-liqpay' ) ] = wp_strip_all_tags( wc_price( $commission, $currency ) );
		}

		// A partial refund leaves the LiqPay status at success, so the amount taken back is the
		// only place it shows up on this panel.
		$refunded = (float) $order->get_total_refunded();
		$full     = $refunded > 0 && $refunded + 0.005 >= (float) $order->get_total();

		if ( $refunded > 0 ) {
			$rows[ __( 'Refunded', 'vitaliihura-checkout-for-liqpay' ) ] = wp_strip_all_tags( wc_price( $refunded, $currency ) );
		}

		if ( 'yes' === $order->get_meta( PGLP_Order_Handler::META_SANDBOX ) ) {
			$rows[ __( 'Mode', 'vitaliihura-checkout-for-liqpay' ) ] = __( 'Test', 'vitaliihura-checkout-for-liqpay' );
		}

		// Fields LiqPay did not report are dropped here rather than skipped while rendering.
		$rows = array_filter(
			$rows,
			static function ( $value ) {
				return '' !== $value;
			}
		);

		$state = PGLP_Statuses::short_label( $status );
		$tone  = isset( $tones[ $group ] ) ? $tones[ $group ] : 'idle';

		// The long wording explains the odd statuses; on the everyday ones it only repeats the
		// badge, so it is left out there.
		$note = in_array( $status, array( 'success', 'sandbox', 'reversed', 'failure' ), true )
			? ''
			: PGLP_Statuses::label( $status );

		// Money that went back outranks the LiqPay status on this panel: the payment itself stays
		// successful in LiqPay after a refund, and the amounts below carry the rest of the story.
		if ( $refunded > 0 && 'reversed' !== $group ) {
			$state = $full ? __( 'Refunded', 'vitaliihura-checkout-for-liqpay' ) : __( 'Partly refunded', 'vitaliihura-checkout-for-liqpay' );
			$tone  = $full ? 'idle' : 'warn';
			$note  = '';
		}

		?>
		<div class="pc pc-panel" data-pc-order="<?php echo esc_attr( $order->get_id() ); ?>" data-pc-nonce="<?php echo esc_attr( wp_create_nonce( 'pglp_check_status' ) ); ?>">
			<?php if ( '' !== $status ) : ?>
				<p class="pc-panel__state">
					<?php PGLP_UI::pill( $state, $tone ); ?>
					<?php if ( '' !== $note ) : ?>
						<span class="pc__muted"><?php echo esc_html( $note ); ?></span>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<div class="pc-empty">
					<h3><?php esc_html_e( 'No payment yet', 'vitaliihura-checkout-for-liqpay' ); ?></h3>
					<p class="pc__muted"><?php esc_html_e( 'Once the customer pays, the transaction details appear here.', 'vitaliihura-checkout-for-liqpay' ); ?></p>
				</div>
			<?php endif; ?>

			<dl class="pc-kv">
				<?php foreach ( $rows as $label => $value ) : ?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd><?php echo esc_html( $value ); ?></dd>
				<?php endforeach; ?>
			</dl>

			<div class="pc-panel__actions">
				<button type="button" class="pc-btn pc-btn--small pc-js-refresh"><?php esc_html_e( 'Refresh from LiqPay', 'vitaliihura-checkout-for-liqpay' ); ?></button>
			</div>
			<div class="pc-feedback" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- ajax --- */

	/**
	 * Re-reads the payment state from LiqPay for one order.
	 */
	public static function ajax_check_status() {
		check_ajax_referer( 'pglp_check_status', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage this order.', 'vitaliihura-checkout-for-liqpay' ) ), 403 );
		}

		$gateway = PGLP_Callback::gateway();
		$api     = $gateway ? $gateway->api_for_order( $order ) : false;

		if ( ! $api ) {
			wp_send_json_error( array( 'message' => __( 'LiqPay API keys are not configured.', 'vitaliihura-checkout-for-liqpay' ) ) );
		}

		$response = $api->get_status( PGLP_Order_Handler::get_reference( $order ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		if ( PGLP_Order_Handler::is_unknown_to_liqpay( $order, $response ) ) {
			wp_send_json_success( array( 'message' => __( 'LiqPay has no record of a payment for this order yet.', 'vitaliihura-checkout-for-liqpay' ) ) );
		}

		PGLP_Order_Handler::apply( $order, $response, $gateway, 'manual check' );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: payment status reported by LiqPay. */
					__( 'LiqPay reports: %s', 'vitaliihura-checkout-for-liqpay' ),
					PGLP_Statuses::label( isset( $response['status'] ) ? $response['status'] : '' )
				),
			)
		);
	}

	/**
	 * Checks the API keys without creating a payment.
	 *
	 * A status query is sent for a reference LiqPay cannot know. Wrong keys come back as a
	 * signature error; correct keys come back complaining about the order, which is the answer
	 * we are looking for.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'pglp_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to change payment settings.', 'vitaliihura-checkout-for-liqpay' ) ), 403 );
		}

		$gateway = PGLP_Callback::gateway();

		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'The LiqPay gateway is not available.', 'vitaliihura-checkout-for-liqpay' ) ) );
		}

		// The keys typed into the form are tested, not the ones last saved: the button sits
		// directly under the fields, so reporting on stale values would be a trap.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$public  = isset( $_POST['public_key'] ) ? sanitize_text_field( wp_unslash( $_POST['public_key'] ) ) : '';
		$private = isset( $_POST['private_key'] ) ? sanitize_text_field( wp_unslash( $_POST['private_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$api = ( '' !== $public && '' !== $private ) ? new PGLP_API( $public, $private ) : $gateway->api();

		if ( ! $api->has_credentials() ) {
			wp_send_json_error( array( 'message' => __( 'Enter both keys first.', 'vitaliihura-checkout-for-liqpay' ) ) );
		}

		$response = $api->get_status( 'pglp-connection-check-' . wp_generate_password( 12, false ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: transport error message. */
						__( 'Your server could not reach LiqPay: %s', 'vitaliihura-checkout-for-liqpay' ),
						$response->get_error_message()
					),
				)
			);
		}

		if ( self::keys_accepted( $response ) ) {
			wp_send_json_success( array( 'message' => __( 'LiqPay accepted these keys.', 'vitaliihura-checkout-for-liqpay' ) ) );
		}

		$error = PGLP_API::response_error( $response );

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %s: message returned by LiqPay. */
					__( 'LiqPay refused the request: %s', 'vitaliihura-checkout-for-liqpay' ),
					$error ? $error->get_error_message() : __( 'no reason given.', 'vitaliihura-checkout-for-liqpay' )
				),
			)
		);
	}

	/**
	 * Whether a status answer proves the keys work.
	 *
	 * Only two answers count: LiqPay processed the request, or it complained specifically about
	 * the made-up order reference. Anything else, including an error with no code at all, is
	 * reported to the merchant as a refusal rather than guessed at.
	 *
	 * @param array $response Decoded LiqPay response.
	 * @return bool
	 */
	private static function keys_accepted( $response ) {
		if ( ! is_array( $response ) ) {
			return false;
		}

		if ( isset( $response['result'] ) && 'ok' === $response['result'] ) {
			return true;
		}

		$code = '';

		foreach ( array( 'err_code', 'err_erc', 'code' ) as $field ) {
			if ( ! empty( $response[ $field ] ) ) {
				$code = strtolower( (string) $response[ $field ] );
				break;
			}
		}

		$unknown_order = array( 'order_id_empty', 'payment_not_found', 'order_not_found', 'payment_err_type' );

		return in_array( $code, $unknown_order, true );
	}

	/* ------------------------------------------------------------ report --- */

	/**
	 * Adds a section to WooCommerce → Status, which is the first thing support asks for.
	 */
	public static function status_report() {
		$gateway = PGLP_Callback::gateway();

		if ( ! $gateway ) {
			return;
		}

		$last      = (int) get_option( 'pglp_last_callback', 0 );
		$languages = wp_list_pluck( PGLP_I18n::languages(), 'locale' );

		$rows = array(
			__( 'Version', 'vitaliihura-checkout-for-liqpay' )          => PGLP_VERSION,
			__( 'Enabled', 'vitaliihura-checkout-for-liqpay' )          => 'yes' === $gateway->get_option( 'enabled', 'no' ) ? __( 'Yes', 'vitaliihura-checkout-for-liqpay' ) : __( 'No', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Mode', 'vitaliihura-checkout-for-liqpay' )             => $gateway->is_test_mode() ? __( 'Test', 'vitaliihura-checkout-for-liqpay' ) : __( 'Live', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Keys present', 'vitaliihura-checkout-for-liqpay' )     => $gateway->api()->has_credentials() ? __( 'Yes', 'vitaliihura-checkout-for-liqpay' ) : __( 'No', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Payment type', 'vitaliihura-checkout-for-liqpay' )     => $gateway->get_payment_action(),
			__( 'API version', 'vitaliihura-checkout-for-liqpay' )      => (string) PGLP_API::version(),
			__( 'Notification URL', 'vitaliihura-checkout-for-liqpay' ) => PGLP_Callback::endpoint_url(),
			__( 'Last notification', 'vitaliihura-checkout-for-liqpay' ) => $last > 0
				? sprintf(
					/* translators: %s: human readable time difference. */
					__( '%s ago', 'vitaliihura-checkout-for-liqpay' ),
					PGLP_UI::time_ago( $last )
				)
				: __( 'Never', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Status recovery', 'vitaliihura-checkout-for-liqpay' )  => wp_next_scheduled( PGLP_Reconciler::HOOK ) ? __( 'Scheduled', 'vitaliihura-checkout-for-liqpay' ) : __( 'Off', 'vitaliihura-checkout-for-liqpay' ),
			__( 'Site languages', 'vitaliihura-checkout-for-liqpay' )   => implode( ', ', $languages ),
		);

		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr><th colspan="3" data-export-label="LiqPay"><h2><?php esc_html_e( 'LiqPay', 'vitaliihura-checkout-for-liqpay' ); ?></h2></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<td data-export-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?>:</td>
						<td class="help">&nbsp;</td>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------ import --- */

	/**
	 * Offers to copy settings across from another LiqPay plugin.
	 */
	public static function import_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		if ( get_option( 'pglp_import_dismissed' ) ) {
			return;
		}

		$settings = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );

		if ( ! empty( $settings['public_key'] ) ) {
			return;
		}

		$source = self::find_source();

		if ( ! $source ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::IMPORT_ACTION . '&source=' . rawurlencode( $source['option'] ) ),
			self::IMPORT_ACTION
		);

		$dismiss = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::IMPORT_ACTION . '&dismiss=1' ),
			self::IMPORT_ACTION
		);

		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: %s: name of the plugin the settings would come from. */
			esc_html__( 'Settings from %s were found on this site. They can be copied into VitaliiHura Checkout for LiqPay so you do not have to enter your keys again.', 'vitaliihura-checkout-for-liqpay' ),
			'<strong>' . esc_html( $source['label'] ) . '</strong>'
		);
		echo '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Copy the settings', 'vitaliihura-checkout-for-liqpay' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'No thanks', 'vitaliihura-checkout-for-liqpay' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Finds a set of settings worth importing.
	 *
	 * @return array|false
	 */
	private static function find_source() {
		foreach ( self::known_sources() as $option => $label ) {
			$settings = get_option( $option, array() );

			if ( is_array( $settings ) && ! empty( $settings['public_key'] ) ) {
				return array(
					'option' => $option,
					'label'  => $label,
				);
			}
		}

		return false;
	}

	/**
	 * Performs the import.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change payment settings.', 'vitaliihura-checkout-for-liqpay' ), 403 );
		}

		check_admin_referer( self::IMPORT_ACTION );

		$redirect = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . PGLP_GATEWAY_ID );

		if ( isset( $_GET['dismiss'] ) ) {
			update_option( 'pglp_import_dismissed', 1, false );
			wp_safe_redirect( $redirect );
			exit;
		}

		$option = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

		if ( ! array_key_exists( $option, self::known_sources() ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$source = get_option( $option, array() );

		if ( ! is_array( $source ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$current = get_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', array() );
		$current = is_array( $current ) ? $current : array();

		// Only settings this plugin actually reads are listed. A source key with no counterpart
		// here is left behind on purpose, and the merchant is told about it below.
		$map = array(
			'title'                        => 'title',
			'description'                  => 'description',
			'public_key'                   => 'public_key',
			'private_key'                  => 'private_key',
			'test_public_key'              => 'sandbox_public_key',
			'test_private_key'             => 'sandbox_private_key',
			'sandbox_public_key'           => 'sandbox_public_key',
			'sandbox_private_key'          => 'sandbox_private_key',
			'liqpay_order_status'          => 'status_paid',
			'payment_description_template' => 'description_template',
		);

		foreach ( $map as $from => $to ) {
			if ( isset( $source[ $from ] ) && '' !== $source[ $from ] ) {
				$current[ $to ] = $source[ $from ];
			}
		}

		// Other plugins write this one as yes, 1 or true; WooCommerce only understands yes and no.
		foreach ( array( 'test_enabled', 'sandbox' ) as $from ) {
			if ( isset( $source[ $from ] ) && '' !== $source[ $from ] ) {
				$current['test_mode'] = wc_bool_to_string( wc_string_to_bool( $source[ $from ] ) );
			}
		}

		update_option( 'woocommerce_' . PGLP_GATEWAY_ID . '_settings', $current );
		update_option( 'pglp_import_dismissed', 1, false );

		$args = array( 'pglp-imported' => '1' );

		// Two-step payments are not in this version, so a shop that used them has to be told
		// rather than left to discover that its money is now taken straight away.
		if ( isset( $source['use_holds'] ) && wc_string_to_bool( $source['use_holds'] ) ) {
			$args['pglp-holds'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Reports what the import did.
	 */
	public static function imported_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading the result of a redirect, not acting on it.
		if ( ! isset( $_GET['pglp-imported'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$holds = isset( $_GET['pglp-holds'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'The LiqPay settings were copied. Check the keys and the payment method before you take an order.', 'vitaliihura-checkout-for-liqpay' );
		echo '</p>';

		if ( $holds ) {
			echo '<p>';
			esc_html_e( 'The shop you copied from blocked the amount on the card and captured it later. This version charges the card straight away, so that setting was not copied.', 'vitaliihura-checkout-for-liqpay' );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Resolves the order behind a meta box argument.
	 *
	 * @param mixed $post Post or order object.
	 * @return WC_Order|false
	 */
	private static function resolve_order( $post ) {
		if ( $post instanceof WC_Order ) {
			return $post;
		}

		if ( $post instanceof WP_Post ) {
			return wc_get_order( $post->ID );
		}

		return false;
	}
}
