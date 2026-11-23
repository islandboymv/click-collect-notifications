=== Click & Collect Notifications for WooCommerce ===
Contributors: islandboy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.7
Stable tag: 0.7.0
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

= 0.7.0 =
* SMS now stays in the standard GSM-7 alphabet where possible. A single
  typographic character (an em dash in the signature, curly quotes, "...")
  switched the whole message to Unicode, which holds only 67 characters per
  SMS: a 151-character payment message was billed as 3 SMS instead of 1.
  Dashes, curly quotes, ellipses, non-breaking spaces and Windows line breaks
  are now replaced before sending (filter `ccn_normalize_sms` turns this off).
  Genuine non-GSM text, such as a name in Thaana, is left unchanged.
* The settings page shows how many SMS each template uses with a typical
  order, updating live while you edit it.
* Default templates sign off with "-" instead of an em dash.

= 0.6.1 =
* Fixed {order_total} arriving as raw HTML entities in the SMS — customers saw
  "15.00&nbsp;.&#x783;" instead of the amount. The total is now built from the
  order value and its ISO currency code ("MVR 15.00") rather than from
  wc_price(), which returns markup; every other placeholder is decoded too.

= 0.6.0 =
* Optional third SMS when an order is marked Completed, i.e. handed over at the
  counter. Off by default: the customer is usually still in the shop when it
  fires, so it is a receipt rather than news, and every send costs credit.

= 0.5.0 =
* Ready for pickup now sends the customer an email as well as an SMS. It appears
  under WooCommerce → Settings → Emails like any other, with its own subject,
  heading and additional content (the natural place for the pickup address and
  opening hours). Previously only the SMS fired: WooCommerce builds transactional
  emails around its own statuses, so a custom status has neither an email class
  nor the notification hook one would listen to — both are now supplied.

= 0.4.0 =
* "Ready for pickup" is now a one-click button in the orders list and in the
  order preview, next to WooCommerce's own Processing/Complete buttons, instead
  of only being reachable through the status dropdown or a bulk action.
* An order already marked ready for pickup now offers a "Completed" button.
  WooCommerce gates its buttons on core statuses only, so such orders previously
  showed no actions at all and the handover could not be recorded from the list.

= 0.3.0 =
* Admins now see the MsgOwl account balance, an estimated "messages left"
  (from an optional per-SMS cost), and sender-ID approval status — on the
  settings page and a dashboard widget. Balance is cached 5 min with a Refresh.
* Every SMS attempt (sent or failed) is now written to a persistent audit log
  (custom table) with a viewer under WooCommerce → C&C SMS Log: time, order,
  trigger, recipient, status, MsgOwl message id, HTTP code, body, and error.
  Optional retention auto-purges old rows; the log can be cleared manually.

= 0.2.0 =
* SMS client now sends through MsgOwl (https://www.msgowl.com): AccessKey auth,
  JSON body { recipients, sender_id, body }. Maldives numbers are normalized to
  960XXXXXXX. Provider error messages are surfaced in the order note. The
  "Send test" button now delivers a real SMS. Default API endpoint pre-filled.

= 0.1.0 =
* Initial skeleton: "Ready for pickup" custom order status, status-change SMS
  notifier with templates + idempotency, settings page with test-SMS, and a
  pluggable SMS client stub awaiting the provider API.
