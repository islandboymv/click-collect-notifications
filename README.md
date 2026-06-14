# Click & Collect Notifications for WooCommerce

Adds a **Ready for pickup** order status and **SMS notifications** for
click-and-collect WooCommerce stores (order + pay online, collect in person).

## What it does

- **Custom order status** `Ready for pickup` — sits after *Processing* in the
  order-status dropdown, available as a bulk action on the Orders screen.
- **SMS on status change**:
  - order → *Processing* (paid) → "payment received" SMS
  - order → *Ready for pickup* → "ready to collect" SMS
- **Pluggable SMS provider** — all API code is isolated in
  `includes/class-ccn-sms-client.php`.
- Message templates with placeholders, per-status toggles, idempotent sends
  (never double-texts), order notes for every attempt, and a test-SMS button.

## Status: skeleton

The status + notification plumbing is complete and testable. The actual SMS API
call is a **stub** awaiting the provider's key + docs — see the `TODO` block in
`includes/class-ccn-sms-client.php`. That's the only file to edit to go live.

Until then: status changes work; `CCN_SMS_Client::send()` returns a clear
`WP_Error` ("not configured" / "not implemented"), surfaced as an order note.

## Architecture

| File | Responsibility |
|---|---|
| `click-collect-notifications.php` | Bootstrap, HPOS declare, self-update, telemetry |
| `includes/class-ccn-status.php` | Registers the `Ready for pickup` status |
| `includes/class-ccn-settings.php` | Settings store + admin page (WooCommerce → Click & Collect SMS) |
| `includes/class-ccn-notifier.php` | Reacts to `woocommerce_order_status_changed`, builds & sends messages |
| `includes/class-ccn-sms-client.php` | **The one integration point** — provider API stub |

The notifier never touches payment logic — it only reacts to status
transitions, so it stays fully decoupled from the BML Connect gateway.

## Hooks for customisation

- `ccn_status_label` — rename the status label.
- `ccn_send_sms` (`$result, $phone, $message`) — short-circuit the provider call
  (return `true` or `WP_Error`).
- `ccn_recipient_phone` — adjust/normalise the recipient number.
- `ccn_telemetry_enabled` — return `false` to disable the anonymous install ping.

## Pairing with BML Connect

Set the BML Connect gateway's **Order status after payment** to **Processing** so
the "payment received" SMS fires on confirmed payment.
