=== Udoy Connect ===
Contributors: myudoy
Tags: woocommerce, orders, sync, abandoned checkout, bangladesh
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your WooCommerce store to Udoy.

== Description ==

Udoy Connect connects your store to a Udoy channel. It sends new and changed orders and abandoned checkouts to Udoy, and gives Udoy access to the WooCommerce REST API for stock and order-status sync.

Sync buttons, mapping and settings live in Udoy, not in WordPress. You need a Udoy account and a WooCommerce channel in Udoy to use this plugin.

== External services ==

This plugin connects to the Udoy service (https://udoy.app). It is needed to send your store's orders to Udoy. Nothing is sent before you click Connect on WooCommerce → Settings → Udoy. All requests go to https://api.udoy.app.

* When you click Connect: your store address, a WooCommerce REST API key with read/write access (created for Udoy), the plugin, WordPress and WooCommerce versions, the store currency and time zone.
* When an order is created or changes: the order as the WooCommerce REST API returns it (customer name, phone, email, billing and shipping address, items, totals, payment method and shipping method).
* About 15 minutes after a customer enters a phone number at checkout without placing an order: the checkout details they entered and the cart contents.
* Every 30 minutes while connected: a health check with the plugin and WooCommerce versions, the number of queued and failed pushes, and the last error message.
* When you click Disconnect or delete the plugin: a disconnect request. The REST API key is deleted from your store.

This service is provided by Udoy: [Terms of Service](https://udoy.app/terms), [Privacy Policy](https://udoy.app/privacy), [Data deletion](https://udoy.app/data-deletion).

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. Go to WooCommerce → Settings → Udoy.
3. Paste the plugin key from your WooCommerce channel in Udoy and click Connect.

== Frequently Asked Questions ==

= Does the plugin send anything before I connect? =

No. Nothing is sent to Udoy until you paste your plugin key and click Connect.

= How do I stop sending data to Udoy? =

Click Disconnect on WooCommerce → Settings → Udoy. The REST API key is deleted and scheduled work is removed. Deleting the plugin does the same and also removes the plugin's options and order meta.

Deactivating the plugin stops sending orders, but keeps the connection and the REST API key, so Udoy can still sync stock until you reactivate, disconnect or delete the plugin.

= Who decides whether abandoned checkouts are sent? =

Abandoned checkout capture is controlled per channel by Udoy. When Udoy turns it off for your channel, the plugin stops capturing and sending abandoned checkouts. To stop all data, use Disconnect.

= Which currencies are supported? =

Udoy accepts orders in Bangladeshi taka (BDT) only.

== Changelog ==

= 1.0.2 =
* Admin notices now appear only on the Udoy settings tab and the Plugins screen.
* The connect form explains the REST key and the data sent to Udoy.
* Adds suggested privacy policy text (Settings → Privacy).
* The Disconnect confirmation uses an enqueued script instead of inline JavaScript.
* Orders are only sent to the same host as the Udoy API.
* The readme lists every external request the plugin makes.
* Declares compatibility with the WooCommerce Cart and Checkout blocks.

= 1.0.1 =
* Update the WordPress.org contributor username.

= 1.0.0 =
* First release.
