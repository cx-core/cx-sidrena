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
	/**
	 * The job of a bulk import lives in the database (CX-SID-124): rows of the options table, autoload no, written and read
	 * with $wpdb straight, never through a transient, the object cache or the server's temporary folder. On live S10 a
	 * transient lived one request only: W3 Total Cache's object-cache.php, with its object cache off, makes WordPress
	 * believe it has a persistent cache, so set_transient() never reached the database ("Import expired" right after the
	 * file was read). <JOB><token> is the job (when, who, the file's columns, how many rows and parts), <JOB><token>_<n> the
	 * file's rows in parts, <JOB><token>_w the catalog as the import has made it so far and the hash of the one it started from.
	 */
	const JOB        = 'cx_sidrena_imp_';
	const JOB_TTL    = HOUR_IN_SECONDS;
	const PART_ROWS  = 500;
	const PART_BYTES = 1048576;

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
			add_action( 'admin_init', array( $this, 'purge_expired_jobs' ) );
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

		$this->no_db_cache();
		$this->purge_old_jobs();
		$this->purge_expired_jobs();

		$token = $this->store_job( $parsed['fields'], $parsed['rows'], $name, 'xml' === $ext ? 'xml' : 'csv' );
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'The file could not be kept for the import in the database. Nothing was imported.', 'cx-sidrena' ) ) );
		}

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
					// Not 'poseban_oblik': since CX-SID-109 that column is DA/NE (is the retail price a special form's), never
					// empty, so every row read as a sale (Argus 110-D; the rest is CX-SID-114).
					'oblik'           => $parser->guess_named_field( $parsed['fields'], array( 'naziv_posebnog_oblika', 'oblik', 'naziv_posebnog_oblika_prodaje' ) ),
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

		$this->no_db_cache();
		$job = $this->load_job( $token );
		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'This import was not found or has expired (an import is kept for an hour after the file is read). Nothing was imported. Upload the file again.', 'cx-sidrena' ) ) );
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

		$fields = $job['fields'];
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
		$flag_field         = $this->special_form_flag_field( $fields );

		$total = (int) $job['total'];
		$slice = $this->job_rows( $token, $job, $offset, self::BATCH_SIZE );
		if ( null === $slice ) {
			$this->delete_job( $token );
			wp_send_json_error( array( 'message' => __( 'Part of this import is missing on the server. Nothing was imported. Upload the file again.', 'cx-sidrena' ) ) );
		}
		$updated   = isset( $_POST['updated'] ) ? absint( wp_unslash( $_POST['updated'] ) ) : 0;
		$skipped   = isset( $_POST['skipped'] ) ? absint( wp_unslash( $_POST['skipped'] ) ) : 0;
		$missing   = isset( $_POST['missing'] ) ? absint( wp_unslash( $_POST['missing'] ) ) : 0;
		$created   = isset( $_POST['created'] ) ? absint( wp_unslash( $_POST['created'] ) ) : 0;
		$special   = isset( $_POST['special'] ) ? absint( wp_unslash( $_POST['special'] ) ) : 0;
		$unpub     = isset( $_POST['unpub'] ) ? absint( wp_unslash( $_POST['unpub'] ) ) : 0;
		$unclear   = isset( $_POST['unclear'] ) ? absint( wp_unslash( $_POST['unclear'] ) ) : 0;
		$other     = isset( $_POST['other'] ) ? absint( wp_unslash( $_POST['other'] ) ) : 0; // rows of another price list (CX-SID-164)
		$examples  = array();
		$amounts   = array_filter( array( $regular, $sale, $sidrena, $jedinica_cijena ) );
		$from_xml  = isset( $job['kind'] ) && 'xml' === $job['kind'];
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
					if ( $retail_is_display && $this->special_form_unnamed( $row, $flag_field, $oblik ) ) {
						++$special;
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
					} elseif ( 'other' === $result ) {
						++$other;
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

				// No import writes the list of places (CX-SID-129): its writer saved the whole list as read at the start
				// of the step, so an Outlets save in between was lost. This branch is refused with WooCommerce anyway
				// (refuse_with_woocommerce, CX-SID-43), and without it an import into a place writes catalog rows only.
				$stock_value = $this->mapped_text( $row, $dostupnost );
				if ( null !== $stock_value ) {
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
			// Nothing is written until the import has ended (Kai, CX-SID-124 and 22's rule): the catalog as the import has
			// made it so far is kept in the job, and written once, at the end, if no one changed the catalog meanwhile.
			// The catalog it starts from is the one at its first step (not at reading the file: the owner may save the
			// product table in between, and the working copy then starts from that).
			$catalog = CX_Sidrena_Catalog::instance();
			$working = 0 === $offset ? array( 'catalog' => $this->catalog_sha(), 'rows' => null ) : $this->job_get( $token . '_w' );
			if ( ! is_array( $working ) || ! isset( $working['catalog'] ) || ( 0 !== $offset && ! is_array( $working['rows'] ) ) ) {
				$this->delete_job( $token );
				wp_send_json_error( array( 'message' => __( 'Part of this import is missing on the server. Nothing was imported. Upload the file again.', 'cx-sidrena' ) ) );
			}
			$catalog->begin_batch( $working['rows'] );

			foreach ( $slice as $row ) {
				++$processed;
				if ( ! is_array( $row ) ) {
					++$skipped;
					continue;
				}
				// A price that cannot be read for sure leaves the whole row as it was (CX-SID-116, Kai): never a guess.
				$unread = $this->unread_amounts( $row, $amounts );
				if ( $unread ) {
					++$unclear;
					foreach ( $unread as $column => $value ) {
						if ( count( $examples ) < 5 ) {
							$examples[] = array(
								'line'   => $from_xml ? $processed : $processed + 1,
								'xml'    => $from_xml,
								'naziv'  => ( $name && isset( $row[ $name ] ) ) ? sanitize_text_field( (string) $row[ $name ] ) : '',
								'column' => sanitize_text_field( (string) $column ),
								'value'  => sanitize_text_field( (string) $value ),
							);
						}
					}
					continue;
				}
				if ( $retail_is_display && $this->special_form_unnamed( $row, $flag_field, $oblik ) ) {
					++$special;
					continue;
				}

				$item = $this->catalog_item_from_map( $row, $sku_field, $name, $url, $regular, $sale, $sidrena, $dostupnost, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, $vrsta, $datum, $poslovnica );
				$id_raw = ( '' !== $id_field && isset( $row[ $id_field ] ) ) ? trim( (string) $row[ $id_field ] ) : '';
				$catalog_id = $this->import_id_target( $id_raw, false )['catalog'];
				if ( '' !== $catalog_id ) {
					$item['id'] = $catalog_id;
				}

				$result = $catalog->upsert_working_row( $item );
				if ( in_array( $result, array( 'updated', 'created' ), true ) && ! $catalog->is_public_row( $catalog->last_upsert_row() ) ) {
					++$unpub; // a service without its date or bazna waits (CX-SID-130)
				}

				if ( 'updated' === $result ) {
					++$updated;
				} elseif ( 'created' === $result ) {
					++$created;
				} elseif ( 'other' === $result ) {
					++$other;
				} else {
					++$skipped;
				}
			}

			if ( $processed < $total ) {
				$kept = $this->job_put( $token . '_w', array( 'catalog' => $working['catalog'], 'rows' => $catalog->batch_rows() ) );
				$catalog->drop_batch();
				if ( ! $kept ) {
					$this->delete_job( $token );
					wp_send_json_error( array( 'message' => __( 'The import could not keep its progress in the database. Nothing was imported. Upload the file again.', 'cx-sidrena' ) ) );
				}
			} elseif ( $this->catalog_sha() !== $working['catalog'] ) {
				$catalog->drop_batch();
				$this->delete_job( $token );
				wp_send_json_error( array( 'message' => __( 'The price list was changed while the import was running (in another tab or by another user). Nothing was imported. Upload the file again.', 'cx-sidrena' ) ) );
			} else {
				$catalog->commit_batch( true );
			}
		}

		$done = $processed >= $total;
		if ( $done ) {
			$this->delete_job( $token );
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
				'special'   => $special,
				'unpub'     => $unpub,
				'unclear'   => $unclear,
				'other'     => $other,
				'unclear_examples' => $examples,
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
	 * The column that says whether the retail price is a special form of sale's (poseban_oblik, DA/NE since CX-SID-109;
	 * da/ne before), or ''.
	 *
	 * @param array<int, string> $fields Header fields.
	 * @return string
	 */
	private function special_form_flag_field( $fields ) {
		return CX_Sidrena_Import_Parse::instance()->guess_named_field( $fields, array( 'poseban_oblik' ) );
	}

	/**
	 * A row whose retail price is a special form of sale's (DA), with no name of that form in the file (the public price
	 * list has none): which price it is cannot be told, so the row is not imported (Rook, 110-D). Else it went in as the
	 * regular price.
	 *
	 * @param array<string, string> $row        Parsed row.
	 * @param string                $flag_field The DA/NE column, or ''.
	 * @param string                $oblik      The mapped name column, or ''.
	 * @return bool
	 */
	private function special_form_unnamed( $row, $flag_field, $oblik ) {
		$flag = $this->mapped_text( $row, $flag_field );
		if ( null === $flag || ! in_array( strtolower( $flag ), array( 'da', 'yes', '1', 'true' ), true ) ) {
			return false;
		}

		return null === $this->mapped_text( $row, $oblik );
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
		// Read only where it cannot be misread (CX-SID-116); a cell that is not read is empty here, and ajax_run() never
		// gets this far with such a row. 1.234,50 used to go through: it still does; 1,234.50 used to become empty.
		$value = CX_Sidrena_Money::read_amount( $value )['value'];

		if ( class_exists( 'CX_Sidrena_Product_Meta' ) ) {
			return CX_Sidrena_Product_Meta::instance()->normalize_price( $value );
		}

		return CX_Sidrena_Catalog::instance()->normalize_price( $value );
	}

	/**
	 * The mapped amount cells of a row that cannot be read for sure: 1.234 (a thousand, or one and a bit?), 1.234.567, a
	 * date (CX_Sidrena_Money::read_amount()).
	 *
	 * @param array<string, string> $row     Parsed row.
	 * @param array<int, string>    $columns Mapped amount columns.
	 * @return array<string, string> Column => cell.
	 */
	private function unread_amounts( $row, $columns ) {
		$out = array();
		foreach ( $columns as $column ) {
			$cell = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
			if ( '' !== CX_Sidrena_Money::read_amount( $cell )['error'] ) {
				$out[ $column ] = $cell;
			}
		}

		return $out;
	}

	/**
	 * Our own queries in an import request are never served from a database query cache (W3 Total Cache's
	 * dbcache.reject.constants, also other caches that honour it). The job is read straight from the table anyway.
	 */
	private function no_db_cache() {
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
	}

	/**
	 * Keep a read file as a new job. The job row first (not yet complete), then the parts, then the job complete: a
	 * request that dies halfway leaves a job that is never run and goes with the hour.
	 *
	 * @param array<int, string>                $fields Columns.
	 * @param array<int, array<string, string>> $rows   Rows.
	 * @param string                            $name   File name.
	 * @param string                            $kind   csv or xml (CX-SID-116: an example names a row or an XML item).
	 * @return string Token, or '' when it could not be kept.
	 */
	public function store_job( $fields, $rows, $name, $kind = 'csv' ) {
		$token = strtolower( wp_generate_password( 32, false, false ) );
		$rows  = array_values( (array) $rows );
		$job   = array(
			'created'  => time(),
			'user'     => get_current_user_id(),
			'filename' => (string) $name,
			'kind'     => 'xml' === $kind ? 'xml' : 'csv',
			'fields'   => array_values( (array) $fields ),
			'total'    => count( $rows ),
			'parts'    => array(),
			'complete' => false,
		);
		if ( ! $this->job_put( $token, $job ) ) {
			return '';
		}

		$part  = array();
		$bytes = 0;
		$from  = 0;
		foreach ( $rows as $i => $row ) {
			$size = strlen( (string) wp_json_encode( $row ) );
			if ( $part && ( count( $part ) >= self::PART_ROWS || $bytes + $size > self::PART_BYTES ) ) {
				if ( ! $this->job_put( $token . '_' . count( $job['parts'] ), $part ) ) {
					$this->delete_job( $token );
					return '';
				}
				$job['parts'][] = $from;
				$part           = array();
				$bytes          = 0;
				$from           = $i;
			}
			$part[] = $row;
			$bytes += $size;
		}
		if ( $part ) {
			if ( ! $this->job_put( $token . '_' . count( $job['parts'] ), $part ) ) {
				$this->delete_job( $token );
				return '';
			}
			$job['parts'][] = $from;
		}

		$job['complete'] = true;
		if ( ! $this->job_put( $token, $job ) ) {
			$this->delete_job( $token );
			return '';
		}

		return $token;
	}

	/**
	 * A complete job of this token that has not expired, or null.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	private function load_job( $token ) {
		if ( ! preg_match( '/^[a-z0-9]{32}$/', (string) $token ) ) {
			return null;
		}

		$job = $this->job_get( $token );
		if ( ! is_array( $job ) || empty( $job['complete'] ) || ! isset( $job['created'], $job['total'], $job['parts'], $job['fields'] ) ) {
			return null;
		}

		if ( (int) $job['created'] < time() - self::JOB_TTL ) {
			$this->delete_job( $token );
			return null;
		}

		return $job;
	}

	/**
	 * Rows [offset, offset + count) of a job, from its parts; null when a part is missing.
	 *
	 * @param string               $token  Token.
	 * @param array<string, mixed> $job    Job.
	 * @param int                  $offset First row.
	 * @param int                  $count  How many.
	 * @return array<int, mixed>|null
	 */
	private function job_rows( $token, $job, $offset, $count ) {
		$out   = array();
		$parts = array_map( 'intval', (array) $job['parts'] );
		$end   = min( (int) $job['total'], $offset + $count );
		foreach ( $parts as $n => $from ) {
			$to = isset( $parts[ $n + 1 ] ) ? $parts[ $n + 1 ] : (int) $job['total'];
			if ( $to <= $offset || $from >= $end ) {
				continue;
			}
			$rows = $this->job_get( $token . '_' . $n );
			if ( ! is_array( $rows ) || count( $rows ) !== $to - $from ) {
				return null;
			}
			foreach ( $rows as $i => $row ) {
				$at = $from + $i;
				if ( $at >= $offset && $at < $end ) {
					$out[] = $row;
				}
			}
		}

		return count( $out ) === max( 0, $end - $offset ) ? $out : null;
	}

	/**
	 * One row of the job, straight from the table (never a cache).
	 *
	 * @param string $key Token, or token plus its suffix.
	 * @return mixed Decoded JSON, or null.
	 */
	private function job_get( $key ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::JOB . $key ) ); // phpcs:ignore WordPress.DB
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return json_decode( $raw, true );
	}

	/**
	 * Write one row of the job straight to the table, autoload no; true when it is there afterwards.
	 *
	 * @param string $key   Token, or token plus its suffix.
	 * @param mixed  $value Value (as JSON).
	 * @return bool
	 */
	private function job_put( $key, $value ) {
		global $wpdb;
		$json = wp_json_encode( $value );
		if ( false === $json ) {
			return false;
		}

		$name = self::JOB . $key;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $json,
				'autoload'     => 'no',
			)
		);
		if ( false === $ok ) {
			error_log( 'CX Sidrena import: the job could not be written: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return false;
		}

		return true;
	}

	/**
	 * Delete a job: its row, its parts and its working copy.
	 *
	 * @param string $token Token.
	 */
	private function delete_job( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9]{32}$/', (string) $token ) ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s", self::JOB . $token, $wpdb->esc_like( self::JOB . $token . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Delete the jobs older than an hour (a job that never ended, a tab left open), by the time in the job.
	 */
	public function purge_expired_jobs() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::JOB ) . '%' ) ); // phpcs:ignore WordPress.DB
		$jobs = array();
		foreach ( (array) $rows as $row ) {
			$token = substr( (string) $row->option_name, strlen( self::JOB ), 32 );
			if ( ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) {
				continue;
			}
			if ( self::JOB . $token === $row->option_name ) {
				$job            = json_decode( (string) $row->option_value, true );
				$jobs[ $token ] = is_array( $job ) && isset( $job['created'] ) ? (int) $job['created'] : 0;
			} elseif ( ! array_key_exists( $token, $jobs ) ) {
				$jobs[ $token ] = null; // a part: its job row, when there is one, sets the time (above)
			}
		}
		foreach ( $jobs as $token => $created ) {
			// A part without its job row (the job row is written first, so only a cut delete leaves one) goes too.
			if ( null === $created || $created < time() - self::JOB_TTL ) {
				$this->delete_job( $token );
			}
		}
	}

	/**
	 * sha256 of the catalog as stored, read straight from the table: the import writes only if it is still this one.
	 *
	 * @return string
	 */
	private function catalog_sha() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CX_Sidrena_Catalog::OPTION_KEY ) ); // phpcs:ignore WordPress.DB

		return hash( 'sha256', is_string( $raw ) ? $raw : '' );
	}

	/**
	 * The folder in the server's temporary folder where 0.2.2 and earlier kept an import's job (only cleaned now).
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
	 * Remove import files older than an hour from the server's temporary folder, where 0.2.2 and earlier kept the job
	 * (CX-SID-124 keeps it in the database). Also on admin_init: with WooCommerce ajax_parse is refused (CX-SID-43), and
	 * such files hold the uploaded SKUs and prices.
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
