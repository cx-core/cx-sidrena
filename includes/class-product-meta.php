<?php
/**
 * Read / write sidrena cijena product meta.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product meta helpers.
 */
class CX_Sidrena_Product_Meta {

	/**
	 * Net quantity of the goods and its unit (CX-SID-38), per simple product and per size: the unit price comes from it
	 * (unit_price()). A variable product has none of its own; a size without one has no unit price.
	 */
	const META_NETO    = '_cx_sidrena_neto';
	const META_NETO_JM = '_cx_sidrena_neto_jm';

	/** Units a net quantity may be given in; g and ml count as thousandths of kg and l. */
	const NETO_UNITS = array( 'g', 'kg', 'ml', 'l' );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register product meta. Existing sidrena values are never overwritten on save.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'maybe_autofill' ), 20, 1 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'fill_new_variation' ), 20 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_from_duplicate' ) );
	}

	/**
	 * What a copy made with "Copy to a new draft" (WooCommerce's duplicate) does not take from the original (CX-SID-44).
	 *
	 * The copy is a new product, so it starts without the original's bazna, its date and its source, and without the
	 * original's price history and frozen lowest price. On its own save it gets its bazna and date by the rule
	 * (maybe_autofill(), source "new"), and a copy on sale gets its lowest price in 30 days as any new product does.
	 * Kept: the unit price and its switch, the unit of measure and the name of the form of sale (properties of the goods).
	 *
	 * WooCommerce applies this one list to the product and to every variation it copies, so the keys are always added,
	 * also when the product itself has none of them: a variable product's bazna is on its variations.
	 *
	 * @param array $exclude Keys other code already leaves out; kept.
	 * @return array
	 */
	public function exclude_from_duplicate( $exclude ) {
		$ours = array(
			CX_SIDRENA_META_PRICE,
			CX_SIDRENA_META_DATE,
			CX_Sidrena_History::IZVOR_META,
			CX_SIDRENA_N30_HIST,
			CX_SIDRENA_N30_START,
			CX_SIDRENA_N30_LOWEST,
			CX_SIDRENA_N30_SOURCE,
		);

		return array_values( array_unique( array_merge( (array) $exclude, $ours ) ) );
	}

	/**
	 * Bazna for a new variation saved from the admin variations panel (from the bazna add-on 1.1.1, point 1).
	 *
	 * maybe_autofill() fills it on $variation->save(), but WooCommerce fires woocommerce_save_product_variation after
	 * that save, and the field save at priority 10 (CX_Sidrena_Admin::save_variation_fields) writes the posted, empty
	 * form field back and so deletes it again. Priority 20 runs after that.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public function fill_new_variation( $variation_id ) {
		if ( ! CX_Sidrena_Settings::instance()->is_auto_fill_enabled() ) {
			return;
		}
		if ( self::existed_before_install( $variation_id ) ) {
			return; // an old variation never gets today's bazna on a save (MJDD's trap); Status lists it (CX-SID-23)
		}
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		$date    = CX_Sidrena_Dates::date_for( $variation->get_id() );
		$written = CX_Sidrena_History::with(
			array(
				'type'      => 'new',
				'date_from' => $date['from'],
			),
			function () use ( $variation, $date ) {
				return $this->snapshot_if_empty( $variation, $date['date'] );
			}
		);
		if ( $written && class_exists( 'CX_Sidrena_Export' ) ) {
			CX_Sidrena_Export::instance()->queue_feed_after_change();
		}
	}

	/**
	 * Expose meta to the REST API for products and variations.
	 */
	public function register_meta() {
		$args = array(
			'single'        => true,
			'type'          => 'string',
			'show_in_rest'  => true,
			'auth_callback' => static function () {
				return current_user_can( 'edit_products' );
			},
		);

		foreach ( array( 'product', 'product_variation' ) as $type ) {
			register_post_meta( $type, CX_SIDRENA_META_PRICE, $args );
			register_post_meta( $type, CX_SIDRENA_META_DATE, $args );
			register_post_meta( $type, CX_SIDRENA_META_CJM, $args );
			register_post_meta( $type, CX_SIDRENA_META_CJM_SUFFIX, $args );
			register_post_meta( $type, CX_SIDRENA_META_OBLIK, $args );
			register_post_meta( $type, CX_SIDRENA_META_JEDINICA, $args );
			register_post_meta( $type, CX_SIDRENA_META_JEDINICA_CIJENA, $args );
			// Every write (REST, WooCommerce's meta columns, any update_post_meta) keeps a valid value or ''.
			register_post_meta( $type, self::META_NETO, $args + array( 'sanitize_callback' => array( $this, 'normalize_neto' ) ) );
			register_post_meta( $type, self::META_NETO_JM, $args + array( 'sanitize_callback' => array( $this, 'normalize_neto_unit' ) ) );
		}
	}

	/**
	 * Get stored sidrena price (raw string).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_price( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_PRICE, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Post that holds sidrena for storefront display.
	 *
	 * WPML and Polylang store each translation as its own product. Sidrena is
	 * saved on the default-language product, and those plugins do not copy this
	 * field, so a translated product id has no price of its own.
	 *
	 * @param int $product_id Product or variation ID currently being viewed.
	 * @return int
	 */
	public function display_id( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return 0;
		}

		if ( '' !== $this->get_price( $product_id ) ) {
			return $product_id;
		}

		if ( ! class_exists( 'CX_Sidrena_I18n' ) ) {
			return $product_id;
		}

		$source = CX_Sidrena_I18n::instance()->source_product_id( $product_id );
		if ( $source > 0 && $source !== $product_id && '' !== $this->get_price( $source ) ) {
			return $source;
		}

		return $product_id;
	}

	/**
	 * A stored amount (sidrena, the retail price, the unit price) as the consumer pays it: always with tax.
	 *
	 * ZZP art. 4 (NN 59/2026): the retail price and the unit price are final prices "including taxes", and the bazna is
	 * the retail price that applied on a day (CX-SID-160). So the bazna on the page and every amount of the price list
	 * include tax, whatever the shop shows next to them.
	 * - not taxable (taxes off, or the product's tax status is not "taxable"), or prices entered with tax: as stored;
	 * - prices entered without tax: plus the tax of the shop's own address (its base rates for the product's tax class),
	 *   rounded as WooCommerce rounds a price with tax (wc_get_price_including_tax()).
	 * Not wc_get_price_including_tax() itself: it takes the customer's location, and the price list is built in cron,
	 * where there is none. With tax by the shipping or billing address and no default customer location WooCommerce
	 * then finds no rate and returns the amount without tax. The shop's address is also what the price list is for
	 * (one list, the Croatian rate), for a customer abroad or exempt from VAT too.
	 *
	 * This is the only place where the plugin adds tax to an amount (CX-SID-38, CX-SID-156 call it).
	 *
	 * @param WC_Product $product   Product or variation (tax class / status).
	 * @param string     $raw_price Optional raw amount. Defaults to stored meta.
	 * @return float|null Amount with tax, or null when there is no price.
	 */
	public function get_price_to_display( $product, $raw_price = null ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$raw = null === $raw_price ? $this->get_price( $product->get_id() ) : (string) $raw_price;
		if ( '' === $raw ) {
			return null;
		}

		if ( function_exists( 'wc_format_decimal' ) ) {
			$raw = wc_format_decimal( $raw );
			if ( false === $raw || null === $raw || '' === $raw ) {
				return null;
			}
		}

		$amount = (float) $raw;
		if ( $this->adds_base_tax( $product ) ) {
			$taxes  = WC_Tax::calc_tax( $amount, WC_Tax::get_base_tax_rates( $product->get_tax_class() ), false );
			$tax    = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ? array_sum( $taxes ) : array_sum( array_map( 'wc_round_tax_total', $taxes ) );
			$amount = $this->round_price( $amount + (float) $tax );
		}

		/**
		 * The amount with tax for a stored sidrena price (also the retail and unit price of the price list).
		 *
		 * Shops that compute VAT their own way (not WooCommerce Tax) can adjust the amount here. Since CX-SID-160 it
		 * already includes WooCommerce's tax by the shop's address: do not add it again.
		 *
		 * @param float      $amount  Amount with tax.
		 * @param WC_Product $product Product or variation.
		 * @param string     $raw     Stored amount (same basis as _regular_price).
		 */
		return (float) apply_filters( 'sidrena_cijena_price_to_display', $amount, $product, $raw );
	}

	/**
	 * Turn an amount of the price list (with tax) back into the stored `_regular_price` basis.
	 *
	 * The inverse of get_price_to_display(). When the amount already matches the current stored price, that stored
	 * value is returned unchanged so reimporting the same public file does not drift on tax rounding.
	 *
	 * @param WC_Product $product        Product or variation.
	 * @param string     $display_price  Amount as shown on the price list.
	 * @param string     $current_stored Stored amount kept when the display matches.
	 * @return string Stored decimal, or empty when the display amount is empty.
	 */
	public function display_price_to_stored( $product, $display_price, $current_stored = '' ) {
		if ( ! $product instanceof WC_Product ) {
			return $this->normalize_price( $display_price );
		}

		$display = $this->normalize_price( $display_price );
		if ( '' === $display || ! is_numeric( $display ) ) {
			return '';
		}

		$current_stored = is_scalar( $current_stored ) ? trim( (string) $current_stored ) : '';
		if ( '' !== $current_stored ) {
			$current_display = $this->get_price_to_display( $product, $current_stored );
			if ( null !== $current_display && $this->display_amounts_match( $current_display, $display ) ) {
				return $current_stored;
			}
		}

		if ( ! $this->adds_base_tax( $product ) ) {
			return $display;
		}

		$amount = (float) $display;
		$stored = $amount - (float) array_sum( WC_Tax::calc_tax( $amount, WC_Tax::get_base_tax_rates( $product->get_tax_class() ), true ) );
		if ( $stored < 0 ) {
			$stored = 0;
		}

		$normalized = $this->normalize_price( $stored );
		return '' === $normalized ? $display : $normalized;
	}

	/**
	 * Format a stored sidrena amount the same way WooCommerce formats shop prices.
	 *
	 * @param WC_Product $product   Product or variation.
	 * @param string     $raw_price Optional raw amount. Defaults to stored meta.
	 * @return string Price HTML, or empty string.
	 */
	public function format_price_html( $product, $raw_price = null ) {
		$amount = $this->get_price_to_display( $product, $raw_price );
		if ( null === $amount ) {
			return '';
		}

		return wc_price( $amount );
	}

	/**
	 * Get stored reference date (d.m.Y. or empty).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_date( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_DATE, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Save sidrena price. Empty string deletes the meta.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $price      Raw price.
	 */
	public function set_price( $product_id, $price ) {
		$price = $this->normalize_price( $price );

		if ( '' === $price ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_PRICE );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_PRICE, $price );
	}

	/**
	 * Save optional per-product date.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $date       Date string.
	 */
	public function set_date( $product_id, $date ) {
		$date = sanitize_text_field( $date );

		if ( '' === $date ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_DATE );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_DATE, $date );
	}

	/**
	 * Whether the product has the CJM unit suffix enabled.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	public function is_cjm_enabled( $product_id ) {
		return 'yes' === get_post_meta( $product_id, CX_SIDRENA_META_CJM, true );
	}

	/**
	 * Enable or disable CJM on a product.
	 *
	 * @param int  $product_id Product or variation ID.
	 * @param bool $enabled    Toggle.
	 */
	public function set_cjm_enabled( $product_id, $enabled ) {
		if ( $enabled ) {
			update_post_meta( $product_id, CX_SIDRENA_META_CJM, 'yes' );
			return;
		}

		delete_post_meta( $product_id, CX_SIDRENA_META_CJM );
	}

	/**
	 * CJM prefix suffix (e.g. "za kg/l").
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_cjm_suffix( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_CJM_SUFFIX, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Save CJM suffix.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $suffix     Suffix text.
	 */
	public function set_cjm_suffix( $product_id, $suffix ) {
		$suffix = sanitize_text_field( $suffix );

		if ( '' === $suffix ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_CJM_SUFFIX );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_CJM_SUFFIX, $suffix );
	}

	/**
	 * Optional name that replaces "akcija" while a sale price is active.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_oblik( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_OBLIK, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Save the special-sale name override. Empty deletes it.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $name       Sale-form name.
	 */
	public function set_oblik( $product_id, $name ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_OBLIK );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_OBLIK, $name );
	}

	/**
	 * Name written to the public feed while the charged price is the sale price: MJDD's own name of the item, or "akcija".
	 * Every item on sale is a special form of sale in the price list (poseban_oblik DA, CX_Sidrena_Feed_Rows), as the
	 * customer's page shows every sale as a sale (CX-SID-179, Hrvoje 20.11.2026: the struck price is the owner's, and so
	 * is whether the sale is below the lowest price in 30 days). The retail price is untouched: it is the price the
	 * customer pays.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string akcija, a per-product name, or empty.
	 */
	public function public_oblik_name( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return '';
		}

		if ( '' === (string) $product->get_sale_price() ) {
			return '';
		}

		$override = trim( $this->get_oblik( $product->get_id() ) );
		return '' !== $override ? $override : 'akcija';
	}

	/**
	 * Unit of measure for the public feed (kg, l, kom).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_jedinica( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_JEDINICA, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Save the unit of measure. Empty deletes it.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $unit       Unit.
	 */
	public function set_jedinica( $product_id, $unit ) {
		$unit = sanitize_text_field( $unit );

		if ( '' === $unit ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_JEDINICA );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_JEDINICA, $unit );
	}

	/**
	 * Unit price stored on the same tax basis as the regular price.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_jedinica_cijena( $product_id ) {
		$value = get_post_meta( $product_id, CX_SIDRENA_META_JEDINICA_CIJENA, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Save the unit price. Empty deletes it.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $price      Raw price.
	 */
	public function set_jedinica_cijena( $product_id, $price ) {
		$price = $this->normalize_price( $price );

		if ( '' === $price ) {
			delete_post_meta( $product_id, CX_SIDRENA_META_JEDINICA_CIJENA );
			return;
		}

		update_post_meta( $product_id, CX_SIDRENA_META_JEDINICA_CIJENA, $price );
	}

	/**
	 * Net quantity as stored (CX-SID-38): the number in the unit it was given in, and that unit; '' when none.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array{neto:string, jm:string}
	 */
	public function get_neto( $product_id ) {
		$neto = get_post_meta( $product_id, self::META_NETO, true );
		$jm   = get_post_meta( $product_id, self::META_NETO_JM, true );

		return array(
			'neto' => is_string( $neto ) ? $neto : '',
			'jm'   => is_string( $jm ) ? $jm : '',
		);
	}

	/**
	 * Save the net quantity. An empty quantity clears it (both keys), and the unit of measure and unit price typed by
	 * hand count again. A quantity this cannot read, or one the price list cannot write (the list gives kg and l with at
	 * most 4 decimals: at most one decimal of a gram or a millilitre), is refused and nothing changes.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $quantity   Number, decimal comma or point.
	 * @param string $unit       g, kg, ml or l.
	 * @return bool Whether it was saved or cleared.
	 */
	public function set_neto( $product_id, $quantity, $unit ) {
		$raw = is_scalar( $quantity ) ? trim( str_replace( "\xc2\xa0", ' ', (string) $quantity ) ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $product_id, self::META_NETO );
			delete_post_meta( $product_id, self::META_NETO_JM );
			return true;
		}

		$neto = $this->normalize_neto( $raw );
		$jm   = $this->normalize_neto_unit( $unit );
		if ( '' === $neto || '' === $jm || null === self::tenth_grams( $neto, $jm ) ) {
			return false;
		}

		update_post_meta( $product_id, self::META_NETO, $neto );
		update_post_meta( $product_id, self::META_NETO_JM, $jm );
		return true;
	}

	/**
	 * A net quantity in its canonical form: digits, a point before at most 4 decimals, no leading or trailing zeros
	 * ('0,250' → '0.25'). '' for anything else: empty, zero, negative, exponent, text, more than 9 digits before the point.
	 * Also the sanitize callback of the meta key, so every way of writing it keeps this form or ''.
	 *
	 * @param mixed $quantity Raw value.
	 * @return string
	 */
	public function normalize_neto( $quantity ) {
		$raw = is_scalar( $quantity ) ? trim( (string) $quantity ) : '';
		if ( ! preg_match( '/^(\d{1,9})(?:[.,](\d{1,4}))?$/', $raw, $m ) ) {
			return '';
		}

		$whole    = ltrim( $m[1], '0' );
		$fraction = isset( $m[2] ) ? rtrim( $m[2], '0' ) : '';
		if ( '' === $whole && '' === $fraction ) {
			return '';
		}

		return ( '' === $whole ? '0' : $whole ) . ( '' === $fraction ? '' : '.' . $fraction );
	}

	/**
	 * The unit of a net quantity: g, kg, ml or l (any letter case, spaces around), '' for anything else.
	 *
	 * @param mixed $unit Raw value.
	 * @return string
	 */
	public function normalize_neto_unit( $unit ) {
		$unit = is_scalar( $unit ) ? strtolower( trim( (string) $unit ) ) : '';
		return in_array( $unit, self::NETO_UNITS, true ) ? $unit : '';
	}

	/**
	 * The stored net quantity when the unit price can use it (CX-SID-38): canonical number and unit, or null when there
	 * is none, or one the price list could not write (stored past the setter, e.g. by REST: 12.55 g). Without one the
	 * price list uses the old hand-typed unit and unit price, so the product screen shows them as before.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array{neto:string, jm:string}|null
	 */
	public function readable_neto( $product_id ) {
		$stored = $this->get_neto( $product_id );
		$neto   = $this->normalize_neto( $stored['neto'] );
		$jm     = $this->normalize_neto_unit( $stored['jm'] );
		if ( '' === $neto || '' === $jm || null === self::tenth_grams( $neto, $jm ) ) {
			return null;
		}

		return array(
			'neto' => $neto,
			'jm'   => $jm,
		);
	}

	/**
	 * A canonical quantity in ten-thousandths of a kilogram or a litre (tenths of a gram or millilitre), or null when the
	 * price list could not write it (Rook 19.11.: at most 4 decimals of a kg or l): more than one decimal of a g or ml.
	 *
	 * @param string $neto Canonical quantity (normalize_neto()).
	 * @param string $jm   g, kg, ml or l.
	 * @return int|null
	 */
	private static function tenth_grams( $neto, $jm ) {
		$parts    = explode( '.', $neto );
		$whole    = (int) $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';
		if ( 'g' === $jm || 'ml' === $jm ) {
			$units = strlen( $fraction ) <= 1 ? $whole * 10 + (int) str_pad( $fraction, 1, '0' ) : null;
		} else {
			$units = $whole * 10000 + (int) str_pad( $fraction, 4, '0' );
		}

		return ( null !== $units && $units > 0 ) ? $units : null;
	}

	/**
	 * The unit price of a product or size with a net quantity (CX-SID-38): what one kilogram or one litre costs at the
	 * price the customer pays now, with tax as the shop shows it (ZZP art. 4 item 3). The ONE place it is worked out:
	 * the product page and the price list both call this, so they cannot differ.
	 *
	 * The price is the price list's own (CX_Sidrena_Feed_Rows::active_raw_price(): the sale price during a sale) through
	 * get_price_to_display(), the one place tax is applied, at the decimals the shop shows; the unit price is that
	 * shown price divided by the quantity, half up at the same decimals, in whole numbers (so 0,21 € for 0,4 kg is 0,53).
	 *
	 * `page` is whether the product page shows it: not for goods under 50 g or 50 ml (Pravilnik NN 105/2026 art. 8(2),
	 * first indent) and not when it equals the retail price (ZZP art. 7(3)). The price list carries it either way.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array{neto:string, jm:string, amount:float, page:bool}|null Null without a (readable) quantity or a price.
	 */
	public function unit_price( $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'variable-subscription', 'grouped' ) ) ) {
			return null;
		}

		$stored = $this->readable_neto( $product->get_id() );
		if ( null === $stored ) {
			return null;
		}
		$jm    = $stored['jm'];
		$units = self::tenth_grams( $stored['neto'], $jm );

		$paid = $this->get_price_to_display( $product, CX_Sidrena_Feed_Rows::instance()->active_raw_price( $product ) );
		if ( null === $paid || $paid < 0 ) {
			return null;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
		$shown    = (int) str_replace( '.', '', number_format( $paid, $decimals, '.', '' ) ); // in the shop's smallest unit
		if ( $shown > 1000000000000 ) {
			return null; // beyond any price; keeps the sum below in integers
		}
		$amount = intdiv( 2 * $shown * 10000 + $units, 2 * $units ); // shown / (units / 10000), half up

		return array(
			'neto'   => rtrim( rtrim( intdiv( $units, 10000 ) . '.' . str_pad( (string) ( $units % 10000 ), 4, '0', STR_PAD_LEFT ), '0' ), '.' ),
			'jm'     => ( 'g' === $jm || 'kg' === $jm ) ? 'kg' : 'l',
			'amount' => (float) ( $amount / ( 10 ** $decimals ) ),
			'page'   => $units >= 500 && $amount !== $shown,
		);
	}

	/**
	 * A unit price as the customer reads it (Norman SPEC §3.3): the amount as wc_price() prints it (the shop's currency,
	 * decimals and symbol position), then "/kg" or "/l" with no space before the slash: "19,95 €/l". The product page
	 * and the product screen both show it this way.
	 *
	 * @param array{amount:float, jm:string} $unit unit_price().
	 * @return string HTML.
	 */
	public function unit_price_html( $unit ) {
		return wc_price( $unit['amount'] ) . '/' . esc_html( $unit['jm'] );
	}

	/**
	 * Copy regular price and today's date into empty sidrena fields on new / unsaved products.
	 *
	 * @param WC_Product $product Product or variation just persisted.
	 */
	public function maybe_autofill( $product ) {
		if ( ! CX_Sidrena_Settings::instance()->is_auto_fill_enabled() ) {
			return;
		}

		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( self::existed_before_install( $product->get_id() ) ) {
			return; // an old product never gets today's bazna on a save (MJDD's trap); Status lists it (CX-SID-23)
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'variable-subscription' ) ) {
			return;
		}

		// A new product's first price, dated by the rule (spec §1.3: the later of its category's date and the day it
		// was added). Which products may still be filled this way: CX_Sidrena_Dates::fills_on_save().
		$date = CX_Sidrena_Dates::date_for( $product->get_id() );
		CX_Sidrena_History::with(
			array(
				'type'      => 'new',
				'date_from' => $date['from'],
			),
			function () use ( $product, $date ) {
				return $this->snapshot_if_empty( $product, $date['date'] );
			}
		);
	}

	/**
	 * Whether regular price can be copied into an empty sidrena field.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public function can_snapshot( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			return false;
		}

		if ( '' !== $this->get_price( $product->get_id() ) ) {
			return false;
		}

		$regular = $product->get_regular_price();

		return ! ( '' === $regular || null === $regular );
	}

	/**
	 * Snapshot regular price into sidrena meta if empty.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $date    Optional reference date to store when the date field is also empty.
	 * @return bool True when a value was written.
	 */
	public function snapshot_if_empty( $product, $date = '' ) {
		if ( ! $this->can_snapshot( $product ) ) {
			return false;
		}

		$id = $product->get_id();
		$this->set_price( $id, $product->get_regular_price() );

		$date = is_string( $date ) ? trim( $date ) : '';
		if ( '' !== $date && '' === $this->get_date( $id ) ) {
			$this->set_date( $id, $date );
		}

		return true;
	}

	/**
	 * Whether a product or variation was created before CX Sidrena first ran on this shop (cx_sidrena_migrated.first,
	 * written on activation or on the first load). Only items created after that may be filled on a save; the date
	 * rule for them is CX-SID-21's. Unknown install time: treated as old, so nothing is filled by guess.
	 *
	 * @param int $post_id Product or variation (a variation by its own creation time).
	 * @return bool
	 */
	public static function existed_before_install( $post_id ) {
		$done  = get_option( 'cx_sidrena_migrated', array() );
		$since = is_array( $done ) && ! empty( $done['first'] ) ? (int) $done['first'] : 0;
		if ( $since <= 0 ) {
			return true;
		}
		$created = get_post_timestamp( (int) $post_id ); // post_date in the site's time zone; also right for an auto-draft

		return false !== $created && $created < $since;
	}

	/**
	 * Today's date in the Croatian d.m.Y. form (WordPress timezone).
	 *
	 * @return string
	 */
	public static function today_date() {
		$date = function_exists( 'wp_date' ) ? wp_date( 'd.m.Y.' ) : date_i18n( 'd.m.Y.' );
		$date = trim( (string) $date );

		if ( '' === $date ) {
			return '';
		}

		return rtrim( $date, '.' ) . '.';
	}

	/**
	 * Visible label: prefix + (date). Date is the product override or the shop default.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_display_prefix( $product_id ) {
		$label = $this->get_label_text( $product_id );
		$date  = $this->get_display_date( $product_id );

		return CX_Sidrena_Settings::instance()->format_prefix_with_date( $label, $date );
	}

	/**
	 * Date shown with the bazna: the product's own date, otherwise the date by the rule (CX_Sidrena_Dates).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_display_date( $product_id ) {
		$custom = trim( $this->get_date( $product_id ) );
		if ( '' !== $custom ) {
			return $this->normalize_date( $custom );
		}

		// No own date: the rule (category, day added; CX_Sidrena_Dates), no longer one date for the whole shop.
		return $this->normalize_date( CX_Sidrena_Dates::date_for( (int) $product_id )['date'] );
	}

	/**
	 * Prefix words only (plus CJM suffix), without the date.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	private function get_label_text( $product_id ) {
		$label = CX_Sidrena_Settings::instance()->prefix_words();

		return $this->apply_cjm_suffix( $label, $product_id );
	}

	/**
	 * Ensure a trailing period on a Croatian date.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	private function normalize_date( $date ) {
		return CX_Sidrena_Money::normalize_date_display( $date );
	}

	/**
	 * Insert the CJM unit text after the prefix words.
	 *
	 * "Sidrena cijena" + "za kg/l" → "Sidrena cijena za kg/l"
	 *
	 * @param string $prefix     Base prefix.
	 * @param int    $product_id Product or variation ID.
	 * @return string
	 */
	private function apply_cjm_suffix( $prefix, $product_id ) {
		if ( ! CX_Sidrena_Settings::instance()->is_cjm_enabled() ) {
			return $prefix;
		}

		if ( ! $this->is_cjm_enabled( $product_id ) ) {
			return $prefix;
		}

		return CX_Sidrena_Settings::append_unit_suffix( $prefix, $this->get_cjm_suffix( $product_id ) );
	}

	/**
	 * Normalize a posted price to WooCommerce storage format.
	 *
	 * @param mixed $price Raw value.
	 * @return string
	 */
	public function normalize_price( $price ) {
		return CX_Sidrena_Money::normalize_price_woo( $price );
	}

	/**
	 * Whether a stored amount of this product is without tax, so the amount with tax adds the shop's own tax: taxes on,
	 * the product taxable, prices entered without tax.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function adds_base_tax( $product ) {
		if ( ! class_exists( 'WC_Tax' ) || ! function_exists( 'wc_prices_include_tax' ) || ! function_exists( 'wc_round_tax_total' ) ) {
			return false;
		}

		return $product->is_taxable() && ! wc_prices_include_tax();
	}

	/**
	 * An amount with tax at the shop's price decimals, as WooCommerce rounds one (wc_get_price_including_tax()).
	 *
	 * @param float $amount Amount.
	 * @return float
	 */
	private function round_price( $amount ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\NumberUtil' ) ) {
			return (float) \Automattic\WooCommerce\Utilities\NumberUtil::round( $amount, $decimals );
		}

		return round( $amount, $decimals );
	}

	/**
	 * Compare two display amounts at the shop's price precision.
	 *
	 * @param float|string $left  Display amount.
	 * @param float|string $right Display amount.
	 * @return bool
	 */
	private function display_amounts_match( $left, $right ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( (float) $left, $decimals ) === round( (float) $right, $decimals );
	}
}
