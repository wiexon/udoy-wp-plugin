<?php
/**
 * Admin: settings tab registration, form handlers, notices.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Admin {

	public static function init() {
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( UDOY_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function register_settings_page( $pages ) {
		require_once UDOY_DIR . 'includes/class-udoy-settings-page.php';
		$pages[] = new Udoy_Settings_Page();
		return $pages;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'udoy-connect' ) . '</a>' );
		return $links;
	}

	public static function settings_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'wc-settings', 'tab' => 'udoy' ), $args ), admin_url( 'admin.php' ) );
	}

	private static function flash( $type, $message ) {
		set_transient( 'udoy_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	/** Connect (POST), Disconnect and Retry failed (GET with nonce). Runs before WooCommerce's own save. */
	public static function handle_actions() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['udoy_connect'] ) ) {
			check_admin_referer( 'udoy_connect', 'udoy_nonce' );

			$key = isset( $_POST['udoy_plugin_key'] ) ? sanitize_text_field( wp_unslash( $_POST['udoy_plugin_key'] ) ) : '';

			$result = Udoy_Connect::connect( $key );
			if ( is_wp_error( $result ) ) {
				self::flash( 'error', $result->get_error_message() );
			} else {
				self::flash( 'success', __( 'Connected to Udoy.', 'udoy-connect' ) );
			}
			wp_safe_redirect( self::settings_url() );
			exit;
		}

		$action = isset( $_GET['udoy_action'] ) ? sanitize_key( wp_unslash( $_GET['udoy_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below for each action.
		if ( '' === $action || 'wc-settings' !== self::query_arg( 'page' ) ) {
			return;
		}

		if ( 'disconnect' === $action ) {
			check_admin_referer( 'udoy_disconnect' );
			Udoy_Connect::disconnect();
			self::flash( 'success', __( 'Disconnected from Udoy.', 'udoy-connect' ) );
			wp_safe_redirect( self::settings_url() );
			exit;
		}

		if ( 'retry' === $action ) {
			check_admin_referer( 'udoy_retry' );
			$count = Udoy_Orders::retry_failed();
			/* translators: %d: number of orders */
			self::flash( 'success', sprintf( _n( '%d failed order queued again.', '%d failed orders queued again.', $count, 'udoy-connect' ), $count ) );
			wp_safe_redirect( self::settings_url() );
			exit;
		}
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'This store uses Udoy (https://udoy.app) to manage orders. When you place an order, your name, phone number, email address, billing and shipping address, ordered items and payment details are sent to Udoy.', 'udoy-connect' ) . '</p>'
			. '<p>' . esc_html__( 'If you enter your phone number at checkout but do not complete the order, the details you entered and the contents of your cart may be sent to Udoy after about 15 minutes, so the store can contact you about the unfinished order.', 'udoy-connect' ) . '</p>'
			. '<p>' . sprintf(
				/* translators: 1: Udoy privacy policy URL, 2: Udoy data deletion URL */
				esc_html__( 'See the Udoy privacy policy (%1$s) and data deletion page (%2$s).', 'udoy-connect' ),
				esc_url( 'https://udoy.app/privacy' ),
				esc_url( 'https://udoy.app/data-deletion' )
			) . '</p>';
		wp_add_privacy_policy_content( 'Udoy Connect', wp_kses_post( $content ) );
	}

	/** Confirm before Disconnect, only on the Udoy settings tab. */
	public static function enqueue() {
		if ( ! self::is_udoy_tab() ) {
			return;
		}
		wp_register_script( 'udoy-connect-admin', false, array(), UDOY_VERSION, true );
		wp_enqueue_script( 'udoy-connect-admin' );
		wp_add_inline_script(
			'udoy-connect-admin',
			'document.addEventListener("click",function(e){var a=e.target.closest(".udoy-disconnect");if(a&&!window.confirm(' . wp_json_encode( __( 'Disconnect this store from Udoy?', 'udoy-connect' ) ) . ')){e.preventDefault();}});'
		);
	}

	/**
	 * A sanitized query-string value. Only used to decide which screen is showing, never to change data.
	 *
	 * @param string $name Query arg name.
	 */
	private static function query_arg( $name ) {
		return isset( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Whether the current screen is WooCommerce → Settings → Udoy. */
	public static function is_udoy_tab() {
		return 'wc-settings' === self::query_arg( 'page' ) && 'udoy' === self::query_arg( 'tab' );
	}

	/** Whether the current admin screen is the Plugins list. */
	public static function is_plugins_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'plugins' === $screen->id;
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen_is_tab = self::is_udoy_tab();

		$flash = get_transient( 'udoy_flash_' . get_current_user_id() );
		if ( $flash && $screen_is_tab ) {
			delete_transient( 'udoy_flash_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'error' === $flash[0] ? 'error' : 'success' ),
				esc_html( $flash[1] )
			);
		}

		if ( ! Udoy_Options::is_connected() ) {
			return;
		}

		// Keep notices to the Udoy settings tab and the Plugins screen; never show them on other admin pages.
		$on_plugins = self::is_plugins_screen();
		if ( ! $screen_is_tab && ! $on_plugins ) {
			return;
		}

		$state = Udoy_Options::get( 'state', '' );
		if ( '' !== $state && $on_plugins ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( Udoy_Options::get( 'state_message', '' ) ),
				esc_url( self::settings_url() ),
				esc_html__( 'Open Udoy settings', 'udoy-connect' )
			);
		}

		$min = (string) Udoy_Options::remote_setting( 'minPluginVersion', '' );
		if ( '' !== $min && version_compare( UDOY_VERSION, $min, '<' ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Update Udoy Connect — this version is no longer supported', 'udoy-connect' ) . '</p></div>';
		}
	}
}
