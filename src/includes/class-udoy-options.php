<?php
/**
 * Option storage and plugin-key encryption.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Options {

	/** Options the plugin owns (all stored with autoload off). */
	const KEYS = array(
		'udoy_api_base', // No longer written; kept so Disconnect cleans up older installs.
		'udoy_plugin_key',
		'udoy_channel_id',
		'udoy_channel_name',
		'udoy_intake_url',
		'udoy_wc_key_id',
		'udoy_connected_at',
		'udoy_settings',
		'udoy_state',
		'udoy_state_message',
		'udoy_last_success',
		'udoy_last_error',
	);

	public static function get( $name, $default = '' ) {
		return get_option( 'udoy_' . $name, $default );
	}

	public static function set( $name, $value ) {
		update_option( 'udoy_' . $name, $value, false );
	}

	public static function delete( $name ) {
		delete_option( 'udoy_' . $name );
	}

	public static function delete_all() {
		foreach ( self::KEYS as $key ) {
			delete_option( $key );
		}
	}

	/** Plugin control API version; a breaking change moves to v2 while v1 stays. */
	const CONTROL_VERSION = 'v1';

	/**
	 * The Udoy API base. Production is compiled in; for testing, define UDOY_API_BASE in
	 * wp-config.php or use the udoy_api_base filter (only for testing against another udoy server).
	 */
	public static function api_base() {
		return untrailingslashit( (string) apply_filters( 'udoy_api_base', UDOY_API_BASE ) );
	}

	/**
	 * A control endpoint: {base}/api/plugin/v1/<action>. Every control call goes through here.
	 *
	 * @param string $action connect | heartbeat | disconnect.
	 */
	public static function endpoint( $action ) {
		return self::api_base() . '/api/plugin/' . self::CONTROL_VERSION . '/' . rawurlencode( $action );
	}

	/**
	 * Whether a URL is acceptable for talking to udoy: https, or http while WP_DEBUG is on.
	 */
	public static function is_allowed_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/* ---- plugin key encryption (wp_salt('auth') + openssl) ---- */

	private static function crypt_key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/** Whether a stored value is the hex string set_plugin_key() writes. */
	private static function is_hex( $value ) {
		return is_string( $value ) && '' !== $value && 0 === strlen( $value ) % 2 && 1 === preg_match( '/^[0-9a-f]+$/i', $value );
	}

	public static function set_plugin_key( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return false;
		}
		self::set( 'plugin_key', bin2hex( $iv . $cipher ) );
		return true;
	}

	public static function plugin_key() {
		$stored = (string) self::get( 'plugin_key', '' );
		if ( ! self::is_hex( $stored ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = hex2bin( $stored );
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::crypt_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/* ---- connection state ---- */

	public static function is_connected() {
		return '' !== self::get( 'intake_url', '' ) && '' !== self::get( 'plugin_key', '' );
	}

	/** Connected and not stopped by a 401/410. */
	public static function is_active() {
		return self::is_connected() && '' === self::get( 'state', '' );
	}

	public static function set_state( $state, $message = '' ) {
		self::set( 'state', $state );
		self::set( 'state_message', $message );
	}

	public static function clear_state() {
		self::delete( 'state' );
		self::delete( 'state_message' );
	}

	public static function remote_setting( $name, $default = null ) {
		$settings = self::get( 'settings', array() );
		return is_array( $settings ) && array_key_exists( $name, $settings ) ? $settings[ $name ] : $default;
	}

	public static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'udoy' ) );
		}
	}
}
