<?php
/**
 * The cart, the mini-cart and the checkout summary drawn by WooCommerce blocks (CX-SID-14).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * A previous price in the cart may only be the lowest price in 30 days with its caption, as on the product page, or
 * none (Kai: ZZP art. 7(2) and 19(4); the cart itself needs neither the bazna nor the lowest price). The cart, mini-cart
 * and checkout blocks are drawn in the browser from the Store API cart, where WooCommerce gives the regular price as
 * the previous one: they strike it and say "Save" from it, so a product whose page strikes 7,90 showed 8,50 in the cart.
 * Our price filter (woocommerce_get_price_html) never runs there. We give every line one price: the previous price is
 * the price itself, so nothing is struck and there is no "Save". What the customer pays, the totals, coupons and taxes
 * do not change; only the carts in the answers are touched (items and cross-sells).
 *
 * The same for the products the blocks draw from /wc/store/v1/products (CX-SID-45: All Products and its Product Price and
 * Sale Badge): there WooCommerce gives the regular price and on_sale, so a product on sale showed its regular price
 * struck and a "Sale" badge, without the lowest price in 30 days and its caption that its own page shows. A product on
 * sale gets one price there (regular and sale price = the price) and on_sale false: no struck price, and no badge where
 * the lowest price is not shown with it (CX-SID-26). price_html (our whole price block, with the lowest price and its
 * caption) and price_range stay as WooCommerce gives them, and so does a product that is not on sale. Rook accepted that
 * a client reading regular_price from this endpoint (a headless shop, an app) gets the current price there.
 * The blocks also redraw from the cart inside the checkout's answer (__experimentalCart) and inside an error (data.cart,
 * e.g. a 409 when a line was already removed in another tab), so those get one price too.
 *
 * Reached on two paths: every REST response (requests from the browser, each request of a /batch, and the page preload
 * up to WooCommerce 8.8), and WooCommerce's own preload from 8.9 on (the cart in the page's HTML, also the
 * Interactivity mini-cart's state), which calls the route directly and skips the REST filters.
 *
 * The classic cart, mini-cart and checkout print only the current price, so they need nothing.
 * Another plugin's cart discount (quantity, member price) is not struck in these blocks either.
 */
