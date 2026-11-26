<?php
/**
 * Storefront display of sidrena cijena.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-badge.php';

/**
 * Frontend renderer.
 */
class CX_Sidrena_Frontend {

	/**
	 * Class of our caption and bazna line when they stand after a theme's price wrapper: frontend.css gives each a row of
	 * its own where the wrapper is in a flex row (S8's product page), as inside WooCommerce's p.price (CX-SID-93).
	 */
	const OWN_ROW = 'cx-sidrena-own-row';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether text-vars.js is already on the page.
	 *
	 * @var bool
	 */
	private $text_vars_printed = false;

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
	 * Register storefront hooks.
	 */
	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'sidrena_cijena', array( $this, 'render_action' ), 10, 1 );
		add_shortcode( 'sidrena-cijena', array( $this, 'shortcode' ) );
		add_shortcode( 'sidrena_cijena', array( $this, 'shortcode' ) );
		add_shortcode( 'sidrena-cjenik', array( $this, 'cjenik_shortcode' ) );
		add_shortcode( 'sidrena_cjenik', array( $this, 'cjenik_shortcode' ) );
		add_shortcode( 'sidrena-arhiva', array( $this, 'arhiva_shortcode' ) );
		add_shortcode( 'sidrena_arhiva', array( $this, 'arhiva_shortcode' ) );

		add_action( 'wp_footer', array( $this, 'render_cjenik_link' ) );
		// The shop's text colour and font for our lines (CX-SID-41): right after <body>, or first thing in the footer for a
		// theme without wp_body_open.
		add_action( 'wp_body_open', array( $this, 'print_text_vars' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_text_vars' ), 1 );

		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		// One block per price, built in one place (spec §2.3): WooCommerce's price (on a sale the regular price struck, then
		// the sale price), the lowest price in 30 days with its amount, the unit price, the bazna line. Always on, because the lowest price is required even where the owner turned the automatic
		// bazna line off; the bazna part checks that setting itself.
		add_filter( 'woocommerce_get_price_html', array( $this, 'price_block' ), 9999, 2 );
		// The sale badge: on every sale, a percentage from the regular price WooCommerce strikes (CX-SID-179, CX-SID-26).
		CX_Sidrena_Badge::instance();
		// A chosen size's unit price (CX-SID-38) for the case where WooCommerce shows no price box for it (every size costs
		// the same): frontend.js puts it into the parent's block. Always, as the caption: not part of the bazna line.
		add_filter( 'woocommerce_available_variation', array( $this, 'add_unit_data' ), 12, 3 );
		// A chosen size's lowest price in 30 days (CX-SID-179), for the same case: frontend.js puts its amount into the
		// parent's line, which names the lowest of the sizes.
		add_filter( 'woocommerce_available_variation', array( $this, 'add_n30_data' ), 11, 3 );

		if ( CX_Sidrena_Settings::instance()->is_auto_display_enabled() ) {
			add_filter( 'render_block_woocommerce/product-price', array( $this, 'append_to_price_block' ), 20, 3 );
			add_filter( 'elementor/widget/render_content', array( $this, 'append_to_elementor_widget' ), 20, 2 );
			add_filter( 'woocommerce_available_variation', array( $this, 'add_variation_data' ), 10, 3 );
		}
	}

	/**
	 * Frontend CSS / JS.
	 */
	public function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'sidrena-cijena',
			CX_SIDRENA_URL . 'assets/css/frontend.css',
			array(),
			CX_SIDRENA_VERSION
		);

		// The owner's own colour for both lines (Settings, CX-SID-41): with the stylesheet, so it holds before and without
		// JavaScript. A #rrggbb value, checked again on reading.
		$own = CX_Sidrena_Settings::instance()->line_color();
		if ( '' !== $own ) {
			wp_add_inline_style( 'sidrena-cijena', ':root{--cx-sidrena-own:' . $own . ';}' );
		}

		if ( $this->content_has_cjenik_shortcode() ) {
			$this->enqueue_cjenik_embed_style();
		}

		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		// Also without the automatic bazna line: the script hides a variable parent's caption when a size is chosen and
		// checks the contrast of the lines. It inserts the bazna line into page builders only when given its HTML.
		$auto = CX_Sidrena_Settings::instance()->is_auto_display_enabled();

		// The same text-vars.js as printed after <body>, again as a file at the end of the page: it then also sees a
		// stylesheet printed later in the page, and it runs where a security policy (CSP) blocks inline scripts.
		wp_enqueue_script(
			'cx-sidrena-text-vars',
			CX_SIDRENA_URL . 'assets/js/text-vars.js',
			array(),
			CX_SIDRENA_VERSION,
			true
		);

		wp_enqueue_script(
			'sidrena-cijena',
			CX_SIDRENA_URL . 'assets/js/frontend.js',
			array( 'jquery', 'cx-sidrena-text-vars' ),
			CX_SIDRENA_VERSION,
			true
		);

		// The page's line, for page builders that print the price without WooCommerce's filter: only on a product's own
		// page. On a list page the "current product" is just the first in the loop, and its line ended up on other
		// products' cards (Argus CX-SID-25 B1).
		$product = is_singular( 'product' ) ? $this->current_product() : null;
		wp_localize_script(
			'sidrena-cijena',
			'sidrenaCijenaFront',
			array(
				'html'           => ( $auto && $product ) ? $this->get_html_for_product( $product ) : '',
				// Whose line it is: frontend.js puts it only into that product's own price (CX-SID-57).
				'productId'      => $product ? $product->get_id() : 0,
				'selectors'      => CX_Sidrena_Compat::price_selectors(),
				'watchRoots'     => CX_Sidrena_Compat::watch_roots(),
				'hasLowestPrice' => CX_Sidrena_Compat::lowest_price_plugin_active(),
				// Set: frontend.js leaves the lines' colour as the owner chose it (no contrast steps).
				'ownColor'       => $own,
			)
		);
	}

	/**
	 * assets/js/text-vars.js inline, once per page: it reads the colour and font of <body> into the custom properties
	 * frontend.css gives our lines. Inline, so they are set before the first line is drawn.
	 */
	public function print_text_vars() {
		if ( $this->text_vars_printed || is_admin() ) {
			return;
		}
		$this->text_vars_printed = true;

		$js = file_get_contents( CX_SIDRENA_PATH . 'assets/js/text-vars.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $js ) {
			return;
		}

		// The file's opening comment is for whoever reads the file, not for every page.
		wp_print_inline_script_tag( trim( (string) preg_replace( '#^/\*.*?\*/#s', '', $js ) ), array( 'id' => 'cx-sidrena-text-vars' ) );
	}

	/**
	 * Append sidrena line after WooCommerce price HTML.
	 *
	 * @param string     $price_html Price HTML.
	 * @param WC_Product $product    Product.
	 * @param bool       $own_row    The price is in a theme's wrapper (CX-SID-93): the line gets a row of its own.
	 * @return string
	 */
	public function append_to_price_html( $price_html, $product, $own_row = false ) {
		if ( ! CX_Sidrena_Settings::instance()->is_auto_display_enabled() ) {
			return $price_html;
		}

		if ( is_admin() && ! wp_doing_ajax() && ! $this->is_elementor_preview() ) {
			return $price_html;
		}

		if ( ! $product instanceof WC_Product ) {
			return $price_html;
		}

		if ( '' === $price_html ) {
			return $price_html;
		}

		if ( false !== strpos( $price_html, 'sidrena-cijena' ) ) {
			return $price_html;
		}

		$html = $this->get_html_for_product( $product );
		if ( '' === $html ) {
			return $price_html;
		}

		if ( $own_row ) {
			$html = CX_Sidrena_Compat::add_class( $html, self::OWN_ROW );
		}

		// Never into an element with the class price: in this HTML that is a theme's wrapper around the whole price, and
		// the line goes after it (CX-SID-93). WooCommerce's own price HTML has no such element, so there nothing changes.
		return CX_Sidrena_Compat::merge_price_html( $price_html, $html, false );
	}

	/**
	 * The whole price block of one product: the price as WooCommerce and the theme print it (on a sale: the lowest price
	 * in 30 days struck), then the caption, the form's line and the unit price, then the bazna line. One filter, so a page
	 * never shows a line twice.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function price_block( $html, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		// A theme that wraps the whole price in its own element with the class price (S8's: div.price, at 999) gets the
		// caption and the bazna line after its wrapper, each on a row of its own: never inside it, where the first closing
		// tag is the currency symbol's and on a sale the struck amount's <del aria-hidden> (CX-SID-93). With another
		// lowest-price plugin the line keeps its place at that plugin's line, as before.
		$own_row = CX_Sidrena_Compat::has_price_element( $html ) && ! CX_Sidrena_Compat::lowest_price_plugin_active();

		return $this->append_to_price_html( $this->lowest_part( $html, $product, $own_row ), $product, $own_row );
	}

	/**
	 * The price part of the block, then the unit price (CX-SID-38, Norman SPEC §3.1): after the price and the lowest-price
	 * line, before the bazna line that append_to_price_html() adds. Merged as that line is, so beside another lowest-price
	 * plugin's line it stands before it, and the bazna line after ours.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @param bool       $own_row The lines stand after a theme's price wrapper (CX-SID-93).
	 * @return string
	 */
	private function lowest_part( $html, $product, $own_row = false ) {
		return CX_Sidrena_Compat::merge_price_html( $this->sale_part( $html, $product, $own_row ), $this->unit_line( $product, $own_row ), false );
	}

	/**
	 * A sale as the owner set it (CX-SID-179, Hrvoje 20.11.2026): WooCommerce's price stays as it is (the owner's regular
	 * price struck, then the sale price), and under it the lowest price in the 30 days before the sale with its amount
	 * (ZZP art. 19(4)) while our line is on and no other lowest-price plugin draws one. Whether the sale price is below that
	 * lowest price is the owner's responsibility: the customer sees every sale as a sale, and Status reminds the owner.
	 * A variable product whose sizes have different prices strikes nothing and has no line until a size is chosen (the
	 * size's own block has it); a grouped product neither.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @param bool       $own_row The line stands after a theme's price wrapper (CX-SID-93).
	 * @return string
	 */
	private function sale_part( $html, $product, $own_row = false ) {
		$state = $this->sale_state( $product );
		if ( null === $state || 'sale' !== $state['kind'] || ! self::lowest_line_on() || '' === $state['low'] ) {
			return $html;
		}

		return $html . $this->lowest_line( $product, $state, $own_row );
	}

	/**
	 * Whether our lowest-price line is drawn: the shop shows it (cx_sidrena_n30_display, Settings, CX-SID-63) and no other
	 * lowest-price plugin draws its own.
	 *
	 * @return bool
	 */
	public static function lowest_line_on() {
		return function_exists( 'cx_sidrena_n30_display_enabled' ) && cx_sidrena_n30_display_enabled() && ! CX_Sidrena_Compat::lowest_price_plugin_active();
	}

	/**
	 * "Najniža cijena u 30 dana prije sniženja: X" under the price. Text, never a tooltip (a tooltip does not work on a
	 * phone). X on the basis the shop shows its prices on, as WooCommerce's struck amount (CX-SID-160). A variable parent
	 * at one price names the lowest of its sizes' amounts, or "X – Y" when they differ; frontend.js puts the chosen
	 * size's own amount in its place (cx_n30_html). The class is kept from the bazna add-on: themes may style it.
	 *
	 * @param WC_Product $product Product or size.
	 * @param array      $state   sale_state(), kind "sale".
	 * @param bool       $own_row After a theme's price wrapper: a row of its own (CX-SID-93).
	 * @return string
	 */
	private function lowest_line( $product, $state, $own_row = false ) {
		$amount = wc_price( wc_get_price_to_display( $product, array( 'price' => $state['low'] ) ) );
		if ( abs( (float) $state['high'] - (float) $state['low'] ) > 0.0001 ) {
			$amount .= ' – ' . wc_price( wc_get_price_to_display( $product, array( 'price' => $state['high'] ) ) );
		}
		$text = sprintf(
			/* translators: %s: the lowest price in the 30 days before the sale; on a product whose sizes have different ones, the lowest and the highest ("X – Y"). */
			esc_html__( 'Lowest price in the 30 days before the discount: %s', 'cx-sidrena' ),
			$amount
		);

		return '<span class="bazna-najniza30' . ( $product->is_type( 'variable' ) ? ' bazna-najniza30--variable' : '' ) . ( $own_row ? ' ' . self::OWN_ROW : '' ) . '" style="display:block">'
			. $text . '</span>';
	}

	/**
	 * The unit price under the price on the product's own page (CX-SID-38, Norman SPEC §3): "19,95 €/l", from the one
	 * unit_price() the price list uses, so the two never differ. With a screen reader's label before it, hidden on
	 * screen. None where unit_price() says the page does not show it (under 50 g or 50 ml, or equal to the price), for a
	 * variable product before a size is chosen, or anywhere but the product's own page. Its class must not contain
	 * "sidrena-cijena": the bazna line is added only to price HTML without that word (append_to_price_html()).
	 *
	 * @param WC_Product $product Product or size.
	 * @param bool       $own_row After a theme's price wrapper: a row of its own (CX-SID-93).
	 * @return string
	 */
	public function unit_line( $product, $own_row = false ) {
		if ( ! $product instanceof WC_Product || ! $this->unit_on_page( $product ) ) {
			return '';
		}

		$meta = CX_Sidrena_Product_Meta::instance();
		$unit = $meta->unit_price( $product );
		if ( null === $unit || ! $unit['page'] ) {
			return '';
		}

		return '<span class="cx-sidrena-jm' . ( $own_row ? ' ' . self::OWN_ROW : '' ) . '">'
			. '<span class="cx-sidrena-sr">' . esc_html__( 'Unit price:', 'cx-sidrena' ) . ' </span>'
			. $meta->unit_price_html( $unit ) . '</span>';
	}

	/**
	 * Whether the unit price is drawn here (Norman SPEC §3.2): only on the product's own page, for it or its chosen size,
	 * also when WooCommerce fetches that size by ajax (more than 30 sizes). Not on cards, related products, a quick view,
	 * the cart or the Store API.
	 *
	 * @param WC_Product $product Product or size.
	 * @return bool
	 */
	private function unit_on_page( $product ) {
		if ( $product->is_type( 'variation' ) && wp_doing_ajax() && isset( $_GET['wc-ajax'] ) && 'get_variation' === $_GET['wc-ajax'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		return is_singular( 'product' ) && $this->is_queried_product( $product );
	}

	/**
	 * A size's unit price for the product's page (cx_jm_html): frontend.js puts it into the parent's block when the size
	 * has no price box of its own (every size costs the same). Same price and another quantity is another unit price, so
	 * the parent's text can never stand in for it (Norman SPEC §3.2).
	 *
	 * @param array                $data      Variation data.
	 * @param WC_Product           $product   Parent.
	 * @param WC_Product_Variation $variation Size.
	 * @return array
	 */
	public function add_unit_data( $data, $product, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$data['cx_jm_html'] = $variation instanceof WC_Product ? $this->unit_line( $variation ) : '';

		return $data;
	}

	/**
	 * A size's lowest-price line for its product's page (cx_n30_html): frontend.js puts it into the parent's line when the
	 * size is chosen and WooCommerce shows no price box for it (every size costs the same; the parent's line names the
	 * lowest of the sizes, or their range). '' where the size is not on sale or our line is off.
	 *
	 * @param array                $data      Variation data.
	 * @param WC_Product           $product   Parent.
	 * @param WC_Product_Variation $variation Size.
	 * @return array
	 */
	public function add_n30_data( $data, $product, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$data['cx_n30_html'] = $variation instanceof WC_Product ? $this->sale_part( '', $variation ) : '';

		return $data;
	}

	/**
	 * How a product on sale is shown, or null where WooCommerce's sale stays as it is with nothing of ours: not on sale,
	 * no price, or another lowest-price plugin that draws its own line.
	 *
	 * @param WC_Product $product Product.
	 * @return array{kind:string,regular:string,sale:string,low:string,high:string}|null kind: "sale" (WooCommerce strikes
	 *         the regular price; low and high: the lowest price in the 30 days before the sale, for a variable product the
	 *         lowest and the highest of its sizes on sale, otherwise the same amount), or "range" (sizes or grouped items
	 *         with different prices: nothing is struck, no line and no badge; only while our line is on, as in 0.3.0).
	 */
	public function sale_state( $product ) {
		if ( ! $product instanceof WC_Product || ! function_exists( 'cx_sidrena_n30_prior' ) || ! $product->is_on_sale() ) {
			return null;
		}

		if ( CX_Sidrena_Compat::lowest_price_plugin_active() ) {
			return null;
		}

		if ( $product->is_type( 'grouped' ) ) {
			return self::lowest_line_on() ? self::range_state() : null;
		}

		if ( '' === (string) $product->get_price() ) {
			return null;
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->variable_sale_state( $product );
		}

		$low = (string) cx_sidrena_n30_prior( $product );

		return array(
			'kind'    => 'sale',
			'regular' => (string) $product->get_regular_price(),
			// Sale price, not get_price(): between a scheduled start date and WooCommerce's cron run _price is still the old one.
			'sale'    => (string) $product->get_sale_price(),
			'low'     => $low,
			'high'    => $low,
		);
	}

	/**
	 * A variable product, its sizes priced differently: nothing struck, no line, no badge (0.3.0, spec §2.4).
	 *
	 * @return array{kind:string,regular:string,sale:string,low:string,high:string}
	 */
	private static function range_state() {
		return array( 'kind' => 'range', 'regular' => '', 'sale' => '', 'low' => '', 'high' => '' );
	}

	/**
	 * Variable parent on sale. One price for every size: WooCommerce strikes the regular price on the parent, and the line
	 * names the lowest prices of the sizes on sale (the lowest and the highest of them). Otherwise a range.
	 *
	 * @param WC_Product_Variable $product Parent.
	 * @return array{kind:string,regular:string,sale:string,low:string,high:string}|null
	 */
	private function variable_sale_state( $product ) {
		$lows = array();
		$one  = null;
		foreach ( $product->get_visible_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation && $variation->is_on_sale() && $variation->is_purchasable() ) {
				$lows[] = (string) cx_sidrena_n30_prior( $variation );
				$one    = array( (string) $variation->get_regular_price(), (string) $variation->get_sale_price() );
			}
		}

		if ( ! $lows ) {
			return null;
		}

		$prices = $product->get_variation_prices( true );
		$single = $prices['price'] && min( $prices['price'] ) === max( $prices['price'] ) && min( $prices['regular_price'] ) === max( $prices['regular_price'] );
		if ( ! $single ) {
			return self::lowest_line_on() ? self::range_state() : null;
		}

		$low  = $lows[0];
		$high = $lows[0];
		foreach ( $lows as $l ) {
			if ( (float) $l < (float) $low ) {
				$low = $l;
			}
			if ( (float) $l > (float) $high ) {
				$high = $l;
			}
		}

		return array(
			'kind'    => 'sale',
			'regular' => $one[0],
			'sale'    => $one[1],
			'low'     => $low,
			'high'    => $high,
		);
	}

	/**
	 * The sale badge stands on every sale (CX-SID-179): the customer sees each sale as a sale, also one whose price is not
	 * below the lowest in 30 days. Not on a parent or a card that strikes nothing (sizes with different prices) while our
	 * line is on, as in 0.3.0.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public function badge_allowed( $product ) {
		$state = $this->sale_state( $product );

		return null === $state || 'sale' === $state['kind'];
	}

	/**
	 * A small "Cjenik" link at the bottom of every shop page (spec §2.6): inspectors and price comparison services need
	 * to find the price list. On by default (setting cjenik_link); the public page and the files exist either way.
	 */
	public function render_cjenik_link() {
		if ( is_admin() || 'no' === CX_Sidrena_Settings::instance()->get( 'cjenik_link' ) || ! class_exists( 'CX_Sidrena_Feed_Files' ) ) {
			return;
		}

		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return;
		}

		// Not before the first price list (spec §2.8): until then the page only says it is being prepared.
		if ( ! $this->cjenik_published() ) {
			return;
		}

		printf(
			'<p class="cx-sidrena-cjenik-link"><a href="%1$s">%2$s</a></p>',
			esc_url( CX_Sidrena_Feed_Files::instance()->get_cjenik_url() ),
			esc_html__( 'Price list', 'cx-sidrena' )
		);
	}

	/**
	 * Whether the public price list has been written with at least one row. Reads only the count comment on the first
	 * line of the HTML snapshot (CX_Sidrena_Feed_Files::write_cjenik_html), once per page.
	 *
	 * @return bool
	 */
	private function cjenik_published() {
		$path = CX_Sidrena_Feed_Files::instance()->get_cjenik_html_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$first = (string) fgets( $fh, 128 );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return (bool) preg_match( '/<!-- sidrena-count:([1-9]\d*) -->/', $first );
	}

	/**
	 * Append sidrena line inside Elementor / AnyWhere Elementor product price widgets.
	 *
	 * @param string                 $content Widget HTML.
	 * @param \Elementor\Widget_Base $widget  Widget instance.
	 * @return string
	 */
	public function append_to_elementor_widget( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
			return $content;
		}

		if ( 'woocommerce-product-price' !== $widget->get_name() ) {
			return $content;
		}

		if ( '' === $content || false !== strpos( $content, 'sidrena-cijena' ) ) {
			return $content;
		}

		$html = $this->get_html_for_context();
		if ( '' === $html ) {
			return $content;
		}

		return CX_Sidrena_Compat::merge_price_html( $content, $html );
	}

	/**
	 * Inject sidrena HTML into variation JSON.
	 *
	 * @param array                $data      Variation payload.
	 * @param WC_Product_Variable  $product   Parent.
	 * @param WC_Product_Variation $variation Variation.
	 * @return array
	 */
	public function add_variation_data( $data, $product, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$html                         = $this->get_html_for_product( $variation );
		$data['sidrena_cijena_html'] = $html;

		if ( '' === $html ) {
			return $data;
		}

		$price_html = isset( $data['price_html'] ) ? (string) $data['price_html'] : '';
		if ( false !== strpos( $price_html, 'sidrena-cijena' ) ) {
			return $data;
		}

		/*
		 * WooCommerce leaves price_html empty when every remaining in-stock variation has the same price (selecting
		 * 1000 ml after 200 ml is out of stock). The parent above then already shows that price, struck with its
		 * caption when on sale, and stays one block: frontend.js leaves the parent's bazna line as it is when the size's
		 * line says the same, or puts the size's line in its place, inside the parent's price (Norman, CX-SID-25: under
		 * the size select it stood apart from its price and caption, and smaller). Its whole price again would put a
		 * second struck amount on the page.
		 */
		if ( '' === trim( $price_html ) ) {
			$data['sidrena_cijena_inplace'] = true;
			return $data;
		}

		if ( false === strpos( $price_html, 'sidrena-cijena' ) ) {
			$price_html = CX_Sidrena_Compat::merge_price_html( $price_html, $html );
		}

		$data['price_html'] = $price_html;

		return $data;
	}

	/**
	 * Append sidrena HTML to the WooCommerce product-price block when missing.
	 *
	 * @param string        $content  Block HTML.
	 * @param array         $block    Block data.
	 * @param WP_Block|null $instance Block instance: its context names the product of a card in a product list.
	 * @return string
	 */
	public function append_to_price_block( $content, $block, $instance = null ) {
		if ( '' === $content || false !== strpos( $content, 'sidrena-cijena' ) ) {
			return $content;
		}

		$product = $this->product_from_block( $block, $instance );
		if ( ! $product ) {
			return $content;
		}

		return CX_Sidrena_Compat::merge_price_html( $content, $this->get_html_for_product( $product ) );
	}

	/**
	 * Universal theme hook: do_action( 'sidrena_cijena' ) or do_action( 'sidrena_cijena', $product ).
	 *
	 * @param WC_Product|int|null $product Product, ID, or current product.
	 */
	public function render_action( $product = null ) {
		echo wp_kses_post( $this->get_html_for_context( $product ) );
	}

	/**
	 * Resolve a product from the current context and return HTML.
	 *
	 * @param WC_Product|int|null $product Product, ID, or null.
	 * @return string
	 */
	public function get_html_for_context( $product = null ) {
		if ( CX_Sidrena_Plugin::woocommerce_active() && $product instanceof WC_Product ) {
			return $this->get_html_for_product( $product );
		}

		if ( is_numeric( $product ) && $product ) {
			if ( CX_Sidrena_Plugin::woocommerce_active() ) {
				$resolved = wc_get_product( $product );
				if ( $resolved instanceof WC_Product && $this->explicit_product_allowed( $resolved ) ) {
					return $this->get_html_for_product( $resolved );
				}

				if ( $resolved instanceof WC_Product ) {
					return '';
				}
			}

			$row = CX_Sidrena_Catalog::instance()->get( $product );
			return ( $row && CX_Sidrena_Catalog::instance()->is_public_row( $row ) ) ? $this->get_html_for_catalog_row( $row ) : '';
		}

		$resolved = $this->current_product();
		return $resolved ? $this->get_html_for_product( $resolved ) : '';
	}

	/**
	 * Current product from WooCommerce globals or the queried object.
	 *
	 * @return WC_Product|null
	 */
	private function current_product() {
		global $product;

		if ( $product instanceof WC_Product ) {
			return $product;
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$resolved = wc_get_product( get_the_ID() );
			if ( $resolved instanceof WC_Product ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Elementor editor / preview request.
	 *
	 * @return bool
	 */
	private function is_elementor_preview() {
		if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		return isset( $_GET['action'] ) && 'elementor' === $_GET['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Product of a block: its own attribute, else the block instance's context (the product of each card in a product
	 * list, Argus CX-SID-25 B1). The page's post only for a caller without an instance: inside a product list it is
	 * another product (the page or the previous card), whose line would show on this card.
	 *
	 * @param array         $block    Parsed block.
	 * @param WP_Block|null $instance Block instance.
	 * @return WC_Product|null
	 */
	public function product_from_block( $block, $instance = null ) {
		$product_id = 0;

		if ( ! empty( $block['attrs']['productId'] ) ) {
			$product_id = absint( $block['attrs']['productId'] );
		}

		if ( ! $product_id && $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) ) {
			$product_id = absint( $instance->context['postId'] );
		}

		if ( ! $product_id && ! empty( $block['context']['postId'] ) ) {
			$product_id = absint( $block['context']['postId'] );
		}

		if ( ! $product_id && ! $instance instanceof WP_Block ) {
			$product_id = get_the_ID();
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * [sidrena-cjenik] prints the searchable pricelist table.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function cjenik_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'oznaka' => '',
			),
			$atts,
			'sidrena-cjenik'
		);

		$this->enqueue_cjenik_embed_style();
		self::enqueue_cjenik_search_script();
		self::enqueue_cjenik_fit_script();

		return CX_Sidrena_Export::instance()->get_cjenik_embed_html( (string) $atts['oznaka'] );
	}

	/**
	 * [sidrena-arhiva] prints the archive file names and dates.
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function arhiva_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'oznaka' => '',
			),
			$atts,
			'sidrena-arhiva'
		);

		$this->enqueue_archive_embed_style();
		CX_Sidrena_Page_Cache::shortcode_shown( false ); // cleared with the page after each build (CX-SID-141)

		return CX_Sidrena_Export::instance()->get_archive_embed_html( (string) $atts['oznaka'] );
	}

	/**
	 * Whether the current post contains the table shortcode.
	 *
	 * @return bool
	 */
	private function content_has_cjenik_shortcode() {
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'sidrena-cjenik' ) || has_shortcode( $post->post_content, 'sidrena_cjenik' );
	}

	/**
	 * Table stylesheet. Printed in place when the shortcode runs after wp_head.
	 */
	private function enqueue_cjenik_embed_style() {
		$handle = 'sidrena-cijena-cjenik-embed';
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style(
				$handle,
				CX_SIDRENA_URL . 'assets/css/cjenik-embed.css',
				array(),
				CX_SIDRENA_VERSION
			);
		}

		wp_enqueue_style( $handle );

		if ( did_action( 'wp_head' ) && ! wp_style_is( $handle, 'done' ) ) {
			wp_print_styles( $handle );
		}
	}

	/**
	 * The price list's search and pages (CX-SID-111, assets/js/cjenik-search.js), at the end of the page, before
	 * cjenik-fit.js. A file and not a script inside the price list's HTML: a block theme (and Elementor's Text Editor)
	 * runs wptexturize() over the page after the shortcode, and that broke the script ("&&" became "&#038;&#038;").
	 * The end of the page because the shortcode runs inside the content, after wp_head, so a script it queues can only
	 * come out in wp_footer. Without it the first page of rows shows, without search.
	 */
	public static function enqueue_cjenik_search_script() {
		$handle = 'sidrena-cijena-cjenik-search';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, CX_SIDRENA_URL . 'assets/js/cjenik-search.js', array(), CX_SIDRENA_VERSION, true );
		}

		wp_enqueue_script( $handle );
	}

	/**
	 * The same search script printed at once, for the public price list page (it prints its own HTML, without wp_footer).
	 */
	public static function print_cjenik_search_script() {
		self::enqueue_cjenik_search_script();
		wp_print_scripts( 'sidrena-cijena-cjenik-search' );
	}

	/**
	 * The price list's script that shows a table as a table where it fits in its frame and as cards where it does not
	 * (CX-SID-104, assets/js/cjenik-fit.js), at the end of the page. Without it the stylesheet alone holds.
	 */
	public static function enqueue_cjenik_fit_script() {
		$handle = 'sidrena-cijena-cjenik-fit';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script( $handle, CX_SIDRENA_URL . 'assets/js/cjenik-fit.js', array(), CX_SIDRENA_VERSION, true );
		}

		wp_enqueue_script( $handle );
	}

	/**
	 * The same script printed at once, for the public price list page (it prints its own HTML, without wp_footer).
	 */
	public static function print_cjenik_fit_script() {
		self::enqueue_cjenik_fit_script();
		wp_print_scripts( 'sidrena-cijena-cjenik-fit' );
	}

	/**
	 * Archive list stylesheet. Printed in place when the shortcode runs after wp_head.
	 */
	private function enqueue_archive_embed_style() {
		$handle = 'sidrena-cijena-arhiva-embed';
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style(
				$handle,
				CX_SIDRENA_URL . 'assets/css/archive-embed.css',
				array(),
				CX_SIDRENA_VERSION
			);
		}

		wp_enqueue_style( $handle );

		if ( did_action( 'wp_head' ) && ! wp_style_is( $handle, 'done' ) ) {
			wp_print_styles( $handle );
		}
	}

	/**
	 * [sidrena-cijena] shortcode. Woo product loops, or standalone catalog id="123".
	 *
	 * @param array<string, mixed> $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'prikaz' => '',
			),
			$atts,
			'sidrena-cijena'
		);

		$prikaz = $this->normalize_prikaz( isset( $atts['prikaz'] ) ? $atts['prikaz'] : '' );
		$raw_id = isset( $atts['id'] ) ? trim( (string) $atts['id'] ) : '';
		if ( preg_match( '/^d(\d+)$/i', $raw_id, $match ) ) {
			$row = CX_Sidrena_Catalog::instance()->get( $match[1] );
			if ( ! $row || ! CX_Sidrena_Catalog::instance()->is_public_row( $row ) ) {
				return ''; // a service not published yet shows nothing (CX-SID-130)
			}

			return '' !== $prikaz ? $this->catalog_plain_amount( $row, $prikaz ) : $this->get_html_for_catalog_row( $row );
		}

		$item_id = absint( $raw_id );

		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			if ( $item_id ) {
				$product = wc_get_product( $item_id );
			} else {
				$product = $this->current_product();
				if ( ! $product ) {
					$product = wc_get_product( get_the_ID() );
				}
			}

			if ( $product instanceof WC_Product ) {
				if ( $item_id && ! $this->explicit_product_allowed( $product ) ) {
					return '';
				}

				return '' !== $prikaz ? $this->product_plain_amount( $product, $prikaz ) : $this->get_html_for_product( $product );
			}
		}

		if ( ! $item_id ) {
			return '';
		}

		$row = CX_Sidrena_Catalog::instance()->get( $item_id );
		if ( ! $row || ! CX_Sidrena_Catalog::instance()->is_public_row( $row ) ) {
			return '';
		}

		return '' !== $prikaz ? $this->catalog_plain_amount( $row, $prikaz ) : $this->get_html_for_catalog_row( $row );
	}

	/**
	 * Allowed prikaz values. Empty keeps the full price block.
	 *
	 * @param mixed $value Raw attribute.
	 * @return string redovna|akcijska|sidrena|''
	 */
	private function normalize_prikaz( $value ) {
		$value = sanitize_key( (string) $value );

		return in_array( $value, array( 'redovna', 'akcijska', 'sidrena' ), true ) ? $value : '';
	}

	/**
	 * One catalog amount, without label or tooltip.
	 *
	 * @param array<string, string> $row    Catalog row.
	 * @param string                $prikaz redovna|akcijska|sidrena.
	 * @return string
	 */
	private function catalog_plain_amount( $row, $prikaz ) {
		$keys = array(
			'redovna'  => 'cijena_redovna',
			'akcijska' => 'cijena_akcijska',
			'sidrena'  => 'sidrena_cijena',
		);
		$raw  = isset( $row[ $keys[ $prikaz ] ] ) ? trim( (string) $row[ $keys[ $prikaz ] ] ) : '';
		if ( '' === $raw ) {
			return '';
		}

		return esc_html( CX_Sidrena_Catalog::instance()->format_amount( $raw ) );
	}

	/**
	 * One WooCommerce amount, without label or tooltip.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $prikaz  redovna|akcijska|sidrena.
	 * @return string
	 */
	private function product_plain_amount( $product, $prikaz ) {
		if ( ! $product instanceof WC_Product || ! $this->viewer_may_see_product( $product ) || $product->is_type( 'grouped' ) ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->variable_plain_amount( $product, $prikaz );
		}

		$amount = $this->product_display_amount( $product, $prikaz );
		if ( null === $amount ) {
			return '';
		}

		return $this->format_plain_price( $amount );
	}

	/**
	 * Lowest variation amount. A range is prefixed with "od".
	 *
	 * @param WC_Product $product Variable product.
	 * @param string     $prikaz  redovna|akcijska|sidrena.
	 * @return string
	 */
	private function variable_plain_amount( $product, $prikaz ) {
		$child_ids = method_exists( $product, 'get_visible_children' ) ? $product->get_visible_children() : $product->get_children();
		$amounts   = array();

		foreach ( $child_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_purchasable() || ! $this->viewer_may_see_product( $variation ) ) {
				continue;
			}

			$display = $this->product_display_amount( $variation, $prikaz );
			if ( null === $display ) {
				continue;
			}

			$amounts[] = $display;
		}

		if ( empty( $amounts ) ) {
			return '';
		}

		$min  = min( $amounts );
		$text = $this->format_plain_price( $min );
		if ( $min === max( $amounts ) ) {
			return $text;
		}

		return sprintf(
			/* translators: %s formatted price */
			esc_html__( 'from %s', 'cx-sidrena' ),
			$text
		);
	}

	/**
	 * Display amount for one simple product or variation.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $prikaz  redovna|akcijska|sidrena.
	 * @return float|null
	 */
	private function product_display_amount( $product, $prikaz ) {
		if ( 'sidrena' === $prikaz ) {
			$meta = CX_Sidrena_Product_Meta::instance();
			$raw  = $meta->get_price( $meta->display_id( $product->get_id() ) );
			if ( '' === $raw ) {
				return null;
			}

			return $meta->get_price_to_display( $product, $raw );
		}

		$raw = 'akcijska' === $prikaz ? (string) $product->get_sale_price() : (string) $product->get_regular_price();
		if ( '' === trim( $raw ) ) {
			return null;
		}

		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return (float) wc_get_price_to_display(
				$product,
				array(
					'price' => (float) $raw,
				)
			);
		}

		return (float) $raw;
	}

	/**
	 * Shop-formatted amount as plain text.
	 *
	 * @param float $amount Display amount.
	 * @return string
	 */
	private function format_plain_price( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			$text = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );

			return esc_html( trim( $text ) );
		}

		return esc_html( CX_Sidrena_Catalog::instance()->format_amount( (string) $amount ) );
	}

	/**
	 * Sidrena line plus current price for a standalone catalog row.
	 *
	 * @param array<string, string> $row Catalog row.
	 * @return string
	 */
	public function get_html_for_catalog_row( $row ) {
		if ( ! is_array( $row ) || '' === (string) $row['sidrena_cijena'] ) {
			return '';
		}

		$settings = CX_Sidrena_Settings::instance();
		$catalog  = CX_Sidrena_Catalog::instance();
		$line     = $this->render_line(
			$settings->label_for_catalog_row( $row ),
			esc_html( $catalog->format_amount( $row['sidrena_cijena'] ) ),
			$settings->is_tooltip_enabled() ? $settings->get_translated( 'tooltip' ) : '',
			true
		);

		$price_html = $this->catalog_current_price_html( $row );
		if ( '' === $price_html ) {
			return $line;
		}

		return '<span class="sidrena-cijena-item">' . $price_html . $line . '</span>';
	}

	/**
	 * Regular / sale amount for a catalog row (plain formatted euros).
	 *
	 * @param array<string, string> $row Catalog row.
	 * @return string
	 */
	private function catalog_current_price_html( $row ) {
		$catalog = CX_Sidrena_Catalog::instance();
		$regular = isset( $row['cijena_redovna'] ) ? (string) $row['cijena_redovna'] : '';
		$sale    = isset( $row['cijena_akcijska'] ) ? (string) $row['cijena_akcijska'] : '';

		if ( '' === $regular && '' === $sale ) {
			return '';
		}

		if ( '' !== $sale && $sale !== $regular ) {
			$html = '<span class="sidrena-cijena-item__price">';
			if ( '' !== $regular ) {
				$html .= '<del>' . esc_html( $catalog->format_amount( $regular ) ) . '</del> ';
			}
			$html .= '<ins>' . esc_html( $catalog->format_amount( $sale ) ) . '</ins>';
			$html .= '</span>';

			return $html;
		}

		$amount = '' !== $sale ? $sale : $regular;

		return '<span class="sidrena-cijena-item__price">' . esc_html( $catalog->format_amount( $amount ) ) . '</span>';
	}

	/**
	 * Render markup for a product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function get_html_for_product( $product ) {
		if ( ! $product instanceof WC_Product || ! $this->viewer_may_see_product( $product ) ) {
			return '';
		}

		$settings = CX_Sidrena_Settings::instance();
		$meta     = CX_Sidrena_Product_Meta::instance();

		if ( $product->is_type( 'grouped' ) ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->get_html_for_variable( $product );
		}

		$stored_id = $meta->display_id( $product->get_id() );
		$price     = $meta->get_price( $stored_id );
		if ( '' === $price ) {
			return '';
		}

		return $this->render_line(
			$meta->get_display_prefix( $stored_id ),
			$meta->format_price_html( $product, $price ),
			$settings->is_tooltip_enabled() ? $settings->get_translated( 'tooltip' ) : '',
			true
		);
	}

	/**
	 * Variable parent until a size is chosen: the range of the sizes' bazna cijene, in the same form as the theme's range
	 * of prices ("39,00 € – 42,00 €"; one amount when all are the same). Not "od 39,00 €": that is the bazna of one
	 * size only (spec §2.4). A date belongs to every amount on the line, so when the sizes have different labels or
	 * dates the parent shows no line and each size shows its own once chosen.
	 *
	 * @param WC_Product_Variable $product Parent product.
	 * @return string
	 */
	private function get_html_for_variable( $product ) {
		$settings  = CX_Sidrena_Settings::instance();
		$meta      = CX_Sidrena_Product_Meta::instance();
		$prices    = array();
		$prefixes  = array();
		$prefix    = $meta->get_display_prefix( $meta->display_id( $product->get_id() ) );
		$child_ids = method_exists( $product, 'get_visible_children' ) ? $product->get_visible_children() : $product->get_children();

		foreach ( $child_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_purchasable() || ! $this->viewer_may_see_product( $variation ) ) {
				continue;
			}

			$stored_id = $meta->display_id( $variation_id );
			$value     = $meta->get_price( $stored_id );
			if ( '' === $value ) {
				continue;
			}

			$display = $meta->get_price_to_display( $variation, $value );
			if ( null === $display ) {
				continue;
			}

			$prices[]   = $display;
			$prefix     = $meta->get_display_prefix( $stored_id );
			$prefixes[] = $prefix;
		}

		if ( empty( $prices ) || count( array_unique( $prefixes ) ) > 1 ) {
			return '';
		}

		$min    = min( $prices );
		$max    = max( $prices );
		$amount = abs( (float) $min - (float) $max ) < 0.00001 ? wc_price( $min ) : wc_format_price_range( $min, $max );

		return $this->render_line(
			$prefix,
			$amount,
			$settings->is_tooltip_enabled() ? $settings->get_translated( 'tooltip' ) : '',
			true,
			true
		);
	}

	/**
	 * Draft and private products stay hidden from people who cannot edit them.
	 * A password-protected product stays hidden until the visitor unlocks it.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function viewer_may_see_product( $product ) {
		$ids = array( (int) $product->get_id() );
		if ( $product->get_parent_id() ) {
			$ids[] = (int) $product->get_parent_id();
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}

			if ( post_password_required( $post ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * An explicit ID may be rendered off its own page only when the product is not catalog-hidden.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function explicit_product_allowed( $product ) {
		if ( $this->is_queried_product( $product ) ) {
			return true;
		}

		$subject = $product;
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				$subject = $parent;
			}
		}

		return 'hidden' !== $subject->get_catalog_visibility();
	}

	/**
	 * Whether this product (or its parent) is the current singular view.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private function is_queried_product( $product ) {
		$current_id = (int) get_queried_object_id();
		if ( $current_id < 1 ) {
			return false;
		}

		return $current_id === (int) $product->get_id() || $current_id === (int) $product->get_parent_id();
	}

	/**
	 * Shared markup.
	 *
	 * @param string $prefix        Label including date.
	 * @param mixed  $price         Raw number or already-formatted HTML.
	 * @param string $tooltip       Tooltip text.
	 * @param bool   $price_is_html Whether $price is already HTML.
	 * @param bool   $is_variable   Extra class for JS replacement.
	 * @return string
	 */
	private function render_line( $prefix, $price, $tooltip, $price_is_html = false, $is_variable = false ) {
		$formatted = $price_is_html ? (string) $price : wc_price( $price );
		$classes   = 'sidrena-cijena';
		if ( $is_variable ) {
			$classes .= ' sidrena-cijena--variable';
		}

		$has_tooltip = '' !== trim( (string) $tooltip );
		if ( $has_tooltip ) {
			$classes .= ' sidrena-cijena--has-tooltip';
		}

		$separator = CX_Sidrena_Settings::instance()->preview_separator( false );

		// One string, no whitespace between the parts: between inline spans it would show as a space
		// (MJDD printed "Bazna cijena (10.09.2026.) : 19,90 €").
		$html  = '<span class="' . esc_attr( $classes ) . '"' . ( $has_tooltip ? ' tabindex="0" aria-label="' . esc_attr( $tooltip ) . '"' : '' ) . '>';
		$html .= '<span class="sidrena-cijena__text">';
		$html .= '<span class="sidrena-cijena__prefix">' . esc_html( $prefix ) . '</span>';
		$html .= '<span class="sidrena-cijena__sep">' . esc_html( $separator ) . '</span>';
		$html .= '<span class="sidrena-cijena__amount">' . wp_kses_post( $formatted ) . '</span>';
		$html .= '</span>';
		if ( $has_tooltip ) {
			$html .= '<span class="sidrena-cijena__tooltip">' . esc_html( $tooltip ) . '</span>';
		}

		return $html . '</span>';
	}
}
