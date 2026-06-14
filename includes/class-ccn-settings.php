<?php
/**
 * Settings store + admin page for Click & Collect Notifications.
 *
 * All settings live in a single option array (self::OPTION). Read anywhere with
 * CCN_Settings::get( 'key' ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_Settings {

	const OPTION = 'ccn_settings';

	/**
	 * Defaults — also the source of truth for which keys exist.
	 */
	public static function defaults() {
		return array(
			'enabled'                 => 'no',
			'api_url'                 => '',
			'api_key'                 => '',
			'sender_id'               => '',
			'shop_name'               => '',
			'notify_processing'       => 'yes',
			'notify_ready_for_pickup' => 'yes',
			'msg_processing'          => __( 'Hi {customer_name}, we have received your payment for order {order_number} ({order_total}). We will let you know when it is ready to collect. — {shop_name}', 'click-collect' ),
			'msg_ready_for_pickup'    => __( 'Hi {customer_name}, your order {order_number} is now ready for pickup at our shop. — {shop_name}', 'click-collect' ),
			'debug'                   => 'no',
		);
	}

	public static function get( $key, $default = '' ) {
		$opts = get_option( self::OPTION, array() );
		if ( is_array( $opts ) && isset( $opts[ $key ] ) ) {
			return $opts[ $key ];
		}
		$def = self::defaults();
		return array_key_exists( $key, $def ) ? $def[ $key ] : $default;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Click & Collect SMS', 'click-collect' ),
			__( 'Click & Collect SMS', 'click-collect' ),
			'manage_woocommerce',
			'ccn-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Sanitize + persist the submitted form.
	 */
	private static function handle_save() {
		$d     = self::defaults();
		$clean = array();

		$checkboxes = array( 'enabled', 'notify_processing', 'notify_ready_for_pickup', 'debug' );
		foreach ( $checkboxes as $key ) {
			$clean[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
		}

		$clean['api_url']   = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';
		$clean['api_key']   = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$clean['sender_id'] = isset( $_POST['sender_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : '';
		$clean['shop_name'] = isset( $_POST['shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) : '';

		$clean['msg_processing']       = isset( $_POST['msg_processing'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg_processing'] ) ) : $d['msg_processing'];
		$clean['msg_ready_for_pickup'] = isset( $_POST['msg_ready_for_pickup'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg_ready_for_pickup'] ) ) : $d['msg_ready_for_pickup'];

		update_option( self::OPTION, $clean );
		add_settings_error( 'ccn', 'saved', __( 'Settings saved.', 'click-collect' ), 'updated' );
	}

	/**
	 * Fire a one-off test SMS through the (stubbed) client so wiring can be
	 * verified before the provider API is in place.
	 */
	private static function handle_test() {
		$phone = isset( $_POST['ccn_test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['ccn_test_phone'] ) ) : '';
		if ( '' === $phone ) {
			add_settings_error( 'ccn', 'test_no_phone', __( 'Enter a phone number to send a test SMS.', 'click-collect' ), 'error' );
			return;
		}
		/* translators: %s: site name */
		$msg = sprintf( __( 'Test SMS from %s — Click & Collect Notifications.', 'click-collect' ), get_bloginfo( 'name' ) );
		$res = CCN_SMS_Client::send( $phone, $msg );

		if ( is_wp_error( $res ) ) {
			add_settings_error( 'ccn', 'test_failed', sprintf(
				/* translators: %s: error message */
				__( 'Test SMS failed: %s', 'click-collect' ),
				$res->get_error_message()
			), 'error' );
		} else {
			add_settings_error( 'ccn', 'test_ok', __( 'Test SMS sent.', 'click-collect' ), 'updated' );
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['ccn_save'] ) ) {
			check_admin_referer( 'ccn_settings' );
			self::handle_save();
		} elseif ( isset( $_POST['ccn_test'] ) ) {
			check_admin_referer( 'ccn_settings' );
			self::handle_test();
		}

		settings_errors( 'ccn' );

		$g = function ( $key ) {
			return self::get( $key );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Click & Collect SMS Notifications', 'click-collect' ); ?></h1>

			<?php if ( '' === self::get( 'api_key' ) || '' === self::get( 'api_url' ) ) : ?>
				<div class="notice notice-info inline" style="margin:12px 0;">
					<p><?php esc_html_e( 'SMS sending is not active yet — add the provider API URL and key below. Order statuses still work; messages just won\'t be delivered until then.', 'click-collect' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'ccn_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'General', 'click-collect' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable SMS', 'click-collect' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="yes" <?php checked( $g( 'enabled' ), 'yes' ); ?> /> <?php esc_html_e( 'Send SMS notifications on order status changes', 'click-collect' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="shop_name"><?php esc_html_e( 'Shop name', 'click-collect' ); ?></label></th>
						<td>
							<input type="text" id="shop_name" name="shop_name" class="regular-text" value="<?php echo esc_attr( $g( 'shop_name' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used in the {shop_name} placeholder. Defaults to the site name.', 'click-collect' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'SMS provider', 'click-collect' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="api_url"><?php esc_html_e( 'API endpoint URL', 'click-collect' ); ?></label></th>
						<td><input type="url" id="api_url" name="api_url" class="regular-text" value="<?php echo esc_attr( $g( 'api_url' ) ); ?>" placeholder="https://" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key"><?php esc_html_e( 'API key', 'click-collect' ); ?></label></th>
						<td><input type="password" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr( $g( 'api_key' ) ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="sender_id"><?php esc_html_e( 'Sender ID', 'click-collect' ); ?></label></th>
						<td><input type="text" id="sender_id" name="sender_id" class="regular-text" value="<?php echo esc_attr( $g( 'sender_id' ) ); ?>" /></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Messages', 'click-collect' ); ?></h2>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'Placeholders:', 'click-collect' ); ?>
					<code>{customer_name}</code> <code>{order_number}</code> <code>{order_total}</code> <code>{shop_name}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment received', 'click-collect' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="notify_processing" value="yes" <?php checked( $g( 'notify_processing' ), 'yes' ); ?> /> <?php esc_html_e( 'Send when an order becomes Processing (paid)', 'click-collect' ); ?></label>
							<textarea name="msg_processing" rows="3" class="large-text"><?php echo esc_textarea( $g( 'msg_processing' ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ready for pickup', 'click-collect' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="notify_ready_for_pickup" value="yes" <?php checked( $g( 'notify_ready_for_pickup' ), 'yes' ); ?> /> <?php esc_html_e( 'Send when an order becomes Ready for pickup', 'click-collect' ); ?></label>
							<textarea name="msg_ready_for_pickup" rows="3" class="large-text"><?php echo esc_textarea( $g( 'msg_ready_for_pickup' ) ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Advanced', 'click-collect' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug logging', 'click-collect' ); ?></th>
						<td><label><input type="checkbox" name="debug" value="yes" <?php checked( $g( 'debug' ), 'yes' ); ?> /> <?php esc_html_e( 'Log SMS attempts to WooCommerce → Status → Logs (source: click-collect)', 'click-collect' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'click-collect' ), 'primary', 'ccn_save' ); ?>
			</form>

			<hr />
			<h2 class="title"><?php esc_html_e( 'Send a test SMS', 'click-collect' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'ccn_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ccn_test_phone"><?php esc_html_e( 'Phone number', 'click-collect' ); ?></label></th>
						<td>
							<input type="text" id="ccn_test_phone" name="ccn_test_phone" class="regular-text" placeholder="9607xxxxxx" />
							<?php submit_button( __( 'Send test', 'click-collect' ), 'secondary', 'ccn_test', false ); ?>
							<p class="description"><?php esc_html_e( 'Uses the saved provider settings. Until the provider API is implemented this returns a clear "not configured / not implemented" error — that confirms the wiring is correct.', 'click-collect' ); ?></p>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<?php
	}
}
