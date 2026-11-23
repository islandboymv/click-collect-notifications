<?php
/**
 * SMS text helpers: keep messages in the GSM 7-bit alphabet, and count segments.
 *
 * One character outside GSM-7 switches the WHOLE message to UCS-2, which cuts the
 * per-SMS limit from 160 to 70 characters (153 to 67 per part once it is split).
 * The usual culprits are not real content but typography: an em dash in a
 * signature, curly quotes pasted from Word or typed on a phone keyboard, "…".
 * A 151-character payment message that was 1 SMS became 3 because of a single
 * "—" (MsgOwl bills per part). normalize() swaps those lookalikes for their
 * plain equivalents before sending. Genuine non-GSM text (a Dhivehi name in
 * Thaana, for example) is left as it is; such a message is simply Unicode.
 *
 * @package ClickCollect
 */

defined( 'ABSPATH' ) || exit;

class CCN_Sms_Text {

	/** GSM 03.38 basic character set. */
	const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1bÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

	/** GSM 03.38 extension table: allowed, but each costs two characters. */
	const GSM_EXT = "^{}\\[~]|€";

	/**
	 * Replace typographic lookalikes with GSM-7 equivalents.
	 *
	 * @param string $text Message body.
	 * @return string
	 */
	public static function normalize( $text ) {
		$map = array(
			"\u{2014}" => '-',   // em dash
			"\u{2013}" => '-',   // en dash
			"\u{2012}" => '-',   // figure dash
			"\u{2010}" => '-',   // hyphen
			"\u{2011}" => '-',   // non-breaking hyphen
			"\u{2212}" => '-',   // minus sign
			"\u{2018}" => "'",   // left single quote
			"\u{2019}" => "'",   // right single quote / apostrophe
			"\u{201A}" => "'",
			"\u{2032}" => "'",
			"\u{201C}" => '"',   // left double quote
			"\u{201D}" => '"',   // right double quote
			"\u{201E}" => '"',
			"\u{2033}" => '"',
			"\u{2026}" => '...', // ellipsis
			"\u{00A0}" => ' ',   // no-break space
			"\u{2007}" => ' ',
			"\u{202F}" => ' ',
			"\u{2009}" => ' ',
			"\u{200B}" => '',    // zero-width space
			"\u{FEFF}" => '',
			"\u{2022}" => '-',   // bullet
			"\u{00B7}" => '-',   // middle dot
		);
		$text = strtr( (string) $text, $map );

		// Windows line breaks from the settings textarea cost two characters each.
		$text = str_replace( "\r\n", "\n", $text );
		// Spaces left at the end of a line are invisible but still billed.
		$text = preg_replace( '/[ \t]+\n/u', "\n", $text );

		return trim( $text );
	}

	/**
	 * Whether every character is in the GSM-7 alphabet (basic or extension).
	 *
	 * @param string $text Message body.
	 * @return bool
	 */
	public static function is_gsm7( $text ) {
		$allowed = self::GSM_BASIC . self::GSM_EXT;
		foreach ( self::chars( $text ) as $ch ) {
			if ( false === mb_strpos( $allowed, $ch, 0, 'UTF-8' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * How the message will be billed.
	 *
	 * @param string $text Message body (as sent).
	 * @return array { encoding: 'GSM-7'|'Unicode', length: int, segments: int }
	 */
	public static function segments( $text ) {
		if ( '' === (string) $text ) {
			return array( 'encoding' => 'GSM-7', 'length' => 0, 'segments' => 0 );
		}
		if ( self::is_gsm7( $text ) ) {
			$len = 0;
			foreach ( self::chars( $text ) as $ch ) {
				$len += ( false !== mb_strpos( self::GSM_EXT, $ch, 0, 'UTF-8' ) ) ? 2 : 1;
			}
			return array( 'encoding' => 'GSM-7', 'length' => $len, 'segments' => $len <= 160 ? 1 : (int) ceil( $len / 153 ) );
		}
		// UCS-2 counts UTF-16 code units (emoji take two).
		$len = (int) ( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
		return array( 'encoding' => 'Unicode', 'length' => $len, 'segments' => $len <= 70 ? 1 : (int) ceil( $len / 67 ) );
	}

	/** @return string[] */
	private static function chars( $text ) {
		return preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
	}
}
