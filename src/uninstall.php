<?php
/**
 * Uninstall: everything Disconnect does, plus delete _udoy_* order meta.
 *
 * @package UdoyConnect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Tell udoy and delete the REST key. Failures are ignored.
$udoy_key_id = (int) get_option( 'udoy_wc_key_id', 0 );
$udoy_stored = (string) get_option( 'udoy_plugin_key', '' );
$udoy_intake = (string) get_option( 'udoy_intake_url', '' );
// Same base the plugin uses: UDOY_API_BASE from wp-config.php if set, else production.
$udoy_base = defined( 'UDOY_API_BASE' ) ? (string) UDOY_API_BASE : 'https://api.udoy.app';
$udoy_base = (string) apply_filters( 'udoy_api_base', $udoy_base );

if ( '' !== $udoy_stored && '' !== $udoy_intake ) {
	$udoy_raw = ( 0 === strlen( $udoy_stored ) % 2 && 1 === preg_match( '/^[0-9a-f]+$/i', $udoy_stored ) ) ? hex2bin( $udoy_stored ) : false;
	if ( false !== $udoy_raw && strlen( $udoy_raw ) > 16 && function_exists( 'openssl_decrypt' ) ) {
		$udoy_plain = openssl_decrypt( substr( $udoy_raw, 16 ), 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $udoy_raw, 0, 16 ) );
		if ( false !== $udoy_plain && '' !== $udoy_plain ) {
			wp_remote_post(
				untrailingslashit( $udoy_base ) . '/api/plugin/v1/disconnect',
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'headers'     => array( 'Authorization' => 'Bearer ' . $udoy_plain ),
				)
			);
		}
	}
}

if ( $udoy_key_id > 0 ) {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $udoy_key_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup, no WC API for this.
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'udoy' );
}

// Options and transients.
// Uninstall cleanup by prefix: WordPress has no API for deleting options or meta by pattern.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'udoy_' ) . '%',
		$wpdb->esc_like( '_transient_udoy_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_udoy_' ) . '%'
	)
);

// Order meta (legacy posts storage and HPOS).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_udoy_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) === $wpdb->prefix . 'wc_orders_meta' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s", $wpdb->esc_like( '_udoy_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}
