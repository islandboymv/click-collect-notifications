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
			'api_url'                 => 'https://rest.msgowl.com/messages',
			'api_key'                 => '',
			'sender_id'               => '',
			'sms_unit_cost'           => '',
			'log_retention_days'      => '0',
			'shop_name'               => '',
			'notify_processing'       => 'yes',
			'notify_ready_for_pickup' => 'yes',
			// Off by default: the customer is in the shop when this one fires, so
			// it is a receipt rather than news. Each send costs credit.
			'notify_completed'        => 'no',
			'msg_processing'          => __( 'Hi {customer_name}, we have received your payment for order {order_number} ({order_total}). We will let you know when it is ready to collect. - {shop_name}', 'click-collect' ),
			'msg_ready_for_pickup'    => __( 'Hi {customer_name}, your order {order_number} is now ready for pickup at our shop. - {shop_name}', 'click-collect' ),
			'msg_completed'           => __( 'Hi {customer_name}, thanks for collecting order {order_number} ({order_total}) from {shop_name}. See you again soon!', 'click-collect' ),
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
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
	}

	public static function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'ccn_sms_balance', __( 'Click & Collect SMS', 'click-collect' ), array( __CLASS__, 'dashboard_widget' ) );
	}

	public static function dashboard_widget() {
		echo self::account_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within.
		echo '<p style="margin-top:10px;"><a href="' . esc_url( admin_url( 'admin.php?page=ccn-settings' ) ) . '">' . esc_html__( 'Settings', 'click-collect' ) . '</a> &middot; <a href="' . esc_url( admin_url( 'admin.php?page=ccn-log' ) ) . '">' . esc_html__( 'SMS log', 'click-collect' ) . '</a></p>';
	}

	/**
	 * Renders the MsgOwl account panel: balance, estimated messages left, and
	 * sender-ID status. Used on both the settings page and the dashboard widget.
	 */
	public static function account_status_html() {
		if ( '' === self::get( 'api_key' ) ) {
			return '<p>' . esc_html__( 'Add your MsgOwl API key to see the account balance.', 'click-collect' ) . '</p>';
		}

		$balance = CCN_SMS_Client::get_balance();
		ob_start();

		if ( is_wp_error( $balance ) ) {
			echo '<p style="color:#c00;">' . esc_html( $balance->get_error_message() ) . '</p>';
		} else {
			echo '<p style="font-size:15px;margin:4px 0;"><strong>' . esc_html__( 'Balance:', 'click-collect' ) . '</strong> '
				. '<span style="font-size:20px;font-weight:600;">' . esc_html( number_format( (float) $balance, 2 ) ) . '</span> '
				. '<span style="color:#666;" title="' . esc_attr__( 'Exact amount reported by MsgOwl', 'click-collect' ) . '">(' . esc_html( $balance ) . ')</span></p>';

			$unit = (float) self::get( 'sms_unit_cost' );
			if ( $unit > 0 ) {
				$left = (int) floor( (float) $balance / $unit );
				echo '<p style="margin:4px 0;"><strong>' . esc_html__( 'Estimated messages left:', 'click-collect' ) . '</strong> '
					. '<span style="font-size:16px;font-weight:600;">' . esc_html( number_format( $left ) ) . '</span> '
					/* translators: %s: per-message cost */
					. '<span style="color:#666;">' . esc_html( sprintf( __( 'at %s each', 'click-collect' ), self::get( 'sms_unit_cost' ) ) ) . '</span></p>';
			} else {
				echo '<p style="color:#666;margin:4px 0;">' . esc_html__( 'Set a per-SMS cost in settings to estimate messages remaining.', 'click-collect' ) . '</p>';
			}
		}

		$headers = CCN_SMS_Client::get_sms_headers();
		if ( ! is_wp_error( $headers ) && ! empty( $headers ) ) {
			echo '<p style="margin:8px 0 2px;"><strong>' . esc_html__( 'Sender IDs:', 'click-collect' ) . '</strong></p><ul style="margin:0 0 4px 4px;">';
			foreach ( $headers as $h ) {
				$name   = isset( $h['name'] ) ? $h['name'] : '?';
				$status = isset( $h['status'] ) ? $h['status'] : '';
				$ok     = ( false !== stripos( $status, 'approv' ) );
				echo '<li><code>' . esc_html( $name ) . '</code> — <span style="color:' . ( $ok ? '#1a7f37' : '#b26a00' ) . ';">' . esc_html( $status ) . '</span></li>';
			}
			echo '</ul>';
		}

		return ob_get_clean();
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

		$checkboxes = array( 'enabled', 'notify_processing', 'notify_ready_for_pickup', 'notify_completed', 'debug' );
		foreach ( $checkboxes as $key ) {
			$clean[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
		}

		$clean['api_url']   = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';
		$clean['api_key']   = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$clean['sender_id'] = isset( $_POST['sender_id'] ) ? sanitize_text_field( wp_unslash( $_POST['sender_id'] ) ) : '';
		$clean['shop_name'] = isset( $_POST['shop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['shop_name'] ) ) : '';

		// Per-SMS cost is used only to estimate "messages left" — keep digits + one dot.
		$clean['sms_unit_cost']      = isset( $_POST['sms_unit_cost'] ) ? preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['sms_unit_cost'] ) ) : '';
		$clean['log_retention_days'] = isset( $_POST['log_retention_days'] ) ? (string) max( 0, (int) $_POST['log_retention_days'] ) : '0';

		$clean['msg_processing']       = isset( $_POST['msg_processing'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg_processing'] ) ) : $d['msg_processing'];
		$clean['msg_ready_for_pickup'] = isset( $_POST['msg_ready_for_pickup'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg_ready_for_pickup'] ) ) : $d['msg_ready_for_pickup'];
		$clean['msg_completed']        = isset( $_POST['msg_completed'] ) ? sanitize_textarea_field( wp_unslash( $_POST['msg_completed'] ) ) : $d['msg_completed'];

		update_option( self::OPTION, $clean );
		CCN_SMS_Client::flush_cache(); // creds may have changed — re-fetch balance next load.
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
		$res = CCN_SMS_Client::send( $phone, $msg, array( 'trigger' => 'test' ) );

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

	/**
	 * "Uses N SMS" line under a message template, using sample order values, plus
	 * a live update while the template is edited. See CCN_Sms_Text for why one
	 * character can triple the cost.
	 */
	private static function segment_hint( $template, $key ) {
		$shop   = self::get( 'shop_name' );
		$sample = strtr( (string) $template, array(
			'{customer_name}' => 'Aishath',
			'{order_number}'  => '2719',
			'{order_total}'   => 'MVR 150.00',
			'{site_name}'     => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'{shop_name}'     => '' !== $shop ? $shop : wp_strip_all_tags( get_bloginfo( 'name' ) ),
		) );
		$info = CCN_Sms_Text::segments( CCN_Sms_Text::normalize( $sample ) );
		printf(
			'<p class="description ccn-seg" data-for="%1$s" data-site="%2$s" data-shop="%3$s">%4$s</p>',
			esc_attr( $key ),
			esc_attr( wp_strip_all_tags( get_bloginfo( 'name' ) ) ),
			esc_attr( $shop ),
			esc_html( self::segment_text( $info ) )
		);
		static $js = false;
		if ( ! $js ) {
			$js = true;
			?>
			<script>
			(function () {
				var basic = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", ext = "^{}\\[~]|€";
				var map = {"\u2014":"-","\u2013":"-","\u2012":"-","\u2010":"-","\u2011":"-","\u2212":"-","\u2018":"'","\u2019":"'","\u201A":"'","\u2032":"'","\u201C":'"',"\u201D":'"',"\u201E":'"',"\u2033":'"',"\u2026":"...","\u00A0":" ","\u2007":" ","\u202F":" ","\u2009":" ","\u200B":"","\uFEFF":"","\u2022":"-","\u00B7":"-"};
				function count(t) {
					t = t.replace(/[\u2010-\u2014\u2212\u2018\u2019\u201A\u2032\u201C\u201D\u201E\u2033\u2026\u00A0\u2007\u202F\u2009\u200B\uFEFF\u2022\u00B7]/g, function (c) { return map[c]; })
						.replace(/\r\n/g, "\n").replace(/[ \t]+\n/g, "\n").trim();
					var gsm = true, len = 0;
					for (var c of t) { if (ext.indexOf(c) > -1) { len += 2; } else if (basic.indexOf(c) > -1) { len += 1; } else { gsm = false; break; } }
					if (!gsm) { len = t.length; return { e: "Unicode", l: len, s: len <= 70 ? 1 : Math.ceil(len / 67) }; }
					return { e: "GSM-7", l: len, s: len <= 160 ? 1 : Math.ceil(len / 153) };
				}
				document.querySelectorAll(".ccn-seg").forEach(function (p) {
					var ta = document.querySelector('textarea[name="' + p.dataset.for + '"]');
					if (!ta) return;
					ta.addEventListener("input", function () {
						var t = ta.value.split("{customer_name}").join("Aishath").split("{order_number}").join("2719")
							.split("{order_total}").join("MVR 150.00").split("{site_name}").join(p.dataset.site)
							.split("{shop_name}").join(p.dataset.shop || p.dataset.site);
						var r = count(t);
						p.textContent = <?php echo wp_json_encode( __( 'Uses %1$d SMS (%2$d characters, %3$s) with a typical order.', 'click-collect' ) ); ?>
							.replace("%1$d", r.s).replace("%2$d", r.l).replace("%3$s", r.e)
							+ (r.e === "Unicode" ? " " + <?php echo wp_json_encode( __( 'A character outside the standard SMS alphabet makes every SMS hold only 67 characters.', 'click-collect' ) ); ?> : "");
						p.style.color = r.s > 1 ? "#b32d2e" : "";
					});
				});
			})();
			</script>
			<?php
		}
	}

	private static function segment_text( $info ) {
		/* translators: 1: number of SMS, 2: characters, 3: encoding */
		$text = sprintf( __( 'Uses %1$d SMS (%2$d characters, %3$s) with a typical order.', 'click-collect' ), $info['segments'], $info['length'], $info['encoding'] );
		if ( 'Unicode' === $info['encoding'] ) {
			$text .= ' ' . __( 'A character outside the standard SMS alphabet makes every SMS hold only 67 characters.', 'click-collect' );
		}
		return $text;
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
		} elseif ( isset( $_GET['ccn_refresh'] ) && check_admin_referer( 'ccn_refresh' ) ) {
			CCN_SMS_Client::flush_cache();
			add_settings_error( 'ccn', 'refreshed', __( 'Account balance refreshed.', 'click-collect' ), 'updated' );
		}

		settings_errors( 'ccn' );

		$g = function ( $key ) {
			return self::get( $key );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Click & Collect SMS Notifications', 'click-collect' ); ?></h1>

			<?php if ( '' !== self::get( 'api_key' ) ) : ?>
				<div class="card" style="max-width:520px;padding:8px 16px;margin:12px 0;">
					<h2 style="margin-top:8px;"><?php esc_html_e( 'MsgOwl account', 'click-collect' ); ?></h2>
					<?php echo self::account_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within. ?>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=ccn-settings&ccn_refresh=1' ), 'ccn_refresh' ) ); ?>"><?php esc_html_e( 'Refresh balance', 'click-collect' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ccn-log' ) ); ?>"><?php esc_html_e( 'View SMS log', 'click-collect' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

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
						<td>
							<input type="url" id="api_url" name="api_url" class="regular-text" value="<?php echo esc_attr( $g( 'api_url' ) ); ?>" placeholder="https://rest.msgowl.com/messages" />
							<p class="description"><?php esc_html_e( 'MsgOwl send-message endpoint. Leave as the default unless MsgOwl tells you otherwise.', 'click-collect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key"><?php esc_html_e( 'API key', 'click-collect' ); ?></label></th>
						<td>
							<input type="password" id="api_key" name="api_key" class="regular-text" value="<?php echo esc_attr( $g( 'api_key' ) ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'MsgOwl API key (with the message.write scope). Sent as "Authorization: AccessKey …".', 'click-collect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sender_id"><?php esc_html_e( 'Sender ID', 'click-collect' ); ?></label></th>
						<td>
							<input type="text" id="sender_id" name="sender_id" class="regular-text" value="<?php echo esc_attr( $g( 'sender_id' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'An approved MsgOwl sender ID / SMS header. Leave blank to use your account default.', 'click-collect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sms_unit_cost"><?php esc_html_e( 'Cost per SMS', 'click-collect' ); ?></label></th>
						<td>
							<input type="text" id="sms_unit_cost" name="sms_unit_cost" class="small-text" value="<?php echo esc_attr( $g( 'sms_unit_cost' ) ); ?>" placeholder="0.00" />
							<p class="description"><?php esc_html_e( 'Optional. Used only to estimate "messages left" from the balance. Leave blank to show the balance amount only.', 'click-collect' ); ?></p>
						</td>
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
							<?php self::segment_hint( $g( 'msg_processing' ), 'msg_processing' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ready for pickup', 'click-collect' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="notify_ready_for_pickup" value="yes" <?php checked( $g( 'notify_ready_for_pickup' ), 'yes' ); ?> /> <?php esc_html_e( 'Send when an order becomes Ready for pickup', 'click-collect' ); ?></label>
							<textarea name="msg_ready_for_pickup" rows="3" class="large-text"><?php echo esc_textarea( $g( 'msg_ready_for_pickup' ) ); ?></textarea>
							<?php self::segment_hint( $g( 'msg_ready_for_pickup' ), 'msg_ready_for_pickup' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Collected', 'click-collect' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="notify_completed" value="yes" <?php checked( $g( 'notify_completed' ), 'yes' ); ?> /> <?php esc_html_e( 'Send when an order becomes Completed (handed over)', 'click-collect' ); ?></label>
							<textarea name="msg_completed" rows="3" class="large-text"><?php echo esc_textarea( $g( 'msg_completed' ) ); ?></textarea>
							<?php self::segment_hint( $g( 'msg_completed' ), 'msg_completed' ); ?>
							<p class="description"><?php esc_html_e( 'The customer is usually still in the shop when this fires, so it reads as a receipt rather than news. Off by default.', 'click-collect' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Advanced', 'click-collect' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="log_retention_days"><?php esc_html_e( 'Log retention', 'click-collect' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1" id="log_retention_days" name="log_retention_days" class="small-text" value="<?php echo esc_attr( $g( 'log_retention_days' ) ); ?>" />
							<?php esc_html_e( 'days', 'click-collect' ); ?>
							<p class="description"><?php esc_html_e( 'Auto-delete SMS audit-log rows older than this. 0 = keep forever.', 'click-collect' ); ?></p>
						</td>
					</tr>
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
							<input type="text" id="ccn_test_phone" name="ccn_test_phone" class="regular-text" placeholder="7xxxxxx or 9607xxxxxx" />
							<?php submit_button( __( 'Send test', 'click-collect' ), 'secondary', 'ccn_test', false ); ?>
							<p class="description"><?php esc_html_e( 'Sends a real SMS via MsgOwl using the saved settings above. A 7-digit local number is auto-prefixed with 960.', 'click-collect' ); ?></p>
						</td>
					</tr>
				</table>
			</form>
		</div>
		<?php
	}
}
