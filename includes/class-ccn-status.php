<?php
/**
 * Registers the "Ready for pickup" custom WooCommerce order status.
 *
 * Self-contained: this is the only place the status is defined, so it can be
 * lifted into its own plugin later without touching the SMS code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_Status {

	/** Unprefixed key, as used by $order->update_status() and status hooks. */
	const STATUS = 'ready-for-pickup';
	/** Prefixed key, as stored by WooCommerce and used in wc_order_statuses. */
	const STATUS_KEY = 'wc-ready-for-pickup';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_list' ) );

		// Bulk action on the orders screen (legacy CPT + HPOS table).
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_action' ) );
	}

	/** Human label, filterable so a site can rename it without code edits. */
	public static function label() {
		return apply_filters( 'ccn_status_label', _x( 'Ready for pickup', 'Order status', 'click-collect' ) );
	}

	public static function register() {
		register_post_status( self::STATUS_KEY, array(
			'label'                     => self::label(),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: order count */
			'label_count'               => _n_noop(
				'Ready for pickup <span class="count">(%s)</span>',
				'Ready for pickup <span class="count">(%s)</span>',
				'click-collect'
			),
		) );
	}

	/**
	 * Insert the status into the order-status dropdown, right after "Processing"
	 * so it reads as the natural next fulfilment step.
	 */
	public static function add_to_list( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out[ self::STATUS_KEY ] = self::label();
			}
		}
		if ( ! isset( $out[ self::STATUS_KEY ] ) ) {
			$out[ self::STATUS_KEY ] = self::label();
		}
		return $out;
	}

	/**
	 * WooCommerce auto-handles bulk actions named "mark_{status}" for any
	 * registered status, so this just exposes it in the dropdown.
	 */
	public static function add_bulk_action( $actions ) {
		$actions[ 'mark_' . self::STATUS ] = sprintf(
			/* translators: %s: status label */
			__( 'Change status to %s', 'click-collect' ),
			strtolower( self::label() )
		);
		return $actions;
	}
}
