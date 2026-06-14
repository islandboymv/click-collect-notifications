=== Click & Collect Notifications for WooCommerce ===
Contributors: islandboy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.7
Stable tag: 0.1.0
License: GPLv2 or later

Adds a "Ready for pickup" order status and SMS notifications for click-and-collect WooCommerce stores.

== Description ==

For shops where customers order and pay online, then collect in person. The plugin:

* Registers a "Ready for pickup" custom order status (after Processing in the
  fulfilment flow), selectable from the order screen and as a bulk action.
* Sends the customer an SMS when their order is paid (Processing) and again when
  it is Ready for pickup.
* Keeps the SMS provider pluggable — the actual API call lives in one file
  (includes/class-ccn-sms-client.php), so any provider can be wired in.

The status and notification plumbing is complete. The SMS provider call is a
clearly-marked stub awaiting the provider's API key and documentation; until then
the status changes work and SMS attempts return a clear "not configured" error.

== Configuration ==

WooCommerce → Click & Collect SMS:
1. Enter the SMS provider API URL, key, and sender ID.
2. Tick "Enable SMS" and choose which statuses notify.
3. Edit the message templates (placeholders: {customer_name}, {order_number},
   {order_total}, {shop_name}, {site_name}).
4. Use "Send a test SMS" to verify wiring.

Pairs with the BML Connect gateway: set that gateway's "Order status after
payment" to Processing so the "payment received" SMS fires.

== Changelog ==

= 0.1.0 =
* Initial skeleton: "Ready for pickup" custom order status, status-change SMS
  notifier with templates + idempotency, settings page with test-SMS, and a
  pluggable SMS client stub awaiting the provider API.
