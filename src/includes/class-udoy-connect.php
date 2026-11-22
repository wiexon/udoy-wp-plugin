<?php
/**
 * Connect / disconnect flow.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Connect {

	/**
	 * Create a WooCommerce REST key the same way WC_Auth::create_keys() does.
	 *
	 * @return array|WP_Error {key_id, consumer_key, consumer_secret}
	 */
	private static function create_rest_key() {
		global $wpdb;

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		// WooCommerce has no API for creating REST keys outside its auth flow; this mirrors WC_Auth::create_keys().
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => get_current_user_id(),
				'description'     => 'udoy',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'udoy_key', __( 'Could not create a WooCommerce REST key.', 'udoy-connect' ) );
		}

		return array(
			'key_id'          => (int) $wpdb->insert_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	public static function delete_rest_key( $key_id ) {
		global $wpdb;
		$key_id = (int) $key_id;
		if ( $key_id > 0 ) {
			// No WooCommerce API for deleting a REST key; same table WC_Auth writes to. Nothing to cache.
			$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Connect this store to a udoy channel.
	 *
	 * @param string $plugin_key Key pasted by the merchant.
	 * @return true|WP_Error
	 */
	public static function connect( $plugin_key ) {
		$plugin_key = trim( $plugin_key );
		if ( '' === $plugin_key ) {
			return new WP_Error( 'udoy_key', __( 'Paste the plugin key from Udoy.', 'udoy-connect' ) );
		}

		if ( ! Udoy_Options::is_allowed_url( Udoy_Options::api_base() ) ) {
			return new WP_Error( 'udoy_base', __( 'The Udoy server address must start with https://.', 'udoy-connect' ) );
		}

		$site_url = untrailingslashit( home_url() );
		if ( 0 !== strpos( $site_url, 'https://' ) ) {
			return new WP_Error( 'udoy_site', __( 'Udoy can only connect to stores served over https://. Set your WordPress Site Address to an https:// address first.', 'udoy-connect' ) );
		}

		// Reconnecting: remove the old REST key first.
		$old_key_id = (int) Udoy_Options::get( 'wc_key_id', 0 );
		if ( $old_key_id ) {
			self::delete_rest_key( $old_key_id );
			Udoy_Options::delete( 'wc_key_id' );
		}

		$keys = self::create_rest_key();
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		global $wp_version;
		$result = Udoy_Client::post(
			Udoy_Options::endpoint( 'connect' ),
			array(
				'siteUrl'           => $site_url,
				'wcConsumerKey'     => $keys['consumer_key'],
				'wcConsumerSecret'  => $keys['consumer_secret'],
				'pluginVersion'     => substr( UDOY_VERSION, 0, 20 ),
				'wpVersion'         => substr( (string) $wp_version, 0, 20 ),
				'wcVersion'         => substr( (string) WC()->version, 0, 20 ),
				'currency'          => substr( get_woocommerce_currency(), 0, 3 ),
				'timezone'          => substr( wp_timezone_string(), 0, 64 ),
			),
			array(),
			30,
			$plugin_key
		);

		$fail = function ( $message ) use ( $keys ) {
			self::delete_rest_key( $keys['key_id'] );
			return new WP_Error( 'udoy_connect', $message );
		};

		if ( 0 === $result['code'] ) {
			return $fail( __( 'Could not reach Udoy. Check the server address and try again.', 'udoy-connect' ) );
		}
		if ( $result['blocked'] ) {
			// Not udoy's JSON (e.g. a Cloudflare page): a network block, not a bad key.
			Udoy_Options::log( sprintf( 'Connect blocked by network (HTTP %d).', $result['code'] ) );
			return $fail( Udoy_Client::blocked_message() );
		}
		if ( 429 === $result['code'] ) {
			return $fail( __( 'Too many attempts — try again in a few minutes', 'udoy-connect' ) );
		}
		if ( 200 !== $result['code'] ) {
			/* translators: %d: HTTP status code */
			return $fail( Udoy_Client::message( $result, sprintf( __( 'Udoy answered with an unexpected error (%d).', 'udoy-connect' ), $result['code'] ) ) );
		}

		$intake = isset( $result['body']['intakeUrl'] ) ? esc_url_raw( (string) $result['body']['intakeUrl'] ) : '';
		if ( '' === $intake || ! Udoy_Options::is_allowed_url( $intake ) || empty( $result['body']['channelId'] ) ) {
			return $fail( __( 'Udoy sent an unexpected answer. Please contact Udoy support.', 'udoy-connect' ) );
		}

		if ( ! Udoy_Options::set_plugin_key( $plugin_key ) ) {
			return $fail( __( 'Could not store the plugin key securely (openssl is unavailable).', 'udoy-connect' ) );
		}

		Udoy_Options::clear_state();
		Udoy_Options::delete( 'last_error' );
		Udoy_Options::delete( 'api_base' );
		Udoy_Options::set( 'channel_id', sanitize_text_field( (string) $result['body']['channelId'] ) );
		Udoy_Options::set( 'channel_name', sanitize_text_field( isset( $result['body']['channelName'] ) ? (string) $result['body']['channelName'] : '' ) );
		Udoy_Options::set( 'intake_url', $intake );
		Udoy_Options::set( 'wc_key_id', $keys['key_id'] );
		Udoy_Options::set( 'connected_at', time() );

		Udoy_Heartbeat::schedule_after_connect();
		return true;
	}

	/**
	 * Disconnect: tell udoy, delete the REST key, unschedule work, delete options.
	 * Order meta stays.
	 */
	public static function disconnect() {
		if ( Udoy_Options::is_connected() ) {
			Udoy_Client::post( Udoy_Options::endpoint( 'disconnect' ), null, array(), 15 ); // Failures are ignored.
		}

		self::delete_rest_key( (int) Udoy_Options::get( 'wc_key_id', 0 ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'udoy' );
		}

		Udoy_Options::delete_all();
	}
}