class CX_Sidrena_Cart {

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
	 * Register the two response filters.
	 */
	private function __construct() {
		add_filter( 'rest_request_after_callbacks', array( $this, 'filter_response' ), 20, 3 );
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'filter_response' ), 20, 3 );
	}

	/**
	 * A Store API answer that carries the cart, or products: one price per line or product.
	 *
	 * @param mixed           $response Response (WP_REST_Response, WP_Error, or anything a filter returned).
	 * @param array           $handler  Route handler (unused).
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function filter_response( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route    = $request->get_route();
		$products = self::carries_products( $route );
		if ( ! $products && ! self::carries_cart( $route ) ) {
			return $response;
		}

		// Another lowest-price plugin draws its own previous price: leave the cart to it, as the product page.
		if ( class_exists( 'CX_Sidrena_Compat' ) && CX_Sidrena_Compat::lowest_price_plugin_active() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$response->set_data( $products ? self::one_price_products( $data ) : self::one_price( $data ) );

		return $response;
	}

	/**
	 * Routes whose answers are products: /wc/store/v1/products (a list), /products/<id> and /products/<slug>, also
	 * unversioned. Not collection-data, attributes, categories, tags, brands or reviews (no previous price in them; their
	 * answers have no "prices" either, which one_price_products() also asks for).
	 *
	 * @param string $route Request route.
	 * @return bool
	 */
	public static function carries_products( $route ) {
		return (bool) preg_match( '#^/wc/store(?:/v\d+)?/products(?:/(?!(?:collection-data|attributes|categories|tags|brands|reviews)(?:/|$))[^/]+)?/?$#', (string) $route );
	}

	/**
	 * The data of a products answer (a list, or one product), each product on sale with one price.
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	public static function one_price_products( array $data ) {
		if ( isset( $data['id'], $data['prices'] ) ) {
			return self::one_price_product( $data );
		}

		if ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $i => $product ) {
				$data[ $i ] = self::one_price_product( $product );
			}
		}

		return $data;
	}

	/**
	 * One product as the Store API gives it: on sale, its regular and sale price become the price and on_sale false. A
	 * product that is not on sale (regular price = price, on_sale false) is left exactly as it is.
	 *
	 * @param mixed $product Product data.
	 * @return mixed
	 */
	private static function one_price_product( $product ) {
		if ( ! is_array( $product ) || ! isset( $product['prices'] ) ) {
			return $product;
		}

		$was_object = is_object( $product['prices'] );
		$prices     = (array) $product['prices'];
		if ( ! isset( $prices['price'] ) ) {
			return $product;
		}

		$on_sale = ! empty( $product['on_sale'] ) || ( isset( $prices['regular_price'] ) && (string) $prices['regular_price'] !== (string) $prices['price'] );
		if ( ! $on_sale ) {
			return $product;
		}

		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( array_key_exists( $key, $prices ) ) {
				$prices[ $key ] = $prices['price'];
			}
		}
		$product['prices'] = $was_object ? (object) $prices : $prices;
		if ( array_key_exists( 'on_sale', $product ) ) {
			$product['on_sale'] = false;
		}

		return $product;
	}

	/**
	 * Routes whose answers carry the cart: /wc/store/v1/cart and everything under it (add-item, items, update-item,
	 * coupons, ...), and the checkout (/wc/store/v1/checkout: the block checkout's updates get the cart back in
	 * __experimentalCart, a failed order in data.cart), also unversioned. Products: carries_products().
	 *
	 * @param string $route Request route.
	 * @return bool
	 */
	public static function carries_cart( $route ) {
		return (bool) preg_match( '#^/wc/store(?:/v\d+)?/(?:cart|checkout)(?:/|$)#', (string) $route );
	}

	/**
	 * The data of a cart response, of a list of cart items, of one cart item, of a checkout answer or of an error that
	 * carries the cart, with one price per line.
	 *
	 * @param array $data Response data.
	 * @return array
	 */
	public static function one_price( array $data ) {
		$data = self::one_price_cart( $data );

		// The checkout's answer: the block checkout redraws its order summary from this cart (WooCommerce gives an object).
		if ( isset( $data['__experimentalCart'] ) ) {
			$data['__experimentalCart'] = self::one_price_cart( $data['__experimentalCart'] );
		}

		// An error that carries the cart (409: a line already removed in another tab, a cart that changed at checkout):
		// the blocks redraw from data.cart.
		if ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['cart'] ) ) {
			$data['data']['cart'] = self::one_price_cart( $data['data']['cart'] );
		}

		// GET /cart/items is a list of items; one item (GET or PUT /cart/items/<key>, POST /cart/items) has its key.
		if ( isset( $data['key'], $data['prices'] ) ) {
			return self::one_price_item( $data );
		}

		if ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $i => $item ) {
				$data[ $i ] = self::one_price_item( $item );
			}
		}

		return $data;
	}

	/**
	 * A cart (array, or an object as in the checkout's answer): its lines and cross-sells.
	 *
	 * @param mixed $cart Cart.
	 * @return mixed
	 */
	private static function one_price_cart( $cart ) {
		$was_object = $cart instanceof stdClass;
		if ( ! is_array( $cart ) && ! $was_object ) {
			return $cart;
		}

		$cart = (array) $cart;
		foreach ( array( 'items', 'cross_sells' ) as $list ) {
			if ( isset( $cart[ $list ] ) && is_array( $cart[ $list ] ) ) {
				foreach ( $cart[ $list ] as $i => $line ) {
					$cart[ $list ][ $i ] = self::one_price_item( $line );
				}
			}
		}

		return $was_object ? (object) $cart : $cart;
	}

	/**
	 * One line (or a cross-sell product): its previous price is the price itself.
	 *
	 * @param mixed $item Item as the Store API gives it ('prices' is an object there, raw_prices an array in it).
	 * @return mixed
	 */
	private static function one_price_item( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['prices'] ) ) {
			return $item;
		}

		$was_object = is_object( $item['prices'] );
		$prices     = (array) $item['prices'];

		if ( isset( $prices['price'] ) && array_key_exists( 'regular_price', $prices ) ) {
			$prices['regular_price'] = $prices['price'];
		}

		if ( isset( $prices['raw_prices'] ) ) {
			$raw_was_object = is_object( $prices['raw_prices'] );
			$raw            = (array) $prices['raw_prices'];
			if ( isset( $raw['price'] ) && array_key_exists( 'regular_price', $raw ) ) {
				$raw['regular_price'] = $raw['price'];
			}
			$prices['raw_prices'] = $raw_was_object ? (object) $raw : $raw;
		}

		$item['prices'] = $was_object ? (object) $prices : $prices;

		return $item;
	}
}
