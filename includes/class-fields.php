<?php
/**
 * Sidrena product field list used by the product screen, catalog, and import.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * The seven WooCommerce product fields, with POST names and save rules.
 */
class CX_Sidrena_Fields {

	/**
	 * Product editor fields. Order matches the current save methods.
	 *
	 * kind: price (wp_unslash + normalize_price), text (sanitize_text_field),
	 * checkbox (isset, only when shop-wide CJM is on).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function product_fields() {
		return array(
			array(
				'post'   => 'sidrena_cijena',
				'setter' => 'set_price',
				'kind'   => 'price',
			),
			array(
				'post'   => 'sidrena_cijena_datum',
				'setter' => 'set_date',
				'kind'   => 'text',
			),
			array(
				'post'   => 'sidrena_naziv_posebnog_oblika',
				'setter' => 'set_oblik',
				'kind'   => 'text',
			),
			array(
				'post'   => 'sidrena_jedinica_mjere',
				'setter' => 'set_jedinica',
				'kind'   => 'text',
			),
			array(
				'post'   => 'sidrena_cijena_jedinice_mjere',
				'setter' => 'set_jedinica_cijena',
				'kind'   => 'price',
			),
			array(
				'post'   => 'sidrena_cijena_cjm',
				'setter' => 'set_cjm_enabled',
				'kind'   => 'checkbox',
			),
			array(
				'post'   => 'sidrena_cijena_cjm_suffix',
				'setter' => 'set_cjm_suffix',
				'kind'   => 'text',
			),
		);
	}

	/**
	 * Persist simple / external product fields from $_POST.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_simple( $product ) {
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return;
		}

		self::save_change( $product->get_id(), null );
		self::save_posted( $product->get_id(), null );
	}

	/**
	 * Persist variation fields from $_POST arrays.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Loop index.
	 */
	public static function save_variation( $variation_id, $i ) {
		self::save_change( $variation_id, (int) $i );
		self::save_posted( $variation_id, $i );
	}

	/**
	 * The product screen's Change (CX-SID-24): only when the owner opened it (its inputs are disabled until then, so
	 * nothing is posted otherwise). Written through CX_Sidrena_History::set_bazna(), which records the history and the
	 * source "Changed by …" with the reason (CX-SID-20). An empty date clears the product's own date, so the date is
	 * the rule's again (A9), except when this same save changed the categories and CX-SID-21 kept the date the product
	 * showed as its own (see below). A refused value never stops the product save: WooCommerce shows what was not saved.
	 *
	 * @param int      $product_id Product or variation.
	 * @param int|null $index      Variation loop index; null for a simple product.
	 */
	private static function save_change( $product_id, $index ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product/variations nonce.
		$posted = null === $index
			? ( isset( $_POST['cx_bazna'] ) ? wp_unslash( $_POST['cx_bazna'] ) : null ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: ( isset( $_POST['cx_bazna_var'][ $index ] ) ? wp_unslash( $_POST['cx_bazna_var'][ $index ] ) : null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $posted ) || empty( $posted['open'] ) || ! class_exists( 'CX_Sidrena_History' ) ) {
			return;
		}

		$amount = isset( $posted['amount'] ) ? (string) $posted['amount'] : '';
		$date   = isset( $posted['date'] ) ? trim( sanitize_text_field( (string) $posted['date'] ) ) : '';
		$reason = isset( $posted['reason'] ) ? trim( sanitize_text_field( (string) $posted['reason'] ) ) : '';
		// The date field was empty when the form was shown (no own date), and still is; meanwhile this same save changed
		// the categories, and CX-SID-21 stored the date the product showed as its own so it does not move (Argus B3,
		// round 2: WordPress sets the categories before WooCommerce saves the fields). An empty field here is not
		// someone clearing that date: the date stays (null). A date typed in the field still wins.
		if ( '' === $date && class_exists( 'CX_Sidrena_Dates' ) && '' !== CX_Sidrena_Dates::kept( $product_id ) ) {
			$date = null;
		}
		$result = CX_Sidrena_History::set_bazna( $product_id, $amount, $date, '' !== $reason ? array( 'reason' => $reason ) : array() );

		if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			$product = wc_get_product( $product_id );
			$what    = $product ? wp_strip_all_tags( $product->get_name() ) : '#' . $product_id;
			/* translators: 1: product or variation name, 2: why */
			WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Bazna cijena of %1$s was not changed: %2$s', 'cx-sidrena' ), $what, $result->get_error_message() ) );
		}
	}

	/**
	 * Read one field from $_POST. $index null = simple product.
	 *
	 * @param int      $product_id Product or variation ID.
	 * @param int|null $index      Variation loop index, or null.
	 */
	private static function save_posted( $product_id, $index ) {
		$meta   = CX_Sidrena_Product_Meta::instance();
		$cjm_on = ( 'yes' === CX_Sidrena_Settings::instance()->get( 'enable_cjm' ) );

		foreach ( self::product_fields() as $field ) {
			// Bazna and its date are changed only through Change (save_change), never from a posted free field.
			if ( in_array( $field['setter'], array( 'set_price', 'set_date' ), true ) ) {
				continue;
			}

			$needs_cjm = in_array( $field['setter'], array( 'set_cjm_enabled', 'set_cjm_suffix' ), true );
			if ( $needs_cjm && ! $cjm_on ) {
				continue;
			}

			if ( 'checkbox' === $field['kind'] ) {
				$meta->{$field['setter']}( $product_id, self::posted_checkbox( $field['post'], $index ) );
				continue;
			}

			$raw = self::posted_value( $field['post'], $index );

			if ( false === $raw ) {
				continue;
			}

			if ( 'text' === $field['kind'] ) {
				$meta->{$field['setter']}( $product_id, sanitize_text_field( $raw ) );
				continue;
			}

			$meta->{$field['setter']}( $product_id, $raw );
		}
	}

	/**
	 * Posted value, or false when the key is absent.
	 *
	 * @param string   $key   $_POST key.
	 * @param int|null $index Variation index.
	 * @return mixed|false
	 */
	private static function posted_value( $key, $index ) {
		if ( null === $index ) {
			if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return false;
			}

			return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) || ! array_key_exists( $index, $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		return wp_unslash( $_POST[ $key ][ $index ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Checkbox posted: simple uses isset, variation uses isset on the loop index.
	 *
	 * @param string   $key   $_POST key.
	 * @param int|null $index Variation index.
	 * @return bool
	 */
	private static function posted_checkbox( $key, $index ) {
		if ( null === $index ) {
			return isset( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) && isset( $_POST[ $key ][ $index ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
}
