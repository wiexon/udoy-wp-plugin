<?php
/**
 * Plugin Name:       Udoy Connect
 * Plugin URI:        https://udoy.app
 * Description:       Connects your WooCommerce store to Udoy: pushes orders and abandoned checkouts, and gives Udoy REST access for stock and status sync.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Udoy
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       udoy-connect
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 *
 * Copyright (c) 2026 Udoy. Licensed under GPL-2.0-or-later.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

define( 'UDOY_VERSION', '1.0.2' );
define( 'UDOY_FILE', __FILE__ );
define( 'UDOY_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'UDOY_API_BASE' ) ) {
	define( 'UDOY_API_BASE', 'https://api.udoy.app' );
}

// Declare HPOS compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UDOY_FILE, true );
			// Works with the Cart and Checkout blocks (Store API hooks in Udoy_Abandoned and Udoy_Orders).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', UDOY_FILE, true );
		}
	}
);

register_deactivation_hook( __FILE__, 'udoy_deactivate' );

/**
 * Deactivation: unschedule actions, keep options and the REST key.
 */
function udoy_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'udoy' );
	}
}

add_action( 'plugins_loaded', 'udoy_boot', 20 );

/**
 * Load the plugin once WooCommerce is available.
 */
function udoy_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				// Only on the Plugins screen, not on every admin page.
				if ( $screen && 'plugins' === $screen->id && current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Udoy Connect needs WooCommerce to be installed and active.', 'udoy-connect' ) . '</p></div>';
				}
			}
		);
		return;
	}

	require_once UDOY_DIR . 'includes/class-udoy-options.php';
	require_once UDOY_DIR . 'includes/class-udoy-client.php';
	require_once UDOY_DIR . 'includes/class-udoy-connect.php';
	require_once UDOY_DIR . 'includes/class-udoy-queue.php';
	require_once UDOY_DIR . 'includes/class-udoy-orders.php';
	require_once UDOY_DIR . 'includes/class-udoy-abandoned.php';
	require_once UDOY_DIR . 'includes/class-udoy-heartbeat.php';
	require_once UDOY_DIR . 'includes/class-udoy-admin.php';

	Udoy_Orders::init();
	Udoy_Abandoned::init();
	Udoy_Heartbeat::init();
	Udoy_Admin::init();
}
