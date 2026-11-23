<?php
/**
 * SMS provider client — the ONE place the SMS API is wired in.
 *
 * Everything else in the plugin calls CCN_SMS_Client::send() and only cares
 * whether it returns true (sent) or a WP_Error (failed). Every attempt is also
 * written to the audit log (CCN_Log).
 *
 * Provider: MsgOwl (https://www.msgowl.com/docs).
 *   POST {api_url}   default https://rest.msgowl.com/messages
 *   GET  {base}/balance        → { "balance": "130.6347" }
 *   GET  {base}/sms_headers    → [ { name, status, ... } ]
 *   Auth  Authorization: AccessKey <api_key>
 *   Body  { recipients, sender_id, body }  (JSON)
 *   OK    HTTP 2xx, body { "message": "...", "id": <int> }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_SMS_Client {

	const BALANCE_TTL = 300;  // 5 minutes
	const HEADERS_TTL = 900;  // 15 minutes

	/**
	 * Send one SMS and record the attempt in the audit log.
	 *
	 * @param string $phone   Recipient number (raw, from billing details).
	 * @param string $message Plain-text message body.
	 * @param array  $context Optional: order_id, trigger (for the audit log).
	 * @return true|WP_Error  true on success, WP_Error on failure.
	 */
	public static function send( $phone, $message, $context = array() ) {
		$phone = trim( (string) apply_filters( 'ccn_recipient_phone', $phone ) );

		// Keep the message in GSM-7 where possible: one typographic character
		// (an em dash, a curly quote) would bill it at 67 characters per SMS.
		if ( apply_filters( 'ccn_normalize_sms', true ) ) {
			$message = CCN_Sms_Text::normalize( $message );
		}

		$entry = array(
			'order_id' => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
			'trigger'  => isset( $context['trigger'] ) ? $context['trigger'] : '',
			'body'     => $message,
		);

		// Full override hook: plug a provider without editing this file.
		$override = apply_filters( 'ccn_send_sms', null, $phone, $message );
		if ( null !== $override ) {
			$entry['recipient'] = $phone;
			return self::log_result( $entry, $override );
		}

		if ( '' === $phone ) {
			return self::log_result( $entry, new WP_Error( 'ccn_no_phone', __( 'No recipient phone number on the order.', 'click-collect' ) ) );
		}

		$recipient          = self::normalize_msisdn( $phone );
		$entry['recipient'] = '' !== $recipient ? $recipient : $phone;

		if ( '' === $recipient ) {
			return self::log_result( $entry, new WP_Error( 'ccn_bad_phone', __( 'Recipient phone number has no digits.', 'click-collect' ) ) );
		}

		if ( '' === CCN_Settings::get( 'api_url' ) || '' === CCN_Settings::get( 'api_key' ) ) {
			return self::log_result( $entry, new WP_Error(
				'ccn_not_configured',
				__( 'SMS provider is not configured yet (set the API URL and key in Click & Collect settings).', 'click-collect' )
			) );
		}

		$sender_id = CCN_Settings::get( 'sender_id' );
		$body      = array(
			'recipients' => $recipient,
			'body'       => $message,
		);
		if ( '' !== $sender_id ) {
			$body['sender_id'] = $sender_id;
		}

		$res = self::request( 'POST', self::api_url(), $body );

		if ( is_wp_error( $res ) ) {
			return self::log_result( $entry, $res ); // transport error
		}

		$entry['http_code'] = $res['code'];

		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$detail             = self::response_message( $res['data'], $res['raw'] );
			$entry['error_code'] = 'http_' . $res['code'];
			return self::log_result(
				$entry,
				new WP_Error(
					'ccn_api_error',
					$detail
						/* translators: 1: HTTP status code, 2: provider error message */
						? sprintf( __( 'SMS provider error (HTTP %1$d): %2$s', 'click-collect' ), $res['code'], $detail )
						/* translators: %d: HTTP status code */
						: sprintf( __( 'SMS provider returned HTTP %d.', 'click-collect' ), $res['code'] ),
					$res['raw']
				),
				$detail
			);
		}

		$entry['provider_id'] = isset( $res['data']['id'] ) ? (string) $res['data']['id'] : '';
		return self::log_result( $entry, true, self::response_message( $res['data'], '' ) );
	}

	/**
	 * Account balance (currency amount, as a string) — transient-cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return string|WP_Error
	 */
	public static function get_balance( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'ccn_balance' );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$res = self::request( 'GET', self::endpoint( '/balance' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$detail = self::response_message( $res['data'], $res['raw'] );
			/* translators: 1: HTTP status, 2: error message */
			return new WP_Error( 'ccn_api_error', $detail ? sprintf( __( 'MsgOwl error (HTTP %1$d): %2$s', 'click-collect' ), $res['code'], $detail ) : sprintf( __( 'MsgOwl returned HTTP %d.', 'click-collect' ), $res['code'] ) );
		}
		if ( ! isset( $res['data']['balance'] ) ) {
			return new WP_Error( 'ccn_bad_response', __( 'No balance in MsgOwl response.', 'click-collect' ) );
		}

		$balance = (string) $res['data']['balance'];
		set_transient( 'ccn_balance', $balance, self::BALANCE_TTL );
		return $balance;
	}

	/**
	 * Sender IDs / SMS headers on the account — transient-cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error List of headers ([{name,status,...}]).
	 */
	public static function get_sms_headers( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'ccn_sms_headers' );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$res = self::request( 'GET', self::endpoint( '/sms_headers' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			return new WP_Error( 'ccn_api_error', sprintf( __( 'MsgOwl returned HTTP %d.', 'click-collect' ), $res['code'] ) );
		}

		$headers = is_array( $res['data'] ) ? $res['data'] : array();
		set_transient( 'ccn_sms_headers', $headers, self::HEADERS_TTL );
		return $headers;
	}

	/** Drop cached balance / sender-ID data so the next read is fresh. */
	public static function flush_cache() {
		delete_transient( 'ccn_balance' );
		delete_transient( 'ccn_sms_headers' );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * One authenticated request to MsgOwl.
	 *
	 * @return array|WP_Error  array{code:int,raw:string,data:mixed} or transport WP_Error.
	 */
	private static function request( $method, $url, $body = null ) {
		$api_key = CCN_Settings::get( 'api_key' );
		if ( '' === $api_key ) {
			return new WP_Error( 'ccn_not_configured', __( 'MsgOwl API key is not set.', 'click-collect' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				// MsgOwl uses "AccessKey <key>", not "Bearer".
				'Authorization' => 'AccessKey ' . $api_key,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_body( $response );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'raw'  => $raw,
			'data' => json_decode( (string) $raw, true ),
		);
	}

	/** The configured send endpoint, falling back to the MsgOwl default. */
	private static function api_url() {
		$url = CCN_Settings::get( 'api_url' );
		return '' !== $url ? $url : 'https://rest.msgowl.com/messages';
	}

	/** Build a sibling endpoint (e.g. /balance) from the configured API origin. */
	private static function endpoint( $path ) {
		$p = wp_parse_url( self::api_url() );
		if ( ! empty( $p['scheme'] ) && ! empty( $p['host'] ) ) {
			$base = $p['scheme'] . '://' . $p['host'];
			if ( ! empty( $p['port'] ) ) {
				$base .= ':' . $p['port'];
			}
			return $base . $path;
		}
		return 'https://rest.msgowl.com' . $path;
	}

	/**
	 * Finalize a send: write the audit row, then return the caller's result.
	 *
	 * @param array         $entry    Partial log entry.
	 * @param true|WP_Error $result   What send() should return.
	 * @param string        $response Optional human-readable detail for the log.
	 * @return true|WP_Error
	 */
	private static function log_result( $entry, $result, $response = '' ) {
		if ( is_wp_error( $result ) ) {
			$entry['status'] = 'failed';
			if ( empty( $entry['error_code'] ) ) {
				$entry['error_code'] = $result->get_error_code();
			}
			$entry['response'] = '' !== $response ? $response : $result->get_error_message();
		} else {
			$entry['status']   = 'sent';
			$entry['response'] = $response;
		}

		if ( class_exists( 'CCN_Log' ) ) {
			CCN_Log::record( $entry );
		}
		return $result;
	}

	/**
	 * Normalize a raw billing phone to an MsgOwl-ready MSISDN.
	 *
	 * MsgOwl expects country-code + number with no punctuation (e.g. 9607771234).
	 * Maldives numbers are 7 local digits; we prepend 960 when that's all we get.
	 */
	private static function normalize_msisdn( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 7 === strlen( $digits ) ) {
			$digits = '960' . $digits;
		}
		return $digits;
	}

	/** Pull the "message" field out of a decoded/raw response body, if any. */
	private static function response_message( $data, $raw ) {
		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}
		return '';
	}
}
