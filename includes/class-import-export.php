<?php
/**
 * WooCommerce product CSV import / export columns.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV mapping.
 */
class CX_Sidrena_Import_Export {

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
	 * Register CSV filters.
	 */
	private function __construct() {
		add_filter( 'woocommerce_product_export_column_names', array( $this, 'export_columns' ) );
		add_filter( 'woocommerce_product_export_product_default_columns', array( $this, 'export_columns' ) );
		add_filter( 'woocommerce_product_export_product_column_sidrena_cijena', array( $this, 'export_price' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_cijena_datum', array( $this, 'export_date' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_cijena_cjm', array( $this, 'export_cjm' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_cijena_cjm_suffix', array( $this, 'export_cjm_suffix' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_naziv_posebnog_oblika', array( $this, 'export_oblik' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_jedinica_mjere', array( $this, 'export_jedinica' ), 10, 2 );
		add_filter( 'woocommerce_product_export_product_column_sidrena_cijena_jedinice_mjere', array( $this, 'export_jedinica_cijena' ), 10, 2 );
		add_filter( 'woocommerce_csv_product_import_mapping_options', array( $this, 'import_options' ) );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', array( $this, 'import_default_columns' ) );
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'import_values' ), 10, 2 );
	}

	/**
	 * Add export columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function export_columns( $columns ) {
		$columns['sidrena_cijena']            = __( 'Sidrena cijena', 'cx-sidrena' );
		$columns['sidrena_cijena_datum']      = __( 'Sidrena cijena date', 'cx-sidrena' );
		$columns['sidrena_cijena_cjm']                 = __( 'Sidrena cijena CJM', 'cx-sidrena' );
		$columns['sidrena_cijena_cjm_suffix']          = __( 'Sidrena cijena CJM suffix', 'cx-sidrena' );
		$columns['sidrena_naziv_posebnog_oblika']      = __( 'Name of special form of sale', 'cx-sidrena' );
		$columns['sidrena_jedinica_mjere']             = __( 'Unit of measure', 'cx-sidrena' );
		$columns['sidrena_cijena_jedinice_mjere']      = __( 'Unit price', 'cx-sidrena' );
		return $columns;
	}

	/**
	 * Export price cell.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_price( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_price( $product->get_id() );
	}

	/**
	 * Export date cell.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_date( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_date( $product->get_id() );
	}

	/**
	 * Export CJM toggle.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_cjm( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->is_cjm_enabled( $product->get_id() ) ? 'yes' : 'no';
	}

	/**
	 * Export CJM suffix.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_cjm_suffix( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_cjm_suffix( $product->get_id() );
	}

	/**
	 * Export the special-sale name override.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_oblik( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_oblik( $product->get_id() );
	}

	/**
	 * Export the unit of measure.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_jedinica( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_jedinica( $product->get_id() );
	}

	/**
	 * Export the unit price.
	 *
	 * @param mixed      $value   Default.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function export_jedinica_cijena( $value, $product ) {
		return CX_Sidrena_Product_Meta::instance()->get_jedinica_cijena( $product->get_id() );
	}

	/**
	 * Import mapping labels.
	 *
	 * @param array<string, string> $options Options.
	 * @return array<string, string>
	 */
	public function import_options( $options ) {
		$options['sidrena_cijena']            = __( 'Sidrena cijena', 'cx-sidrena' );
		$options['sidrena_cijena_datum']      = __( 'Sidrena cijena date', 'cx-sidrena' );
		$options['sidrena_cijena_cjm']        = __( 'Sidrena cijena CJM', 'cx-sidrena' );
		$options['sidrena_cijena_cjm_suffix']     = __( 'Sidrena cijena CJM suffix', 'cx-sidrena' );
		$options['sidrena_naziv_posebnog_oblika'] = __( 'Name of special form of sale', 'cx-sidrena' );
		$options['sidrena_jedinica_mjere']        = __( 'Unit of measure', 'cx-sidrena' );
		$options['sidrena_cijena_jedinice_mjere'] = __( 'Unit price', 'cx-sidrena' );
		return $options;
	}

	/**
	 * Auto-map CSV headers.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function import_default_columns( $columns ) {
		$columns[ __( 'Sidrena cijena', 'cx-sidrena' ) ]            = 'sidrena_cijena';
		$columns[ __( 'Sidrena cijena date', 'cx-sidrena' ) ]      = 'sidrena_cijena_datum';
		$columns[ __( 'Sidrena cijena CJM', 'cx-sidrena' ) ]        = 'sidrena_cijena_cjm';
		$columns[ __( 'Sidrena cijena CJM suffix', 'cx-sidrena' ) ] = 'sidrena_cijena_cjm_suffix';
		$columns['Sidrena cijena']                                     = 'sidrena_cijena';
		$columns['Sidrena cijena datum']                               = 'sidrena_cijena_datum';
		$columns['Sidrena cijena CJM']                                 = 'sidrena_cijena_cjm';
		$columns['Sidrena cijena CJM dodatak']                         = 'sidrena_cijena_cjm_suffix';
		$columns[ __( 'Name of special form of sale', 'cx-sidrena' ) ] = 'sidrena_naziv_posebnog_oblika';
		$columns[ __( 'Unit of measure', 'cx-sidrena' ) ]                = 'sidrena_jedinica_mjere';
		$columns[ __( 'Unit price', 'cx-sidrena' ) ]      = 'sidrena_cijena_jedinice_mjere';
		$columns['Naziv posebnog oblika prodaje'] = 'sidrena_naziv_posebnog_oblika';
		$columns['Jedinica mjere']                = 'sidrena_jedinica_mjere';
		$columns['Cijena za jedinicu mjere']      = 'sidrena_cijena_jedinice_mjere';
		// The English names too: a file exported by an admin who uses WordPress in English (or on an English shop)
		// must import on a Croatian one, and the other way round.
		$columns['Sidrena cijena date']          = 'sidrena_cijena_datum';
		$columns['Sidrena cijena CJM suffix']    = 'sidrena_cijena_cjm_suffix';
		$columns['Name of special form of sale'] = 'sidrena_naziv_posebnog_oblika';
		$columns['Unit of measure']              = 'sidrena_jedinica_mjere';
		$columns['Unit price']                   = 'sidrena_cijena_jedinice_mjere';
		return $columns;
	}

	/**
	 * Apply imported values onto the product object meta.
	 *
	 * @param WC_Product           $product Product.
	 * @param array<string, mixed> $data    Row data.
	 * @return WC_Product
	 */
	public function import_values( $product, $data ) {
		$meta = CX_Sidrena_Product_Meta::instance();

		// An empty bazna or date cell leaves the product's bazna and date alone (note 1397/1). A file exported from
		// another tool often has the column with nothing in it; deleting the bazna let the next save fill it again
		// from the regular price with today's date. A bazna is removed on the product screen, never by an empty cell.
		if ( isset( $data['sidrena_cijena'] ) ) {
			$price = $meta->normalize_price( $data['sidrena_cijena'] );
			if ( '' !== $price ) {
				$product->update_meta_data( CX_SIDRENA_META_PRICE, $price );
			}
		}

		if ( isset( $data['sidrena_cijena_datum'] ) ) {
			$date = sanitize_text_field( (string) $data['sidrena_cijena_datum'] );
			if ( '' !== $date ) {
				$product->update_meta_data( CX_SIDRENA_META_DATE, $date );
			}
		}

		// The same for WooCommerce's own "Meta: <key>" columns, which it has already put on the product: an empty one of any
		// key of ours leaves what is stored (CX-SID-161). WooCommerce's export gives a column for every key any exported
		// product has, so its file imported back would otherwise delete our fields, or add empty ones where there were none.
		foreach ( isset( $data['meta_data'] ) ? (array) $data['meta_data'] : array() as $m ) {
			if ( isset( $m['key'], $m['value'] ) && self::is_ours( (string) $m['key'] ) && '' === trim( (string) $m['value'] ) ) {
				self::keep_stored( $product, $m['key'] );
			}
		}

		// Our other columns read as our own import does (CX-SID-161, KAKO-RADI 3.5): an empty cell changes nothing, '-'
		// deletes the field. CJM: 'yes' turns it on, anything else ('no', '-') off.
		$cjm = self::cell( $data, 'sidrena_cijena_cjm' );
		if ( null !== $cjm ) {
			if ( 'yes' === strtolower( $cjm ) ) {
				$product->update_meta_data( CX_SIDRENA_META_CJM, 'yes' );
			} else {
				$product->delete_meta_data( CX_SIDRENA_META_CJM );
			}
		}

		$suffix = self::cell( $data, 'sidrena_cijena_cjm_suffix' );
		if ( '' === $suffix ) {
			$product->delete_meta_data( CX_SIDRENA_META_CJM_SUFFIX );
		} elseif ( null !== $suffix ) {
			$product->update_meta_data( CX_SIDRENA_META_CJM_SUFFIX, $suffix );
		}

		// MJDD's name of the special form of sale, plain text (CX-SID-179: no forms of sale any more). As the unit below: an
		// empty cell changes nothing (Rook 1822: a file from another tool often has the column with nothing in it), '-'
		// deletes the name, any other text is the name.
		$name = self::cell( $data, 'sidrena_naziv_posebnog_oblika' );
		if ( '' === $name ) {
			$product->delete_meta_data( CX_SIDRENA_META_OBLIK );
		} elseif ( null !== $name ) {
			$product->update_meta_data( CX_SIDRENA_META_OBLIK, $name );
		}

		$unit = self::cell( $data, 'sidrena_jedinica_mjere' );
		if ( '' === $unit ) {
			$product->delete_meta_data( CX_SIDRENA_META_JEDINICA );
		} elseif ( null !== $unit ) {
			$product->update_meta_data( CX_SIDRENA_META_JEDINICA, $unit );
		}

		// A unit price is written only when it reads for sure, as in our own import (CX-SID-116): 12,5 and 12.50 are
		// stored as before (12.5, 12.50); a cell that is not an amount (abc, 1,2,3, 1.234) changes nothing.
		$unit_price = self::cell( $data, 'sidrena_cijena_jedinice_mjere' );
		if ( '' === $unit_price ) {
			$product->delete_meta_data( CX_SIDRENA_META_JEDINICA_CIJENA );
		} elseif ( null !== $unit_price ) {
			$read = CX_Sidrena_Money::read_amount( $unit_price );
			if ( '' === $read['error'] && '' !== $read['value'] ) {
				$product->update_meta_data( CX_SIDRENA_META_JEDINICA_CIJENA, $read['value'] );
			}
		}

		return $product;
	}

	/**
	 * One of our named cells in WooCommerce's CSV import: null for an empty cell (leave the field), '' for '-' (delete
	 * it), else the text as WooCommerce cleaned it.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @param string               $key  Column.
	 * @return string|null
	 */
	private static function cell( $data, $key ) {
		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		$value = sanitize_text_field( (string) $data[ $key ] );
		if ( '' === $value ) {
			return null;
		}

		return '-' === $value ? '' : $value;
	}

	/**
	 * Whether a meta key is one of ours: the bazna and its date, our product fields (CJM, unit, form of sale, brand,
	 * codes, a service's mark) and what the plugin keeps itself (lowest price, source, history).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_ours( $key ) {
		foreach ( array( '_sidrena_', '_bazna_', '_cx_sidrena_' ) as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Undo a change the importer made to one of our keys: back to what the database holds (nothing is written).
	 *
	 * @param WC_Product $product Product being imported.
	 * @param string     $key     Meta key.
	 */
	private static function keep_stored( $product, $key ) {
		$id = (int) $product->get_id();
		if ( $id > 0 && metadata_exists( 'post', $id, $key ) ) {
			$product->update_meta_data( $key, get_post_meta( $id, $key, true ) );
		} else {
			$product->delete_meta_data( $key );
		}
	}
}
