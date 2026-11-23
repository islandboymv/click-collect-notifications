<?php
/**
 * Listens for order status changes and fires the matching SMS.
 *
 * This is the integration contract with the payment side: the BML Connect
 * gateway moves an order to "Processing" on payment, shop staff move it to
 * "Ready for pickup", and to "Completed" at handover. This class reacts to those
 * transitions and nothing else — it never touches payment logic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_Notifier {

	/**
	 * Map of order status (unprefixed) → [settings toggle key, message key].
	 */
	private static function status_map() {
		return array(
			'processing'                 => array( 'notify_processing', 'msg_processing' ),
			CCN_Status::STATUS /* ready-for-pickup */ => array( 'notify_ready_for_pickup', 'msg_ready_for_pickup' ),
			'completed'                  => array( 'notify_completed', 'msg_completed' ),
		);
	}

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * @param int      $order_id
	 * @param string   $from
	 * @param string   $to
	 * @param WC_Order $order
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( 'yes' !== CCN_Settings::get( 'enabled' ) ) {
			return;
		}

		$map = self::status_map();
		if ( ! isset( $map[ $to ] ) ) {
			return;
		}
		list( $toggle_key, $msg_key ) = $map[ $to ];

		if ( 'yes' !== CCN_Settings::get( $toggle_key ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Idempotency: never send the same status's SMS twice for one order.
		$sent_meta = '_ccn_sms_sent_' . sanitize_key( $to );
		if ( $order->get_meta( $sent_meta ) ) {
			self::log( "order {$order_id}: '{$to}' SMS already sent; skipping" );
			return;
		}

		$phone   = $order->get_billing_phone();
		$message = self::render( CCN_Settings::get( $msg_key ), $order );
		$result  = CCN_SMS_Client::send( $phone, $message, array(
			'order_id' => $order_id,
			'trigger'  => $to,
		) );

		if ( is_wp_error( $result ) ) {
			self::log( "order {$order_id}: '{$to}' SMS failed: " . $result->get_error_message(), 'error' );
			$order->add_order_note( sprintf(
				/* translators: 1: status, 2: error message */
				__( 'SMS notification (%1$s) not sent: %2$s', 'click-collect' ),
				$to,
				$result->get_error_message()
			) );
			return;
		}

		$order->update_meta_data( $sent_meta, current_time( 'mysql' ) );
		$order->add_order_note( sprintf(
			/* translators: 1: phone number, 2: status */
			__( 'SMS notification sent to %1$s (status: %2$s).', 'click-collect' ),
			$phone,
			$to
		) );
		$order->save();
		self::log( "order {$order_id}: '{$to}' SMS sent to {$phone}" );
	}

	/**
	 * Replace {placeholders} in a template with order data.
	 *
	 * Everything is forced through plain_text(): an SMS is not HTML, and several
	 * of these sources are. The site name arrives entity-encoded straight from
	 * the option, and stripping tags alone leaves the entities intact.
	 */
	private static function render( $template, WC_Order $order ) {
		$shop = CCN_Settings::get( 'shop_name' );
		$repl = array(
			'{customer_name}' => $order->get_billing_first_name(),
			'{order_number}'  => $order->get_order_number(),
			'{order_total}'   => self::total( $order ),
			'{site_name}'     => get_bloginfo( 'name' ),
			'{shop_name}'     => '' !== $shop ? $shop : get_bloginfo( 'name' ),
		);

		foreach ( $repl as $token => $value ) {
			$repl[ $token ] = self::plain_text( $value );
		}

		return self::plain_text( strtr( (string) $template, $repl ) );
	}

	/**
	 * The order total as text.
	 *
	 * Deliberately not wc_price()/get_formatted_order_total(): those return
	 * markup, and their symbol for MVR is the Thaana abbreviation ".ރ", which
	 * would drag the whole message out of the GSM 7-bit alphabet into UCS-2 and
	 * cut the per-segment limit from 160 characters to 70. The ISO code is
	 * unambiguous, plain ASCII, and works for whatever currency a shop uses.
	 */
	private static function total( WC_Order $order ) {
		return $order->get_currency() . ' ' . number_format(
			(float) $order->get_total(),
			wc_get_price_decimals(),
			wc_get_price_decimal_separator(),
			wc_get_price_thousand_separator()
		);
	}

	/**
	 * Reduce a possibly-HTML string to something a handset can render.
	 *
	 * wp_strip_all_tags() removes the tags but leaves entities untouched, which
	 * is how "&nbsp;.&#x783;" reached customers verbatim. Decoding also yields a
	 * non-breaking space, swapped for an ordinary one so it cannot show up as a
	 * missing-glyph box.
	 */
	private static function plain_text( $value ) {
		$value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( str_replace( "\xc2\xa0", ' ', $value ) );
	}

	private static function log( $message, $level = 'info' ) {
		if ( 'yes' !== CCN_Settings::get( 'debug' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'click-collect' ) );
	}
}
