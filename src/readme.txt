=== Udoy Connect ===
Contributors: udoy
Tags: woocommerce, orders, sync, abandoned checkout, bangladesh
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your WooCommerce store to Udoy.

== Description ==

Udoy Connect connects your store to a Udoy channel. It sends new and changed orders and abandoned checkouts to Udoy, and gives Udoy access to the WooCommerce REST API for stock and order-status sync.

Sync buttons, mapping and settings live in Udoy, not in WordPress.

= External service =

This plugin sends order data (customer name, phone, email, address, items, payment details) and abandoned checkout data to the Udoy service at https://api.udoy.app once you connect with your Udoy plugin key. It also sends a periodic health check (plugin and WooCommerce versions, queue size). Nothing is sent before you connect.

This service is provided by Udoy: [Terms of Service](https://udoy.app/terms), [Privacy Policy](https://udoy.app/privacy), [Data deletion](https://udoy.app/data-deletion).

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. Go to WooCommerce → Settings → Udoy.
3. Paste the plugin key from your WooCommerce channel in Udoy and click Connect.

== Changelog ==

= 1.0.0 =
* First release.
