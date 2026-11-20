<?php
/**
 * Shared price, date, type, and availability normalizers.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * One place for Croatian / WooCommerce amount and flag parsing.
 */
class CX_Sidrena_Money {

	/**
	 * Catalog / standalone price: keep Croatian thousand/decimal forms.
	 *
	 * @param mixed $price Raw value.
	 * @return string
	 */
	public static function normalize_price_catalog( $price ) {
		if ( null === $price ) {
			return '';
		}

		$price = sanitize_text_field( wp_unslash( (string) $price ) );
		$price = str_replace( array( ' ', "\xc2\xa0" ), '', $price );
		$price = preg_replace( '/[^\d,.\-]/u', '', $price );

		if ( ! is_string( $price ) || '' === $price || '-' === $price || '.' === $price || ',' === $price ) {
			return '';
		}

		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+,\d+$/', $price ) ) {
			$price = str_replace( '.', '', $price );
			$price = str_replace( ',', '.', $price );
		} elseif ( false !== strpos( $price, ',' ) && false === strpos( $price, '.' ) ) {
			$price = str_replace( ',', '.', $price );
		}

		if ( ! is_numeric( $price ) ) {
			return '';
		}

		return (string) $price;
	}

	/**
	 * An amount from an imported file, read only where it cannot be misread (CX-SID-116, Kai: a format must never change
	 * a real price). Spaces, NBSP, apostrophes, € and letters go first; a cell with no digit is empty.
	 * Read: 1234; one separator with 1-2 digits after it (23,5 23.50); one separator that cannot be a thousands one (0,125
	 * 1234,567 12,3456: a 0 before it, more than 3 digits before or after); both separators, the last one decimal, the
	 * other between groups of 3 (1.234,50 1,234.50).
	 * Not read, the row is left as it was: "unclear" for one separator with 1-3 digits before it (not starting with 0) and
	 * exactly 3 after (1.234 1,234 12.500: a thousand, or one and a bit?); "unreadable" for any other shape of digits
	 * (1.234.567, 1.234.5, a date, 1.5E+3).
	 *
	 * @param mixed $raw Cell.
	 * @return array{value:string,error:string} value: dot decimal ('' when empty or not read); error: '', unclear, unreadable.
	 */
	public static function read_amount( $raw ) {
		$raw = (string) $raw;
		if ( preg_match( '/\d\s*[eE]\s*[+\-]?\s*\d/u', $raw ) ) {
			return array( 'value' => '', 'error' => 'unreadable' );
		}

		$s = preg_replace( '/[^\d,.\-]/u', '', $raw );
		$s = is_string( $s ) ? $s : '';
		if ( ! preg_match( '/\d/', $s ) ) {
			return array( 'value' => '', 'error' => '' );
		}

		$sign = '';
		if ( '-' === substr( $s, 0, 1 ) ) {
			$sign = '-';
			$s    = substr( $s, 1 );
		}

		if ( preg_match( '/^\d+$/', $s ) ) {
			return array( 'value' => $sign . self::int_part( $s ), 'error' => '' );
		}

		if ( preg_match( '/^(\d*)[.,](\d+)$/', $s, $m ) ) {
			$thousands = 3 === strlen( $m[2] ) && '' !== $m[1] && strlen( $m[1] ) <= 3 && '0' !== $m[1][0];
			if ( $thousands ) {
				return array( 'value' => '', 'error' => 'unclear' );
			}

			return array( 'value' => $sign . self::int_part( $m[1] ) . '.' . $m[2], 'error' => '' );
		}

		if ( preg_match( '/^(\d{1,3}(?:\.\d{3})+),(\d+)$/', $s, $m ) || preg_match( '/^(\d{1,3}(?:,\d{3})+)\.(\d+)$/', $s, $m ) ) {
			return array( 'value' => $sign . self::int_part( str_replace( array( '.', ',' ), '', $m[1] ) ) . '.' . $m[2], 'error' => '' );
		}

		return array( 'value' => '', 'error' => 'unreadable' );
	}

	/**
	 * Whole part without leading zeros (007 → 7, '' → 0).
	 *
	 * @param string $digits Digits.
	 * @return string
	 */
	private static function int_part( $digits ) {
		$t = ltrim( (string) $digits, '0' );

		return '' === $t ? '0' : $t;
	}

	/**
	 * The same amount, whatever the trailing zeros (23.5 = 23.50 = 23.500, 19 = 19.00), compared as text: no float,
	 * nothing rounded. Dot-decimal strings as the catalog stores them; anything else is never the same.
	 *
	 * @param string $a Amount.
	 * @param string $b Amount.
	 * @return bool
	 */
	public static function same_amount( $a, $b ) {
		$ca = self::canonical( $a );

		return '' !== $ca && self::canonical( $b ) === $ca;
	}

	/**
	 * An amount with exactly two decimals, by adding zeros only (23.5 → 23.50, 19 → 19.00, 23.500 → 23.50). An amount
	 * with more than two decimals that are not zeros (0.125) stays as it is: a price is never rounded (Kai, ZZP art. 7(7)).
	 * Anything that is not a dot-decimal amount comes back unchanged.
	 *
	 * @param string $amount Stored amount.
	 * @return string
	 */
	public static function two_decimals( $amount ) {
		$c = self::canonical( $amount );
		if ( '' === $c ) {
			return (string) $amount;
		}

		$parts = explode( '.', $c );
		$frac  = isset( $parts[1] ) ? $parts[1] : '';
		if ( strlen( $frac ) > 2 ) {
			return $c;
		}

		return $parts[0] . '.' . str_pad( $frac, 2, '0' );
	}

	/**
	 * -?int[.frac] without leading zeros in int and trailing zeros in frac, '' for anything else.
	 *
	 * @param string $amount Amount.
	 * @return string
	 */
	private static function canonical( $amount ) {
		if ( ! preg_match( '/^(-?)(\d*)(?:\.(\d*))?$/', trim( (string) $amount ), $m ) || ( '' === $m[2] && ( ! isset( $m[3] ) || '' === $m[3] ) ) ) {
			return '';
		}

		$frac = isset( $m[3] ) ? rtrim( $m[3], '0' ) : '';
		$int  = self::int_part( $m[2] );
		$sign = ( '0' === $int && '' === $frac ) ? '' : $m[1];

		return $sign . $int . ( '' !== $frac ? '.' . $frac : '' );
	}

	/**
	 * WooCommerce product-meta price: wc_clean, comma decimal, then wc_format_decimal.
	 *
	 * @param mixed $price Raw value.
	 * @return string
	 */
	public static function normalize_price_woo( $price ) {
		if ( null === $price ) {
			return '';
		}

		$price = wc_clean( wp_unslash( (string) $price ) );
		$price = str_replace( array( ' ', "\xc2\xa0" ), '', $price );

		if ( '' === $price ) {
			return '';
		}

		if ( false !== strpos( $price, ',' ) && false === strpos( $price, '.' ) ) {
			$price = str_replace( ',', '.', $price );
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			$formatted = wc_format_decimal( $price );
			return ( false === $formatted || null === $formatted ) ? '' : (string) $formatted;
		}

		return $price;
	}

	/**
	 * Trailing period on a Croatian date, or empty.
	 *
	 * @param mixed $date Raw.
	 * @return string
	 */
	public static function normalize_date( $date ) {
		$date = sanitize_text_field( wp_unslash( (string) $date ) );
		$date = trim( $date );
		if ( '' === $date ) {
			return '';
		}

		return rtrim( $date, '.' ) . '.';
	}

	/**
	 * Trailing period without extra sanitizing (product-meta display dates).
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	public static function normalize_date_display( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		return rtrim( $date, '.' ) . '.';
	}

	/**
	 * A date as the customer reads it next to a price: 10.9.2026. (no leading zeros, as in the draft regulation and the
	 * screens' spec). Stored dates and the price list keep 10.09.2026.; anything that is not d.m.Y comes back unchanged.
	 *
	 * @param string $date Date, usually 10.09.2026.
	 * @return string
	 */
	public static function customer_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})\.?$/', $date, $m ) ) {
			return (int) $m[1] . '.' . (int) $m[2] . '.' . $m[3] . '.';
		}

		return $date;
	}

	/**
	 * proizvod or usluga. Anything else becomes proizvod.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function normalize_vrsta( $value ) {
		$value = strtolower( sanitize_text_field( wp_unslash( (string) $value ) ) );
		$value = str_replace( array( ' ', '_', '-' ), '', $value );

		if ( in_array( $value, array( 'usluga', 'service', 'services', 'usluge' ), true ) ) {
			return 'usluga';
		}

		return 'proizvod';
	}

	/**
	 * Legal availability flag.
	 *
	 * @param mixed $value Raw value.
	 * @return string dostupno|nedostupno
	 */
	public static function normalize_dostupnost( $value ) {
		$value = strtolower( sanitize_text_field( wp_unslash( (string) $value ) ) );
		$value = str_replace( array( ' ', '_', '-' ), '', $value );

		if ( in_array( $value, array( 'nedostupno', 'nedostupan', 'outofstock', 'out', 'no', 'ne', '0', 'false' ), true ) ) {
			return 'nedostupno';
		}

		return 'dostupno';
	}

	/**
	 * Availability stored for one outlet. Missing entries stay nedostupno so webshop stock is not copied.
	 *
	 * @param mixed                $product_id Product or variation id.
	 * @param array<string,string> $map        Product id => dostupno|nedostupno.
	 * @return string
	 */
	public static function dostupnost_from_outlet_map( $product_id, $map ) {
		$id = preg_replace( '/\D/', '', (string) $product_id );
		if ( ! is_string( $id ) || '' === $id || ! is_array( $map ) || ! isset( $map[ $id ] ) ) {
			return 'nedostupno';
		}

		return self::normalize_dostupnost( $map[ $id ] );
	}

	/**
	 * A text field of a manual row or a row by agreement, kept as the owner typed it (CX-SID-142). It cleans as
	 * sanitize_text_field() does (invalid UTF-8, tags, line breaks, percent octets), but a lone '<' stays a '<':
	 * sanitize_text_field() turns it and the rest up to the next '<' into entities ('a < b & "c"' became
	 * 'a &lt; b &amp; &quot;c&quot;'), which broke the page's JSON, and the CSV wrote them as they were (Kai 3255). The
	 * stored value is text, so every output escapes it (CX_Sidrena_Feed_Files::text_html(), xml_text()).
	 *
	 * @param mixed $value Raw value (already unslashed).
	 * @return string
	 */
	public static function plain_text( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$text = wp_check_invalid_utf8( (string) $value );
		$mark = '';
		if ( false !== strpos( $text, '<' ) ) {
			// The same split as wp_pre_kses_less_than(): a '<' with no '>' before the next '<' or the end is not a tag.
			// It waits behind a mark the text does not contain, so strip_tags() cannot take it for one.
			$mark = "\u{E000}";
			while ( false !== strpos( $text, $mark ) ) {
				$mark .= "\u{E001}";
			}
			$text = (string) preg_replace_callback(
				'%<[^>]*?((?=<)|>|$)%',
				static function ( $m ) use ( $mark ) {
					return false === strpos( $m[0], '>' ) ? $mark . substr( $m[0], 1 ) : $m[0];
				},
				$text
			);
			$text = wp_strip_all_tags( $text, false );
		}

		$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		$text = trim( $text );

		$found = false;
		while ( preg_match( '/%[a-f0-9]{2}/i', $text, $match ) ) {
			$text  = str_replace( $match[0], '', $text );
			$found = true;
		}
		if ( $found ) {
			$text = trim( (string) preg_replace( '/ +/', ' ', $text ) );
		}

		return '' !== $mark ? str_replace( $mark, '<', $text ) : $text;
	}

	/**
	 * A text stored by sanitize_text_field() before 0.3.0, as the owner typed it (CX-SID-142). Its entities come only
	 * from a lone '<': from each '&lt;' to the next one or the end. Text before the first '&lt;' was stored as typed,
	 * so its entities are the owner's and stay.
	 *
	 * @param string $text Stored text.
	 * @return string
	 */
	public static function legacy_text( $text ) {
		$text = (string) $text;
		$at   = strpos( $text, '&lt;' );
		if ( false === $at ) {
			return $text;
		}

		return substr( $text, 0, $at ) . wp_specialchars_decode( substr( $text, $at ), ENT_QUOTES );
	}
}
