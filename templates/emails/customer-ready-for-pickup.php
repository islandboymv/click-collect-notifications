<?php
/**
 * Customer "Ready for pickup" email (HTML).
 *
 * Override by copying to yourtheme/woocommerce/emails/customer-ready-for-pickup.php.
 *
 * @package click-collect
 * @version 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ccn_shop = ! empty( $shop_name ) ? $shop_name : get_bloginfo( 'name' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
<?php
if ( '' !== $order->get_billing_first_name() ) {
	/* translators: %s: customer first name */
	printf( esc_html__( 'Hi %s,', 'click-collect' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Hi,', 'click-collect' );
}
?>
</p>

<p>
<?php
printf(
	/* translators: 1: order number, 2: shop name */
	esc_html__( 'Good news &mdash; your order #%1$s is packed and waiting for you at %2$s. Come and collect it whenever suits you.', 'click-collect' ),
	esc_html( $order->get_order_number() ),
	esc_html( $ccn_shop )
);
?>
</p>

<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Pickup address, opening hours and anything else the shop wants to add — set
 * under WooCommerce → Settings → Emails → Ready for pickup.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
