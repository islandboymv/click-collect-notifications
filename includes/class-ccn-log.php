<?php
/**
 * SMS audit log — persistent record of every send attempt.
 *
 * Stores one row per CCN_SMS_Client::send() call (success or failure) in a
 * dedicated table so shop staff have a durable audit trail independent of
 * WooCommerce order notes / WC logs (which rotate and can be cleared).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_Log {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'ccn_log_db_version';
	const CLEANUP_HOOK      = 'ccn_log_cleanup';
	const PER_PAGE          = 50;

	/** Fully-qualified table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ccn_sms_log';
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Create/upgrade the table. Safe to call repeatedly (dbDelta is idempotent).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// NOTE: `trigger` is a reserved SQL word — column is `trigger_key`.
		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			trigger_key VARCHAR(40) NOT NULL DEFAULT '',
			recipient VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			http_code SMALLINT(6) NOT NULL DEFAULT 0,
			provider_id VARCHAR(64) NOT NULL DEFAULT '',
			error_code VARCHAR(64) NOT NULL DEFAULT '',
			body TEXT NULL,
			response TEXT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY created_at (created_at),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Run install() when the schema version has changed (upgrades without reactivation). */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert one audit row.
	 *
	 * @param array $data Keys: order_id, trigger, recipient, status, http_code,
	 *                    provider_id, error_code, body, response.
	 * @return int|false Insert ID, or false on failure.
	 */
	public static function record( $data ) {
		global $wpdb;

		$trigger = isset( $data['trigger'] ) ? $data['trigger'] : ( isset( $data['trigger_key'] ) ? $data['trigger_key'] : '' );
		$get     = function ( $key, $default = '' ) use ( $data ) {
			return isset( $data[ $key ] ) ? $data[ $key ] : $default;
		};

		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'order_id'    => (int) $get( 'order_id', 0 ),
				'trigger_key' => substr( (string) $trigger, 0, 40 ),
				'recipient'   => substr( (string) $get( 'recipient' ), 0, 32 ),
				'status'      => substr( (string) $get( 'status' ), 0, 20 ),
				'http_code'   => (int) $get( 'http_code', 0 ),
				'provider_id' => substr( (string) $get( 'provider_id' ), 0, 64 ),
				'error_code'  => substr( (string) $get( 'error_code' ), 0, 64 ),
				'body'        => (string) $get( 'body' ),
				'response'    => substr( (string) $get( 'response' ), 0, 2000 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/** Total rows, optionally filtered by status. */
	public static function count( $status = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Fetch a page of rows, newest first.
	 *
	 * @param int    $paged  1-based page number.
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public static function get_rows( $paged = 1, $status = '' ) {
		global $wpdb;
		$table  = self::table();
		$paged  = max( 1, (int) $paged );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		if ( '' !== $status ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				$status,
				self::PER_PAGE,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			);
		}
		return $wpdb->get_results( $sql );
	}

	/** Delete rows older than the configured retention (0/blank = keep forever). */
	public static function cleanup() {
		$days = (int) CCN_Settings::get( 'log_retention_days' );
		if ( $days < 1 ) {
			return;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE created_at < %s", $cutoff ) );
	}

	/** Empty the entire log. */
	public static function clear() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE " . self::table() );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Click & Collect SMS Log', 'click-collect' ),
			__( 'C&C SMS Log', 'click-collect' ),
			'manage_woocommerce',
			'ccn-log',
			array( __CLASS__, 'render_page' )
		);
	}

	private static function status_badge( $status ) {
		$color = 'sent' === $status ? '#1a7f37' : ( 'failed' === $status ? '#c00' : '#666' );
		return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;background:' . esc_attr( $color ) . '">' . esc_html( $status ) . '</span>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Handle "clear log".
		if ( isset( $_POST['ccn_clear_log'] ) ) {
			check_admin_referer( 'ccn_clear_log' );
			self::clear();
			add_settings_error( 'ccn_log', 'cleared', __( 'SMS log cleared.', 'click-collect' ), 'updated' );
		}

		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, array( 'sent', 'failed' ), true ) ? $status : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$total       = self::count( $status );
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$rows        = self::get_rows( $paged, $status );

		settings_errors( 'ccn_log' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Click & Collect SMS Log', 'click-collect' ); ?></h1>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ccn-log' ) ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'click-collect' ); ?> <span class="count">(<?php echo (int) self::count(); ?>)</span></a> | </li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ccn-log&status=sent' ) ); ?>" class="<?php echo 'sent' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Sent', 'click-collect' ); ?> <span class="count">(<?php echo (int) self::count( 'sent' ); ?>)</span></a> | </li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=ccn-log&status=failed' ) ); ?>" class="<?php echo 'failed' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Failed', 'click-collect' ); ?> <span class="count">(<?php echo (int) self::count( 'failed' ); ?>)</span></a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:140px;"><?php esc_html_e( 'When', 'click-collect' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Order', 'click-collect' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Trigger', 'click-collect' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Recipient', 'click-collect' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Status', 'click-collect' ); ?></th>
						<th><?php esc_html_e( 'Message / result', 'click-collect' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No SMS logged yet.', 'click-collect' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r->created_at ); ?></td>
								<td>
									<?php if ( (int) $r->order_id > 0 ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ) ); ?>">#<?php echo (int) $r->order_id; ?></a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $r->trigger_key ); ?></td>
								<td><?php echo esc_html( $r->recipient ); ?></td>
								<td><?php echo self::status_badge( $r->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a fixed set. ?></td>
								<td>
									<div><?php echo esc_html( $r->body ); ?></div>
									<?php if ( '' !== $r->response || '' !== $r->error_code || (int) $r->http_code > 0 || '' !== $r->provider_id ) : ?>
										<div style="color:#666;font-size:12px;margin-top:3px;">
											<?php if ( '' !== $r->provider_id ) : ?><code><?php esc_html_e( 'id', 'click-collect' ); ?>:<?php echo esc_html( $r->provider_id ); ?></code> <?php endif; ?>
											<?php if ( (int) $r->http_code > 0 ) : ?><code>HTTP <?php echo (int) $r->http_code; ?></code> <?php endif; ?>
											<?php if ( '' !== $r->error_code ) : ?><code><?php echo esc_html( $r->error_code ); ?></code> <?php endif; ?>
											<?php echo esc_html( $r->response ); ?>
										</div>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					$q = array( 'page' => 'ccn-log' );
					if ( '' !== $status ) {
						$q['status'] = $status;
					}
					$base_url = admin_url( 'admin.php?' . http_build_query( $q ) );
					echo wp_kses_post( paginate_links( array(
						'base'      => $base_url . '%_%',
						'format'    => '&paged=%#%',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					) ) );
					?>
				</div></div>
			<?php endif; ?>

			<hr />
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete the entire SMS log?', 'click-collect' ) ); ?>');">
				<?php wp_nonce_field( 'ccn_clear_log' ); ?>
				<?php submit_button( __( 'Clear entire log', 'click-collect' ), 'delete', 'ccn_clear_log', false ); ?>
				<?php $days = (int) CCN_Settings::get( 'log_retention_days' ); ?>
				<?php if ( $days > 0 ) : ?>
					<span class="description" style="margin-left:8px;"><?php printf( esc_html__( 'Rows older than %d days are auto-purged daily.', 'click-collect' ), $days ); ?></span>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}
