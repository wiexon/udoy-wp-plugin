<?php
/**
 * HTTP client for udoy.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Client {

	/**
	 * POST JSON to udoy.
	 *
	 * @param string     $url     Full URL.
	 * @param array|null $body    JSON body, or null for none.
	 * @param array      $headers Extra headers.
	 * @param int        $timeout Seconds.
	 * @return array {code:int, body:array, retry_after:int, error:string}
	 */
	public static function post( $url, $body = null, $headers = array(), $timeout = 15, $key = null ) {
		$key = null === $key ? Udoy_Options::plugin_key() : $key;

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'code'        => 0,
				'body'        => array(),
				'json'        => false,
				'blocked'     => false,
				'retry_after' => 0,
				'error'       => $response->get_error_message(),
			);
		}

		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$is_json = is_array( $decoded );

		return array(
			'code'        => $code,
			'body'        => $is_json ? $decoded : array(),
			'json'        => $is_json,
			// An error answer that is not udoy's JSON (e.g. a Cloudflare HTML page) never reached udoy.
			'blocked'     => $code >= 400 && ! $is_json && '' !== trim( $raw ),
			'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
			'error'       => '',
		);
	}

	/** Shown when a firewall or proxy answered instead of udoy. */
	public static function blocked_message() {
		return __( 'Udoy couldn’t be reached (blocked by network).', 'udoy-connect' );
	}

	/** Message from an error body, or a fallback. */
	public static function message( $result, $fallback = '' ) {
		if ( ! empty( $result['body']['message'] ) && is_string( $result['body']['message'] ) ) {
			return sanitize_text_field( $result['body']['message'] );
		}
		return $fallback;
	}
}
