<?php
/**
 * Plugin settings stored in a single option.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper.
 */
class CX_Sidrena_Settings {

	const OPTION_KEY       = 'cx_sidrena_settings';
	// MJDD's label: still recognised (translations, the old "Cijena" notice), no longer the default.
	const DEFAULT_PREFIX   = 'Bazna cijena';
	// The label a new shop starts with, plain date style: "Bazna cijena na 10.9.2026.: 19,90 €" (Kai: draft regulation
	// art. 5(2)-(3), the bazna must not be mistaken for the selling price).
	const LABEL_PREFIX     = 'Bazna cijena na';
	const GENERIC_PREFIX   = 'Cijena';
	const PREVIOUS_TOOLTIP = 'Sidrena (bazna) cijena je redovna maloprodajna cijena koja je za ovaj proizvod vrijedila na navedeni referentni datum. Služi potrošaču za usporedbu s aktualnom cijenom, sukladno odlukama Vlade RH i Zakonu o zaštiti potrošača. Nije akcijska cijena niti najniža cijena u zadnjih 30 dana.';
	const DEFAULT_TOOLTIP  = 'Bazna cijena je maloprodajna cijena koja je za ovaj proizvod vrijedila na navedeni dan. Ističe se uz aktualnu cijenu radi usporedbe. Nije akcijska cijena niti najniža cijena u zadnjih 30 dana.';
	const PREFIX_CHOICE_OPTION   = 'cx_sidrena_prefix_choice';
	const FOLLOWUP_NOTICE_OPTION = 'cx_sidrena_followup_13_11';

