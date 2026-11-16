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
}
