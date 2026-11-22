<?php
/**
 * Sending to the udoy intake URL and interpreting the answer.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Queue {

	/** Retry delays in seconds: 1 min, 5 min, 30 min, 2 h, 6 h, 24 h. */
	const DELAYS = array( 60, 300, 1800, 7200, 21600, 86400 );

	/**
	 * Push one payload.
	 *
	 * @param string $kind    'order' or 'abandoned'.
	 * @param array  $payload Body.
	 * @return array {outcome: sent|retry|failed|stop, retry_after: int, error: string}
	 */
	public static function send( $kind, array $payload ) {
		if ( ! Udoy_Options::is_active() ) {
			return array(
				'outcome'     => 'stop',
				'retry_after' => 0,
				'error'       => 'Not connected',
			);
		}

		$result = Udoy_Client::post(
			Udoy_Options::get( 'intake_url' ),
			$payload,
			array(
				'X-Udoy-Source'         => 'plugin',
				'X-Udoy-Kind'           => $kind,
				'X-Udoy-Plugin-Version' => UDOY_VERSION,
			),
			15
		);

		$code = $result['code'];

		if ( 200 === $code || 202 === $code ) {
			Udoy_Options::set( 'last_success', time() );
			return array(
				'outcome'     => 'sent',
				'retry_after' => 0,
				'error'       => '',
			);
		}

		// A firewall/proxy page instead of udoy's JSON: the request never reached udoy, so keep it and retry.
		if ( $result['blocked'] ) {
			return array(
				'outcome'     => 'retry',
				'retry_after' => 0,
				'error'       => 'Blocked by network (HTTP ' . $code . ')',
			);
		}

		if ( 401 === $code ) {
			Udoy_Options::set_state( 'disconnected', __( 'Disconnected — reconnect in settings.', 'udoy-connect' ) );
			return self::stop( 'HTTP 401' );
		}

		if ( 410 === $code ) {
			Udoy_Options::set_state( 'closed', Udoy_Client::message( $result, __( 'This business was closed in Udoy.', 'udoy-connect' ) ) );
			return self::stop( 'HTTP 410' );
		}

		if ( 503 === $code ) {
			return array(
				'outcome'     => 'retry',
				'retry_after' => max( 60, $result['retry_after'] ),
				'error'       => 'HTTP 503',
			);
		}

		if ( 0 === $code || 408 === $code || 429 === $code || $code >= 500 ) {
			return array(
				'outcome'     => 'retry',
				'retry_after' => 0,
				'error'       => 0 === $code ? 'Network error' : 'HTTP ' . $code,
			);
		}

		return array(
			'outcome'     => 'failed',
			'retry_after' => 0,
			'error'       => 'HTTP ' . $code,
		);
	}

	private static function stop( $error ) {
		Udoy_Options::set( 'last_error', $error );
		return array(
			'outcome'     => 'stop',
			'retry_after' => 0,
			'error'       => $error,
		);
	}

	/**
	 * Seconds to wait before the Nth retry (1-based), or null when retries are used up.
	 */
	public static function delay_for_attempt( $attempt, $retry_after = 0 ) {
		if ( $attempt > count( self::DELAYS ) ) {
			return null;
		}
		return max( (int) $retry_after, self::DELAYS[ $attempt - 1 ] );
	}

	/** Number of pending udoy push actions. */
	public static function depth() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => 'udoy',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$action = ActionScheduler::store()->fetch_action( $id );
			if ( in_array( $action->get_hook(), array( 'udoy_push_order', 'udoy_push_abandoned' ), true ) ) {
				++$count;
			}
		}
		return $count;
	}
}
