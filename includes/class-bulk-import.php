<?php
/**
 * Bulk CSV / XML price import matched by SKU, then by the existing id column.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin AJAX importer.
 */
class CX_Sidrena_Bulk_Import {

	const BATCH_SIZE = 40;
	const MAX_ROWS   = 25000;
	const MAX_BYTES  = 20971520;
	const TRANSIENT  = 'cx_sidrena_import_';

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
	 * Register AJAX.
	 */
	private function __construct() {
		add_action( 'wp_ajax_sidrena_cijena_import_parse', array( $this, 'ajax_parse' ) );
		add_action( 'wp_ajax_sidrena_cijena_import_run', array( $this, 'ajax_run' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'purge_legacy_upload_imports' ) );
			add_action( 'admin_init', array( $this, 'purge_old_jobs' ) );
		}
	}

	/**
	 * With WooCommerce the bazna cijena is imported only through Import (CX-SID-22), with the client's confirmation, so
	 * this import is not offered there (CX-SID-43). A Settings page opened before the update still has the form and a
	 * valid nonce: its requests are answered here, before anything is read or written. The actions stay registered so
	 * that such a page shows this message, not WordPress's bare "0".
	 */
	private function refuse_with_woocommerce() {
		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			wp_send_json_error( array( 'message' => __( 'Bulk import is no longer available with WooCommerce. Import bazna cijene on the Import tab.', 'cx-sidrena' ) ) );
		}
	}

	/**
	 * Upload + parse a CSV/XML file into a staged JSON job.
	 */
	public function ajax_parse() {
		check_ajax_referer( 'sidrena_cijena_import', 'nonce' );
		$this->refuse_with_woocommerce();

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}

		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a CSV or XML file.', 'cx-sidrena' ) ) );
		}

		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'File upload failed.', 'cx-sidrena' ) ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'xml', 'txt' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only CSV and XML are allowed.', 'cx-sidrena' ) ) );
		}

		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'The file is not valid.', 'cx-sidrena' ) ) );
		}

		$bytes = filesize( $tmp );
		if ( false === $bytes || $bytes > self::MAX_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'The file is larger than 20 MB.', 'cx-sidrena' ) ) );
		}

		$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === trim( $contents ) ) {
			wp_send_json_error( array( 'message' => __( 'The file is empty.', 'cx-sidrena' ) ) );
		}

		$parser   = CX_Sidrena_Import_Parse::instance();
		$contents = $parser->normalize_encoding( $contents );
		$parsed   = ( 'xml' === $ext ) ? $parser->parse_xml( $contents ) : $parser->parse_csv( $contents );

		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		$dir = $this->get_import_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			wp_send_json_error( array( 'message' => __( 'The import folder could not be created.', 'cx-sidrena' ) ) );
		}

		$this->ensure_import_dir_protected( $dir );
		$this->purge_old_jobs();

		$token = strtolower( wp_generate_password( 32, false, false ) );
		$path  = $this->import_job_path( $token, false );
		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => __( 'The temporary import file could not be saved.', 'cx-sidrena' ) ) );
		}
		$json  = wp_json_encode(
			array(
				'fields' => $parsed['fields'],
				'rows'   => $parsed['rows'],
			)
		);

		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_send_json_error( array( 'message' => __( 'The temporary import file could not be saved.', 'cx-sidrena' ) ) );
		}

		set_transient(
			self::TRANSIENT . $token,
			array(
				'total'    => count( $parsed['rows'] ),
				'filename' => $name,
				'created'  => time(),
			),
			HOUR_IN_SECONDS
		);

		wp_send_json_success(
			array(
				'token'    => $token,
				'fields'   => $parsed['fields'],
				'total'    => count( $parsed['rows'] ),
				'preview'  => array_slice( $parsed['rows'], 0, 4 ),
				'filename' => $name,
				'guessed'  => array(
					'sku'             => $parser->guess_sku_field( $parsed['fields'] ),
					'name'            => $parser->guess_named_field( $parsed['fields'], array( 'naziv', 'name', 'title', 'proizvod', 'product', 'product_name', 'usluga' ) ),
					'url'             => $parser->guess_named_field( $parsed['fields'], array( 'url', 'link', 'permalink', 'href' ) ),
					'regular'         => $parser->guess_named_field( $parsed['fields'], array( 'cijena_redovna', 'maloprodajna_cijena', 'maloprodajna', 'regular_price', 'regular', 'price', 'cijena', 'cijena_s_pdvom', 'cijena_s_pdv_om' ) ),
					'sale'            => $parser->guess_named_field( $parsed['fields'], array( 'cijena_akcijska', 'sale_price', 'sale', 'akcijska' ) ),
					'sidrena'         => $parser->guess_named_field( $parsed['fields'], array( 'sidrena_cijena', 'sidrena', 'bazna_cijena', 'anchor_price' ) ),
					'datum'           => $parser->guess_named_field( $parsed['fields'], array( 'sidrena_datum', 'sidrena_cijena_datum', 'datum', 'sidrena_datum', 'datum_sidrene' ) ),
					'vrsta'           => $parser->guess_named_field( $parsed['fields'], array( 'vrsta', 'tip', 'type', 'usluga_proizvod' ) ),
					'poslovnica'      => $parser->guess_named_field( $parsed['fields'], array( 'poslovnica', 'oznaka', 'outlet' ) ),
					'jedinica'        => $parser->guess_named_field( $parsed['fields'], array( 'jedinica_mjere', 'jedinica', 'unit', 'unit_of_measure', 'mjerna_jedinica' ) ),
					'jedinica_cijena' => $parser->guess_named_field( $parsed['fields'], array( 'cijena_jedinice_mjere', 'cijena_jedinice', 'cijena_za_jedinicu', 'cijena_za_jedinicu_mjere', 'unit_price' ) ),
					'marka'           => $parser->guess_named_field( $parsed['fields'], array( 'marka', 'brand', 'proizvodjac', 'manufacturer' ) ),
					'oblik'           => $parser->guess_named_field( $parsed['fields'], array( 'naziv_posebnog_oblika', 'poseban_oblik', 'oblik', 'naziv_posebnog_oblika_prodaje' ) ),
					'cjm'             => $parser->guess_named_field( $parsed['fields'], array( 'cjm', 'sidrena_cijena_cjm' ) ),
					'cjm_suffix'      => $parser->guess_named_field( $parsed['fields'], array( 'cjm_suffix', 'cjm_dodatak', 'sidrena_cijena_cjm_suffix' ) ),
					'dostupnost'      => $parser->guess_named_field( $parsed['fields'], array( 'dostupnost', 'availability', 'stock_status', 'zaliha' ) ),
					'id'              => $parser->guess_named_field( $parsed['fields'], array( 'id' ) ),
				),
			)
		);
	}

	/**
	 * Process one batch of mapped rows.
	 */
	public function ajax_run() {
		check_ajax_referer( 'sidrena_cijena_import', 'nonce' );
		$this->refuse_with_woocommerce();

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}

		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$map    = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$job  = get_transient( self::TRANSIENT . $token );
		$path = $this->import_job_path( $token, true );
		if ( ! is_array( $job ) || '' === $path ) {
			wp_send_json_error( array( 'message' => __( 'Import expired. Upload the file again.', 'cx-sidrena' ) ) );
		}

		$sku_field = isset( $map['sku'] ) ? sanitize_text_field( $map['sku'] ) : '';
		$id_field  = isset( $map['id'] ) ? sanitize_text_field( $map['id'] ) : '';
		$woo       = CX_Sidrena_Plugin::woocommerce_active();

		if ( $woo && '' === $sku_field && '' === $id_field ) {
			wp_send_json_error( array( 'message' => __( 'Select the column for SKU or ID.', 'cx-sidrena' ) ) );
		}

		$import_outlet = isset( $_POST['import_poslovnica'] ) ? sanitize_text_field( wp_unslash( $_POST['import_poslovnica'] ) ) : '';
		if ( '' === $import_outlet ) {
			wp_send_json_error( array( 'message' => __( 'Select the outlet to import into.', 'cx-sidrena' ) ) );
		}
		if ( 'webshop' !== $import_outlet && null === CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $import_outlet ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected outlet does not exist.', 'cx-sidrena' ) ) );
		}

		$payload = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $payload ) || empty( $payload['rows'] ) || ! is_array( $payload['rows'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The temporary import file cannot be read.', 'cx-sidrena' ) ) );
		}

		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( '' !== $sku_field && ! in_array( $sku_field, $fields, true ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected SKU column does not exist in the file.', 'cx-sidrena' ) ) );
		}
		if ( '' !== $id_field && ! in_array( $id_field, $fields, true ) ) {
			wp_send_json_error( array( 'message' => __( 'The selected ID column does not exist in the file.', 'cx-sidrena' ) ) );
		}

		$regular            = $this->mapped_field( $map, 'regular', $fields );
		$sale               = $this->mapped_field( $map, 'sale', $fields );
		$sidrena            = $this->mapped_field( $map, 'sidrena', $fields );
		$name               = $this->mapped_field( $map, 'name', $fields );
		$url                = $this->mapped_field( $map, 'url', $fields );
		$dostupnost         = $this->mapped_field( $map, 'dostupnost', $fields );
		$datum              = $this->mapped_field( $map, 'datum', $fields );
		$jedinica           = $this->mapped_field( $map, 'jedinica', $fields );
		$jedinica_cijena    = $this->mapped_field( $map, 'jedinica_cijena', $fields );
		$marka              = $this->mapped_field( $map, 'marka', $fields );
		$oblik              = $this->mapped_field( $map, 'oblik', $fields );
		$cjm                = $this->mapped_field( $map, 'cjm', $fields );
		$cjm_suffix         = $this->mapped_field( $map, 'cjm_suffix', $fields );
		$vrsta              = $this->mapped_field( $map, 'vrsta', $fields );
		$poslovnica         = $this->mapped_field( $map, 'poslovnica', $fields );
		$retail_is_display  = $this->is_display_retail_column( $regular );
		$sidrena_is_display = $this->file_has_display_retail( $fields );

		$rows      = $payload['rows'];
		$total     = count( $rows );
		$slice     = array_slice( $rows, $offset, self::BATCH_SIZE );
		$updated   = isset( $_POST['updated'] ) ? absint( wp_unslash( $_POST['updated'] ) ) : 0;
		$skipped   = isset( $_POST['skipped'] ) ? absint( wp_unslash( $_POST['skipped'] ) ) : 0;
		$missing   = isset( $_POST['missing'] ) ? absint( wp_unslash( $_POST['missing'] ) ) : 0;
		$created   = isset( $_POST['created'] ) ? absint( wp_unslash( $_POST['created'] ) ) : 0;
		$processed = $offset;

		if ( $woo ) {
			$meta            = CX_Sidrena_Product_Meta::instance();
			$catalog         = CX_Sidrena_Catalog::instance();
			$catalog_started = false;
			foreach ( $slice as $row ) {
				++$processed;
				if ( ! is_array( $row ) ) {
					++$skipped;
					continue;
				}

				$sku        = ( '' !== $sku_field && isset( $row[ $sku_field ] ) ) ? trim( (string) $row[ $sku_field ] ) : '';
				$id_raw     = ( '' !== $id_field && isset( $row[ $id_field ] ) ) ? trim( (string) $row[ $id_field ] ) : '';
				$id_target  = $this->import_id_target( $id_raw, true );
				$product_id = ( '' !== $sku ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
				$vrsta_raw  = $this->mapped_text( $row, $vrsta );
				$is_service = null !== $vrsta_raw && 'usluga' === $catalog->normalize_vrsta( $vrsta_raw );
				if ( ( ! $product_id || $is_service ) && ! $is_service && $id_target['woo'] > 0 && function_exists( 'wc_get_product' ) && wc_get_product( $id_target['woo'] ) ) {
					$product_id = $id_target['woo'];
				}
				if ( ! $product_id || $is_service ) {
					$catalog_id = $id_target['catalog'];
					$name_value = trim( (string) ( $name && isset( $row[ $name ] ) ? $row[ $name ] : '' ) );
					if ( '' === $sku && '' === $name_value && '' === $catalog_id ) {
						++$skipped;
						continue;
					}
					if ( ! $catalog_started ) {
						$catalog->begin_batch();
						$catalog_started = true;
					}
					$item = $this->catalog_item_from_map( $row, $sku_field, $name, $url, $regular, $sale, $sidrena, $dostupnost, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, $vrsta, $datum, $poslovnica );
					if ( '' !== $catalog_id ) {
						$item['id'] = $catalog_id;
					}
					$result = $catalog->upsert_working_row( $item );
					if ( 'updated' === $result ) {
						++$updated;
					} elseif ( 'created' === $result ) {
						++$created;
					} else {
						++$skipped;
					}
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					++$missing;
					continue;
				}

				$changed       = false;
				$product_dirty = false;
				$oblik_value   = $this->mapped_text( $row, $oblik );

				if ( $retail_is_display ) {
					$price = $this->import_price( isset( $row[ $regular ] ) ? $row[ $regular ] : '' );
					if ( '' !== $price ) {
						$target_sale = null !== $oblik_value;
						$current     = $target_sale ? (string) $product->get_sale_price() : (string) $product->get_regular_price();
						$stored      = $meta->display_price_to_stored( $product, $price, $current );
						if ( $current !== (string) $stored ) {
							if ( $target_sale ) {
								$product->set_sale_price( $stored );
							} else {
								$product->set_regular_price( $stored );
							}
							$changed       = true;
							$product_dirty = true;
						}
					}
				} else {
					if ( $regular ) {
						$price = $this->import_price( isset( $row[ $regular ] ) ? $row[ $regular ] : '' );
						if ( '' !== $price ) {
							$product->set_regular_price( $price );
							$changed       = true;
							$product_dirty = true;
						}
					}

					if ( $sale ) {
						$price = $this->import_price( isset( $row[ $sale ] ) ? $row[ $sale ] : '' );
						if ( '' !== $price ) {
							$product->set_sale_price( $price );
							$changed       = true;
							$product_dirty = true;
						}
					}
				}

				if ( $sidrena ) {
					$price = $this->import_price( isset( $row[ $sidrena ] ) ? $row[ $sidrena ] : '' );
					if ( '' !== $price ) {
						if ( $sidrena_is_display ) {
							$current = (string) $meta->get_price( $product_id );
							$price   = $meta->display_price_to_stored( $product, $price, $current );
							if ( $current !== (string) $price ) {
								$meta->set_price( $product_id, $price );
								$changed = true;
							}
						} else {
							$meta->set_price( $product_id, $price );
							$changed = true;
						}
					}
				}

				$date_value = $this->mapped_text( $row, $datum );
				if ( null !== $date_value ) {
					$meta->set_date( $product_id, $date_value );
					$changed = true;
				}

				$unit = $this->mapped_text( $row, $jedinica );
				if ( null !== $unit ) {
					$meta->set_jedinica( $product_id, $unit );
					$changed = true;
				}

				if ( $jedinica_cijena ) {
					$unit_price = $this->import_price( isset( $row[ $jedinica_cijena ] ) ? $row[ $jedinica_cijena ] : '' );
					if ( '' !== $unit_price ) {
						$meta->set_jedinica_cijena( $product_id, $unit_price );
						$changed = true;
					}
				}

				if ( null !== $oblik_value ) {
					$meta->set_oblik( $product_id, $oblik_value );
					$changed = true;
				}

				$marka_value = $this->mapped_text( $row, $marka );
				if ( null !== $marka_value && CX_Sidrena_Settings::instance()->assign_product_marka( $product, $marka_value ) ) {
					$changed = true;
				}

				$cjm_value = $this->mapped_text( $row, $cjm );
				if ( null !== $cjm_value ) {
					$meta->set_cjm_enabled( $product_id, $this->is_cjm_on( $cjm_value ) );
					$changed = true;
				}

				$suffix_value = $this->mapped_text( $row, $cjm_suffix );
				if ( null !== $suffix_value ) {
					$meta->set_cjm_suffix( $product_id, $suffix_value );
					$changed = true;
				}

				$stock_value = $this->mapped_text( $row, $dostupnost );
				$outlet_import = ( 'webshop' !== strtolower( $import_outlet ) );
				if ( $outlet_import ) {
					CX_Sidrena_Poslovnice::instance()->set_product_dostupnost( $import_outlet, $product_id, null === $stock_value ? '' : $stock_value );
					$changed = true;
				} elseif ( null !== $stock_value ) {
					$flag = CX_Sidrena_Catalog::instance()->normalize_dostupnost( $stock_value );
					$product->set_stock_status( 'nedostupno' === $flag ? 'outofstock' : 'instock' );
					$changed       = true;
					$product_dirty = true;
				}

				if ( $product_dirty ) {
					$product->save();
				}

				if ( $changed ) {
					++$updated;
				} else {
					++$skipped;
				}
			}

			if ( $catalog_started ) {
				$catalog->commit_batch( $processed >= $total );
			}
		} else {
			$catalog = CX_Sidrena_Catalog::instance();
			$catalog->begin_batch();

			foreach ( $slice as $row ) {
				++$processed;
				if ( ! is_array( $row ) ) {
					++$skipped;
					continue;
				}

				$item = $this->catalog_item_from_map( $row, $sku_field, $name, $url, $regular, $sale, $sidrena, $dostupnost, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, $vrsta, $datum, $poslovnica );
				$id_raw = ( '' !== $id_field && isset( $row[ $id_field ] ) ) ? trim( (string) $row[ $id_field ] ) : '';
				$catalog_id = $this->import_id_target( $id_raw, false )['catalog'];
				if ( '' !== $catalog_id ) {
					$item['id'] = $catalog_id;
				}

				$result = $catalog->upsert_working_row( $item );

				if ( 'updated' === $result ) {
					++$updated;
				} elseif ( 'created' === $result ) {
					++$created;
				} else {
					++$skipped;
				}
			}

			$done_batch = $processed >= $total;
			$catalog->commit_batch( $done_batch );
		}

		$done = $processed >= $total;
		if ( $done ) {
			wp_delete_file( $path );
			delete_transient( self::TRANSIENT . $token );
			if ( $woo && class_exists( 'CX_Sidrena_Export' ) ) {
				CX_Sidrena_Export::instance()->queue_public_xml();
			}
		}

		wp_send_json_success(
			array(
				'offset'    => $processed,
				'total'     => $total,
				'updated'   => $updated,
				'skipped'   => $skipped,
				'missing'   => $missing,
				'created'   => $created,
				'done'      => $done,
				'percent'   => $total ? (int) round( ( $processed / $total ) * 100 ) : 100,
			)
		);
	}

	/**
	 * Split an export id into a Woo product id or a manual catalog id.
	 *
	 * A leading d marks a manual row. That number is never looked up as a Woo product.
	 * With WooCommerce, a plain number is a product id. Without it, a plain number is a catalog id.
	 *
	 * @param string $raw Cell value.
	 * @param bool   $woo WooCommerce is active.
	 * @return array{woo: int, catalog: string}
	 */
	private function import_id_target( $raw, $woo ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array(
				'woo'     => 0,
				'catalog' => '',
			);
		}

		if ( preg_match( '/^d(\d+)$/i', $raw, $matches ) ) {
			return array(
				'woo'     => 0,
				'catalog' => $matches[1],
			);
		}

		if ( preg_match( '/^\d+$/', $raw ) ) {
			if ( $woo ) {
				return array(
					'woo'     => (int) $raw,
					'catalog' => '',
				);
			}

			return array(
				'woo'     => 0,
				'catalog' => $raw,
			);
		}

		return array(
			'woo'     => 0,
			'catalog' => '',
		);
	}

	/**
	 * Sanitize a mapped field name against available columns.
	 *
	 * @param array<string, mixed> $map    Posted map.
	 * @param string               $key    Map key.
	 * @param array<int, string>   $fields Allowed fields.
	 * @return string
	 */
	private function mapped_field( $map, $key, $fields ) {
		$value = isset( $map[ $key ] ) ? sanitize_text_field( $map[ $key ] ) : '';
		if ( '' === $value || ! in_array( $value, $fields, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Non-empty mapped cell, or null when the column is unmapped or blank.
	 *
	 * @param array<string, string> $row   Parsed row.
	 * @param string                $field Column name.
	 * @return string|null
	 */
	private function mapped_text( $row, $field ) {
		if ( '' === $field || ! is_array( $row ) || ! isset( $row[ $field ] ) ) {
			return null;
		}

		$value = trim( (string) $row[ $field ] );
		if ( '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Catalog payload from one mapped import row.
	 *
	 * @param array<string, string> $row             Parsed row.
	 * @param string                $sku_field       SKU column.
	 * @param string                $name            Name column.
	 * @param string                $url             URL column.
	 * @param string                $regular         Regular price column.
	 * @param string                $sale            Sale price column.
	 * @param string                $sidrena         Sidrena column.
	 * @param string                $dostupnost      Stock column.
	 * @param string                $jedinica        Unit column.
	 * @param string                $jedinica_cijena Unit price column.
	 * @param string                $marka           Brand column.
	 * @param string                $oblik           Sale form column.
	 * @param string                $cjm             CJM column.
	 * @param string                $cjm_suffix      CJM suffix column.
	 * @param string                $vrsta           Type column.
	 * @param string                $datum           Date column.
	 * @param string                $poslovnica      Outlet column.
	 * @return array<string, string>
	 */
	private function catalog_item_from_map( $row, $sku_field, $name, $url, $regular, $sale, $sidrena, $dostupnost, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, $vrsta, $datum, $poslovnica ) {
		$retail      = $regular && isset( $row[ $regular ] ) ? $this->import_price( $row[ $regular ] ) : '';
		$sale_amount = $sale && isset( $row[ $sale ] ) ? $this->import_price( $row[ $sale ] ) : '';
		if ( $this->is_display_retail_column( $regular ) && null !== $this->mapped_text( $row, $oblik ) ) {
			$regular_amount = '';
			if ( '' === $sale_amount && '' !== $retail ) {
				$sale_amount = $retail;
			}
		} else {
			$regular_amount = $retail;
		}

		$item = array(
			'naziv'           => $name && isset( $row[ $name ] ) ? $row[ $name ] : '',
			'sku'             => $sku_field && isset( $row[ $sku_field ] ) ? $row[ $sku_field ] : '',
			'url'             => $url && isset( $row[ $url ] ) ? $row[ $url ] : '',
			'cijena_redovna'  => $regular_amount,
			'cijena_akcijska' => $sale_amount,
			'sidrena_cijena'  => $sidrena && isset( $row[ $sidrena ] ) ? $this->import_price( $row[ $sidrena ] ) : '',
		);
		$this->assign_mapped_text( $item, 'marka', $row, $marka );
		$this->assign_mapped_text( $item, 'dostupnost', $row, $dostupnost );
		$this->assign_mapped_text( $item, 'jedinica_mjere', $row, $jedinica );
		$this->assign_mapped_text( $item, 'naziv_posebnog_oblika', $row, $oblik );
		$this->assign_mapped_text( $item, 'cjm', $row, $cjm );
		$this->assign_mapped_text( $item, 'cjm_suffix', $row, $cjm_suffix );
		$this->assign_mapped_text( $item, 'vrsta', $row, $vrsta );
		$this->assign_mapped_text( $item, 'sidrena_datum', $row, $datum );
		$this->assign_mapped_text( $item, 'poslovnica', $row, $poslovnica );
		if ( isset( $_POST['import_poslovnica'] ) ) {
			$target = sanitize_text_field( wp_unslash( $_POST['import_poslovnica'] ) );
			$item['poslovnica'] = ( '' === $target || 'webshop' === strtolower( $target ) ) ? '' : $target;
		}
		if ( $jedinica_cijena ) {
			$unit_price = $this->import_price( isset( $row[ $jedinica_cijena ] ) ? $row[ $jedinica_cijena ] : '' );
			if ( '' !== $unit_price ) {
				$item['cijena_jedinice_mjere'] = $unit_price;
			}
		}

		return $item;
	}

	/**
	 * Copy a non-empty mapped cell onto a catalog payload.
	 *
	 * @param array<string, string> $item  Payload.
	 * @param string                $key   Catalog key.
	 * @param array<string, string> $row   Parsed row.
	 * @param string                $field Column name.
	 */
	private function assign_mapped_text( &$item, $key, $row, $field ) {
		$value = $this->mapped_text( $row, $field );
		if ( null === $value ) {
			return;
		}

		$item[ $key ] = $value;
	}

	/**
	 * Public price-list column mapped onto Redovna cijena.
	 *
	 * @param string $field Column name.
	 * @return bool
	 */
	private function is_display_retail_column( $field ) {
		$field = strtolower( remove_accents( (string) $field ) );
		$field = str_replace( array( ' ', '-', '.' ), '_', $field );

		return in_array( $field, array( 'maloprodajna_cijena', 'maloprodajna' ), true );
	}

	/**
	 * Whether this file is a public price list (display amounts, not stored ones).
	 *
	 * @param array<int, string> $fields Header fields.
	 * @return bool
	 */
	private function file_has_display_retail( $fields ) {
		foreach ( (array) $fields as $field ) {
			if ( $this->is_display_retail_column( $field ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an imported CJM cell turns the suffix on.
	 *
	 * @param string $value Raw cell.
	 * @return bool
	 */
	private function is_cjm_on( $value ) {
		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( 'yes', '1', 'true' ), true );
	}

	/**
	 * Normalize an imported price string.
	 *
	 * @param mixed $value Raw cell.
	 * @return string
	 */
	private function import_price( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^\d,.\-]/u', '', $value );

		if ( class_exists( 'CX_Sidrena_Product_Meta' ) ) {
			return CX_Sidrena_Product_Meta::instance()->normalize_price( $value );
		}

		return CX_Sidrena_Catalog::instance()->normalize_price( $value );
	}

	/**
	 * Import staging directory, outside the web root.
	 *
	 * @return string
	 */
	private function get_import_dir() {
		$base = get_temp_dir();
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . 'sidrena-cijena-imports';
	}

	/**
	 * JSON path for a token. The path is derived here, never read from the transient.
	 *
	 * @param string $token      Job token.
	 * @param bool   $must_exist False while the file is about to be created.
	 * @return string
	 */
	private function import_job_path( $token, $must_exist ) {
		$token = sanitize_key( (string) $token );
		if ( strlen( $token ) < 12 ) {
			return '';
		}

		$dir = $this->get_import_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return '';
		}

		$real_dir = wp_normalize_path( $real_dir );
		$path     = $real_dir . '/' . $token . '.json';

		if ( ! $must_exist ) {
			return $path;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return '';
		}

		$real = wp_normalize_path( $real );
		if ( 0 !== strpos( $real, trailingslashit( $real_dir ) ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Hide the imports directory if the temp path is ever web-accessible.
	 *
	 * @param string $dir Directory.
	 */
	private function ensure_import_dir_protected( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$web_config = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			$config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";
			file_put_contents( $web_config, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Delete staged JSON left in the old public uploads folder.
	 */
	public function purge_legacy_upload_imports() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}

		$dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . 'cx-sidrena/imports' );
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $dir ) . '*.json' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Remove staged import files older than an hour (the job's transient lasts an hour too, so such a file can no
	 * longer run). Also on admin_init: with WooCommerce ajax_parse is refused (CX-SID-43), so the files a Settings page
	 * opened before the update staged would otherwise stay, with the uploaded SKUs and prices.
	 */
	public function purge_old_jobs() {
		$dir = $this->get_import_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $dir ) . '*.json' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff = time() - HOUR_IN_SECONDS;
		foreach ( $files as $file ) {
			if ( filemtime( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}
}
