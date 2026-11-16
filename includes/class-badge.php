<?php
/**
 * The sale badge on product pages and cards (spec §2.5, CX-SID-26).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * One rule for every badge a customer sees, whoever draws it:
 * - no badge where the lowest price in 30 days is not shown with it (Kai: ZZP art. 19(4) and (6)): a sale that is no
 *   reduction, a variable or grouped product whose items have different prices (CX_Sidrena_Frontend::badge_allowed);
 * - a percentage only when it is computed from the struck amount (the lowest price in 30 days), rounded down
 *   (Kai: CJEU C-330/23 Aldi Süd), never from the regular price as themes do;
 * - otherwise the text "Akcija", or nothing when the owner chose to hide it (setting discount_badge).
 *
 * Who draws it, and how we reach it:
 * - WooCommerce's badge (woocommerce_sale_flash), also when a theme's filter echoes its labels instead of returning
 *   them (WoodMart): only the .onsale element changes, the theme's other labels (New, Hot, Sold out) stay;
 * - the Product Sale Badge block (block themes, also inside the Product Image block);
 * - the Ordo theme, which draws its own box: its function runs in its own text mode with our text, for the length of
 *   its call only (CX-SID-26 proposal, Rook: not unhooked, because the same box draws Hot and Sold out and the
 *   theme's plugin hooks it back after every product shortcode);
 * - another theme that draws its own: we cannot see it; theme_mode() says 'own' for the Status screen, and "hide"
 *   hides it with CSS.
 */
class CX_Sidrena_Badge {

	/**
	 * Setting key in cx_sidrena_settings. Empty = by theme (default_mode()).
	 */
	const SETTING = 'discount_badge';

	/**
	 * The owner's choices (Settings > Discount badge, CX-SID-23).
	 */
	const MODES = array( 'percent', 'akcija', 'hide' );

	/**
	 * CSS that hides the badge of a theme that draws its own, per theme (get_template()). Added when we meet one;
	 * every other such theme gets the generic selector.
	 */
	const OWN_THEME_SELECTORS = array();

	/**
	 * What theme_mode() said on the shop's own pages, per theme (stylesheet). WooCommerce adds its template hooks only
	 * on the front end, so in the admin every classic theme would look as if it drew its own badge (CX-SID-23).
	 */
	const SEEN_OPTION = 'cx_sidrena_badge_theme';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Output buffer levels opened around woocommerce_sale_flash (a stack: the filter can nest).
	 *
	 * @var int[]
	 */
	private $flash_buffers = array();

