<?php
/**
 * Build public and download catalog rows from WooCommerce and the manual catalog.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build public and download catalog rows from WooCommerce and the manual catalog.
 */
class CX_Sidrena_Feed_Rows {

	/**
	 * A product's own brand, EAN and barcode, written only by the Import tab (CX-SID-122): the owner's or the supplier's
	 * data, read after WooCommerce's own fields (product_codes(), CX_Sidrena_Settings::product_marka()).
	 */
	const META_MARKA  = '_cx_sidrena_marka';
	const META_EAN    = '_cx_sidrena_ean';
	const META_BARKOD = '_cx_sidrena_barkod';

	/** Other plugins' code fields, read after WooCommerce's GTIN (the list from before CX-SID-122). */
	const OTHER_CODE_KEYS = array( '_global_unique_id', '_gtin', '_ean', '_upc', '_isbn', '_barcode', 'barcode', '_alg_ean', '_wpm_gtin_code', 'hwp_product_gtin' );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * SKUs of the form ID-<n>, lower case (id_codes_taken()); null until read. Read again for each collect_rows().
	 *
	 * @var array<string,bool>|null
	 */
	private $id_codes_taken = null;

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
	 * Collect catalog rows: name, sku, prices.
	 *
	 * WooCommerce rows are the shop default language. A translation is not a second row.
	 *
	 * @param bool $missing_only Only products/variations without sidrena price.
	 * @param bool $public       Published catalog for the public XML (adds id/url).
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		$this->id_codes_taken = null; // a SKU may have changed since the last build in this request
		$collect              = function () use ( $missing_only, $public, $only_ids, $catalog_scope ) {
			return CX_Sidrena_I18n::instance()->run_in_default_language(
				function () use ( $missing_only, $public, $only_ids, $catalog_scope ) {
					return $this->collect_rows_now( $missing_only, $public, $only_ids, $catalog_scope );
				}
			);
		};

		if ( ! $public ) {
			return $collect();
		}