	/**
	 * Kept price list files: 4 years, the limitation period of a misdemeanour (Prekršajni zakon čl. 13 st. 1).
	 */
	const KEEP_DAYS_DEFAULT = 1461;

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

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
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'prefix'          => self::LABEL_PREFIX,
			'tooltip'         => self::DEFAULT_TOOLTIP,
			// Off: the customer sees only what is required (Hrvoje, spec §2.1). MJDD's tooltip is unchanged when on.
			'enable_tooltip'  => 'no',
			'auto_display'    => 'yes',
			'auto_fill'       => 'yes',
			'default_date'    => '10.09.2026.',
			'date_style'      => 'plain',
			'enable_cjm'      => 'no',
			'daily_xml'       => 'yes',
			'export_format'   => 'xml',
			'file_oblik'      => 'webshop',
			'file_adresa'     => '',
			'file_oznaka'     => '001',
			'file_pohrana'    => 1,
			// OIB or registration number (MBO of a craft, MIBPG of a family farm), first in the price list file's name
			// (Pravilnik o cjeniku, NN 132/2026, art. 6(3)). Digits as a string: leading zeros are part of it (CX-SID-110).
			'file_oib'        => '',
			'brand_taxonomy'  => '',
			'brand_manual'    => '',
			'multi_poslovnica' => 'no',
			'public_cjenik'    => 'yes',
			// A small "Cjenik" link at the bottom of every shop page (spec §2.6, on by default).
			'cjenik_link'      => 'yes',
			// Sale badge: percent | akcija | hide; empty = by theme (CX_Sidrena_Badge::default_mode(), spec §2.5).
			'discount_badge'   => '',
			// Days a price list file older than the public 30 is kept out of public reach; 0 = for ever (CX-SID-27).
			'archive_keep_days' => self::KEEP_DAYS_DEFAULT,
			'i18n'            => array(
				'prefix'  => array(),
				'tooltip' => array(),
			),
			// Colour of the bazna line and the lowest-price caption: '' = the shop's text colour, or the owner's #rrggbb
			// (CX-SID-41).
			'line_color'      => '',
			// The dostupno/nedostupno column in the public price list: off unless the owner turns it on. Only the repealed
			// Decision asked for it (CX-SID-28); a shop updated from 0.1.0 has no key and gets 'no' too.
			'feed_dostupnost' => 'no',
		);
	}

	/**
	 * Write defaults on first activation.
	 */
	public static function add_defaults() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		$defaults = self::defaults();

		if ( array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}

		return $default;
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$dirty  = false;

		if ( isset( $stored['prefix'] ) ) {
			$stripped = self::strip_date_from_prefix( (string) $stored['prefix'] );
			if ( $stripped !== $stored['prefix'] ) {
				$stored['prefix'] = '' !== $stripped ? $stripped : self::defaults()['prefix'];
				$dirty            = true;
			}
		}

		if ( isset( $stored['tooltip'] ) && self::PREVIOUS_TOOLTIP === $stored['tooltip'] ) {
			$stored['tooltip'] = self::DEFAULT_TOOLTIP;
			$dirty             = true;
		}

		if ( isset( $stored['i18n']['tooltip'] ) && is_array( $stored['i18n']['tooltip'] ) ) {
			foreach ( $stored['i18n']['tooltip'] as $lang => $value ) {
				if ( is_string( $value ) && self::PREVIOUS_TOOLTIP === $value ) {
					$stored['i18n']['tooltip'][ $lang ] = self::DEFAULT_TOOLTIP;
					$dirty                              = true;
				}
			}
		}

		if ( isset( $stored['date_style'] ) && 'na' === $stored['date_style'] ) {
			$stored['date_style'] = 'plain';
			$prefix               = isset( $stored['prefix'] ) ? trim( (string) $stored['prefix'] ) : '';
			if ( '' !== $prefix && ! preg_match( '/\bna$/iu', $prefix ) ) {
				$stored['prefix'] = $prefix . ' na';
			}
			$dirty = true;
		}

		if ( $dirty ) {
			update_option( self::OPTION_KEY, $stored );
		}

		$this->settings = wp_parse_args( $stored, self::defaults() );

		return $this->settings;
	}

	/**
	 * Forget the cached settings, after the option was written by someone else (the take-over, CX_Sidrena_Migration).
	 */
	public function flush() {
		$this->settings = null;
	}

	/**
	 * Persist settings.
	 *
	 * @param array<string, mixed> $settings Raw values.
	 */
	public function save( $settings ) {
		$prev_oib = (string) $this->get( 'file_oib', '' );
		$settings = array_merge( $this->all(), is_array( $settings ) ? $settings : array() );
		$oib      = self::check_legal_id( isset( $settings['file_oib'] ) ? $settings['file_oib'] : '' );

		$clean = array(
			'prefix'         => isset( $settings['prefix'] ) ? self::strip_date_from_prefix( sanitize_text_field( $settings['prefix'] ) ) : self::defaults()['prefix'],
			'tooltip'        => isset( $settings['tooltip'] ) ? sanitize_textarea_field( $settings['tooltip'] ) : self::defaults()['tooltip'],
			'enable_tooltip' => ( isset( $settings['enable_tooltip'] ) && 'yes' === $settings['enable_tooltip'] ) ? 'yes' : 'no',
			'auto_display'   => ( isset( $settings['auto_display'] ) && 'yes' === $settings['auto_display'] ) ? 'yes' : 'no',
			'auto_fill'      => ( isset( $settings['auto_fill'] ) && 'yes' === $settings['auto_fill'] ) ? 'yes' : 'no',
			'default_date'   => isset( $settings['default_date'] ) ? sanitize_text_field( $settings['default_date'] ) : self::defaults()['default_date'],
			'date_style'     => $this->sanitize_date_style( isset( $settings['date_style'] ) ? $settings['date_style'] : self::defaults()['date_style'] ),
			'enable_cjm'     => ( isset( $settings['enable_cjm'] ) && 'yes' === $settings['enable_cjm'] ) ? 'yes' : 'no',
			'daily_xml'      => ( isset( $settings['daily_xml'] ) && 'yes' === $settings['daily_xml'] ) ? 'yes' : 'no',
			'export_format'  => $this->sanitize_format( isset( $settings['export_format'] ) ? $settings['export_format'] : 'xml' ),
			'file_oblik'     => $this->sanitize_file_part( isset( $settings['file_oblik'] ) ? $settings['file_oblik'] : 'webshop', 'webshop' ),
			'file_adresa'    => sanitize_text_field( isset( $settings['file_adresa'] ) ? $settings['file_adresa'] : '' ),
			'file_oznaka'      => $this->normalize_oznaka( isset( $settings['file_oznaka'] ) ? $settings['file_oznaka'] : '001' ) ?: '001',
			'file_pohrana'     => $this->sanitize_pohrana( isset( $settings['file_pohrana'] ) ? $settings['file_pohrana'] : 1 ),
			// A value that may not be stored (not only digits, an OIB whose check digit is wrong) keeps the stored one.
			'file_oib'         => self::LEGAL_ID_REFUSED === $oib['status'] ? $prev_oib : $oib['value'],
			'brand_taxonomy'   => $this->sanitize_brand_taxonomy( isset( $settings['brand_taxonomy'] ) ? $settings['brand_taxonomy'] : '' ),
			'brand_manual'     => sanitize_text_field( isset( $settings['brand_manual'] ) ? $settings['brand_manual'] : '' ),
			'multi_poslovnica' => ( isset( $settings['multi_poslovnica'] ) && 'yes' === $settings['multi_poslovnica'] ) ? 'yes' : 'no',
			'public_cjenik'    => 'yes',
			'cjenik_link'      => ( isset( $settings['cjenik_link'] ) && 'no' === $settings['cjenik_link'] ) ? 'no' : 'yes',
			'discount_badge'   => ( isset( $settings['discount_badge'] ) && in_array( $settings['discount_badge'], array( 'percent', 'akcija', 'hide' ), true ) ) ? $settings['discount_badge'] : '',
			'archive_keep_days' => $this->sanitize_keep_days( isset( $settings['archive_keep_days'] ) ? $settings['archive_keep_days'] : self::KEEP_DAYS_DEFAULT ),
			'i18n'           => $this->sanitize_i18n( isset( $settings['i18n'] ) ? $settings['i18n'] : array() ),
			'line_color'     => self::sanitize_line_color( isset( $settings['line_color'] ) ? $settings['line_color'] : '' ),
			'feed_dostupnost' => ( isset( $settings['feed_dostupnost'] ) && 'yes' === $settings['feed_dostupnost'] ) ? 'yes' : 'no',
		);

		if ( '' === $clean['default_date'] ) {
			$clean['default_date'] = self::defaults()['default_date'];
		}

		update_option( self::OPTION_KEY, $clean );
		$this->settings = $clean;

		if ( class_exists( 'CX_Sidrena_I18n' ) ) {
			CX_Sidrena_I18n::instance()->register_strings();
		}
	}

	/**
	 * The owner's own colour for the bazna line and the lowest-price caption, or '' for the shop's text colour.
	 *
	 * @return string #rrggbb or ''.
	 */
	public function line_color() {
		return self::sanitize_line_color( $this->get( 'line_color', '' ) );
	}

	/**
	 * A colour as #rrggbb in lower case (#abc becomes #aabbcc, the colour picker's form); anything else is '' (the shop's
	 * text colour). The value is printed into the shop's CSS, so nothing but a hex colour gets through.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_line_color( $value ) {
		$value = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$value = strtolower( $value );
		if ( 4 === strlen( $value ) ) {
			$value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
		}

		return $value;
	}

	/**
	 * Prefix or tooltip for the current shop language.
	 *
	 * @param string $key prefix|tooltip.
	 * @return string
	 */
	public function get_translated( $key ) {
		if ( class_exists( 'CX_Sidrena_I18n' ) ) {
			return CX_Sidrena_I18n::instance()->get_front_string( $key );
		}

		return (string) $this->get( $key );
	}

	/**
	 * Whether the storefront tooltip is on.
	 *
	 * @return bool
	 */
	public function is_tooltip_enabled() {
		return 'yes' === $this->get( 'enable_tooltip' );
	}

	/**
	 * Whether sidrena is appended automatically under shop prices.
	 *
	 * @return bool
	 */
	public function is_auto_display_enabled() {
		return 'yes' === $this->get( 'auto_display' );
	}

	/**
	 * Whether new products get sidrena cijena and today's date when those fields are empty.
	 *
	 * @return bool
	 */
	public function is_auto_fill_enabled() {
		return 'yes' === $this->get( 'auto_fill' );
	}

	/**
	 * Live public feed format: xml or csv. Archive files keep the type they were generated as.
	 *
	 * @return string
	 */
	public function export_format() {
		return $this->sanitize_format( $this->get( 'export_format', 'xml' ) );
	}

	/**
	 * Whether the public price list has the dostupno/nedostupno column (CX-SID-28: off unless the owner turns it on).
	 *
	 * @return bool
	 */
	public function feed_has_dostupnost() {
		return 'yes' === $this->get( 'feed_dostupnost', 'no' );
	}

	/**
	 * A shop that sells only to businesses publishes no price list (CX-SID-28). The key is CX-SID-31's; until it is in
	 * the settings, get() gives 'no'.
	 *
	 * @return bool
	 */
	public function is_b2b_only() {
		return 'yes' === $this->get( 'b2b_only', 'no' );
	}

	/**
	 * Whether extra outlets publish their own feeds.
	 *
	 * True when the Poslovnice repeater has at least one outlet.
	 *
	 * @return bool
	 */
	public function is_multi_poslovnica() {
		if ( ! class_exists( 'CX_Sidrena_Poslovnice' ) ) {
			return false;
		}

		return array() !== CX_Sidrena_Poslovnice::instance()->all();
	}

	/**
	 * /sidrena-cjenik is always published.
	 *
	 * @return bool
	 */
	public function is_public_cjenik_enabled() {
		return true;
	}

	/**
	 * Whether the unit-price (CJM) controls are shown.
	 *
	 * @return bool
	 */
	public function is_cjm_enabled() {
		return 'yes' === $this->get( 'enable_cjm' );
	}

	/**
	 * Insert a unit phrase after the prefix words.
	 *
	 * "Cijena" + "za kg/l" becomes "Cijena za kg/l".
	 *
	 * @param string $prefix Base prefix.
	 * @param string $suffix Unit phrase.
	 * @return string
	 */
	public static function append_unit_suffix( $prefix, $suffix ) {
		$prefix = trim( (string) $prefix );
		$suffix = trim( (string) $suffix );
		if ( '' === $prefix || '' === $suffix ) {
			return $prefix;
		}

		if ( false !== stripos( $prefix, $suffix ) ) {
			return $prefix;
		}

		$paren = strpos( $prefix, ' (' );
		if ( false !== $paren ) {
			return trim( substr( $prefix, 0, $paren ) ) . ' ' . $suffix . substr( $prefix, $paren );
		}

		$colon = strpos( $prefix, ':' );
		if ( false !== $colon ) {
			return trim( substr( $prefix, 0, $colon ) ) . ' ' . $suffix . substr( $prefix, $colon );
		}

		return trim( $prefix . ' ' . $suffix );
	}

	/**
	 * Storefront label for one manual catalog row, including its unit when CJM is on.
	 *
	 * @param array<string, string> $row Catalog row.
	 * @return string
	 */
	public function label_for_catalog_row( $row ) {
		$prefix = $this->prefix_words();

		if ( $this->is_cjm_enabled() && is_array( $row ) && isset( $row['cjm'] ) && 'yes' === $row['cjm'] ) {
			$prefix = self::append_unit_suffix( $prefix, isset( $row['cjm_suffix'] ) ? (string) $row['cjm_suffix'] : '' );
		}

		$date = CX_Sidrena_Catalog::instance()->display_date( is_array( $row ) ? $row : array() );
		if ( '' === $date ) {
			return $prefix;
		}

		return $this->format_prefix_with_date( $prefix, $date );
	}

	/**
	 * check_legal_id(): may be stored, nothing to say.
	 */
	const LEGAL_ID_OK = 'ok';

	/**
	 * check_legal_id(): stored, but the owner should look at it (not an OIB, and not a valid 8-digit MBO).
	 */
	const LEGAL_ID_WARN = 'warn';

	/**
	 * check_legal_id(): not stored (not only digits, or 11 digits whose OIB check digit is wrong).
	 */
	const LEGAL_ID_REFUSED = 'refused';

	/**
	 * What an OIB or registration number typed by the owner is (CX-SID-110, Kai on card note 2611, Rook 2622). Spaces,
	 * dots and hyphens, and an "HR" in front (the VAT number), are dropped first without a word (Norman):
	 * - empty: ok, the file name goes without it;
	 * - anything but digits (spaces dropped): refused;
	 * - 11 digits: an OIB, its check digit by ISO 7064 MOD 11,10 (the official algorithm), wrong = refused;
	 * - 8 digits with a right MBO check digit: ok;
	 * - other digits (an 8-digit MBO whose check digit is off, an MIBPG whose format is not known): stored, with a
	 *   warning only. Kai checked the MBO rule on two numbers only, so it never blocks.
	 *
	 * @param mixed $raw Typed value.
	 * @return array{status:string,value:string,reason:string} reason: empty|digits|oib|mbo|other
	 */
	public static function check_legal_id( $raw ) {
		$value = is_string( $raw ) || is_int( $raw ) ? self::clean_legal_id( (string) $raw ) : '';
		if ( '' === $value ) {
			return array( 'status' => self::LEGAL_ID_OK, 'value' => '', 'reason' => 'empty' );
		}
		if ( 1 !== preg_match( '/^\d+$/D', $value ) ) {
			return array( 'status' => self::LEGAL_ID_REFUSED, 'value' => '', 'reason' => 'digits' );
		}
		if ( 11 === strlen( $value ) ) {
			return self::oib_valid( $value )
				? array( 'status' => self::LEGAL_ID_OK, 'value' => $value, 'reason' => 'oib' )
				: array( 'status' => self::LEGAL_ID_REFUSED, 'value' => '', 'reason' => 'oib' );
		}
		if ( 8 === strlen( $value ) && self::mbo_valid( $value ) ) {
			return array( 'status' => self::LEGAL_ID_OK, 'value' => $value, 'reason' => 'mbo' );
		}

		return array( 'status' => self::LEGAL_ID_WARN, 'value' => $value, 'reason' => 'other' );
	}

	/**
	 * A typed number without spaces, dots, hyphens and a leading "HR" (HR12345678903 is the VAT form of an OIB).
	 *
	 * @param string $raw Typed value.
	 * @return string
	 */
	public static function clean_legal_id( $raw ) {
		$value = (string) preg_replace( '/[\s.\-]+/u', '', (string) $raw );

		return (string) preg_replace( '/^HR/i', '', $value );
	}

	/**
	 * OIB check digit, ISO 7064 MOD 11,10.
	 *
	 * @param string $oib 11 digits.
	 * @return bool
	 */
	public static function oib_valid( $oib ) {
		if ( 1 !== preg_match( '/^\d{11}$/D', (string) $oib ) ) {
			return false;
		}
		$a = 10;
		for ( $i = 0; $i < 10; $i++ ) {
			$a = ( $a + (int) $oib[ $i ] ) % 10;
			$a = ( 0 === $a ? 10 : $a ) * 2 % 11;
		}
		$k = 11 - $a;

		return ( 10 === $k ? 0 : $k ) === (int) $oib[10];
	}

	/**
	 * MBO check digit (as the DZS registration number): weights 8 to 2 on the first seven digits, r = sum mod 11,
	 * k = 11 - r, and 0 when r is 0 or 1.
	 *
	 * @param string $mbo 8 digits.
	 * @return bool
	 */
	public static function mbo_valid( $mbo ) {
		if ( 1 !== preg_match( '/^\d{8}$/D', (string) $mbo ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += (int) $mbo[ $i ] * ( 8 - $i );
		}
		$r = $sum % 11;

		return ( $r <= 1 ? 0 : 11 - $r ) === (int) $mbo[7];
	}

	/**
	 * The OIB or registration number for the price list file's name, or '' (none, or a stored value that may not be
	 * used: written straight to the option, not through save()). A shop without it still publishes; Status says so.
	 *
	 * @return string
	 */
	public function legal_id() {
		$check = self::check_legal_id( $this->get( 'file_oib', '' ) );

		return self::LEGAL_ID_REFUSED === $check['status'] ? '' : $check['value'];
	}

	/**
	 * Object code for filenames and /sidrena-cijena-{oznaka}.xml.
	 *
	 * @param mixed $value Raw code.
	 * @return string
	 */
	public function normalize_oznaka( $value ) {
		$value = is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
		$value = sanitize_text_field( $value );
		$value = preg_replace( '/[^A-Za-z0-9\-]/', '', $value );
		$value = is_string( $value ) ? trim( $value, '-' ) : '';

		return $value;
	}

	/**
	 * Filename part that keeps spaces, hyphens, dots, and the typed case.
	 *
	 * Underscores are turned into spaces so they stay out of the field separator.
	 * Other punctuation (commas in a street address) becomes a space so the
	 * archive name can be opened from a public URL.
	 *
	 * @param mixed  $value    Raw.
	 * @param string $fallback Fallback when empty.
	 * @return string
	 */
	public function sanitize_file_part( $value, $fallback = '' ) {
		$value = is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
		$value = sanitize_text_field( $value );
		$value = str_replace( array( '_', '/', '\\', ':', '?', '*', '"', '<', '>', '|' ), ' ', $value );
		$value = preg_replace( '/[^\p{L}\p{N} .\-]+/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return $fallback;
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 80, 'UTF-8' );
		}

		return substr( $value, 0, 80 );
	}

	/**
	 * Taxonomies a shop can use as the product brand.
	 *
	 * @return array<string, string> slug => label
	 */
	public function brand_taxonomy_choices() {
		if ( ! class_exists( 'CX_Sidrena_Plugin' ) || ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return array();
		}

		$skip  = array( 'product_type', 'product_visibility', 'product_shipping_class' );
		$taxes = get_object_taxonomies( 'product', 'objects' );
		$out   = array();

		if ( ! is_array( $taxes ) ) {
			return $out;
		}

		foreach ( $taxes as $tax ) {
			if ( ! $tax instanceof WP_Taxonomy || in_array( $tax->name, $skip, true ) ) {
				continue;
			}

			$label = isset( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : (string) $tax->label;
			$out[ $tax->name ] = $label . ' (' . $tax->name . ')';
		}

		asort( $out );

		return $out;
	}

	/**
	 * Keep a brand taxonomy only when it is still registered.
	 *
	 * @param mixed $value Raw slug.
	 * @return string
	 */
	public function sanitize_brand_taxonomy( $value ) {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( '' === $value ) {
			return '';
		}

		$choices = $this->brand_taxonomy_choices();

		return isset( $choices[ $value ] ) ? $value : '';
	}

	/**
	 * Shop-wide brand used when a product or manual row has none of its own.
	 *
	 * @return string
	 */
	public function manual_marka() {
		return trim( (string) $this->get( 'brand_manual', '' ) );
	}

	/**
	 * Brand for one manual row, falling back to the settings value.
	 *
	 * @param string $marka Row value.
	 * @return string
	 */
	public function row_marka( $marka ) {
		$marka = trim( (string) $marka );

		return '' !== $marka ? $marka : $this->manual_marka();
	}

	/**
	 * Brand written to the public feed for a WooCommerce product.
	 *
	 * Terms on the product win. A variation with no terms uses the parent.
	 * The settings value is used when the taxonomy has no term.
	 * An owner who chose neither a taxonomy nor a manual brand gets WooCommerce's own Brands (product_brand, WooCommerce
	 * 9.6+), when the shop has them (CX-SID-28).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function product_marka( $product ) {
		$manual = $this->manual_marka();
		$tax    = (string) $this->get( 'brand_taxonomy', '' );

		if ( '' === $tax && '' === $manual ) {
			$tax = 'product_brand';
		}

		if ( '' === $tax || ! taxonomy_exists( $tax ) || ! $product instanceof WC_Product ) {
			return $manual;
		}

		$ids = array( (int) $product->get_id() );
		if ( $product->is_type( 'variation' ) ) {
			$ids[] = (int) $product->get_parent_id();
		}

		foreach ( $ids as $id ) {
			if ( $id < 1 ) {
				continue;
			}

			$terms = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$names = array();
			foreach ( $terms as $term ) {
				$term = trim( (string) $term );
				if ( '' !== $term ) {
					$names[] = $term;
				}
			}

			if ( array() !== $names ) {
				return implode( ', ', $names );
			}
		}

		return $manual;
	}

	/**
	 * Store one brand name on the selected taxonomy.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $marka   Brand name.
	 * @return bool
	 */
	public function assign_product_marka( $product, $marka ) {
		$tax   = (string) $this->get( 'brand_taxonomy', '' );
		$marka = sanitize_text_field( (string) $marka );

		if ( '' === $tax || '' === $marka || ! taxonomy_exists( $tax ) || ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( $product_id < 1 ) {
			return false;
		}

		$term = term_exists( $marka, $tax );
		if ( ! $term ) {
			$term = wp_insert_term( $marka, $tax );
		}

		if ( is_wp_error( $term ) || empty( $term['term_id'] ) ) {
			return false;
		}

		$result = wp_set_object_terms( $product_id, array( (int) $term['term_id'] ), $tax, false );

		return ! is_wp_error( $result );
	}

	/**
	 * Suggested store address for the legal filename (WooCommerce address or host).
	 *
	 * @return string
	 */
	public function default_file_address() {
		$parts = array();
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_postcode', 'woocommerce_store_city' ) as $key ) {
			$value = trim( (string) get_option( $key, '' ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		if ( array() !== $parts ) {
			return implode( ' ', $parts );
		}

		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return '' !== $host ? $host : 'webshop';
	}

	/**
	 * Uppercase A-Z/0-9 token for filename parts.
	 *
	 * @param mixed  $value    Raw.
	 * @param string $fallback Fallback token.
	 * @return string
	 */
	public function sanitize_file_token( $value, $fallback = 'WEBSHOP' ) {
		$value = is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
		$value = remove_accents( $value );
		$value = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
		$value = preg_replace( '/[^A-Z0-9]/', '', $value );

		return ( is_string( $value ) && '' !== $value ) ? $value : $fallback;
	}

	/**
	 * Numeric/code part, zero-padded.
	 *
	 * @param mixed  $value    Raw.
	 * @param int    $width    Pad width.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public function sanitize_file_code( $value, $width = 3, $fallback = '001' ) {
		$token = $this->sanitize_file_token( $value, '' );
		if ( '' === $token ) {
			$token = $fallback;
		}

		if ( ctype_digit( $token ) ) {
			return str_pad( $token, $width, '0', STR_PAD_LEFT );
		}

		return $token;
	}

	/**
	 * Storage sequence, 1+.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public function sanitize_pohrana( $value ) {
		$n = absint( $value );

		return $n > 0 ? $n : 1;
	}

	/**
	 * Days kept price list files are kept: 0 = for ever, otherwise at least the public 30 (nothing goes before it was
	 * public its whole time). Not a number: the default.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public function sanitize_keep_days( $value ) {
		if ( ! is_numeric( $value ) ) {
			return self::KEEP_DAYS_DEFAULT;
		}
		$n = (int) $value;
		if ( 0 === $n ) {
			return 0;
		}

		return min( max( $n, CX_Sidrena_Archive::PUBLIC_DAYS ), 36525 );
	}

	/**
	 * Stored translation for one language, or empty.
	 *
	 * @param string $key  prefix|tooltip.
	 * @param string $lang Language code.
	 * @return string
	 */
	public function get_i18n_value( $key, $lang ) {
		$i18n = $this->get( 'i18n' );
		if ( ! is_array( $i18n ) || empty( $i18n[ $key ] ) || ! is_array( $i18n[ $key ] ) ) {
			return '';
		}

		$lang = (string) $lang;
		if ( isset( $i18n[ $key ][ $lang ] ) && is_string( $i18n[ $key ][ $lang ] ) ) {
			return trim( $i18n[ $key ][ $lang ] );
		}

		return '';
	}

	/**
	 * Remove an embedded date so the prefix is only the words.
	 *
	 * @param string $prefix Raw prefix.
	 * @return string
	 */
	public static function strip_date_from_prefix( $prefix ) {
		$prefix = preg_replace( '/\s*\(\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*\)\s*/', '', $prefix );
		$prefix = preg_replace( '/\s*:\s*\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/', '', $prefix );
		$prefix = preg_replace( '/\s+\d{1,2}\.\d{1,2}\.\d{4}\.?\s*/', ' ', $prefix );
		$prefix = is_string( $prefix ) ? trim( preg_replace( '/\s+/', ' ', $prefix ) ) : '';

		return $prefix;
	}

	/**
	 * Prefix + default date as shown on the shop.
	 *
	 * @return string
	 */
	public function get_preview_label() {
		$prefix = $this->prefix_words( true );
		$date   = trim( (string) $this->get( 'default_date' ) );
		if ( '' === $date ) {
			return $prefix;
		}

		return $this->format_prefix_with_date( $prefix, rtrim( $date, '.' ) . '.' );
	}

	/**
	 * Space before the amount. A colon only when prefix words are shown.
	 *
	 * @param bool $stored_only Use the saved prefix, not a translation.
	 * @return string
	 */
	public function preview_separator( $stored_only = true ) {
		return '' === $this->prefix_words( $stored_only ) ? ' ' : ': ';
	}

	/**
	 * True when the label is only "Cijena", which a shopper can mix up with the selling price.
	 *
	 * "Sidrena cijena" is kept. The merchant confirms a switch to the statutory name.
	 *
	 * @param string $prefix Stored prefix.
	 * @return bool
	 */
	public static function is_generic_prefix( $prefix ) {
		$prefix = trim( (string) $prefix );

		return in_array( $prefix, array( self::GENERIC_PREFIX, self::GENERIC_PREFIX . ' na' ), true );
	}

	/**
	 * Statutory label for a generic prefix. A trailing "na" stays, for the plain date format.
	 *
	 * @param string $prefix Stored prefix.
	 * @return string
	 */
	public static function bazna_replacement( $prefix ) {
		$prefix = trim( (string) $prefix );
		if ( preg_match( '/\bna$/iu', $prefix ) ) {
			return self::DEFAULT_PREFIX . ' na';
		}

		return self::DEFAULT_PREFIX;
	}

	/**
	 * Replace a generic prefix, and matching translations, with Bazna cijena.
	 */
	public function apply_bazna_prefix() {
		$all  = $this->all();
		$i18n = ( isset( $all['i18n'] ) && is_array( $all['i18n'] ) ) ? $all['i18n'] : array();

		if ( isset( $i18n['prefix'] ) && is_array( $i18n['prefix'] ) ) {
			foreach ( $i18n['prefix'] as $lang => $value ) {
				if ( self::is_generic_prefix( $value ) ) {
					$i18n['prefix'][ $lang ] = self::bazna_replacement( $value );
				}
			}
		}

		$this->save(
			array(
				'prefix' => self::bazna_replacement( isset( $all['prefix'] ) ? $all['prefix'] : '' ),
				'i18n'   => $i18n,
			)
		);
	}

	/**
	 * Prefix words for the line. Empty plus parentheses keeps "Bazna cijena".
	 * Empty plus the plain date format stays empty, so the line is the date and the amount.
	 *
	 * @param bool $stored_only Use the saved prefix, not a translation.
	 * @return string
	 */
	public function prefix_words( $stored_only = false ) {
		$raw    = $stored_only ? (string) $this->get( 'prefix' ) : $this->get_translated( 'prefix' );
		$prefix = self::strip_date_from_prefix( $raw );
		if ( '' === $prefix && 'plain' !== $this->get( 'date_style' ) ) {
			$prefix = $stored_only ? self::DEFAULT_PREFIX : __( 'Bazna cijena', 'cx-sidrena' );
		}

		return $prefix;
	}

	/**
	 * Combine prefix words and a date for the storefront.
	 *
	 * @param string $prefix Label without date.
	 * @param string $date   Normalized date.
	 * @return string
	 */
	public function format_prefix_with_date( $prefix, $date ) {
		$prefix = trim( (string) $prefix );
		$date   = CX_Sidrena_Money::customer_date( $date );

		if ( '' === $date ) {
			return $prefix;
		}

		if ( '' === $prefix && 'plain' === $this->get( 'date_style' ) ) {
			return $date;
		}

		if ( 'plain' === $this->get( 'date_style' ) ) {
			return $prefix . ' ' . $date;
		}

		return $prefix . ' (' . $date . ')';
	}

	/**
	 * Per-language prefix and tooltip (not the default language).
	 *
	 * @param mixed $raw Raw i18n payload.
	 * @return array{prefix:array<string,string>,tooltip:array<string,string>}
	 */
	private function sanitize_i18n( $raw ) {
		$out = array(
			'prefix'  => array(),
			'tooltip' => array(),
		);

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		$default_lang = class_exists( 'CX_Sidrena_I18n' ) ? CX_Sidrena_I18n::instance()->get_default_language() : '';

		foreach ( array( 'prefix', 'tooltip' ) as $key ) {
			if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}

			foreach ( $raw[ $key ] as $lang => $value ) {
				$lang = strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $lang ) );
				if ( '' === $lang || $lang === $default_lang ) {
					continue;
				}

				if ( 'prefix' === $key ) {
					$out[ $key ][ $lang ] = self::strip_date_from_prefix( sanitize_text_field( (string) $value ) );
				} else {
					$out[ $key ][ $lang ] = sanitize_textarea_field( (string) $value );
				}
			}
		}

		return $out;
	}

	/**
	 * Allowed date formats: parens or plain.
	 *
	 * @param mixed $style Raw style.
	 * @return string
	 */
	private function sanitize_date_style( $style ) {
		$style = is_string( $style ) ? $style : '';
		if ( 'na' === $style ) {
			return 'plain';
		}

		return in_array( $style, array( 'parens', 'plain' ), true ) ? $style : 'parens';
	}

	/**
	 * xml or csv.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	private function sanitize_format( $value ) {
		$value = is_string( $value ) ? strtolower( $value ) : '';

		return 'csv' === $value ? 'csv' : 'xml';
	}
}
