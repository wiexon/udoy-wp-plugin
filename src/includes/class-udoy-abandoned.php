<?php
/**
 * Abandoned checkout capture.
 *
 * A snapshot is kept in a transient keyed by checkout id; the session only remembers the
 * checkout id. When an order is placed the snapshot is deleted, so the delayed push finds
 * nothing and sends nothing.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Abandoned {

	const DELAY = 900; // 15 minutes.

	/** Posted fields already covered by named keys in the payload. */
	const KNOWN_FIELDS = array(
		'billing_first_name',
		'billing_last_name',
		'billing_phone',
		'billing_email',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'billing_company',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
		'shipping_phone',
		'shipping_method',
		'payment_method',
		'ship_to_different_address',
		'terms',
		'terms-field',
		'createaccount',
		'account_username',
		'wc-ajax',
		'post_data',
		'security',
		'country',
		'state',
		's_country',
		's_state',
		's_postcode',
		's_city',
		's_address',
		's_address_2',
		'has_full_address',
		'woocommerce_checkout_update_totals',
	);

	/** Fields captured in this request, saved at shutdown so the cart and shipping choice are final. */
	private static $pending_fields = null;

	public static function init() {
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'on_update_order_review' ), 20, 1 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_validation' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_placed' ), 5, 1 );

		// Block checkout (Store API): address updates and order placement use different hooks.
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'on_store_api_customer' ), 20, 2 );
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			function ( $order ) {
				if ( $order instanceof WC_Order ) {
					self::on_order_placed( $order->get_id() );
				}
			},
			5
		);
		add_action( 'udoy_push_abandoned', array( __CLASS__, 'push' ), 10, 1 );
	}

	private static function enabled() {
		return Udoy_Options::is_active() && false !== Udoy_Options::remote_setting( 'pushAbandoned', true );
	}

	public static function on_update_order_review( $post_data ) {
		if ( ! self::enabled() ) {
			return;
		}
		$fields = array();
		parse_str( (string) $post_data, $fields );
		self::$pending_fields = is_array( $fields ) ? wc_clean( wp_unslash( $fields ) ) : array();
		add_action( 'shutdown', array( __CLASS__, 'save_pending' ) );
	}

	/**
	 * Block checkout sends the address to /wc/store/v1/cart/update-customer as the customer types.
	 *
	 * @param WC_Customer     $customer Customer with the new address applied.
	 * @param WP_REST_Request $request  Store API request.
	 */
	public static function on_store_api_customer( $customer, $request = null ) {
		if ( ! self::enabled() || ! $customer instanceof WC_Customer ) {
			return;
		}
		$fields = array( 'billing_email' => $customer->get_billing_email() );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( array( 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $part ) {
				$getter = "get_{$type}_{$part}";
				if ( is_callable( array( $customer, $getter ) ) ) {
					$fields[ "{$type}_{$part}" ] = (string) $customer->$getter();
				}
			}
		}
		// Phone may be collected only on the shipping address.
		if ( '' === $fields['billing_phone'] && ! empty( $fields['shipping_phone'] ) ) {
			$fields['billing_phone'] = $fields['shipping_phone'];
		}
		self::$pending_fields = wc_clean( $fields );
		add_action( 'shutdown', array( __CLASS__, 'save_pending' ) );
	}

	public static function on_validation( $data, $errors ) {
		if ( ! self::enabled() || ! $errors instanceof WP_Error || ! $errors->has_errors() ) {
			return;
		}
		self::snapshot( is_array( $data ) ? wc_clean( $data ) : array() );
	}

	public static function save_pending() {
		if ( is_array( self::$pending_fields ) ) {
			self::snapshot( self::$pending_fields );
		}
	}

	/**
	 * Save a snapshot and (re)schedule the delayed push.
	 *
	 * @param array $fields Checkout fields.
	 */
	private static function snapshot( array $fields ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$phone = isset( $fields['billing_phone'] ) ? trim( (string) $fields['billing_phone'] ) : '';
		if ( '' === $phone ) {
			return; // No phone, no snapshot.
		}

		$session = WC()->session;
		// Classic session handler: make sure a guest keeps the same session. The Store API handler has no cookie.
		if ( method_exists( $session, 'has_session' ) && method_exists( $session, 'set_customer_session_cookie' ) && ! $session->has_session() ) {
			$session->set_customer_session_cookie( true );
		}

		$checkout_id = (string) $session->get( 'udoy_checkout_id' );
		$previous    = '' !== $checkout_id ? get_transient( self::key( $checkout_id ) ) : false;
		if ( '' === $checkout_id || false === $previous ) {
			$customer_id = method_exists( $session, 'get_customer_id' ) ? (string) $session->get_customer_id() : '';
			$checkout_id = ( '' !== $customer_id ? $customer_id : wp_generate_uuid4() ) . ':' . time();
			$session->set( 'udoy_checkout_id', $checkout_id );
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'sku'          => (string) $product->get_sku(),
				'name'         => wp_strip_all_tags( $product->get_name() ),
				'quantity'     => (int) $item['quantity'],
				'price'        => number_format( (float) $product->get_price(), 2, '.', '' ),
			);
		}
		if ( ! $items ) {
			return;
		}

		$chosen = $session->get( 'chosen_shipping_methods' );

		$snapshot = array(
			'checkout_id'     => $checkout_id,
			'captured_at_gmt' => gmdate( 'Y-m-d\TH:i:s' ),
			'customer'        => array(
				'first_name' => self::field( $fields, 'billing_first_name' ),
				'last_name'  => self::field( $fields, 'billing_last_name' ),
				'phone'      => $phone,
				'email'      => self::field( $fields, 'billing_email' ),
			),
			'billing'         => self::address( $fields, 'billing_' ),
			'shipping'        => self::address( $fields, 'shipping_' ),
			'currency'        => get_woocommerce_currency(),
			'items'           => $items,
			'shipping_method' => is_array( $chosen ) && ! empty( $chosen[0] ) ? (string) $chosen[0] : '',
			'shipping_total'  => number_format( (float) WC()->cart->get_shipping_total(), 2, '.', '' ),
			'cart_total'      => number_format( (float) WC()->cart->get_total( 'edit' ), 2, '.', '' ),
			'checkout_url'    => self::checkout_url(),
			'meta'            => self::extra_meta( $fields ) ?: new stdClass(), // Always a JSON object, even when empty.
			'attempts'        => 0,
		);

		set_transient( self::key( $checkout_id ), $snapshot, 2 * DAY_IN_SECONDS );

		// Reschedule on every update.
		as_unschedule_all_actions( 'udoy_push_abandoned', array( $checkout_id ), 'udoy' );
		as_schedule_single_action( time() + self::DELAY, 'udoy_push_abandoned', array( $checkout_id ), 'udoy' );
	}

	/**
	 * The page the customer is checking out on. A CartFlows (or other custom) checkout page sends
	 * its AJAX from that page, so the referer is the real checkout; fall back to Woo's checkout page.
	 */
	private static function checkout_url() {
		$fallback = wc_get_checkout_url();
		$referer  = wp_get_referer();
		if ( ! $referer ) {
			return $fallback;
		}
		$referer = remove_query_arg( array( 'wc-ajax', '_wpnonce' ), $referer );
		return wp_validate_redirect( $referer, $fallback );
	}

	private static function key( $checkout_id ) {
		return 'udoy_ab_' . md5( $checkout_id );
	}

	private static function field( array $fields, $name ) {
		return isset( $fields[ $name ] ) && is_scalar( $fields[ $name ] ) ? (string) $fields[ $name ] : '';
	}

	private static function address( array $fields, $prefix ) {
		$out = array();
		foreach ( array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $part ) {
			$out[ $part ] = self::field( $fields, $prefix . $part );
		}
		return $out;
	}

	/** Every checkout field not already covered above, key => value. */
	private static function extra_meta( array $fields ) {
		$meta = array();
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			if ( in_array( $key, self::KNOWN_FIELDS, true ) || 0 === strpos( $key, '_' ) ) {
				continue;
			}
			// Never carry anything that looks like a secret or payment detail.
			if ( preg_match( '/nonce|pass|card|cvc|cvv|expir|token|secret|pin\b/i', $key ) ) {
				continue;
			}
			$meta[ $key ] = mb_substr( (string) $value, 0, 500 );
		}
		return $meta;
	}

	/** An order was placed from this session: forget the snapshot. */
	public static function on_order_placed( $order_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$checkout_id = (string) WC()->session->get( 'udoy_checkout_id' );
		if ( '' === $checkout_id ) {
			return;
		}
		delete_transient( self::key( $checkout_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'udoy_push_abandoned', array( $checkout_id ), 'udoy' );
		}
		WC()->session->set( 'udoy_checkout_id', null );
	}

	/** Action Scheduler callback. */
	public static function push( $checkout_id ) {
		$checkout_id = (string) $checkout_id;
		$snapshot    = get_transient( self::key( $checkout_id ) );
		if ( ! is_array( $snapshot ) || ! self::enabled() ) {
			return; // Order was placed, or the feature is off.
		}

		$attempts = isset( $snapshot['attempts'] ) ? (int) $snapshot['attempts'] : 0;
		unset( $snapshot['attempts'] );
		$result = Udoy_Queue::send( 'abandoned', $snapshot );

		if ( 'sent' === $result['outcome'] ) {
			delete_transient( self::key( $checkout_id ) );
			Udoy_Options::log( 'Abandoned checkout pushed (sent).' );
			return;
		}

		if ( 'retry' === $result['outcome'] ) {
			++$attempts;
			$delay = Udoy_Queue::delay_for_attempt( $attempts, $result['retry_after'] );
			if ( null !== $delay ) {
				$snapshot['attempts'] = $attempts;
				set_transient( self::key( $checkout_id ), $snapshot, 2 * DAY_IN_SECONDS );
				as_schedule_single_action( time() + $delay, 'udoy_push_abandoned', array( $checkout_id ), 'udoy' );
				return;
			}
		}

		Udoy_Options::set( 'last_error', $result['error'] );
		Udoy_Options::log( 'Abandoned checkout not sent: ' . $result['error'] );
		delete_transient( self::key( $checkout_id ) );
	}
}
