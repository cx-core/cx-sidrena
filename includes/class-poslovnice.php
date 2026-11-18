<?php
/**
 * Multiple sales outlets (poslovnice) for per-object public feeds.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stored outlet list: legal filename parts plus assigned product ids.
 */
class CX_Sidrena_Poslovnice {

	const OPTION_KEY = 'cx_sidrena_poslovnice';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Request-cached outlets.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $cached_all = null;

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
	 * All outlets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		if ( null !== $this->cached_all ) {
			return $this->cached_all;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$this->cached_all = array();
			return $this->cached_all;
		}

		$out = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = $this->sanitize_row( $row );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}

		$this->cached_all = $this->unique_oznake( $out );
		return $this->cached_all;
	}

	/**
	 * One outlet by its object code.
	 *
	 * @param string $oznaka Normalized code.
	 * @return array<string, mixed>|null
	 */
	public function get_by_oznaka( $oznaka ) {
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			return null;
		}

		foreach ( $this->all() as $row ) {
			if ( $row['oznaka'] === $oznaka ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Outlets that have at least one product.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function with_products() {
		$out = array();
		foreach ( $this->all() as $row ) {
			if ( ! empty( $row['products'] ) || CX_Sidrena_Catalog::instance()->to_export_rows_for( false, false, $row['oznaka'], $row['products'] ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Catalog / Woo ids assigned on any outlet card.
	 *
	 * @return array<string, bool>
	 */
	public function assigned_ids_lookup() {
		$ids = array();
		foreach ( $this->all() as $row ) {
			foreach ( $row['products'] as $product_id ) {
				$ids[ (string) $product_id ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Persist rows.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function save( $rows ) {
		$clean    = array();
		$used_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$item = $this->sanitize_row( $row );
				if ( null === $item ) {
					continue;
				}

				if ( '' !== $item['id'] && isset( $used_ids[ $item['id'] ] ) ) {
					$item['id'] = '';
				}

				$clean[] = $item;
				if ( '' !== $item['id'] ) {
					$used_ids[ $item['id'] ] = true;
				}
			}
		}

		$clean = $this->unique_oznake( $clean );
		$next  = $this->next_id( $clean );
		foreach ( $clean as $index => $item ) {
			if ( '' === $item['id'] ) {
				$clean[ $index ]['id'] = 'p' . $next;
				++$next;
			}
		}

		update_option( self::OPTION_KEY, $clean, false );
		$this->cached_all = $clean;

		return $clean;
	}

	/**
	 * Decode JSON from the Poslovnice tab and save.
	 *
	 * @param string $json Raw JSON.
	 * @return array<int, array<string, mixed>>
	 */
	public function save_from_json( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		return $this->save( $decoded );
	}

	/**
	 * Raise the next storage number of one outlet, never lower it: its own counter row (CX_Sidrena_Archive::
	 * raise_pohrana(), CX-SID-119 dopuna). The outlet list is not saved: a build that saved it, as read when it started,
	 * undid an outlet change the owner saved meanwhile.
	 *
	 * @param string $id      Outlet id.
	 * @param int    $pohrana Next sequence, 1+.
	 * @return int The outlet's counter after.
	 */
	public function set_pohrana( $id, $pohrana ) {
		return CX_Sidrena_Archive::instance()->raise_pohrana( (string) $id, $pohrana );
	}

	/**
	 * Labels for product ids already assigned to outlets.
	 *
	 * @return array<string, string>
	 */
	public function product_labels() {
		$ids = array();
		foreach ( $this->all() as $row ) {
			foreach ( $row['products'] as $product_id ) {
				$ids[ (string) $product_id ] = true;
			}
		}

		$labels = array();
		foreach ( array_keys( $ids ) as $product_id ) {
			$labels[ $product_id ] = $this->product_label( $product_id );
		}

		return $labels;
	}

	/**
	 * Human label for one catalog row or Woo product.
	 *
	 * @param string $product_id Id.
	 * @return string
	 */
	public function product_label( $product_id ) {
		$product_id = (string) $product_id;

		if ( CX_Sidrena_Plugin::woocommerce_active() && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $product_id ) );
			if ( $product ) {
				return wp_strip_all_tags( $product->get_name() );
			}

			return '#' . $product_id;
		}

		$row = CX_Sidrena_Catalog::instance()->get( $product_id );
		if ( ! is_array( $row ) ) {
			return '#' . $product_id;
		}

		$label = $row['naziv'];
		if ( '' !== $row['sku'] ) {
			$label .= ' (' . $row['sku'] . ')';
		}

		return $label;
	}

	/**
	 * Keep a row that has a name, address, or products.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_row( $row ) {
		$settings = CX_Sidrena_Settings::instance();
		$naziv    = isset( $row['naziv'] ) ? sanitize_text_field( (string) $row['naziv'] ) : '';
		$adresa   = isset( $row['adresa'] ) ? sanitize_text_field( (string) $row['adresa'] ) : '';
		$oblik    = isset( $row['oblik'] ) ? sanitize_text_field( (string) $row['oblik'] ) : '';
		$products = $this->sanitize_products( isset( $row['products'] ) ? $row['products'] : array() );

		if ( '' === $naziv && '' === $adresa && array() === $products && '' === $oblik ) {
			return null;
		}

		$id = isset( $row['id'] ) ? strtolower( (string) $row['id'] ) : '';
		$id = preg_replace( '/[^a-z0-9]/', '', $id );
		if ( ! is_string( $id ) || ! preg_match( '/^p\d+$/', $id ) ) {
			$id = '';
		}

		$oznaka = $settings->normalize_oznaka( isset( $row['oznaka'] ) ? $row['oznaka'] : '' );
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		return array(
			'id'       => $id,
			'naziv'    => $naziv,
			'oblik'    => $oblik,
			'adresa'   => $adresa,
			'oznaka'   => $oznaka,
			'pohrana'  => $settings->sanitize_pohrana( isset( $row['pohrana'] ) ? $row['pohrana'] : 1 ),
			'products' => $products,
			'stock'    => $this->sanitize_stock( isset( $row['stock'] ) ? $row['stock'] : array() ),
		);
	}

	/**
	 * Remember one product on an outlet and its own availability. Does not touch WooCommerce stock.
	 *
	 * @param string $oznaka     Outlet code.
	 * @param int    $product_id Product or variation id.
	 * @param string $flag       Raw dostupnost. Empty leaves the stored flag unchanged.
	 */
	public function set_product_dostupnost( $oznaka, $product_id, $flag ) {
		$oznaka     = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		$product_id = (string) absint( $product_id );
		if ( '' === $oznaka || '0' === $product_id ) {
			return;
		}

		$ids = array( $product_id );
		if ( CX_Sidrena_Plugin::woocommerce_active() && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product_id );
			if ( $product && ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child_id = (string) absint( $child_id );
					if ( '0' !== $child_id ) {
						$ids[] = $child_id;
					}
				}
			}
		}

		$flag = '' !== trim( (string) $flag ) ? CX_Sidrena_Money::normalize_dostupnost( $flag ) : '';
		$rows = $this->all();
		foreach ( $rows as $index => $row ) {
			if ( $row['oznaka'] !== $oznaka ) {
				continue;
			}

			$products = isset( $row['products'] ) && is_array( $row['products'] ) ? $row['products'] : array();
			if ( ! in_array( $product_id, $products, true ) ) {
				$products[] = $product_id;
			}
			$rows[ $index ]['products'] = $products;

			if ( '' === $flag ) {
				continue;
			}

			$stock = isset( $row['stock'] ) && is_array( $row['stock'] ) ? $row['stock'] : array();
			foreach ( $ids as $id ) {
				$stock[ $id ] = $flag;
			}
			$rows[ $index ]['stock'] = $stock;
		}

		$this->save( $rows );
	}

	/**
	 * Stored availability for one outlet, keyed by product id.
	 *
	 * @param string $oznaka Outlet code.
	 * @return array<string, string>
	 */
	public function stock_map( $oznaka ) {
		$outlet = $this->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) || empty( $outlet['stock'] ) || ! is_array( $outlet['stock'] ) ) {
			return array();
		}

		return $outlet['stock'];
	}

	/**
	 * Product or catalog ids.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int, string>
	 */
	private function sanitize_products( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( ! is_string( $id ) || '' === $id || '0' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $id;
		}

		return $out;
	}

	/**
	 * Product id => dostupno|nedostupno. Unknown keys are dropped.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string, string>
	 */
	private function sanitize_stock( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $id => $flag ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( ! is_string( $id ) || '' === $id || '0' === $id ) {
				continue;
			}
			$out[ $id ] = CX_Sidrena_Money::normalize_dostupnost( $flag );
		}

		return $out;
	}

	/**
	 * Make object codes unique. Numeric codes increment; others gain a suffix.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function unique_oznake( $rows ) {
		$used = array();

		foreach ( $rows as $index => $row ) {
			$oznaka = (string) $row['oznaka'];
			$guard  = 0;
			while ( isset( $used[ $oznaka ] ) && $guard < 1000 ) {
				if ( ctype_digit( $oznaka ) ) {
					$oznaka = str_pad( (string) ( (int) $oznaka + 1 ), strlen( $oznaka ), '0', STR_PAD_LEFT );
				} else {
					$oznaka .= '2';
				}
				++$guard;
			}
			$rows[ $index ]['oznaka'] = $oznaka;
			$used[ $oznaka ]          = true;
		}

		return $rows;
	}

	/**
	 * Next numeric suffix for p1, p2, …
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return int
	 */
	private function next_id( $rows ) {
		$max = 0;
		foreach ( $rows as $row ) {
			if ( preg_match( '/^p(\d+)$/', (string) $row['id'], $match ) ) {
				$max = max( $max, (int) $match[1] );
			}
		}

		return $max + 1;
	}
}
