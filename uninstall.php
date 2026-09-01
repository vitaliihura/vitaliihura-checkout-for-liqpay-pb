<?php
/**
 * Removes everything the plugin stored, except data that belongs to orders.
 *
 * Order meta is deliberately kept: transaction identifiers and masked card numbers are part of
 * the shop's payment records and deleting them would damage the store's accounting history.
 *
 * @package VitaliiHuraCheckoutForLiqPay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_pglp_liqpay_settings' );
delete_option( 'pglp_db_version' );
delete_option( 'pglp_import_dismissed' );

wp_clear_scheduled_hook( 'pglp_reconcile_payments' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'pglp_reconcile_payments' );
}

// Release any order locks left behind by an interrupted callback. These are short lived option
// rows with generated names, so there is no API that can enumerate them.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall; the option names are not known in advance.
$pglp_locks = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'pglp_lock_' ) . '%'
	)
);

foreach ( (array) $pglp_locks as $pglp_lock ) {
	delete_option( $pglp_lock );
}
