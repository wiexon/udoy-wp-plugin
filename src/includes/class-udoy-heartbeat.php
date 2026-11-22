<?php
/**
 * Heartbeat every 30 minutes, plus once right after connect.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Heartbeat {

	const INTERVAL = 1800;

	public static function init() {
		add_action( 'udoy_heartbeat', array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function schedule_after_connect() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( 'udoy_heartbeat', array(), 'udoy' );
		self::ensure_schedule();
	}

	/** Keep one recurring heartbeat while connected (also resumes after reactivation). */
	public static function ensure_schedule() {
		if ( ! Udoy_Options::is_connected() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( 'udoy_heartbeat', array(), 'udoy' ) ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, 'udoy_heartbeat', array(), 'udoy' );
		}
	}

	public static function run() {
		if ( ! Udoy_Options::is_connected() ) {
			return;
		}

		$last_error = (string) Udoy_Options::get( 'last_error', '' );
		$result     = Udoy_Client::post(
			Udoy_Options::endpoint( 'heartbeat' ),
			array(
				'pluginVersion' => UDOY_VERSION,
				'wcVersion'     => substr( (string) WC()->version, 0, 20 ),
				'queueDepth'    => Udoy_Queue::depth(),
				'failedCount'   => count( Udoy_Orders::failed_ids() ),
				'lastError'     => '' === $last_error ? null : mb_substr( $last_error, 0, 500 ),
			)
		);

		if ( 200 === $result['code'] ) {
			$body = $result['body'];
			Udoy_Options::set(
				'settings',
				array(
					'ok'               => ! empty( $body['ok'] ),
					'minPluginVersion' => isset( $body['minPluginVersion'] ) ? sanitize_text_field( (string) $body['minPluginVersion'] ) : '',
					'pushAbandoned'    => ! isset( $body['pushAbandoned'] ) || (bool) $body['pushAbandoned'],
					'zoneFieldKey'     => ! empty( $body['zoneFieldKey'] ) ? sanitize_text_field( (string) $body['zoneFieldKey'] ) : null,
				)
			);
			Udoy_Options::set( 'last_success', time() );
		} elseif ( $result['blocked'] ) {
			Udoy_Options::set( 'last_error', 'Heartbeat blocked by network (HTTP ' . $result['code'] . ')' );
		} elseif ( 401 === $result['code'] ) {
			Udoy_Options::set_state( 'disconnected', __( 'Disconnected — reconnect in settings.', 'udoy-connect' ) );
		} elseif ( 410 === $result['code'] ) {
			Udoy_Options::set_state( 'closed', Udoy_Client::message( $result, __( 'This business was closed in Udoy.', 'udoy-connect' ) ) );
		}
	}
}
