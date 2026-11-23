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

		// One-click buttons in the orders list and in the order preview modal.
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		add_filter( 'woocommerce_admin_order_preview_actions', array( __CLASS__, 'add_preview_actions' ), 10, 2 );
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

	/**
	 * Buttons in the Actions column of the orders list.
	 */
	public static function add_row_actions( $actions, $order ) {
		return self::merge_buttons( $actions, self::buttons_for( $order ) );
	}

	/**
	 * Buttons in the footer of the order preview modal.
	 *
	 * These arrive nested under a "Change status:" group rather than as a flat
	 * list, and the group is absent entirely when WooCommerce found nothing to
	 * offer — which is the case for a ready-for-pickup order, so it has to be
	 * rebuilt here.
	 */
	public static function add_preview_actions( $actions, $order ) {
		$buttons = self::buttons_for( $order );
		if ( ! $buttons ) {
			return $actions;
		}

		if ( ! isset( $actions['status']['actions'] ) ) {
			$actions['status'] = array(
				'group'   => __( 'Change status: ', 'click-collect' ),
				'actions' => array(),
			);
		}

		$actions['status']['actions'] = self::merge_buttons( $actions['status']['actions'], $buttons );

		return $actions;
	}

	/**
	 * The two buttons WooCommerce cannot know about.
	 *
	 * Core gates its own buttons on a hardcoded list of core statuses, so the
	 * pickup step never appears and — worse — an order already marked ready for
	 * pickup falls outside every check and is left with no buttons at all, which
	 * makes the handover impossible to record without opening the order.
	 *
	 * Pending is deliberately excluded from the pickup offer: an unpaid order is
	 * not ready to collect, and the button would fire the "come and get it" SMS.
	 */
	private static function buttons_for( $order ) {
		$buttons = array();

		if ( $order->has_status( array( 'processing', 'on-hold' ) ) ) {
			$buttons[ self::STATUS ] = self::button( $order, self::STATUS, self::STATUS, self::label() );
		}

		if ( $order->has_status( array( self::STATUS ) ) ) {
			$buttons['complete'] = self::button( $order, 'completed', 'complete', __( 'Completed', 'click-collect' ) );
		}

		return $buttons;
	}

	/**
	 * Same shape and same AJAX endpoint core uses for its own status buttons;
	 * wc_ajax handles any registered status, so nothing else is needed.
	 */
	private static function button( $order, $status, $action, $name ) {
		return array(
			'url'    => wp_nonce_url(
				admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=' . $status . '&order_id=' . $order->get_id() ),
				'woocommerce-mark-order-status'
			),
			'name'   => $name,
			/* translators: %s: order status label */
			'title'  => sprintf( __( 'Change order status to %s', 'click-collect' ), strtolower( $name ) ),
			'action' => $action,
		);
	}

	/**
	 * Splice ours in ahead of "Complete", so the buttons read in fulfilment
	 * order rather than pushing the last step into the middle.
	 */
	private static function merge_buttons( $actions, $buttons ) {
		if ( ! $buttons ) {
			return $actions;
		}

		$out = array();
		foreach ( $actions as $key => $action ) {
			if ( 'complete' === $key && $buttons ) {
				$out     = array_merge( $out, $buttons );
				$buttons = array();
			}
			$out[ $key ] = $action;
		}

		return array_merge( $out, $buttons );
	}
}
