<?php
/**
 * Customer "Ready for pickup" email.
 *
 * Loaded lazily from CCN_Email::add_email_class(), i.e. only once WooCommerce
 * has defined WC_Email — the class cannot be declared before then.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

class CCN_Email_Ready_For_Pickup extends WC_Email {

	public function __construct() {
		$this->id             = CCN_Email::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = __( 'Ready for pickup', 'click-collect' );
		$this->description    = __( 'Sent to the customer when their order is marked ready for pickup, telling them to come and collect it.', 'click-collect' );
		$this->email_group    = 'order-updates';

		$this->template_html  = 'emails/customer-ready-for-pickup.php';
		$this->template_plain = 'emails/plain/customer-ready-for-pickup.php';
		// Set before the parent constructor, which only fills this in when null.
		$this->template_base  = CCN_PATH . 'templates/';

		$this->placeholders = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action(
			'woocommerce_order_status_' . CCN_Status::STATUS . '_notification',
			array( $this, 'trigger' ),
			10,
			2
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your {site_title} order #{order_number} is ready to collect', 'click-collect' );
	}

	public function get_default_heading() {
		return __( 'Your order is ready to collect', 'click-collect' );
	}

	/**
	 * Where the pickup address and opening hours go. Kept as additional content
	 * rather than baked into the template so the shop can edit it from the
	 * settings screen without touching a file.
	 */
	public function get_default_additional_content() {
		return __( 'Please bring your order number with you. If you have any questions, just reply to this email.', 'click-collect' );
	}

	/**
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order object, when WooCommerce passes one.
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	private function template_args( $plain_text = false ) {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'shop_name'          => CCN_Settings::get( 'shop_name' ),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
