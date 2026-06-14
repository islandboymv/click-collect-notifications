<?php
/**
 * SMS provider client — the ONE place to wire in the client's SMS API.
 *
 * Everything else in the plugin calls CCN_SMS_Client::send() and only cares
 * whether it returns true (sent) or a WP_Error (failed). When the provider's
 * key + docs arrive, fill in the TODO block below; nothing else needs to change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCN_SMS_Client {

	/**
	 * Send one SMS.
	 *
	 * @param string $phone   Recipient number (raw, from billing details).
	 * @param string $message Plain-text message body.
	 * @return true|WP_Error  true on success, WP_Error on failure.
	 */
	public static function send( $phone, $message ) {
		$phone = trim( (string) apply_filters( 'ccn_recipient_phone', $phone ) );

		if ( '' === $phone ) {
			return new WP_Error( 'ccn_no_phone', __( 'No recipient phone number on the order.', 'click-collect' ) );
		}

		// Full override hook: plug a provider without editing this file.
		// Return true or a WP_Error from the filter to short-circuit.
		$override = apply_filters( 'ccn_send_sms', null, $phone, $message );
		if ( null !== $override ) {
			return $override;
		}

		$api_url   = CCN_Settings::get( 'api_url' );
		$api_key   = CCN_Settings::get( 'api_key' );
		$sender_id = CCN_Settings::get( 'sender_id' );

		if ( '' === $api_url || '' === $api_key ) {
			return new WP_Error(
				'ccn_not_configured',
				__( 'SMS provider is not configured yet (set the API URL and key in Click & Collect settings).', 'click-collect' )
			);
		}

		/* =====================================================================
		 * TODO — integrate the client's SMS API here when the key + docs land.
		 *
		 * The block below is a typical bearer-auth JSON POST. Adjust the URL
		 * shape, auth header, body field names, and success check to match the
		 * provider's actual documentation, then delete the WP_Error return at
		 * the bottom of this method.
		 *
		 * $response = wp_remote_post( $api_url, array(
		 *     'timeout' => 15,
		 *     'headers' => array(
		 *         'Authorization' => 'Bearer ' . $api_key,
		 *         'Content-Type'  => 'application/json',
		 *         'Accept'        => 'application/json',
		 *     ),
		 *     'body'    => wp_json_encode( array(
		 *         'sender'  => $sender_id,
		 *         'to'      => $phone,
		 *         'message' => $message,
		 *     ) ),
		 * ) );
		 *
		 * if ( is_wp_error( $response ) ) {
		 *     return $response; // network/transport error
		 * }
		 *
		 * $code = (int) wp_remote_retrieve_response_code( $response );
		 * $body = wp_remote_retrieve_body( $response );
		 *
		 * if ( $code < 200 || $code >= 300 ) {
		 *     return new WP_Error(
		 *         'ccn_api_error',
		 *         sprintf( __( 'SMS provider returned HTTP %d.', 'click-collect' ), $code ),
		 *         $body
		 *     );
		 * }
		 *
		 * return true;
		 * ===================================================================== */

		// Remove this once the TODO above is implemented.
		unset( $sender_id );
		return new WP_Error(
			'ccn_not_implemented',
			__( 'SMS sending is not implemented yet — awaiting the provider API key and documentation.', 'click-collect' )
		);
	}
}
