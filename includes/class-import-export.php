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

		if ( isset( $data['sidrena_cijena'] ) ) {
			$price = $meta->normalize_price( $data['sidrena_cijena'] );
			if ( '' === $price ) {
				$product->delete_meta_data( CX_SIDRENA_META_PRICE );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_PRICE, $price );
			}
		}

		if ( isset( $data['sidrena_cijena_datum'] ) ) {
			$date = sanitize_text_field( (string) $data['sidrena_cijena_datum'] );
			if ( '' === $date ) {
				$product->delete_meta_data( CX_SIDRENA_META_DATE );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_DATE, $date );
			}
		}

		if ( isset( $data['sidrena_cijena_cjm'] ) ) {
			$cjm = 'yes' === strtolower( trim( (string) $data['sidrena_cijena_cjm'] ) ) ? 'yes' : '';
			if ( '' === $cjm ) {
				$product->delete_meta_data( CX_SIDRENA_META_CJM );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_CJM, 'yes' );
			}
		}

		if ( isset( $data['sidrena_cijena_cjm_suffix'] ) ) {
			$suffix = sanitize_text_field( (string) $data['sidrena_cijena_cjm_suffix'] );
			if ( '' === $suffix ) {
				$product->delete_meta_data( CX_SIDRENA_META_CJM_SUFFIX );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_CJM_SUFFIX, $suffix );
			}
		}

		if ( isset( $data['sidrena_naziv_posebnog_oblika'] ) ) {
			$name = sanitize_text_field( (string) $data['sidrena_naziv_posebnog_oblika'] );
			if ( '' === $name ) {
				$product->delete_meta_data( CX_SIDRENA_META_OBLIK );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_OBLIK, $name );
			}
		}

		if ( isset( $data['sidrena_jedinica_mjere'] ) ) {
			$unit = sanitize_text_field( (string) $data['sidrena_jedinica_mjere'] );
			if ( '' === $unit ) {
				$product->delete_meta_data( CX_SIDRENA_META_JEDINICA );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_JEDINICA, $unit );
			}
		}

		if ( isset( $data['sidrena_cijena_jedinice_mjere'] ) ) {
			$unit_price = $meta->normalize_price( $data['sidrena_cijena_jedinice_mjere'] );
			if ( '' === $unit_price ) {
				$product->delete_meta_data( CX_SIDRENA_META_JEDINICA_CIJENA );
			} else {
				$product->update_meta_data( CX_SIDRENA_META_JEDINICA_CIJENA, $unit_price );
			}
		}

		return $product;
	}
}
