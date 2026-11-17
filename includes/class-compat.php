<?php
/**
 * Theme and builder compatibility for frontend price display.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks and selectors for popular builders / themes.
 */
class CX_Sidrena_Compat {

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
	 * Register builder-specific PHP filters.
	 */
	private function __construct() {
		if ( ! CX_Sidrena_Settings::instance()->is_auto_display_enabled() ) {
			return;
		}

		add_filter( 'et_module_shortcode_output', array( $this, 'filter_divi_module' ), 20, 2 );
		add_filter( 'et_pb_wc_price_shortcode_output', array( $this, 'append_html' ), 20 );
		add_filter( 'render_block', array( $this, 'filter_price_block' ), 20, 3 );
		add_filter( 'do_shortcode_tag', array( $this, 'filter_price_shortcode' ), 20, 3 );
		add_filter( 'bricks/element/render', array( $this, 'filter_bricks_element' ), 20, 2 );
		add_filter( 'fl_builder_render_module_content', array( $this, 'filter_beaver_module' ), 20, 2 );
		add_filter( 'fusion_element_woo_price_content', array( $this, 'append_html' ), 20 );
		add_filter( 'elementor/widget/render_content', array( $this, 'filter_elementor_family' ), 25, 2 );
	}

	/**
	 * WooCommerce Lowest Price (Igor Kovačić 1.x or Media X 2.x) is active.
	 *
	 * @return bool
	 */
	public static function lowest_price_plugin_active() {
		if ( defined( 'WPLP_VERSION' ) || defined( 'WPLP_FILE' ) ) {
			return true;
		}

		if ( class_exists( 'Lowest_Price', false ) || class_exists( '\Lowest_Price\Frontend\WPLP_Frontend', false ) ) {
			return true;
		}

		if ( function_exists( 'lowest_price' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Human label for the detected 30-day plugin.
	 *
	 * @return string
	 */
	public static function lowest_price_plugin_label() {
		if ( defined( 'WPLP_VERSION' ) ) {
			return sprintf(
				/* translators: %s plugin version */
				__( 'WooCommerce Lowest Price (Media X %s)', 'cx-sidrena' ),
				WPLP_VERSION
			);
		}

		if ( class_exists( 'Lowest_Price', false ) || function_exists( 'lowest_price' ) ) {
			return __( 'WooCommerce Lowest Price (Igor Kovačić)', 'cx-sidrena' );
		}

		return __( 'WooCommerce Lowest Price', 'cx-sidrena' );
	}

	/**
	 * Insert sidrena HTML once: after the current price, before the 30-day line.
	 *
	 * Media X 2.x wraps get_price_html in an extra span.price and appends
	 * .lowest_price. Igor 1.x uses .lowest_price / .actual_price. Without this,
	 * Sidrena both appends after the wrapper and JS-injects into the inner
	 * .price, so the line appears twice.
	 *
	 * @param string $content      Existing price markup.
	 * @param string $sidrena_html Sidrena markup.
	 * @param bool   $into_price   Whether the line may go inside an element with the class price in $content. False for
	 *                             the price HTML of woocommerce_get_price_html (the price block): there such an element
	 *                             is a theme's wrapper around the whole price, and the line goes after it (CX-SID-93).
	 * @return string
	 */
	public static function merge_price_html( $content, $sidrena_html, $into_price = true ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( ! is_string( $sidrena_html ) || '' === $sidrena_html ) {
			return $content;
		}

		if ( false !== strpos( $content, 'sidrena-cijena' ) ) {
			return $content;
		}

		$before_lowest = self::insert_before_lowest_price_line( $content, $sidrena_html );
		if ( null !== $before_lowest ) {
			return $before_lowest;
		}

		if ( preg_match( '/<(?:span|div|p)[^>]*\bactual_price\b/i', $content ) ) {
			return $content . $sidrena_html;
		}

		if ( $into_price ) {
			$inside = self::insert_at_end_of_price_element( $content, $sidrena_html );
			if ( null !== $inside ) {
				return $inside;
			}
		}

		return $content . $sidrena_html;
	}

	/**
	 * Opening tag of a p, div or span with the class price (the word, not woocommerce-Price-amount or price-wrap).
	 */
	const PRICE_ELEMENT = '/<(p|div|span)\b[^>]*\bclass=(["\'])[^"\']*(?<=["\'\s])price(?=["\'\s])[^"\']*\2[^>]*>/';

	/**
	 * Whether markup has an element with the class price. In the HTML of woocommerce_get_price_html that is not
	 * WooCommerce's own (its classes are woocommerce-Price-amount and woocommerce-price-suffix): a theme wrapped the
	 * price.
	 *
	 * @param string $content Markup.
	 * @return bool
	 */
	public static function has_price_element( $content ) {
		return is_string( $content ) && 1 === preg_match( self::PRICE_ELEMENT, $content );
	}

	/**
	 * Put the line last inside the first element with the class price (a page builder's p.price around WooCommerce's
	 * price): right before that element's own closing tag, found by counting the nested tags of its name. MJDD's lazy
	 * match stopped at the first closing p, div or span, the currency symbol's </span>, so the line ended up inside the
	 * first amount, and on a sale inside <del aria-hidden> (CX-SID-93).
	 *
	 * @param string $content      Markup.
	 * @param string $sidrena_html Sidrena markup.
	 * @return string|null Null when there is no such element, or it is never closed.
	 */
	private static function insert_at_end_of_price_element( $content, $sidrena_html ) {
		if ( ! preg_match( self::PRICE_ELEMENT, $content, $open, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$close = self::price_element_close( $content, $open );
		if ( null === $close ) {
			return null;
		}

		return substr( $content, 0, $close ) . $sidrena_html . substr( $content, $close );
	}

	/**
	 * Replace what is inside the first element with the class price, keeping its own opening and closing tag: a theme's
	 * wrapper around the price (S8's div.price) stays when the price block draws the amounts anew (CX-SID-99). The closing
	 * tag is found as in insert_at_end_of_price_element, by counting the nested tags of its name.
	 *
	 * @param string $content Markup.
	 * @param string $inner   What goes inside instead.
	 * @return string|null Null when there is no such element, or it is never closed.
	 */
	public static function replace_price_element_content( $content, $inner ) {
		if ( ! is_string( $content ) || ! preg_match( self::PRICE_ELEMENT, $content, $open, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$close = self::price_element_close( $content, $open );
		if ( null === $close ) {
			return null;
		}

		$from = $open[0][1] + strlen( $open[0][0] );

		return substr( $content, 0, $from ) . $inner . substr( $content, $close );
	}

	/**
	 * Offset of the closing tag of the element with the class price that $open matched (PRICE_ELEMENT, with offsets).
	 *
	 * @param string $content Markup.
	 * @param array  $open    preg_match result with PREG_OFFSET_CAPTURE.
	 * @return int|null Null when it is never closed.
	 */
	private static function price_element_close( $content, $open ) {
		$from = $open[0][1] + strlen( $open[0][0] );
		if ( ! preg_match_all( '#<(/?)' . $open[1][0] . '\b[^>]*>#i', $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $from ) ) {
			return null;
		}

		$depth = 1;
		foreach ( $tags as $tag ) {
			if ( '/' === $tag[1][0] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $tag[0][1];
				}
			} elseif ( '/>' !== substr( $tag[0][0], -2 ) ) {
				++$depth;
			}
		}

		return null;
	}

	/**
	 * Add a class to the first element of a piece of our markup (the caption, the bazna line).
	 *
	 * @param string $html       Markup that starts with an element with a class attribute in double quotes.
	 * @param string $class_name Class to add.
	 * @return string
	 */
	public static function add_class( $html, $class_name ) {
		$out = preg_replace( '/^(<[a-z]+\b[^>]*?\bclass="[^"]*)"/', '$1 ' . $class_name . '"', (string) $html, 1 );

		return is_string( $out ) ? $out : (string) $html;
	}

	/**
	 * Place sidrena immediately before .lowest_price when a current price already exists.
	 *
	 * @param string $content      Price markup.
	 * @param string $sidrena_html Sidrena markup.
	 * @return string|null
	 */
	private static function insert_before_lowest_price_line( $content, $sidrena_html ) {
		if ( ! preg_match( '/<(?:span|div|p)[^>]*\blowest_price\b/i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$lowest_pos = isset( $match[0][1] ) ? (int) $match[0][1] : -1;
		if ( $lowest_pos < 1 ) {
			return null;
		}

		$before = substr( $content, 0, $lowest_pos );
		$has_current_price = (
			false !== stripos( $before, 'woocommerce-Price-amount' )
			|| false !== stripos( $before, '<ins' )
			|| false !== stripos( $before, '<del' )
			|| false !== stripos( $before, 'actual_price' )
		);

		if ( ! $has_current_price ) {
			return null;
		}

		$replaced = preg_replace(
			'/(?:<br\s*\/?>\s*)?(<(?:span|div|p)[^>]*\blowest_price\b[^>]*>)/i',
			$sidrena_html . '$1',
			$content,
			1
		);

		return ( is_string( $replaced ) && $replaced !== $content ) ? $replaced : null;
	}

	/**
	 * CSS selectors used by the frontend injector (single-product widgets).
	 *
	 * @return array<int, string>
	 */
	public static function price_selectors() {
		return array(
			'.elementor-widget-woocommerce-product-price p.price',
			'.elementor-widget-woocommerce-product-price .price',
			'.elementor-widget-jet-single-price .price',
			'.jet-woo-product-price .price',
			'.jet-single-price .price',
			'.et_pb_wc_price .price',
			'.et_pb_module.et_pb_wc_price p.price',
			'.oxy-product-price .price',
			'.oxy-woo-element .price',
			'.ct-woo-product-price .price',
			'.brxe-product-price .price',
			'.brxe-woocommerce-product-price .price',
			'.fl-module-woocommerce-product-price .price',
			'.fl-woo-price .price',
			'.bde-woocommerce-product-price .price',
			'.breakdance-woocommerce-product-price .price',
			'.brz-woo-price .price',
			'.fusion-tb-woo-price .price',
			'.fusion-woo-price-tb .price',
			'.wd-single-price .price',
			'.wd-product-price .price',
			'.product-page-price',
			'.product-info .price-wrapper .price',
			'.shopengine-product-price .price',
			'.wpb_wrapper > p.price',
			'.vc_woo_product_price .price',
			'.wc-block-components-product-price',
			'.wp-block-woocommerce-product-price .price',
			'.summary > p.price',
			'.entry-summary > p.price',
			'.product-summary > p.price',
			'.single-product-summary .price',
		);
	}

	/**
	 * Roots to observe for late JS price rewrites.
	 *
	 * @return array<int, string>
	 */
	public static function watch_roots() {
		return array(
			'.elementor-widget-woocommerce-product-price',
			'.elementor-widget-jet-single-price',
			'.jet-single-price',
			'.et_pb_wc_price',
			'.oxy-product-price',
			'.brxe-product-price',
			'.fl-module-woocommerce-product-price',
			'.fusion-tb-woo-price',
			'.wd-single-price',
			'.shopengine-product-price',
			'.wc-block-components-product-price',
			'.wp-block-woocommerce-product-price',
			'.summary',
			'.entry-summary',
		);
	}

	/**
	 * Divi 4 Woo Product Price module.
	 *
	 * @param string $output      Module HTML.
	 * @param string $render_slug Module slug.
	 * @return string
	 */
	public function filter_divi_module( $output, $render_slug ) {
		if ( 'et_pb_wc_price' !== $render_slug ) {
			return $output;
		}

		return $this->insert_into_price_markup( $output );
	}

	/**
	 * Gutenberg / Divi 5 / Woo blocks named like product-price.
	 *
	 * @param string        $content  Block HTML.
	 * @param array         $block    Block data.
	 * @param WP_Block|null $instance Block instance: in a product list its context names the card's product.
	 * @return string
	 */
	public function filter_price_block( $content, $block, $instance = null ) {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( '' === $name ) {
			return $content;
		}

		$known = array(
			'woocommerce/product-price',
			'divi/wc-product-price',
		);

		if ( ! in_array( $name, $known, true ) && false === strpos( $name, 'product-price' ) ) {
			return $content;
		}

		// The block's own product, never the page's: on a card in a product list that is another product (Argus
		// CX-SID-25 B1). A block without one (another builder's) keeps the old lookup.
		if ( $instance instanceof WP_Block && CX_Sidrena_Plugin::woocommerce_active() ) {
			$product = CX_Sidrena_Frontend::instance()->product_from_block( $block, $instance );
			if ( $product ) {
				return $this->insert_into_price_markup( $content, $product );
			}
		}

		return $this->insert_into_price_markup( $content );
	}

	/**
	 * WooCommerce / WPBakery price shortcodes.
	 *
	 * @param string       $output Shortcode HTML.
	 * @param string       $tag    Tag.
	 * @param array|string $attr   Attributes.
	 * @return string
	 */
	public function filter_price_shortcode( $output, $tag, $attr ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$tags = array(
			'product_price',
			'woocommerce_product_price',
			'woodmart_product_price',
		);

		if ( ! in_array( $tag, $tags, true ) ) {
			return $output;
		}

		return $this->insert_into_price_markup( $output );
	}

	/**
	 * Bricks Product Price element.
	 *
	 * @param string $html    Element HTML.
	 * @param object $element Element instance.
	 * @return string
	 */
	public function filter_bricks_element( $html, $element ) {
		$name = '';
		if ( is_object( $element ) && isset( $element->name ) ) {
			$name = (string) $element->name;
		}

		if ( 'product-price' !== $name && 'woocommerce-product-price' !== $name ) {
			return $html;
		}

		return $this->insert_into_price_markup( $html );
	}

	/**
	 * Beaver Builder WooCommerce price module.
	 *
	 * @param string $out    Module HTML.
	 * @param object $module Module instance.
	 * @return string
	 */
	public function filter_beaver_module( $out, $module ) {
		$slug = '';
		if ( is_object( $module ) && isset( $module->slug ) ) {
			$slug = (string) $module->slug;
		}

		if ( false === strpos( $slug, 'price' ) || false === strpos( $slug, 'woo' ) ) {
			return $out;
		}

		return $this->insert_into_price_markup( $out );
	}

	/**
	 * Extra Elementor-family price widgets (JetWooBuilder, ShopEngine, AE).
	 *
	 * @param string $content Widget HTML.
	 * @param object $widget  Widget.
	 * @return string
	 */
	public function filter_elementor_family( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return $content;
		}

		$name  = $widget->get_name();
		$match = array(
			'jet-single-price',
			'jet-woo-builder-archive-product-price',
			'shopengine-product-price',
			'ae-woo-price',
			'woocommerce-product-price',
		);

		if ( ! in_array( $name, $match, true ) && false === strpos( $name, 'product-price' ) && false === strpos( $name, 'single-price' ) ) {
			return $content;
		}

		return $this->insert_into_price_markup( $content );
	}

	/**
	 * Append sidrena HTML if the string is not empty and does not already contain it.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public function append_html( $html ) {
		return $this->insert_into_price_markup( $html );
	}

	/**
	 * Put sidrena markup inside the first .price paragraph, or append.
	 *
	 * @param string          $content Markup.
	 * @param WC_Product|null $product The product it belongs to; null = from the current context.
	 * @return string
	 */
	private function insert_into_price_markup( $content, $product = null ) {
		if ( ! CX_Sidrena_Settings::instance()->is_auto_display_enabled() ) {
			return $content;
		}

		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		if ( false !== strpos( $content, 'sidrena-cijena' ) ) {
			return $content;
		}

		$html = CX_Sidrena_Frontend::instance()->get_html_for_context( $product );
		if ( '' === $html ) {
			return $content;
		}

		return self::merge_price_html( $content, $html );
	}
}
