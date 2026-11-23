<?php
/**
 * The "Ready for pickup" customer email.
 *
 * WooCommerce builds its transactional emails around its own statuses, so a
 * custom one gets nothing: no email class, and no `..._notification` hook for a
 * class to listen to. Both halves are supplied here, which is why this file
 * registers a filter as well as a class.
 *
 * The SMS in CCN_Notifier and this email are deliberately independent — the SMS
 * has its own toggle and templates on the plugin's settings page, while the
 * email lives under WooCommerce → Settings → Emails with every other one, so
 * staff edit it where they already expect to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_Email {

	/** Matches the WC_Email::$id below; also the settings-page slug. */
	const EMAIL_ID = 'ccn_ready_for_pickup';

	public static function init() {
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'add_email_action' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'add_email_class' ) );
	}

	/**
	 * Teach WC_Emails to fire `..._ready-for-pickup_notification`.
	 *
	 * Without this the transition raises `woocommerce_order_status_ready-for-pickup`
	 * but never the deferred notification hook, and the email below would sit
	 * there listening for something that is never sent.
	 */
	public static function add_email_action( $actions ) {
		$actions[] = 'woocommerce_order_status_' . CCN_Status::STATUS;
		return $actions;
	}

	public static function add_email_class( $emails ) {
		require_once CCN_PATH . 'includes/class-ccn-email-ready-for-pickup.php';
		$emails[ self::EMAIL_ID ] = new CCN_Email_Ready_For_Pickup();
		return $emails;
	}
}
