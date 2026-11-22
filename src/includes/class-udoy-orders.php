<?php
/**
 * Pushing orders to udoy.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Orders {

	/** Order ids already enqueued during this request. */
	private static $seen = array();

	/**
	 * Generic partial-advance meta keys, first numeric match wins. These are NOT verified against a
	 * plugin release (the two plugins above are handled by partial_plugin_amount()); a merchant with
	 * another setup can fix it with the udoy_paid_amount filter.
	 */
	const PARTIAL_META = array( '_advance_amount', '_deposit_paid' );

	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_event' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_event' ), 20, 1 );
		// Block checkout (Store API) does not fire woocommerce_checkout_order_processed.
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function ( $order ) {
				if ( $order instanceof WC_Order ) {
					self::on_order_event( $order->get_id() );
				}
			},
			20
		);
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_event' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'udoy_push_order', array( __CLASS__, 'push' ), 10, 1 );
	}

	public static function on_payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( '_udoy_gateway_paid', '1' );
			$order->save_meta_data();
		}
		self::enqueue( $order_id, true );
	}

	public static function on_order_event( $order_id ) {
		self::enqueue( $order_id );
	}

	/**
	 * Schedule a push through Action Scheduler, never inline.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $force    Ignore the once-per-request dedupe (payment complete).
	 */
	public static function enqueue( $order_id, $force = false ) {
		$order_id = (int) $order_id;
		if ( ! $order_id || ! Udoy_Options::is_active() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( ! $force && isset( self::$seen[ $order_id ] ) ) {
			return;
		}
		self::$seen[ $order_id ] = true;

		if ( as_has_scheduled_action( 'udoy_push_order', array( $order_id ), 'udoy' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order->update_meta_data( '_udoy_push_state', 'pending' );
		$order->update_meta_data( '_udoy_attempts', 0 );
		$order->save_meta_data();

		as_enqueue_async_action( 'udoy_push_order', array( $order_id ), 'udoy' );
	}

	/** Action Scheduler callback. */
	public static function push( $order_id ) {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$payload = self::build_payload( $order );
		$result  = Udoy_Queue::send( 'order', $payload );

		switch ( $result['outcome'] ) {
			case 'sent':
				$order->update_meta_data( '_udoy_push_state', 'sent' );
				$order->delete_meta_data( '_udoy_last_error' );
				$order->update_meta_data( '_udoy_attempts', 0 );
				Udoy_Options::log( sprintf( 'Order %d pushed (sent).', $order_id ) );
				break;

			case 'retry':
				$attempt = (int) $order->get_meta( '_udoy_attempts' ) + 1;
				$delay   = Udoy_Queue::delay_for_attempt( $attempt, $result['retry_after'] );
				if ( null === $delay ) {
					$order->update_meta_data( '_udoy_push_state', 'failed' );
					$order->update_meta_data( '_udoy_last_error', $result['error'] );
					Udoy_Options::set( 'last_error', $result['error'] );
					Udoy_Options::log( sprintf( 'Order %d failed after retries: %s', $order_id, $result['error'] ) );
				} else {
					$order->update_meta_data( '_udoy_attempts', $attempt );
					$order->update_meta_data( '_udoy_last_error', $result['error'] );
					Udoy_Options::log( sprintf( 'Order %d retry %d in %ds: %s', $order_id, $attempt, $delay, $result['error'] ) );
					as_schedule_single_action( time() + $delay, 'udoy_push_order', array( $order_id ), 'udoy' );
				}
				break;

			default: // 'failed' or 'stop'.
				$order->update_meta_data( '_udoy_push_state', 'failed' );
				$order->update_meta_data( '_udoy_last_error', $result['error'] );
				Udoy_Options::set( 'last_error', $result['error'] );
				Udoy_Options::log( sprintf( 'Order %d not sent: %s', $order_id, $result['error'] ) );
		}

		$order->save_meta_data();
	}

	/**
	 * The order as the REST API returns it, plus a `udoy` object.
	 */
	public static function build_payload( WC_Order $order ) {
		$controller = new WC_REST_Orders_Controller();
		$request    = new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() );
		$request->set_param( 'context', 'view' );
		$response = $controller->prepare_object_for_response( $order, $request );
		$data     = $response->get_data();

		$data['udoy'] = self::udoy_fields( $order );
		return $data;
	}

	private static function udoy_fields( WC_Order $order ) {
		$fields = array();

		$paid = self::paid_amount( $order );
		if ( null !== $paid ) {
			$fields['paid_amount'] = $paid;
		}

		$shipping = $order->get_shipping_methods();
		if ( $shipping ) {
			$line        = reset( $shipping );
			$method_id   = $line->get_method_id();
			$instance_id = (int) $line->get_instance_id();
			if ( '' !== $method_id ) {
				$fields['shipping_method'] = $instance_id ? $method_id . ':' . $instance_id : $method_id;
			}
			if ( $instance_id && class_exists( 'WC_Shipping_Zones' ) ) {
				$zone = WC_Shipping_Zones::get_zone_by( 'instance_id', $instance_id );
				// get_zone_by() falls back to zone 0 for an unknown instance, so confirm the instance belongs to the zone.
				if ( $zone && array_key_exists( $instance_id, $zone->get_shipping_methods() ) ) {
					$fields['shipping_zone_id']   = (int) $zone->get_id();
					$fields['shipping_zone_name'] = $zone->get_zone_name();
				}
			}
		}

		$fields['order_url'] = $order->get_edit_order_url();
		return $fields;
	}

	/**
	 * What the customer actually paid online.
	 *
	 * Deliberately NOT inferred from is_paid()/date_paid/status: udoy sets order status itself,
	 * and WooCommerce then stamps date_paid although no money arrived.
	 */
	private static function paid_amount( WC_Order $order ) {
		$amount = self::partial_plugin_amount( $order );

		if ( null === $amount ) {
			foreach ( self::PARTIAL_META as $meta_key ) {
				$value = $order->get_meta( $meta_key );
				if ( '' !== $value && is_numeric( $value ) && (float) $value > 0 ) {
					$amount = (float) $value;
					break;
				}
			}
		}

		if ( null === $amount ) {
			$gateway_paid = '1' === (string) $order->get_meta( '_udoy_gateway_paid' )
				|| ( '' !== $order->get_transaction_id() && 'cod' !== $order->get_payment_method() );
			$amount       = $gateway_paid ? (float) $order->get_total() : 0.0;
		}

		$amount = apply_filters( 'udoy_paid_amount', $amount, $order );
		if ( null === $amount || ! is_numeric( $amount ) ) {
			return null;
		}
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Amount actually paid according to a known deposit plugin, or null if the order is not one of theirs.
	 * For these orders a deposit that is not yet paid is 0, never the full total, even if the gateway
	 * called payment_complete() for the deposit.
	 *
	 * Keys read from the plugin source (checked 2026-11-21):
	 * - Advanced Partial Payment or Deposit (magepeopleteam) 4.0.31: _apd_is_deposit, _apd_amount_paid.
	 * - Deposits & Partial Payments (Acowebs) 1.2.14: _awcdp_deposits_order_has_deposit, _awcdp_deposits_deposit_paid,
	 *   _awcdp_deposits_deposit_amount, _awcdp_deposits_second_payment_paid.
	 */
	private static function partial_plugin_amount( WC_Order $order ) {
		if ( 'yes' === $order->get_meta( '_apd_is_deposit' ) ) {
			$paid = $order->get_meta( '_apd_amount_paid' );
			return is_numeric( $paid ) ? (float) $paid : 0.0;
		}

		if ( 'yes' === $order->get_meta( '_awcdp_deposits_order_has_deposit' ) ) {
			if ( 'yes' === $order->get_meta( '_awcdp_deposits_second_payment_paid' ) ) {
				return (float) $order->get_total();
			}
			if ( 'yes' === $order->get_meta( '_awcdp_deposits_deposit_paid' ) ) {
				$deposit = $order->get_meta( '_awcdp_deposits_deposit_amount' );
				return is_numeric( $deposit ) ? (float) $deposit : 0.0;
			}
			return 0.0;
		}

		return null;
	}

	/** Ids of orders whose last push failed. */
	public static function failed_ids() {
		return wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => '_udoy_push_state', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'failed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/** Re-enqueue every failed order. Returns how many. */
	public static function retry_failed() {
		$count = 0;
		foreach ( self::failed_ids() as $order_id ) {
			self::$seen = array();
			self::enqueue( $order_id, true );
			++$count;
		}
		return $count;
	}
}