	/**
	 * Ordo's options while its label function runs with our text; null when it is not running.
	 *
	 * @var array|null
	 */
	private $ordo_saved = null;

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
	 * Register the badge hooks (WooCommerce is active: CX_Sidrena_Frontend checks it).
	 */
	private function __construct() {
		add_filter( 'woocommerce_sale_flash', array( $this, 'start_flash' ), PHP_INT_MIN, 3 );
		add_filter( 'woocommerce_sale_flash', array( $this, 'filter_sale_flash' ), PHP_INT_MAX, 3 );
		add_filter( 'render_block_woocommerce/product-sale-badge', array( $this, 'filter_sale_badge_block' ), 20, 3 );

		// Ordo calls ftc_template_loop_product_label at priority 1 on cards and 10 on the product image.
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'ordo_before' ), 0 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'ordo_after' ), 2 );
		add_action( 'ftc_before_product_image', array( $this, 'ordo_before' ), 9 );
		add_action( 'ftc_before_product_image', array( $this, 'ordo_after' ), 11 );

		add_action( 'wp_enqueue_scripts', array( $this, 'own_theme_css' ), 20 );
		add_action( 'wp', array( $this, 'remember_theme_mode' ), 99 );
	}

	/**
	 * On a shop page, remember who draws the badge for this theme (written only when it changes).
	 */
	public function remember_theme_mode() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$seen  = get_option( self::SEEN_OPTION, array() );
		$seen  = is_array( $seen ) ? $seen : array();
		$theme = get_stylesheet();
		$mode  = self::theme_mode();
		if ( ! isset( $seen[ $theme ] ) || $seen[ $theme ] !== $mode ) {
			$seen[ $theme ] = $mode;
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}

	/**
	 * Who draws the badge on the active theme, as seen on the shop's pages; null until a shop page was opened.
	 *
	 * @return string|null ordo | block | woo | own
	 */
	public static function seen_theme_mode() {
		$seen = get_option( self::SEEN_OPTION, array() );
		$theme = get_stylesheet();

		return ( is_array( $seen ) && isset( $seen[ $theme ] ) ) ? (string) $seen[ $theme ] : null;
	}

	/**
	 * Who draws the badge on this shop's theme.
	 *
	 * @return string ordo | block | woo | own (a classic theme that removed WooCommerce's badge and draws its own; read
	 *                after the theme has loaded, the Status screen warns about it).
	 */
	public static function theme_mode() {
		static $known = null;
		if ( null !== $known ) {
			return $known;
		}

		if ( 'ordo' === get_template() && function_exists( 'ftc_template_loop_product_label' ) ) {
			$mode = 'ordo';
		} elseif ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$mode = 'block';
		} else {
			$loop   = has_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash' );
			$single = has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash' );
			$mode   = ( false === $loop && false === $single ) ? 'own' : 'woo';
		}

		// Kept once the page is being built (the theme and its hooks are in place); earlier it may still change.
		if ( did_action( 'wp' ) ) {
			$known = $mode;
		}

		return $mode;
	}

	/**
	 * What shows when the owner has not chosen: the percentage only where we draw it ourselves (Ordo), "Akcija"
	 * everywhere else (spec §2.5).
	 *
	 * @return string percent | akcija
	 */
	public static function default_mode() {
		return 'ordo' === self::theme_mode() ? 'percent' : 'akcija';
	}

	/**
	 * The mode in effect: the owner's choice, or the theme's default (not stored, so a theme switch follows).
	 *
	 * @return string percent | akcija | hide
	 */
	public static function mode() {
		$chosen = (string) CX_Sidrena_Settings::instance()->get( self::SETTING, '' );

		return in_array( $chosen, self::MODES, true ) ? $chosen : self::default_mode();
	}

	/**
	 * Discount from the struck amount to the sale price, in whole percent rounded down. On the amounts the customer
	 * sees (the shop's decimals, the price as displayed), in integers, so 10,00 → 9,00 is 10 % and never 9 %.
	 *
	 * @param string|float $struck Struck amount (the lowest price in 30 days, as displayed).
	 * @param string|float $sale   Sale price, as displayed.
	 * @param int          $decimals Price decimals of the shop.
	 * @return int 0 when there is no reduction.
	 */
	public static function percent_off( $struck, $sale, $decimals = 2 ) {
		$scale  = 10 ** max( 0, (int) $decimals );
		$struck = (int) round( (float) $struck * $scale );
		$sale   = (int) round( (float) $sale * $scale );
		if ( $struck <= 0 || $sale >= $struck ) {
			return 0;
		}

		return intdiv( ( $struck - $sale ) * 100, $struck );
	}

	/**
	 * Percentage of a product on a real sale, from the amount the price block strikes; null when it is unknown (our
	 * lowest-price line is off, another lowest-price plugin draws its own) or there is no reduction.
	 *
	 * @param WC_Product $product Product.
	 * @return int|null
	 */
	public function percent_for( $product ) {
		$state = CX_Sidrena_Frontend::instance()->sale_state( $product );
		if ( null === $state || 'sale' !== $state['kind'] ) {
			return null;
		}

		$struck = wc_get_price_to_display( $product, array( 'price' => $state['prior'] ) );
		$sale   = wc_get_price_to_display( $product, array( 'price' => $state['sale'] ) );
		$pct    = self::percent_off( $struck, $sale, wc_get_price_decimals() );

		return $pct >= 1 ? $pct : null;
	}

	/**
	 * Text of the badge for a product on sale, or null: no badge.
	 *
	 * @param WC_Product $product Product on sale.
	 * @return string|null
	 */
	public function text_for( $product ) {
		if ( ! CX_Sidrena_Frontend::instance()->badge_allowed( $product ) ) {
			return null;
		}

		$mode = self::mode();
		if ( 'hide' === $mode ) {
			return null;
		}

		$pct = 'percent' === $mode ? $this->percent_for( $product ) : null;
		if ( null !== $pct ) {
			/* translators: %d: discount in whole percent, from the lowest price in the 30 days before the sale. */
			return sprintf( __( '−%d%%', 'cx-sidrena' ), $pct );
		}

		return __( 'Sale', 'cx-sidrena' );
	}

	/**
	 * Before every other woocommerce_sale_flash filter: catch what they echo (WoodMart prints its labels from inside
	 * the filter and returns nothing), so filter_sale_flash() sees the whole badge.
	 *
	 * @param string $html Badge HTML.
	 * @return string
	 */
	public function start_flash( $html ) {
		if ( ob_start() ) {
			$this->flash_buffers[] = ob_get_level();
		}

		return $html;
	}

	/**
	 * Classic templates: the .onsale element of the badge gets our text, or goes. Other labels in the same output stay.
	 * A product not on sale is left as it is (WoodMart calls the filter for every product, for its New label).
	 *
	 * @param string          $html    Badge HTML.
	 * @param WP_Post|mixed   $post    Post.
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	public function filter_sale_flash( $html, $post = null, $product = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$html  = (string) $html;
		$level = array_pop( $this->flash_buffers );
		if ( null !== $level && ob_get_level() === $level ) {
			$html = (string) ob_get_clean() . $html;
		}

		if ( ! $product instanceof WC_Product ) {
			$product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		}
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return $html;
		}

		return self::replace_onsale( $html, $this->text_for( $product ) );
	}

	/**
	 * Block themes: the Product Sale Badge block (product page, product collections, Product Image's badge).
	 *
	 * @param string        $content  Block HTML.
	 * @param array         $block    Parsed block.
	 * @param WP_Block|null $instance Block instance, with the product in its context.
	 * @return string
	 */
	public function filter_sale_badge_block( $content, $block, $instance = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$id      = ( $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product instanceof WC_Product || '' === (string) $content || ! $product->is_on_sale() ) {
			return $content;
		}

		$text = $this->text_for( $product );
		if ( null === $text ) {
			return '';
		}

		$out = preg_replace_callback(
			'/(<span\b[^>]*class=(["\'])[^"\']*\bwc-block-components-product-sale-badge__text\b[^"\']*\2[^>]*>)(.*?)(<\/span>)/s',
			static function ( $m ) use ( $text ) {
				return $m[1] . esc_html( $text ) . $m[4];
			},
			(string) $content,
			1
		);

		return is_string( $out ) ? $out : $content;
	}

	/**
	 * Ordo's label box, before its function runs: our text in its own text mode, or "not on sale" where no badge may
	 * stand. Hot and Sold out are drawn by the theme as always.
	 */
	public function ordo_before() {
		global $smof_data, $product;

		$this->ordo_after();
		if ( 'ordo' !== self::theme_mode() || ! is_array( $smof_data ) || ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return;
		}

		$text             = $this->text_for( $product );
		$this->ordo_saved = array(
			'as'    => array_key_exists( 'ftc_show_sale_label_as', $smof_data ) ? $smof_data['ftc_show_sale_label_as'] : null,
			'label' => array_key_exists( 'ftc_product_sale_label_text', $smof_data ) ? $smof_data['ftc_product_sale_label_text'] : null,
			'off'   => null === $text,
		);

		if ( null === $text ) {
			add_filter( 'woocommerce_product_is_on_sale', '__return_false', PHP_INT_MAX );
			return;
		}

		$smof_data['ftc_show_sale_label_as']      = 'text';
		$smof_data['ftc_product_sale_label_text'] = $text;
	}

	/**
	 * After Ordo's function: its options back as they were.
	 */
	public function ordo_after() {
		global $smof_data;

		if ( null === $this->ordo_saved ) {
			return;
		}

		$saved            = $this->ordo_saved;
		$this->ordo_saved = null;
		if ( $saved['off'] ) {
			remove_filter( 'woocommerce_product_is_on_sale', '__return_false', PHP_INT_MAX );
			return;
		}

		if ( is_array( $smof_data ) ) {
			foreach ( array( 'as' => 'ftc_show_sale_label_as', 'label' => 'ftc_product_sale_label_text' ) as $k => $key ) {
				if ( null === $saved[ $k ] ) {
					unset( $smof_data[ $key ] );
				} else {
					$smof_data[ $key ] = $saved[ $k ];
				}
			}
		}
	}

	/**
	 * A theme that draws its own badge, and the owner chose to hide it: hide it with CSS (we cannot reach its HTML).
	 */
	public function own_theme_css() {
		if ( is_admin() || 'hide' !== self::mode() || 'own' !== self::theme_mode() ) {
			return;
		}

		$template = get_template();
		$selector = isset( self::OWN_THEME_SELECTORS[ $template ] ) ? self::OWN_THEME_SELECTORS[ $template ] : '.woocommerce .onsale, .woocommerce-page .onsale';
		wp_add_inline_style( 'sidrena-cijena', $selector . '{display:none!important}' );
	}

	/**
	 * Put $text into every element with the class "onsale" (inner HTML replaced), or remove those elements when $text
	 * is null. Everything else in $html stays; an output left with no text and no image is emptied (a labels wrapper
	 * that held only the sale label). A badge without any .onsale element is markup we do not know, so for a product on
	 * sale it is replaced whole: by WooCommerce's own span with our text, or by nothing.
	 *
	 * @param string      $html Badge HTML.
	 * @param string|null $text New text, null = remove.
	 * @return string
	 */
	public static function replace_onsale( $html, $text ) {
		$html   = (string) $html;
		$offset = 0;
		$found  = false;
		while ( preg_match( '/<([a-z][a-z0-9]*)\b[^>]*\bclass\s*=\s*(["\'])(?:(?!\2).)*?(?<![\w-])onsale(?![\w-])(?:(?!\2).)*\2[^>]*>/is', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start = $m[0][1];
			$open  = $m[0][0];
			$end   = self::closing_tag_end( $html, strtolower( $m[1][0] ), $start + strlen( $open ) );
			if ( null === $end ) {
				break;
			}

			$found   = true;
			$element = null === $text ? '' : $open . esc_html( $text ) . '</' . $m[1][0] . '>';
			$html    = substr( $html, 0, $start ) . $element . substr( $html, $end );
			$offset  = $start + strlen( $element );
		}

		if ( ! $found && '' !== trim( $html ) ) {
			return null === $text ? '' : '<span class="onsale">' . esc_html( $text ) . '</span>';
		}

		if ( null === $text && '' === trim( wp_strip_all_tags( $html ) ) && false === stripos( $html, '<img' ) && false === stripos( $html, '<svg' ) ) {
			return '';
		}

		return $html;
	}

	/**
	 * Where the element opened before $from ends: the matching closing tag, counting nested ones of the same name.
	 *
	 * @param string $html HTML.
	 * @param string $tag  Lower-case tag name.
	 * @param int    $from Offset just after the opening tag.
	 * @return int|null Offset just after the closing tag.
	 */
	private static function closing_tag_end( $html, $tag, $from ) {
		$depth = 1;
		if ( ! preg_match_all( '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $from ) ) {
			return null;
		}

		foreach ( $all as $t ) {
			if ( '/' === $t[1][0] ) {
				$depth--;
			} elseif ( '/' !== substr( $t[0][0], -2, 1 ) ) {
				$depth++;
			}
			if ( 0 === $depth ) {
				return $t[0][1] + strlen( $t[0][0] );
			}
		}

		return null;
	}
}