		return $this->finish_public_rows( self::as_visitor( $collect ) );
	}

	/**
	 * Run a callback as a visitor (no user), then put back whoever ran it, also when it throws.
	 *
	 * The public price list shows what any consumer pays and can buy (CX-SID-28). A plugin that gives the admin or a
	 * wholesale role another price, or lets only members buy a product, filters WooCommerce's getters by the current
	 * user. Built under the admin who clicked Generate or saved a product, those prices went into the public file,
	 * while cron and WP-CLI built as a guest: the file depended on who built it last.
	 *
	 * @param callable $callback Work.
	 * @return mixed What the callback returns.
	 */
	public static function as_visitor( $callback ) {
		$who = get_current_user_id();
		if ( 0 === $who ) {
			return $callback();
		}

		wp_set_current_user( 0 );
		try {
			return $callback();
		} finally {
			wp_set_current_user( $who );
		}
	}

	/**
	 * The rules every public row follows, whatever it came from (WooCommerce, the manual catalog, an outlet):
	 * - no retail price, no row: a row the consumer cannot read a price from is not in the price list;
	 * - the bazna date only next to a bazna;
	 * - poseban_oblik: da when the row is in a special form of sale, ne otherwise (art. 3(2) of the Pravilnik o cjeniku,
	 *   NN 132/2026-1584; the file writes it DA|NE, CX_Sidrena_Feed_Files::legal_row()).
	 *
	 * @param array<int, array<string, string>> $rows Public rows.
	 * @return array<int, array<string, string>>
	 */
	public function finish_public_rows( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( '' === trim( isset( $row['maloprodajna_cijena'] ) ? (string) $row['maloprodajna_cijena'] : '' ) ) {
				continue;
			}

			if ( '' === trim( isset( $row['sidrena_cijena'] ) ? (string) $row['sidrena_cijena'] : '' ) ) {
				$row['sidrena_datum'] = '';
			}

			$oblik                = isset( $row['naziv_posebnog_oblika'] ) ? trim( (string) $row['naziv_posebnog_oblika'] ) : '';
			$row['poseban_oblik'] = '' !== $oblik ? 'da' : 'ne';

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Collect catalog rows while the default language is already current.
	 *
	 * @param bool              $missing_only  Only products/variations without sidrena price.
	 * @param bool              $public        Published catalog for the public XML (adds id/url).
	 * @param array<int,string>|null $only_ids Outlet product ids, or null for the full catalog.
	 * @param string|null       $catalog_scope Outlet code, empty for the webshop catalog, null for every manual row.
	 * @return array<int, array<string, string>>
	 */
	private function collect_rows_now( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			$assigned = array();
			if ( is_string( $catalog_scope ) && '' !== $catalog_scope ) {
				$places   = CX_Sidrena_Poslovnice::instance();
				$outlet   = $places->get_by_oznaka( $catalog_scope );
				$assigned = ( is_array( $outlet ) && isset( $outlet['products'] ) ) ? $outlet['products'] : array();
				if ( is_array( $outlet ) && 'svi' === $places->dodjela( $outlet ) ) {
					// Every row of the webshop's price list, and the place's own (CX-SID-74).
					$assigned = array();
					foreach ( CX_Sidrena_Catalog::instance()->to_export_rows_for( false, false, '', array_keys( $places->assigned_ids_lookup() ) ) as $row ) {
						$assigned[] = (string) $row['id'];
					}
				}
			} elseif ( is_string( $catalog_scope ) && '' === $catalog_scope ) {
				$assigned = array_keys( CX_Sidrena_Poslovnice::instance()->assigned_ids_lookup() );
			}

			return CX_Sidrena_Catalog::instance()->to_export_rows_for( $missing_only, $public, $catalog_scope, $assigned );
		}

		if ( is_array( $only_ids ) ) {
			$rows = $this->collect_rows_for_ids( $only_ids, $missing_only, $public );
			if ( is_string( $catalog_scope ) && '' !== $catalog_scope ) {
				$rows = $this->apply_outlet_dostupnost( $rows, $catalog_scope );
			}
			$this->append_extra_catalog_rows( $rows, $missing_only, $public, $catalog_scope, $only_ids );
			return $rows;
		}

		// The full list: the webshop's, or that of a place with all of the webshop's products (CX-SID-74), with its availability.
		$rows = $this->collect_woo_rows( $missing_only, $public );
		if ( is_string( $catalog_scope ) && '' !== $catalog_scope ) {
			$rows = $this->apply_outlet_dostupnost( $rows, $catalog_scope );
		}
		$this->append_extra_catalog_rows( $rows, $missing_only, $public, $catalog_scope, null );

		return $rows;
	}

	/**
	 * WooCommerce products and variations only.
	 *
	 * @param bool $missing_only Empty sidrena only.
	 * @param bool $public       Public catalog columns.
	 * @return array<int, array<string, string>>
	 */
	public function collect_woo_rows( $missing_only, $public ) {
		$meta = CX_Sidrena_Product_Meta::instance();
		$rows = array();
		$page = 1;
		$lang = $this->polylang_catalog_lang();

		/*
		 * WooCommerce drops unknown query vars before WP_Query. Polylang only
		 * limits the catalog when `lang` reaches that query.
		 */
		$forward_lang = static function ( $args, $query_vars ) {
			if ( isset( $query_vars['lang'] ) && is_string( $query_vars['lang'] ) && '' !== $query_vars['lang'] ) {
				$args['lang'] = $query_vars['lang'];
			}

			return $args;
		};

		if ( '' !== $lang ) {
			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10, 2 );
		}

		try {
			while ( true ) {
				$query = array(
					'status'  => $public ? array( 'publish' ) : array( 'publish', 'private', 'draft', 'pending' ),
					'type'    => array( 'simple', 'variable', 'external', 'subscription', 'variable-subscription' ),
					'limit'   => CX_Sidrena_Export::BATCH_SIZE,
					'page'    => $page,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				);
				if ( '' !== $lang ) {
					$query['lang'] = $lang;
				}

				$ids = wc_get_products( $query );

				if ( empty( $ids ) ) {
					break;
				}

				update_meta_cache( 'post', $ids );

				foreach ( $ids as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}

					if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
						$children = $product->get_children();
						if ( ! empty( $children ) ) {
							update_meta_cache( 'post', $children );
						}
						foreach ( $children as $variation_id ) {
							$variation = wc_get_product( $variation_id );
							if ( $variation ) {
								$this->maybe_add_row( $rows, $variation, $meta, $missing_only, $public );
							}
						}
						continue;
					}

					$this->maybe_add_row( $rows, $product, $meta, $missing_only, $public );
				}

				if ( count( $ids ) < CX_Sidrena_Export::BATCH_SIZE ) {
					break;
				}

				++$page;
			}

			return $rows;
		} finally {
			if ( '' !== $lang ) {
				remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $forward_lang, 10 );
			}
		}
	}

	/**
	 * Extra catalog rows (services, made-to-order) after WooCommerce products.
	 *
	 * @param array<int, array<string, string>> $rows         Collector.
	 * @param bool                              $missing_only Filter empty sidrena.
	 * @param bool                              $public       Include public catalog fields.
	 * @param string|null                       $catalog_scope Place code, '' for the webshop, null for every manual row.
	 * @param mixed                             $assigned_ids Unused since CX-SID-74 (kept for callers).
	 */
	public function append_extra_catalog_rows( &$rows, $missing_only, $public, $catalog_scope = null, $assigned_ids = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		// With WooCommerce the ids picked on a place are WooCommerce ids, and a manual row has ids of its own: it belongs
		// to a place only by its own poslovnica. Matching it by the picked ids took the webshop's row whose id happened to
		// be a picked product's onto that place, and off the webshop (CX-SID-74, N2).
		$catalog = CX_Sidrena_Catalog::instance();
		if ( null === $catalog_scope ) {
			$extra = $catalog->to_export_rows( $missing_only, $public );
		} else {
			$extra = $catalog->to_export_rows_for( $missing_only, $public, $catalog_scope, array() );
		}

		foreach ( $extra as $row ) {
			if ( $public && isset( $row['id'] ) && '' !== (string) $row['id'] ) {
				$row['id'] = 'd' . $row['id'];
			}
			unset( $row['poslovnica'] );
			$rows[] = $row;
		}
	}

	/**
	 * Public rows limited to an outlet's product ids.
	 *
	 * @param array<int, array<string, string>> $rows     Rows.
	 * @param array<int, string>|null           $only_ids Ids, or null for the full catalog.
	 * @return array<int, array<string, string>>
	 */
	public function filter_rows_by_ids( $rows, $only_ids ) {
		if ( ! is_array( $only_ids ) ) {
			return $rows;
		}

		$lookup = array();
		foreach ( $only_ids as $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( is_string( $id ) && '' !== $id ) {
				$lookup[ $id ] = true;
			}
		}

		if ( array() === $lookup ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id && isset( $lookup[ $id ] ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Woo rows for an explicit id list (simple products, variations, or variable parents).
	 *
	 * @param array<int, string> $only_ids     Product ids.
	 * @param bool               $missing_only Filter empty sidrena.
	 * @param bool               $public       Include public catalog fields.
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows_for_ids( $only_ids, $missing_only, $public ) {
		$i18n   = CX_Sidrena_I18n::instance();
		$lookup = array();
		foreach ( $only_ids as $id ) {
			$source = $i18n->source_product_id( (int) absint( $id ) );
			if ( $source > 0 ) {
				$lookup[ (string) $source ] = true;
			}
		}

		if ( array() === $lookup ) {
			return array();
		}

		update_meta_cache( 'post', array_map( 'intval', array_keys( $lookup ) ) );

		$meta = CX_Sidrena_Product_Meta::instance();
		$rows = array();
		$seen = array();

		foreach ( array_keys( $lookup ) as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
				$children = $product->get_children();
				if ( ! empty( $children ) ) {
					update_meta_cache( 'post', $children );
				}
				foreach ( $children as $variation_id ) {
					$variation_id = (int) $variation_id;
					if ( isset( $seen[ $variation_id ] ) ) {
						continue;
					}
					$seen[ $variation_id ] = true;
					$variation             = wc_get_product( $variation_id );
					if ( $variation ) {
						$this->maybe_add_row( $rows, $variation, $meta, $missing_only, $public );
					}
				}
				continue;
			}

			$product_id = (int) $product->get_id();
			if ( isset( $seen[ $product_id ] ) ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$this->maybe_add_row( $rows, $product, $meta, $missing_only, $public );
		}

		return $rows;
	}

	/**
	 * Append one product or variation row.
	 *
	 * @param array<int, array<string, string>> $rows         Collector.
	 * @param WC_Product                        $product      Product.
	 * @param CX_Sidrena_Product_Meta       $meta         Meta helper.
	 * @param bool                              $missing_only Filter empty sidrena.
	 * @param bool                              $public       Include public catalog fields.
	 */
	public function maybe_add_row( &$rows, $product, $meta, $missing_only, $public = false ) {
		if ( ! CX_Sidrena_I18n::instance()->is_source_product( $product->get_id() ) ) {
			return;
		}

		if ( $public && ! $this->include_in_public_feed( $product ) ) {
			return;
		}

		$sidrena = $meta->get_price( $product->get_id() );

		if ( $missing_only && '' !== $sidrena ) {
			return;
		}

		$row = array(
			'naziv'           => $this->feed_naziv( $product ),
			'sku'             => $this->sifra( $product ),
			'cijena_redovna'  => (string) $product->get_regular_price(),
			'cijena_akcijska' => (string) $product->get_sale_price(),
			'sidrena_cijena'  => $sidrena,
			'vrsta'           => CX_Sidrena_Product_Fields::is_service( $product ) ? 'usluga' : 'proizvod', // CX-SID-138
			'sidrena_datum'   => $meta->get_display_date( $product->get_id() ),
		);

		if ( $public ) {
			$row['id']                     = (string) $product->get_id();
			$row['url']                    = (string) get_permalink( $product->get_id() );
			$codes                         = $this->product_codes( $product );
			$row['barkod']                 = $codes['barkod'];
			$row['ean_barkod']             = $codes['ean_barkod'];
			$row['marka']                  = CX_Sidrena_Settings::instance()->product_marka( $product );
			$row['dostupnost']             = $this->product_dostupnost( $product );
			// CX-SID-38: with a net quantity, the unit price the product page uses (one helper), also where the page does
			// not show it; without one, the unit of measure and unit price typed by hand, as before (no neto_kolicina key).
			$unit = $meta->unit_price( $product );
			if ( null !== $unit ) {
				$row['neto_kolicina']         = $unit['neto'];
				$row['jedinica_mjere']        = $unit['jm'];
				$row['cijena_jedinice_mjere'] = $this->format_feed_amount( $unit['amount'] );
			} else {
				$row['jedinica_mjere']        = $meta->get_jedinica( $product->get_id() );
				$row['cijena_jedinice_mjere'] = $this->format_feed_amount( $meta->get_price_to_display( $product, $meta->get_jedinica_cijena( $product->get_id() ) ) );
			}
			$row['maloprodajna_cijena']    = $this->format_feed_amount( $meta->get_price_to_display( $product, $this->active_raw_price( $product ) ) );
			$row['naziv_posebnog_oblika']  = $meta->public_oblik_name( $product );
			$row['sidrena_cijena']         = $this->format_feed_amount( $meta->get_price_to_display( $product, $sidrena ) );
		} else {
			// The file without bazna cijena has no id column: the admin's download names its variations by this
			// (CX_Sidrena_Export::handle_download(), CX-SID-153). Not written to the file.
			$row['woo_id'] = (string) $product->get_id();
		}

		$rows[] = $row;
	}

	/**
	 * Catalog name: parent title plus all assigned variation attributes.
	 *
	 * WooCommerce may store only the parent title when a variation has three or
	 * more attributes, or an attribute slug with a hyphen. The price list still
	 * needs those values so each row identifies the item.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function feed_naziv( $product ) {
		$name = wp_strip_all_tags( $product->get_name() );

		if ( ! $product->is_type( 'variation' ) ) {
			return $name;
		}

		$parent_id = $product->get_parent_id();
		$base      = $parent_id ? wp_strip_all_tags( (string) get_post_field( 'post_title', $parent_id ) ) : '';
		if ( '' === $base ) {
			return $name;
		}

		$suffix = function_exists( 'wc_get_formatted_variation' )
			? trim( wp_strip_all_tags( wc_get_formatted_variation( $product, true, false ) ) )
			: '';

		if ( '' === $suffix || false !== strpos( $base, $suffix ) ) {
			return $base;
		}

		return $base . ' - ' . $suffix;
	}

	/**
	 * A product's šifra in the price list (CX-SID-122, Kai 2988): its SKU (a variation without one has its parent's,
	 * WooCommerce's rule), else ID-<its id>, the shop's own number for it. When some product's SKU is that ID-<n>, in
	 * any letters, the product has no šifra, as before: one text would name two products, in the file and on Import.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function sifra( $product ) {
		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			return $sku;
		}

		$code  = 'ID-' . (int) $product->get_id();
		$taken = $this->id_codes_taken();

		return isset( $taken[ strtolower( $code ) ] ) ? '' : $code;
	}

	/**
	 * A product's šifra in the files the owner downloads from the admin (CX-SID-153): sifra(), except for a variation
	 * without a SKU of its own, which gets ID-<its id> (none when some product's SKU is that text, as in sifra()). In the
	 * public price list such a variation shows its parent's SKU (WooCommerce's rule, kept: U13), which Import matches to
	 * the variable parent; the admin's file is the one the owner brings back on Import, so it names the variation.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function admin_sifra( $product ) {
		if ( ! $product instanceof WC_Product_Variation || '' !== (string) $product->get_sku( 'edit' ) ) {
			return $this->sifra( $product );
		}

		$code  = 'ID-' . (int) $product->get_id();
		$taken = $this->id_codes_taken();

		return isset( $taken[ strtolower( $code ) ] ) ? '' : $code;
	}

	/**
	 * SKUs of the form ID-<n>, lower case, of every product and variation Import can match (the same posts as
	 * CX_Sidrena_Import::match()). One query per build.
	 *
	 * @return array<string,bool>
	 */
	private function id_codes_taken() {
		if ( null !== $this->id_codes_taken ) {
			return $this->id_codes_taken;
		}

		global $wpdb;
		$skus = $wpdb->get_col( // phpcs:ignore WordPress.DB
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sku' AND LOWER( pm.meta_value ) LIKE 'id-%' AND p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('" . implode( "','", CX_Sidrena_Import::NOT_MATCHED ) . "')"
		);
		$this->id_codes_taken = array();
		foreach ( (array) $skus as $sku ) {
			if ( 1 === preg_match( '/^id-\d+$/iD', (string) $sku ) ) {
				$this->id_codes_taken[ strtolower( (string) $sku ) ] = true;
			}
		}

		return $this->id_codes_taken;
	}

	/**
	 * A product's EAN and barcode, each in its own column (CX-SID-122, Kai 2988), from the owner's or the supplier's data
	 * only, never guessed:
	 * - EAN: WooCommerce's GTIN when it is one (8 or 13 digits), else the imported EAN, else another plugin's EAN;
	 * - barcode: the imported barcode, else WooCommerce's GTIN or another plugin's code that is not an EAN (UPC-12,
	 *   GTIN-14, ISBN with X);
	 * - never one number in both columns (UPC-12 012345678905 is EAN-13 0012345678905).
	 * A product with one code gets the column its shape says, as before (CX_Sidrena_Feed_Files::split_barcode()).
	 *
	 * @param WC_Product                $product Product or variation.
	 * @param array<string,string>|null $ours    Our imported values to read instead of the stored ones ('ean',
	 *                                           'barkod'; '' = none): what the price list would show after an import.
	 * @return array{barkod:string,ean_barkod:string}
	 */
	public function product_codes( $product, $ours = null ) {
		$id    = (int) $product->get_id();
		$woo   = method_exists( $product, 'get_global_unique_id' ) ? $this->clean_barcode( $product->get_global_unique_id() ) : '';
		$other = array();
		foreach ( self::OTHER_CODE_KEYS as $key ) {
			$code = $this->clean_barcode( get_post_meta( $id, $key, true ) );
			if ( '' !== $code ) {
				$other[] = $code;
			}
		}

		$ean = '';
		$mine = static function ( $field, $key ) use ( $ours, $id ) {
			return is_array( $ours ) && array_key_exists( $field, $ours ) ? (string) $ours[ $field ] : get_post_meta( $id, $key, true );
		};

		foreach ( array_merge( array( $woo, $this->clean_barcode( $mine( 'ean', self::META_EAN ) ) ), $other ) as $code ) {
			if ( self::is_ean( $code ) ) {
				$ean = $code;
				break;
			}
		}

		$barkod = $this->clean_barcode( $mine( 'barkod', self::META_BARKOD ) );
		if ( '' === $barkod ) {
			foreach ( array_merge( array( $woo ), $other ) as $code ) {
				if ( '' !== $code && ! self::is_ean( $code ) ) {
					$barkod = $code;
					break;
				}
			}
		}
		if ( self::same_number( $barkod, $ean ) ) {
			$barkod = '';
		}

		/**
		 * The EAN and the barcode written to the public price list (CX-SID-122; it replaces the single code of
		 * sidrena_cijena_product_barcode).
		 *
		 * @param array{barkod:string,ean_barkod:string} $codes   Codes.
		 * @param WC_Product                             $product Product or variation.
		 */
		$codes = apply_filters(
			'sidrena_cijena_product_codes',
			array(
				'barkod'     => $barkod,
				'ean_barkod' => $ean,
			),
			$product
		);

		return array(
			'barkod'     => is_array( $codes ) && isset( $codes['barkod'] ) ? (string) $codes['barkod'] : $barkod,
			'ean_barkod' => is_array( $codes ) && isset( $codes['ean_barkod'] ) ? (string) $codes['ean_barkod'] : $ean,
		);
	}

	/**
	 * An EAN by its shape: exactly 8 or 13 digits (EAN-8, EAN-13). The check digit is not checked here: a code a shop
	 * already has is shown as it is (CX-SID-109).
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function is_ean( $code ) {
		return 1 === preg_match( '/^(?:\d{8}|\d{13})$/D', (string) $code );
	}

	/**
	 * Whether two codes are one number: the digits without leading zeros (UPC-12, EAN-13 and GTIN-14 of one product
	 * differ only in them), or the same text.
	 *
	 * @param string $a Code.
	 * @param string $b Code.
	 * @return bool
	 */
	public static function same_number( $a, $b ) {
		$a = (string) $a;
		$b = (string) $b;
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( ctype_digit( $a ) && ctype_digit( $b ) ) {
			return ltrim( $a, '0' ) === ltrim( $b, '0' );
		}

		return $a === $b;
	}

	/**
	 * First non-empty barcode token, with whitespace removed.
	 *
	 * @param mixed $raw Meta or getter value.
	 * @return string
	 */
	public function clean_barcode( $raw ) {
		$value = is_scalar( $raw ) ? preg_replace( '/\s+/', '', (string) $raw ) : '';
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Legal availability flag: dostupno or nedostupno.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	public function product_dostupnost( $product ) {
		$value = $product->is_in_stock() ? 'dostupno' : 'nedostupno';

		/**
		 * Availability written to the public XML/CSV feed.
		 *
		 * @param string     $value   dostupno|nedostupno.
		 * @param WC_Product $product Product or variation.
		 */
		$value = apply_filters( 'sidrena_cijena_product_dostupnost', $value, $product );

		return 'nedostupno' === $value ? 'nedostupno' : 'dostupno';
	}

	/**
	 * Price the customer pays now: sale price while the sale is active, otherwise regular.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Raw stored amount.
	 */
	public function active_raw_price( $product ) {
		if ( $product->is_on_sale() ) {
			$sale = (string) $product->get_sale_price();
			if ( '' !== $sale ) {
				return $sale;
			}
		}

		$regular = (string) $product->get_regular_price();
		if ( '' !== $regular ) {
			return $regular;
		}

		return (string) $product->get_price();
	}

	/**
	 * Dot-decimal amount for the machine-readable feed.
	 *
	 * @param float|null $amount Display amount.
	 * @return string
	 */
	public function format_feed_amount( $amount ) {
		if ( null === $amount || '' === $amount ) {
			return '';
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return number_format( (float) $amount, $decimals, '.', '' );
	}

	/**
	 * Published products only; password-protected ones stay out of public files. A product hidden from the catalog and
	 * search is in when a visitor can buy it, by its link (CX-SID-28: any consumer can buy it, so it has a price the law
	 * asks to publish), and out when a visitor cannot (a members' or wholesale product). Rows are built as a visitor
	 * (as_visitor()), so is_purchasable() answers for a guest.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public function include_in_public_feed( $product ) {
		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
			return false;
		}

		$post = get_post( $product->get_id() );
		if ( $post instanceof WP_Post && '' !== (string) $post->post_password ) {
			return false;
		}

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent_post = get_post( $parent_id );
			if ( ! $parent_post instanceof WP_Post || 'publish' !== $parent_post->post_status || '' !== (string) $parent_post->post_password ) {
				return false;
			}

			$parent = wc_get_product( $parent_id );
			if ( $parent instanceof WC_Product && 'hidden' === $parent->get_catalog_visibility() ) {
				return $product->is_purchasable();
			}

			return true;
		}

		return 'hidden' !== $product->get_catalog_visibility() || $product->is_purchasable();
	}

	/**
	 * Public rows grouped once: webshop (all Woo + webshop catalog) and each outlet.
	 *
	 * @return array{webshop: array<int, array<string, string>>, outlets: array<string, array<int, array<string, string>>>}
	 */
	public function collect_public_by_scope() {
		$bundle = self::as_visitor(
			function () {
				return CX_Sidrena_I18n::instance()->run_in_default_language(
					function () {
						return $this->collect_public_by_scope_now();
					}
				);
			}
		);

		$bundle['webshop'] = $this->finish_public_rows( $bundle['webshop'] );
		foreach ( $bundle['outlets'] as $oznaka => $rows ) {
			$bundle['outlets'][ $oznaka ] = $this->finish_public_rows( $rows );
		}

		return $bundle;
	}

	/**
	 * Public rows grouped once, while the default language is already current.
	 *
	 * @return array{webshop: array<int, array<string, string>>, outlets: array<string, array<int, array<string, string>>>}
	 */
	private function collect_public_by_scope_now() {
		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			$web     = $this->collect_rows( false, true, null, '' );
			$outlets = array();
			foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
				$outlets[ $outlet['oznaka'] ] = $this->collect_rows( false, true, isset( $outlet['products'] ) ? $outlet['products'] : array(), $outlet['oznaka'] );
			}

			return array(
				'webshop' => $web,
				'outlets' => $outlets,
			);
		}

		$woo = $this->collect_woo_rows( false, true );

		$web = $woo;
		$this->append_extra_catalog_rows( $web, false, true, '' );

		$places  = CX_Sidrena_Poslovnice::instance();
		$outlets = array();
		foreach ( $places->with_products() as $outlet ) {
			$ids  = isset( $outlet['products'] ) ? $outlet['products'] : array();
			$mine = 'svi' === $places->dodjela( $outlet ) ? $woo : $this->filter_woo_rows_for_assigned_ids( $woo, $ids );
			$rows = $this->apply_outlet_dostupnost( $mine, $outlet['oznaka'] );
			$this->append_extra_catalog_rows( $rows, false, true, $outlet['oznaka'] );
			$outlets[ $outlet['oznaka'] ] = $rows;
		}

		return array(
			'webshop' => $web,
			'outlets' => $outlets,
		);
	}

	/**
	 * Keep Woo rows whose id is in the outlet assignment, expanding variable parents.
	 *
	 * @param array<int, array<string, string>> $woo_rows  Full Woo public rows.
	 * @param array<int, string>                $only_ids  Assigned product ids.
	 * @return array<int, array<string, string>>
	 */
	public function filter_woo_rows_for_assigned_ids( $woo_rows, $only_ids ) {
		$expanded = $this->collect_rows_for_ids( $only_ids, false, true );
		$by_id    = array();
		foreach ( $woo_rows as $row ) {
			if ( isset( $row['id'] ) && '' !== (string) $row['id'] ) {
				$by_id[ (string) $row['id'] ] = $row;
			}
		}

		$out = array();
		foreach ( $expanded as $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' !== $id && isset( $by_id[ $id ] ) ) {
				$out[] = $by_id[ $id ];
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Replace WooCommerce stock on outlet rows with the availability stored for that poslovnica.
	 * Catalog rows (id prefix d) keep the flag imported on that row.
	 *
	 * @param array<int, array<string, string>> $rows   Feed rows.
	 * @param string                            $oznaka Outlet code.
	 * @return array<int, array<string, string>>
	 */
	public function apply_outlet_dostupnost( $rows, $oznaka ) {
		$map = $this->stock_map_for_source_language( CX_Sidrena_Poslovnice::instance()->stock_map( $oznaka ) );
		foreach ( $rows as $index => $row ) {
			$id = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( '' === $id || 'd' === $id[0] ) {
				continue;
			}
			$rows[ $index ]['dostupnost'] = CX_Sidrena_Money::dostupnost_from_outlet_map( $id, $map );
		}

		return $rows;
	}

	/**
	 * Polylang slug for the catalog query. Empty when Polylang is not active.
	 *
	 * @return string
	 */
	private function polylang_catalog_lang() {
		if ( ! function_exists( 'pll_default_language' ) ) {
			return '';
		}

		$lang = CX_Sidrena_I18n::instance()->get_default_language();
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Outlet availability keyed by the default-language product id.
	 *
	 * A flag stored on a translation still matches that source row. The saved
	 * outlet option is left as the merchant stored it.
	 *
	 * @param array<string, string> $map Product id => dostupno|nedostupno.
	 * @return array<string, string>
	 */
	private function stock_map_for_source_language( $map ) {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$i18n = CX_Sidrena_I18n::instance();
		if ( ! $i18n->uses_post_translations() ) {
			return $map;
		}

		$out = array();
		foreach ( $map as $id => $flag ) {
			$source = $i18n->source_product_id( (int) $id );
			if ( $source <= 0 ) {
				continue;
			}

			$key = (string) $source;
			if ( (string) $id === $key || ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $flag;
			}
		}

		return $out;
	}
}
