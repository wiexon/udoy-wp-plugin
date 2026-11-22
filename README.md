# Udoy Connect

Connects a WooCommerce store to [Udoy](https://udoy.app). Orders and abandoned checkouts are sent to Udoy
as they happen, and Udoy gets WooCommerce REST access to keep stock and order status in step.

The plugin has no sync buttons or mapping screens of its own. Those live in Udoy.

## What it does

- **Connect** the store to one Udoy channel with a plugin key copied from Udoy.
- **Push orders** when they are created or change, through Action Scheduler with a retry queue
  (1 min, 5 min, 30 min, 2 h, 6 h, 24 h).
- **Push abandoned checkouts** 15 minutes after the customer last updated the checkout, if no order was placed.
  Works with the classic checkout, the Block checkout and CartFlows.
- **Send a heartbeat** every 30 minutes so Udoy can show the connection's health.
- **Disconnect** cleanly: the REST key is deleted, scheduled work is removed and options are cleared.

The plugin does not sync stock, products or order status. Udoy does that by calling the standard
`/wp-json/wc/v3` API with the REST key the plugin created.

## Requirements

| | Minimum |
|---|---|
| PHP | 7.4 |
| WordPress | 6.0 |
| WooCommerce | 7.0 (HPOS compatible) |
| Store address | public `https://` (Udoy does not call `localhost`, private IPs or plain `http`) |
| Currency | BDT (Udoy only accepts orders in taka) |

## Install

1. Download `udoy-connect-<version>.zip` from the
   [releases](https://github.com/wiexon/udoy-wp-plugin/releases) page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. In Udoy, add a **WooCommerce** order channel and choose **Plugin**. Copy the plugin key; it is shown once.
4. In WordPress: **WooCommerce → Settings → Udoy**, paste the key and click **Connect**.

If the store's REST API is blocked by a firewall or security plugin, allow `/wp-json/wc/v3/*`. On some shared
hosts (Apache with PHP as CGI/FastCGI) the `Authorization` header is stripped; add this above the WordPress rules
in `.htaccess`:

```
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## Data sent to Udoy

After you connect, the plugin sends order data (customer name, phone, email, address, items, payment details)
and abandoned checkout data to the Udoy service, plus a periodic health check (plugin and WooCommerce versions,
queue size). Nothing is sent before you connect. Logs written by the plugin (WooCommerce → Status → Logs,
source `udoy`) contain order ids and HTTP status codes only.

## Development

The plugin source lives in [`src/`](src). The production API address,
`https://api.udoy.app`, is compiled in. To test against another Udoy server, define the base in `wp-config.php`
(plain `http` is accepted only when `WP_DEBUG` is on):

```php
define( 'UDOY_API_BASE', 'https://your-test-server.example' );
```

or use the `udoy_api_base` filter. All control calls go to `{base}/api/plugin/v1/<action>`; pushes go to the
`intakeUrl` that the connect call returns.

Filters:

- `udoy_api_base`: override the API base.
- `udoy_paid_amount( $amount, $order )`: correct the amount a customer paid online, for deposit or advance
  plugins that are not recognised. Recognised out of the box: Advanced Partial Payment or Deposit
  (`_apd_amount_paid`) and Deposits & Partial Payments by Acowebs (`_awcdp_deposits_*`).

Build the release zip (needs Node 18+):

```
npm install
npm run build
```

This copies `src/` to `dist/udoy-connect/` and writes `dist/udoy-connect-<version>.zip`, with the
version read from the plugin header. `npm run clean` removes `dist/`.

Run the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin on `dist/udoy-connect` before a release.

## License

Copyright (c) 2026 Udoy. Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).

The licence covers this source code. It does not grant any right to use the Udoy name, logo or branding.

## Security

To report a vulnerability, see [SECURITY.md](SECURITY.md).
