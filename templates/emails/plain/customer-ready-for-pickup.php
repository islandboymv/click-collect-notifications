<?php
/**
 * Customer "Ready for pickup" email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/customer-ready-for-pickup.php.
 *
 * @package click-collect
 * @version 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccn_shop = ! empty( $shop_name ) ? $shop_name : get_bloginfo( 'name' );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hi %s,', 'click-collect' ), $order->get_billing_first_name() ) ) . "\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: order number, 2: shop name */
		__( 'Good news - your order #%1$s is packed and waiting for you at %2$s. Come and collect it whenever suits you.', 'click-collect' ),
		$order->get_order_number(),
		$ccn_shop
	)
) . "\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
