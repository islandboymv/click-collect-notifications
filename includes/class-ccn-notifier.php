<?php
/**
 * Listens for order status changes and fires the matching SMS.
 *
 * This is the integration contract with the payment side: the BML Connect
 * gateway moves an order to "Processing" on payment, and shop staff move it to
 * "Ready for pickup". This class reacts to those transitions and nothing else —
 * it never touches payment logic.
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
		$result  = CCN_SMS_Client::send( $phone, $message );

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
	 */
	private static function render( $template, WC_Order $order ) {
		$shop = CCN_Settings::get( 'shop_name' );
		$repl = array(
			'{customer_name}' => $order->get_billing_first_name(),
			'{order_number}'  => $order->get_order_number(),
			'{order_total}'   => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'{site_name}'     => get_bloginfo( 'name' ),
			'{shop_name}'     => '' !== $shop ? $shop : get_bloginfo( 'name' ),
		);
		return strtr( (string) $template, $repl );
	}

	private static function log( $message, $level = 'info' ) {
		if ( 'yes' !== CCN_Settings::get( 'debug' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'click-collect' ) );
	}
}
