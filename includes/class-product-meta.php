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
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}
		if ( $this->snapshot_if_empty( $variation, self::today_date() ) && class_exists( 'CX_Sidrena_Export' ) ) {
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
	 * Stored sidrena amount adjusted to the shop tax display setting.
	 *
	 * Sidrena is stored in the same tax basis as `_regular_price`. Regular
	 * price HTML goes through `wc_get_price_to_display()`; this must too,
	 * otherwise shops that enter prices without tax and display them with
	 * tax show sidrena as the raw net amount.
	 *
	 * @param WC_Product $product   Product or variation (tax class / status).
	 * @param string     $raw_price Optional raw amount. Defaults to stored meta.
	 * @return float|null Display amount, or null when there is no price.
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

		if ( function_exists( 'wc_get_price_to_display' ) ) {
			$amount = (float) wc_get_price_to_display(
				$product,
				array(
					'price' => (float) $raw,
				)
			);
		} else {
			$amount = (float) $raw;
		}

		/**
		 * Display amount for a stored sidrena price (after WooCommerce tax display).
		 *
		 * Sidrena is stored in the same tax basis as `_regular_price`. Shops that
		 * show MPC with a custom VAT calculation (not WooCommerce Tax) can adjust
		 * the amount here so it matches the regular price on the storefront.
		 *
		 * @param float      $amount  Amount after wc_get_price_to_display().
		 * @param WC_Product $product Product or variation.
		 * @param string     $raw     Stored sidrena amount (same basis as _regular_price).
		 */
		return (float) apply_filters( 'sidrena_cijena_price_to_display', $amount, $product, $raw );
	}

	/**
	 * Turn a shop-display amount back into the stored `_regular_price` basis.
	 *
	 * When the display amount already matches the current stored price, that
	 * stored value is returned unchanged so reimporting the same public file
	 * does not drift on tax rounding.
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

		if ( ! $this->tax_changes_display( $product ) ) {
			return $display;
		}

		$show_incl  = 'incl' === get_option( 'woocommerce_tax_display_shop' );
		$store_incl = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax();
		if ( $show_incl === $store_incl || ! class_exists( 'WC_Tax' ) ) {
			return $display;
		}

		$rates  = WC_Tax::get_rates( $product->get_tax_class() );
		$amount = (float) $display;
		if ( $show_incl && ! $store_incl ) {
			$taxes  = WC_Tax::calc_tax( $amount, $rates, true );
			$stored = $amount - (float) array_sum( $taxes );
		} else {
			$taxes  = WC_Tax::calc_tax( $amount, $rates, false );
			$stored = $amount + (float) array_sum( $taxes );
		}

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
	 * Name written to the public feed while the charged price is the sale price.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string akcija, a per-product override, or empty.
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

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'variable-subscription' ) ) {
			return;
		}

		$this->snapshot_if_empty( $product, self::today_date() );
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
	 * Date shown in parentheses: product field, otherwise shop default.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string
	 */
	public function get_display_date( $product_id ) {
		$custom = trim( $this->get_date( $product_id ) );
		if ( '' !== $custom ) {
			return $this->normalize_date( $custom );
		}

		return $this->normalize_date( (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) );
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
	 * Whether shop tax display can change a stored amount.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function tax_changes_display( $product ) {
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return false;
		}

		return $product->is_taxable();
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
