<?php
/**
 * Plugin Name: Click & Collect Notifications for WooCommerce
 * Plugin URI:  https://github.com/islandboymv/click-collect-notifications
 * Description: Adds a "Ready for pickup" order status and sends the customer an SMS when their order is paid
 *              and again when it's ready to collect in store. The SMS provider is pluggable — drop in the
 *              provider's API in one place (includes/class-ccn-sms-client.php). Self-updates from GitHub Releases.
 * Author:      Islandboy
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.7
 * Text Domain: click-collect
 * License:     GPL-2.0-or-later
 *
 * NOTE: This is a working skeleton. Order-status + notification plumbing is complete and testable;
 * the actual SMS API call is a clearly-marked stub awaiting the provider's key + docs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CCN_VERSION', '0.1.0' );
define( 'CCN_FILE', __FILE__ );
define( 'CCN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CCN_URL', plugin_dir_url( __FILE__ ) );
define( 'CCN_TELEMETRY_URL', 'https://plugin-telemetry.islandboy.workers.dev/ping' );

/**
 * Declare HPOS (custom order tables) + Blocks compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CCN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CCN_FILE, true );
	}
} );

/**
 * GitHub-powered automatic updates.
 *
 * To ship an update: bump the Version header, commit, and publish a GitHub Release
 * (e.g. tag v0.2.0). PUC ignores releases marked "pre-release".
 */
add_action( 'plugins_loaded', function () {
	require_once CCN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/islandboymv/click-collect-notifications/',
		CCN_FILE,
		'click-collect-notifications'
	);
} );

/* ---------------------------------------------------------------------------
 * Anonymous active-install telemetry.
 *
 * Sends a daily ping containing ONLY the plugin slug, a one-way SHA-256 hash of
 * the site URL (never the URL itself), and the version. It cannot identify the site.
 *
 * Opt out entirely with:
 *   add_filter( 'ccn_telemetry_enabled', '__return_false' );
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ccn_telemetry_ping' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ccn_telemetry_ping' );
	}
} );

add_action( 'ccn_telemetry_ping', 'ccn_send_telemetry' );

function ccn_send_telemetry() {
	if ( ! apply_filters( 'ccn_telemetry_enabled', true ) ) {
		return;
	}
	$home = home_url();
	foreach ( array( 'localhost', '127.0.0.1', '.test', '.local', '.localhost', '.example' ) as $needle ) {
		if ( false !== strpos( $home, $needle ) ) {
			return; // don't count local/dev sites
		}
	}
	wp_remote_post( CCN_TELEMETRY_URL, array(
		'timeout'  => 5,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json' ),
		'body'     => wp_json_encode( array(
			'slug'    => 'click-collect-notifications',
			'site'    => hash( 'sha256', $home ),
			'version' => CCN_VERSION,
		) ),
	) );
}

register_activation_hook( __FILE__, function () {
	wp_schedule_single_event( time() + 30, 'ccn_telemetry_ping' );
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'ccn_telemetry_ping' );
} );

/**
 * Boot once WooCommerce is available.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="error"><p>'
				. esc_html__( 'Click & Collect Notifications requires WooCommerce to be installed and active.', 'click-collect' )
				. '</p></div>';
		} );
		return;
	}

	require_once CCN_PATH . 'includes/class-ccn-settings.php';
	require_once CCN_PATH . 'includes/class-ccn-sms-client.php';
	require_once CCN_PATH . 'includes/class-ccn-status.php';
	require_once CCN_PATH . 'includes/class-ccn-notifier.php';

	CCN_Status::init();
	CCN_Settings::init();
	CCN_Notifier::init();
}, 11 );

/**
 * Settings shortcut on the Plugins screen.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=ccn-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'click-collect' ) . '</a>' );
	return $links;
} );
