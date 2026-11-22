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

		$action = isset( $_GET['udoy_action'] ) ? sanitize_key( wp_unslash( $_GET['udoy_action'] ) ) : '';
		if ( '' === $action || ! isset( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] ) {
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

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen_is_tab = isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'udoy' === $_GET['tab']; // phpcs:ignore WordPress.Security.NonceVerification

		$flash = get_transient( 'udoy_flash_' . get_current_user_id() );
		if ( $flash && $screen_is_tab ) {
			delete_transient( 'udoy_flash_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'error' === $flash[0] ? 'error' : 'success',
				esc_html( $flash[1] )
			);
		}

		if ( ! Udoy_Options::is_connected() ) {
			return;
		}

		$state = Udoy_Options::get( 'state', '' );
		if ( '' !== $state && ! $screen_is_tab ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
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
