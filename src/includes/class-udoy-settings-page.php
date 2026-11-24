<?php
/**
 * WooCommerce → Settings → Udoy tab.
 *
 * @package UdoyConnect
 */

defined( 'ABSPATH' ) || exit;

class Udoy_Settings_Page extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'udoy';
		$this->label = __( 'Udoy', 'udoy-connect' );
		parent::__construct();
	}

	public function get_settings() {
		return array();
	}

	/** Nothing to save through WooCommerce's own form; actions are handled in Udoy_Admin. */
	public function save() {}

	public function output() {
		// WooCommerce's own global for hiding the settings "Save changes" button; the name is not ours to prefix.
		global $hide_save_button; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$hide_save_button = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		echo '<h2>' . esc_html__( 'Udoy', 'udoy-connect' ) . '</h2>';

		if ( 'BDT' !== get_woocommerce_currency() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Udoy only accepts orders in BDT. Set the store currency to BDT.', 'udoy-connect' ) . '</p></div>';
		}

		$state = Udoy_Options::get( 'state', '' );

		if ( Udoy_Options::is_connected() ) {
			if ( '' !== $state ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( Udoy_Options::get( 'state_message', '' ) ) . '</p></div>';
			}
			$this->output_status();
		}

		if ( ! Udoy_Options::is_connected() || '' !== $state ) {
			$this->output_connect_form();
		}

		$this->output_help();
	}

	private function output_connect_form() {
		$base = Udoy_Options::api_base();
		wp_nonce_field( 'udoy_connect', 'udoy_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="udoy_plugin_key"><?php esc_html_e( 'Udoy plugin key', 'udoy-connect' ); ?></label></th>
				<td>
					<input type="password" id="udoy_plugin_key" name="udoy_plugin_key" class="regular-text" autocomplete="off" value="" />
					<p class="description"><?php esc_html_e( 'Copy it from your WooCommerce channel in Udoy.', 'udoy-connect' ); ?></p>
				</td>
			</tr>
			<?php if ( 'https://api.udoy.app' !== $base && ! Udoy_Options::is_connected() ) : // Only when testing against another server; the status table shows it once connected. ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Udoy server', 'udoy-connect' ); ?></th>
				<td><code><?php echo esc_html( $base ); ?></code>
					<p class="description"><?php esc_html_e( 'Set by UDOY_API_BASE in wp-config.php or the udoy_api_base filter.', 'udoy-connect' ); ?></p></td>
			</tr>
			<?php endif; ?>
		</table>
		<p class="description">
			<?php esc_html_e( 'Connecting creates a WooCommerce REST API key with read/write access and sends it to Udoy, so Udoy can sync stock and order status. After you connect, new and changed orders and abandoned checkouts are sent to Udoy. Disconnect deletes the key.', 'udoy-connect' ); ?>
		</p>
		<p class="submit"><button type="submit" name="udoy_connect" value="1" class="button button-primary"><?php esc_html_e( 'Connect', 'udoy-connect' ); ?></button></p>
		<?php
	}

	private function output_status() {
		$connected_at = (int) Udoy_Options::get( 'connected_at', 0 );
		$last_success = (int) Udoy_Options::get( 'last_success', 0 );
		$failed       = count( Udoy_Orders::failed_ids() );
		$last_error   = (string) Udoy_Options::get( 'last_error', '' );

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$never  = __( '—', 'udoy-connect' );
		?>
		<?php
		$active = Udoy_Options::is_active();
		$base   = Udoy_Options::api_base();
		?>
		<table class="form-table">
			<tr><th scope="row"><?php esc_html_e( 'Status', 'udoy-connect' ); ?></th>
				<td><strong style="color:<?php echo esc_attr( $active ? '#008a20' : '#d63638' ); ?>;">
					<?php echo $active ? esc_html__( 'Connected', 'udoy-connect' ) : esc_html__( 'Disconnected', 'udoy-connect' ); ?>
				</strong></td></tr>
			<?php if ( 'https://api.udoy.app' !== $base ) : ?>
			<tr><th scope="row"><?php esc_html_e( 'Udoy server', 'udoy-connect' ); ?></th>
				<td><code><?php echo esc_html( $base ); ?></code></td></tr>
			<?php endif; ?>
			<tr><th scope="row"><?php esc_html_e( 'Channel', 'udoy-connect' ); ?></th>
				<td><?php echo esc_html( Udoy_Options::get( 'channel_name', '' ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Connected since', 'udoy-connect' ); ?></th>
				<td><?php echo $connected_at ? esc_html( wp_date( $format, $connected_at ) ) : esc_html( $never ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Last successful push', 'udoy-connect' ); ?></th>
				<td><?php echo $last_success ? esc_html( wp_date( $format, $last_success ) ) : esc_html( $never ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Queue depth', 'udoy-connect' ); ?></th>
				<td><?php echo esc_html( (string) Udoy_Queue::depth() ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Failed pushes', 'udoy-connect' ); ?></th>
				<td>
					<?php echo esc_html( (string) $failed ); ?>
					<?php if ( $failed > 0 ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( Udoy_Admin::settings_url( array( 'udoy_action' => 'retry' ) ), 'udoy_retry' ) ); ?>"><?php esc_html_e( 'Retry failed', 'udoy-connect' ); ?></a>
					<?php endif; ?>
				</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Last error', 'udoy-connect' ); ?></th>
				<td><?php echo '' !== $last_error ? esc_html( $last_error ) : esc_html( $never ); ?></td></tr>
		</table>
		<p>
			<a class="button udoy-disconnect" href="<?php echo esc_url( wp_nonce_url( Udoy_Admin::settings_url( array( 'udoy_action' => 'disconnect' ) ), 'udoy_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'udoy-connect' ); ?></a>
		</p>
		<?php
	}

	private function output_help() {
		?>
		<details style="margin-top:1.5em;">
			<summary><?php esc_html_e( 'Connection trouble?', 'udoy-connect' ); ?></summary>
			<p><?php esc_html_e( 'If a security plugin or firewall blocks the WooCommerce REST API, allow this path so Udoy can sync stock and order status:', 'udoy-connect' ); ?> <code>/wp-json/wc/v3/*</code></p>
			<p><?php esc_html_e( 'On some shared hosts (Apache with PHP as CGI/FastCGI) the Authorization header is removed before PHP sees it. Add this line to .htaccess above the WordPress rules:', 'udoy-connect' ); ?></p>
			<pre>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</pre>
		</details>
		<?php
	}
}
