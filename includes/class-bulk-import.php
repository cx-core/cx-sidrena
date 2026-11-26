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
		// With WooCommerce: a place's price list or the webshop's services from a file (CX-SID-181).
		add_action( 'wp_ajax_sidrena_cijena_import_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sidrena_cijena_import_confirm', array( $this, 'ajax_confirm' ) );
		add_action( 'wp_ajax_sidrena_cijena_import_history', array( $this, 'ajax_history' ) );
		add_action( 'wp_ajax_sidrena_cijena_import_template', array( $this, 'ajax_template' ) );

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
		// With WooCommerce it reads a place's price list or the webshop's services (CX-SID-181): it only reads, and the
		// run that follows writes nothing without its new parameter (refuse_with_woocommerce(), CX-SID-43).
		$woo = CX_Sidrena_Plugin::woocommerce_active();

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}

		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a CSV or XML file.', 'cx-sidrena' ) ) );
		}

		// With WooCommerce (CX-SID-196): the target (its kept shape) and the names row the owner chose, 0 = none. A row
		// that is not a whole number is bad_row before anything is read; without WooCommerce both are not read.
		$cilj  = '';
		$redak = null;
		$kept  = null;
		if ( $woo ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above
			$cilj = isset( $_POST['cilj'] ) ? sanitize_text_field( wp_unslash( $_POST['cilj'] ) ) : '';
			if ( isset( $_POST['redak'] ) && '' !== trim( (string) wp_unslash( $_POST['redak'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_row = trim( (string) wp_unslash( $_POST['redak'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! preg_match( '/^\d{1,7}$/', $raw_row ) ) {
					$this->parse_fail( __( 'The file is not valid.', 'cx-sidrena' ), 'bad_row' );
				}
				$redak = (int) $raw_row;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}

		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'File upload failed.', 'cx-sidrena' ) ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'xml', 'txt' ), true ) ) {
			// With WooCommerce Excel is read (CX-SID-195); without it the bulk import is as it was.
			if ( $woo && 'ods' === $ext ) {
				$this->parse_fail( $this->excel_message( 'ods' ), 'ods' );
			}
			if ( ! $woo || ! in_array( $ext, array( 'xls', 'xlsx' ), true ) ) {
				$this->parse_fail( $woo ? $this->excel_message( 'other' ) : __( 'Only CSV and XML are allowed.', 'cx-sidrena' ), 'other' );
			}
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
			$this->parse_fail( __( 'The file is empty.', 'cx-sidrena' ), 'empty' );
		}
		$sha256 = hash( 'sha256', $contents ); // the file as uploaded: the confirmation record keeps it (CX-SID-181)

		$parser = CX_Sidrena_Import_Parse::instance();
		$sheets = null;
		// With WooCommerce an Excel file is read by its content, whatever its name (a till's .csv often is an .xls).
		$box = $woo ? CX_Sidrena_Import_Excel::container( $contents ) : '';
		if ( '' === $box && $woo && in_array( $ext, array( 'xls', 'xlsx' ), true ) && preg_match( '/^(\xEF\xBB\xBF)?\s*</', $contents ) ) {
			// An "Excel" export that is a web page or XML: not something this import reads as rows.
			$this->parse_fail( $this->excel_message( 'xls' ), 'xls' );
		}
		if ( '' !== $box ) {
			$read = CX_Sidrena_Import_Excel::instance()->read( $contents, self::MAX_ROWS );
			if ( is_wp_error( $read ) ) {
				$code = $read->get_error_code();
				$this->parse_fail( 'empty' === $code ? __( 'The file is empty.', 'cx-sidrena' ) : $this->excel_message( $code ), $code );
			}
			if ( ! empty( $read['more'] ) ) {
				// CX-SID-199: more rows with content than the reader takes (MAX_ROWS and its EXTRA_ROWS above the names
				// row). Refused before anything is kept, never cut.
				$this->parse_fail( self::too_many_rows_message(), 'rows' );
			}
			$grid   = $read['rows'];
			$sheets = $read['sheets'];
			unset( $read );
			$target = $this->shape_key( $cilj );
			$kept   = null === $redak ? $this->kept_shape( $target ) : null;
			$parsed = $parser->parse_grid( $grid, $redak, $this->known_name(), $kept ); // uses the grid up, row by row (memory, C195-big)
			if ( is_wp_error( $parsed ) && 'too_many_rows' === $parsed->get_error_code() ) {
				$this->parse_fail( $parsed->get_error_message(), 'rows' ); // more rows under the names row than MAX_ROWS (CX-SID-199)
			}
			if ( is_wp_error( $parsed ) ) {
				// The chosen names row is not one (past the end, empty, nothing under it); else no two rows with content.
				$this->parse_fail( $parsed->get_error_message(), 'bad_row' === $parsed->get_error_code() ? 'bad_row' : 'empty' );
			}
		} else {
			$contents = $parser->normalize_encoding( $contents );
			if ( $woo && ! $this->is_utf8( $contents ) ) {
				// Neither UTF-8 nor a code page normalize_encoding() knows: č, ć, ž would be stored broken.
				$this->parse_fail( __( "Letters such as č, ć and ž can't be read in this file. Save it as CSV UTF-8 and upload it again.", 'cx-sidrena' ), 'encoding' );
			}
			if ( 'xml' === $ext ) {
				$parsed = $parser->parse_xml( $contents );
			} elseif ( $woo ) {
				// CX-SID-196: the names row need not be the first, a file may have none, rows keep their real number.
				$target = $this->shape_key( $cilj );
				$kept   = null === $redak ? $this->kept_shape( $target ) : null;
				$parsed = $parser->parse_csv_table( $contents, $redak, $this->known_name(), $kept );
				if ( is_wp_error( $parsed ) && 'bad_row' === $parsed->get_error_code() ) {
					$this->parse_fail( $parsed->get_error_message(), 'bad_row' );
				}
			} else {
				$parsed = $parser->parse_csv( $contents );
			}

			if ( is_wp_error( $parsed ) && 'too_many_rows' === $parsed->get_error_code() ) {
				// CX-SID-199: more rows than MAX_ROWS (CSV, XML), refused before anything is kept, never cut. Without
				// WooCommerce the answer has no code, as always; the bulk import shows the message.
				$this->parse_fail( $parsed->get_error_message(), 'rows' );
			}
			if ( is_wp_error( $parsed ) ) {
				// Empty: no line under the header (the parser's 'csv' error is also a file it cannot read).
				$lines = array_filter( preg_split( '/\r\n|\r|\n/', $contents ), 'trim' );
				$this->parse_fail( $parsed->get_error_message(), 'csv' === $parsed->get_error_code() && count( $lines ) <= 1 ? 'empty' : 'other' );
			}
		}
		unset( $contents );

		$this->no_db_cache();
		$this->purge_old_jobs();
		$this->purge_expired_jobs();

		$token = $this->store_job( $parsed['fields'], $parsed['rows'], $name, 'xml' === $ext ? 'xml' : 'csv' );
		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'The file could not be kept for the import in the database. Nothing was imported.', 'cx-sidrena' ) ) );
		}
		if ( $woo ) {
			$job  = $this->job_get( $token );
			$more = array( 'sha256' => $sha256 );
			if ( isset( $parsed['header'] ) ) {
				// CX-SID-196: the names row and where rows jump over empty lines (line = the row in the file), never a
				// row above the names row.
				$more += array(
					'redak' => (int) $parsed['header']['row'],
					'lines' => $parsed['lines'],
				);
			}
			if ( ! is_array( $job ) || ! $this->job_put( $token, $more + $job ) ) {
				$this->delete_job( $token );
				$this->parse_fail( __( 'The file could not be kept for the import in the database. Nothing was imported.', 'cx-sidrena' ), 'other' );
			}
		}

		$answer = array();
		if ( null !== $sheets ) {
			$answer['sheets'] = $sheets; // Excel: how many sheets, and the one that was read (T99)
		}
		$guessed = array( 'sku' => $parser->guess_sku_field( $parsed['fields'] ) );
		foreach ( self::name_aliases() as $key => $names ) {
			$guessed[ $key ] = $parser->guess_named_field( $parsed['fields'], $names );
		}
		$guessed = $this->till_guesses( $parsed['fields'], $woo, $guessed );
		$more    = array();
		if ( isset( $parsed['header'] ) ) {
			if ( 'applied' === $parsed['saved'] && is_array( $kept ) ) {
				$guessed = $this->kept_guesses( $guessed, $kept['map'], $parsed['fields'] ); // what the owner confirmed last time
			}
			$more = $this->table_answer( $parsed, $guessed );
		}
		wp_send_json_success(
			$answer + array(
				'token'    => $token,
				'fields'   => $parsed['fields'],
				'total'    => count( $parsed['rows'] ),
				'preview'  => array_slice( $parsed['rows'], 0, 4 ),
				'filename' => $name,
				'guessed'  => $guessed,
			) + $more
		);
	}

	/**
	 * The names the columns are guessed by (normalized: lower case, no accents, space - . as _), by key, in the order
	 * the answer lists them. The šifra has its own (guess_sku_field()); a till's names are till_guesses().
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function name_aliases() {
		return array(
			'name'            => array( 'naziv', 'name', 'title', 'proizvod', 'product', 'product_name', 'usluga' ),
			'url'             => array( 'url', 'link', 'permalink', 'href' ),
			'regular'         => array( 'cijena_redovna', 'maloprodajna_cijena', 'maloprodajna', 'regular_price', 'regular', 'price', 'cijena', 'cijena_s_pdvom', 'cijena_s_pdv_om' ),
			'sale'            => array( 'cijena_akcijska', 'sale_price', 'sale', 'akcijska' ),
			'sidrena'         => array( 'sidrena_cijena', 'sidrena', 'bazna_cijena', 'anchor_price' ),
			'datum'           => array( 'sidrena_datum', 'sidrena_cijena_datum', 'datum', 'sidrena_datum', 'datum_sidrene' ),
			'vrsta'           => array( 'vrsta', 'tip', 'type', 'usluga_proizvod' ),
			'poslovnica'      => array( 'poslovnica', 'oznaka', 'outlet' ),
			'jedinica'        => array( 'jedinica_mjere', 'jedinica', 'unit', 'unit_of_measure', 'mjerna_jedinica' ),
			'jedinica_cijena' => array( 'cijena_jedinice_mjere', 'cijena_jedinice', 'cijena_za_jedinicu', 'cijena_za_jedinicu_mjere', 'unit_price' ),
			'marka'           => array( 'marka', 'brand', 'proizvodjac', 'manufacturer' ),
			// Not 'poseban_oblik': since CX-SID-109 that column is DA/NE (is the retail price a special form's), never
			// empty, so every row read as a sale (Argus 110-D; the rest is CX-SID-114).
			'oblik'           => array( 'naziv_posebnog_oblika', 'oblik', 'naziv_posebnog_oblika_prodaje' ),
			'cjm'             => array( 'cjm', 'sidrena_cijena_cjm' ),
			'cjm_suffix'      => array( 'cjm_suffix', 'cjm_dodatak', 'sidrena_cijena_cjm_suffix' ),
			'dostupnost'      => array( 'dostupnost', 'availability', 'stock_status', 'zaliha' ),
			'id'              => array( 'id' ),
		);
	}

	/**
	 * The names a till's export uses, guessed with WooCommerce for a column the usual names did not find.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function till_aliases() {
		return array(
			'name'     => array( 'naziv_artikla', 'naziv_usluge', 'artikl', 'opis', 'naziv_proizvoda' ),
			'regular'  => array( 'mpc', 'mpc_s_pdv', 'mp_cijena', 'cijena_s_pdv', 'prodajna_cijena', 'vpc_s_pdv' ),
			'sidrena'  => array( 'bazna', 'bazna_mpc' ),
			'datum'    => array( 'datum_bazne', 'datum_bazne_cijene' ),
			'jedinica' => array( 'jmj', 'jm', 'jed_mj', 'mjera' ),
			// CX-SID-196: the sale price as a till or a shop names it ("Akcijska cijena"), and the public price list of a
			// chain under the Government's decision (ŠIFRA PROIZVODA, MARKA PROIZVODA).
			'sale'     => array( 'akcijska_cijena', 'akcijska_mpc', 'mpc_akcija' ),
			'sku'      => array( 'sifra_proizvoda' ),
			'marka'    => array( 'marka_proizvoda' ),
		);
	}

	/**
	 * Whether a cell is a column name the import knows (CX-SID-196, rule a of the names row): any name it guesses by.
	 *
	 * @return callable
	 */
	private function known_name() {
		$all = array( 'sku', 'sifra', 'sifra_artikla', 'article', 'artikl', 'product_sku', 'productsku' ); // guess_sku_field()
		foreach ( array( self::name_aliases(), self::till_aliases() ) as $lists ) {
			foreach ( $lists as $names ) {
				$all = array_merge( $all, $names );
			}
		}
		$all = array_fill_keys( $all, true );

		return static function ( $cell ) use ( $all ) {
			$norm = str_replace( array( ' ', '-', '.' ), '_', strtolower( remove_accents( (string) $cell ) ) );
			return isset( $all[ $norm ] );
		};
	}

	/**
	 * What the screen gets of a read file besides its rows (CX-SID-196, contract 06956850): the names row and where it
	 * came from, the kept shape, the rows around the names row, the rows it may choose instead, each column with
	 * examples, and the services heading. Group headings by the guessed šifra, id and amount columns (run uses the map).
	 *
	 * @param array<string, mixed>  $parsed  CX_Sidrena_Import_Parse table.
	 * @param array<string, string> $guessed Guessed columns.
	 * @return array<string, mixed>
	 */
	private function table_answer( $parsed, $guessed ) {
		$parser = CX_Sidrena_Import_Parse::instance();
		$fields = $parsed['fields'];
		$width  = count( $fields );
		$row    = (int) $parsed['header']['row'];
		$code   = array_values( array_filter( array( $guessed['sku'], $guessed['id'] ) ) );
		$price  = array_values( array_filter( array( $guessed['regular'], $guessed['sale'], $guessed['sidrena'], $guessed['jedinica_cijena'] ) ) );
		$assoc  = static function ( $cells ) use ( $fields ) {
			$out = array();
			foreach ( $fields as $i => $f ) {
				$out[ $f ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
			}
			return $out;
		};

		$head  = array();
		$names = array_fill( 0, $width, '' );
		foreach ( $parsed['head'] as $h ) {
			$n     = (int) $h[0];
			$cells = null === $h[1] ? array_fill( 0, $width, '' ) : array_values( $h[1] );
			if ( null === $h[1] ) {
				$kind = 'empty';
			} elseif ( $n < $row ) {
				$kind = 'above';
			} elseif ( $n === $row ) {
				$kind  = 'names';
				$names = $cells;
			} else {
				$kind = '' !== $parser->heading_of( $assoc( $cells ), $code, $price ) ? 'section' : 'data';
			}
			$head[] = array(
				'row'   => $n,
				'kind'  => $kind,
				'cells' => $cells,
			);
		}

		$examples = array_fill( 0, $width, array() );
		$filled   = array();
		$taken    = 0;
		$services = '';
		foreach ( $parsed['rows'] as $r ) {
			$heading = $parser->heading_of( $r, $code, $price );
			if ( '' !== $heading ) {
				if ( '' === $services && $parser->is_services_heading( $heading ) ) {
					$services = $heading;
				}
			} elseif ( $taken < 5 ) {
				foreach ( $fields as $i => $f ) {
					$examples[ $i ][] = (string) $r[ $f ];
				}
				++$taken;
			}
			foreach ( $fields as $i => $f ) {
				if ( ! isset( $filled[ $i ] ) && '' !== (string) $r[ $f ] ) {
					$filled[ $i ] = true;
				}
			}
		}
		$columns = array();
		foreach ( $fields as $i => $f ) {
			$columns[] = array(
				'n'        => $i + 1,
				'field'    => $f,
				'name'     => (string) $names[ $i ],
				'examples' => $examples[ $i ],
				'empty'    => '' === (string) $names[ $i ] && ! isset( $filled[ $i ] ),
			);
		}
		$options = array();
		foreach ( $parsed['options'] as $o ) {
			$options[] = array(
				'row'   => (int) $o[0],
				'cells' => array_values( $o[1] ),
			);
		}

		return array(
			'header'           => $parsed['header'],
			'saved'            => $parsed['saved'],
			'head_rows'        => $head,
			'header_options'   => $options,
			'columns'          => $columns,
			'services_heading' => $services,
		);
	}

	/**
	 * Guessed columns from a kept shape (CX-SID-196, saved 'applied'): what the owner mapped at the last confirmed import
	 * of this target, every other key empty.
	 *
	 * @param array<string, string> $guessed Guessed columns (their keys).
	 * @param mixed                 $map     Kept map: key => column.
	 * @param array<int, string>    $fields  This file's columns.
	 * @return array<string, string>
	 */
	private function kept_guesses( $guessed, $map, $fields ) {
		$out = array_fill_keys( array_keys( $guessed ), '' );
		foreach ( (array) $map as $key => $field ) {
			if ( array_key_exists( $key, $out ) && in_array( (string) $field, $fields, true ) ) {
				$out[ $key ] = (string) $field;
			}
		}

		return $out;
	}

	/** The shape of the last confirmed file per target (CX-SID-196): 'webshop' or a place's id => redak, fields, map. */
	const SHAPE_OPTION = 'cx_sidrena_uvoz_oblik';

	/**
	 * The key a target's shape is kept under: 'webshop', or the id of the place the target names; '' for none.
	 *
	 * @param string $cilj Target as the screen sends it.
	 * @return string
	 */
	private function shape_key( $cilj ) {
		if ( '' === (string) $cilj ) {
			return '';
		}
		if ( 'webshop' === strtolower( (string) $cilj ) ) {
			return 'webshop';
		}
		$place = $this->place_by_code( $cilj );

		return null === $place ? '' : (string) $place['id'];
	}

	/**
	 * The kept shape of a target, or null. A place's only while it is the same place: its id with the code it had.
	 *
	 * @param string $key shape_key().
	 * @return array{redak: int, fields: array<int, string>, map: array<string, string>}|null
	 */
	private function kept_shape( $key ) {
		if ( '' === $key ) {
			return null;
		}
		$all = get_option( self::SHAPE_OPTION, array() );
		$one = is_array( $all ) && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : null;
		if ( null === $one || ! isset( $one['redak'], $one['fields'], $one['map'] ) || ! is_array( $one['fields'] ) ) {
			return null;
		}
		if ( 'webshop' !== $key ) {
			$place = null;
			foreach ( CX_Sidrena_Poslovnice::instance()->all() as $p ) {
				if ( (string) $p['id'] === $key ) {
					$place = $p;
				}
			}
			if ( null === $place || ! isset( $one['oznaka'] ) || CX_Sidrena_Poslovnice::code_key( $place['oznaka'] ) !== CX_Sidrena_Poslovnice::code_key( (string) $one['oznaka'] ) ) {
				return null;
			}
		}

		return array(
			'redak'  => (int) $one['redak'],
			'fields' => array_values( array_map( 'strval', $one['fields'] ) ),
			'map'    => (array) $one['map'],
		);
	}

	/**
	 * Forget the kept shapes of places that are gone (CX-SID-196): the codes a save deleted, and any id no place has
	 * now. A place whose code changed keeps its shape under its new code. Called after the places are saved.
	 *
	 * @param array<int, string>    $deleted Codes of deleted places.
	 * @param array<string, string> $renamed Old code => new code.
	 */
	public static function forget_gone_places( $deleted = array(), $renamed = array() ) {
		self::forget_gone_histories( $deleted, $renamed );
		$all = get_option( self::SHAPE_OPTION, null );
		if ( ! is_array( $all ) ) {
			return;
		}
		$gone = array();
		foreach ( (array) $deleted as $code ) {
			$gone[ CX_Sidrena_Poslovnice::code_key( (string) $code ) ] = true;
		}
		$moved = array();
		foreach ( (array) $renamed as $from => $to ) {
			$moved[ CX_Sidrena_Poslovnice::code_key( (string) $from ) ] = (string) $to;
		}
		$ids = array();
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $p ) {
			$ids[ (string) $p['id'] ] = true;
		}
		$out = array();
		foreach ( $all as $key => $one ) {
			if ( 'webshop' === (string) $key ) {
				$out[ $key ] = $one;
				continue;
			}
			$code = is_array( $one ) && isset( $one['oznaka'] ) ? CX_Sidrena_Poslovnice::code_key( (string) $one['oznaka'] ) : '';
			if ( ! isset( $ids[ (string) $key ] ) || isset( $gone[ $code ] ) ) {
				continue;
			}
			if ( isset( $moved[ $code ] ) ) {
				$one['oznaka'] = $moved[ $code ];
			}
			$out[ $key ] = $one;
		}
		if ( $out !== $all ) {
			update_option( self::SHAPE_OPTION, $out, false );
		}
	}

	/**
	 * A deleted place's codes go with it (CX-SID-198): its history, also when a new place gets its id in the same save,
	 * and its confirmed imports no longer make another place's import a later one. A place whose code changed keeps its
	 * history under the new code (its confirmations from before 198 keep the code they were confirmed with).
	 *
	 * @param array<int, string>    $deleted Codes of deleted places.
	 * @param array<string, string> $renamed Old code => new code.
	 */
	private static function forget_gone_histories( $deleted, $renamed ) {
		global $wpdb;
		$gone = array();
		foreach ( (array) $deleted as $code ) {
			$gone[ CX_Sidrena_Poslovnice::code_key( (string) $code ) ] = true;
		}
		$moved = array();
		foreach ( (array) $renamed as $from => $to ) {
			$moved[ CX_Sidrena_Poslovnice::code_key( (string) $from ) ] = (string) $to;
		}
		$ids = array();
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $p ) {
			$ids[ (string) $p['id'] ] = true;
		}
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::HISTORY ) . '%' ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $names as $name ) {
			$one  = get_option( $name, null );
			$code = is_array( $one ) && isset( $one['oznaka'] ) ? CX_Sidrena_Poslovnice::code_key( (string) $one['oznaka'] ) : '';
			if ( ! isset( $ids[ substr( (string) $name, strlen( self::HISTORY ) ) ] ) || isset( $gone[ $code ] ) ) {
				delete_option( $name );
			} elseif ( isset( $moved[ $code ] ) ) {
				$one['oznaka'] = $moved[ $code ];
				update_option( $name, $one, false );
			}
		}
		$kept = get_option( self::TARGETS_OPTION, null );
		if ( ! is_array( $kept ) ) {
			return;
		}
		$out = $kept;
		foreach ( $kept as $k => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['kind'], $entry['place_id'] ) || 'mjesto' !== $entry['kind'] || ! empty( $entry['gone'] ) ) {
				continue;
			}
			$code = isset( $entry['oznaka'] ) ? CX_Sidrena_Poslovnice::code_key( (string) $entry['oznaka'] ) : '';
			// Marked only: the entry is the record of that import as it was (its code stays as confirmed).
			if ( ! isset( $ids[ (string) $entry['place_id'] ] ) || isset( $gone[ $code ] ) ) {
				$out[ $k ]['gone'] = true;
			}
		}
		if ( $out !== $kept ) {
			update_option( self::TARGETS_OPTION, $out, false );
		}
	}

	/**
	 * Process one batch of mapped rows.
	 */
	public function ajax_run() {
		check_ajax_referer( 'sidrena_cijena_import', 'nonce' );
		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			$this->ajax_run_woo(); // CX-SID-181; without its target it is 43's refusal
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}

		// One price list for all places of sale (CX-SID-113, T77): no place gets rows of its own. Refused before anything
		// is read or written; with the box off nothing here changes.
		$isti_place = isset( $_POST['import_poslovnica'] ) ? sanitize_text_field( wp_unslash( $_POST['import_poslovnica'] ) ) : '';
		if ( '' !== $isti_place && 'webshop' !== $isti_place && CX_Sidrena_Settings::instance()->is_isti_cjenik() && null !== CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $isti_place ) ) {
			wp_send_json_error( array( 'message' => CX_Sidrena_Settings::isti_cjenik_refusal( $isti_place ) ) );
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

		// With WooCommerce the run is ajax_run_woo() (CX-SID-181): the branch that wrote products (CX-SID-43) is gone.
		if ( ! $woo ) {
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

	// ----------------------------------------------------------------------------------------------------------------
	// With WooCommerce: a place's price list, or the webshop's services, from a file (CX-SID-181)
	// ----------------------------------------------------------------------------------------------------------------

	/** The kept targets of confirmed imports of price lists (CX-SID-181), by confirmation id; autoload no. */
	const TARGETS_OPTION = 'cx_sidrena_uvoz_cilj';

	/** How many of them are kept (the newest). */
	const TARGETS_KEEP = 200;

	/** Rows of one page of import_list. */
	const LIST_PAGE = 50;

	/**
	 * CX-SID-198: every code a place ever had in a confirmed import, with its bazna and date as the row had them
	 * (<HISTORY><place id>, autoload no): array{oznaka: string, sifre: array<string, string "bazna|datum">}. Nothing is
	 * ever taken out of it: it is what the place "had". It goes with the place and with the plugin.
	 */
	const HISTORY = 'cx_sidrena_mjesto_sifre_';

	/**
	 * The codes a place had (CX-SID-198), or null when it has no history (no import confirmed since 198). Only while it is
	 * the same place: its id with the code it had (a deleted place's id can be given to a new one).
	 *
	 * @param array<string, mixed> $place Place.
	 * @return array<string, array{b: string, d: string}>|null Šifra => bazna, date.
	 */
	private function place_history( $place ) {
		$one = '' === (string) $place['id'] ? null : get_option( self::HISTORY . $place['id'], null );
		if ( ! is_array( $one ) || ! isset( $one['oznaka'], $one['sifre'] ) || ! is_array( $one['sifre'] )
			|| CX_Sidrena_Poslovnice::code_key( (string) $one['oznaka'] ) !== CX_Sidrena_Poslovnice::code_key( (string) $place['oznaka'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $one['sifre'] as $sku => $kept ) {
			$parts               = explode( '|', (string) $kept, 2 );
			$out[ (string) $sku ] = array( 'b' => $parts[0], 'd' => isset( $parts[1] ) ? $parts[1] : '' );
		}

		return $out;
	}

	/**
	 * Whether a place has a confirmed import (CX-SID-198 "kasniji uvoz"): its history, or a confirmation kept from before
	 * 198 for this place (the same id with the same code, the place not deleted since), so a place imported on 0.3.0 counts.
	 *
	 * @param array<string, mixed> $place Place.
	 * @return bool
	 */
	private function is_later( $place ) {
		if ( null !== $this->place_history( $place ) ) {
			return true;
		}
		$kept = get_option( self::TARGETS_OPTION, array() );
		foreach ( is_array( $kept ) ? $kept : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['kind'], $entry['place_id'], $entry['oznaka'] ) && 'mjesto' === $entry['kind'] && empty( $entry['gone'] )
				&& '' !== (string) $place['id'] && (string) $entry['place_id'] === (string) $place['id']
				&& CX_Sidrena_Poslovnice::code_key( (string) $entry['oznaka'] ) === CX_Sidrena_Poslovnice::code_key( (string) $place['oznaka'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A code as U9 compares it (CX-SID-198, Rook 4244): without dots and spaces, in small letters, without leading zeros.
	 * '' (a code of dots and zeros) is never compared.
	 *
	 * @param string $sku Šifra.
	 * @return string
	 */
	private static function code_norm( $sku ) {
		$s = preg_replace( '/[\s\p{Z}.]+/u', '', (string) $sku );
		$s = null === $s ? str_replace( array( '.', ' ' ), '', (string) $sku ) : $s;
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );

		return ltrim( $s, '0' );
	}

	/**
	 * Keep a place's codes after a confirmed import (CX-SID-198): its rows before the save (also the ones that went) and
	 * after it, each with its bazna and the date it showed (written as it is, so a later change of the default date does
	 * not move it). Rows without a šifra or a bazna say nothing about a price and are not kept.
	 *
	 * @param array<string, mixed>              $place  Place (id, oznaka).
	 * @param array<int, array<string, string>> $before The catalog before the save.
	 * @param array<int, array<string, string>> $after  The catalog after it.
	 */
	private function keep_history( $place, $before, $after ) {
		$catalog = CX_Sidrena_Catalog::instance();
		$sifre   = array();
		foreach ( (array) $this->place_history( $place ) as $sku => $kept ) {
			$sifre[ $sku ] = $kept['b'] . '|' . $kept['d'];
		}
		foreach ( array( $before, $after ) as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || $catalog->row_poslovnica( $row ) !== (string) $place['oznaka'] ) {
					continue;
				}
				$sku   = isset( $row['sku'] ) ? (string) $row['sku'] : '';
				$bazna = isset( $row['sidrena_cijena'] ) ? trim( (string) $row['sidrena_cijena'] ) : '';
				if ( '' !== $sku && '' !== $bazna ) {
					$sifre[ $sku ] = $bazna . '|' . $catalog->display_date( $row );
				}
			}
		}
		update_option( self::HISTORY . $place['id'], array( 'oznaka' => (string) $place['oznaka'], 'sifre' => $sifre ), false );
	}

	/**
	 * A parse error. The code (the screen's text for it, CX-SID-181) only with WooCommerce: without it the answer is
	 * what it always was.
	 *
	 * @param string $message Message.
	 * @param string $code    xls | ods | empty | encoding | bad_row | rows (more than MAX_ROWS, CX-SID-199) | other.
	 */
	private function parse_fail( $message, $code ) {
		$data = array( 'message' => $message );
		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			$data['code'] = $code;
		}
		wp_send_json_error( $data );
	}

	/**
	 * T104 (Norman, CX-SID-199): a file with more rows than a price list may have. The import screen's text for the code
	 * 'rows' is this same string (class-admin.php), and without WooCommerce the bulk import shows it as the message.
	 *
	 * @return string
	 */
	public static function too_many_rows_message() {
		/* translators: %s: the most rows a price list may have (25,000), written as the site writes numbers */
		return sprintf( __( 'The file has more than %s rows, and a price list can have at most that many. Export from your till only the items you offer now, and upload that file.', 'cx-sidrena' ), number_format_i18n( self::MAX_ROWS ) );
	}

	/**
	 * The text of an Excel refusal by its code (SPEC-DUCAN §T, the same texts as the screen's): xls T21n, ods T21o,
	 * other T21x.
	 *
	 * @param string $code xls | ods | other.
	 * @return string
	 */
	private function excel_message( $code ) {
		if ( 'ods' === $code ) {
			return __( "This is an OpenDocument (.ods) file, which can't be read here. Save it as Excel (.xlsx) or CSV and upload that file.", 'cx-sidrena' );
		}
		if ( 'other' === $code ) {
			return __( 'Upload an Excel, CSV or XML file.', 'cx-sidrena' );
		}

		return __( "This Excel file can't be read. Open it in Excel, save it as CSV UTF-8 and upload that file.", 'cx-sidrena' );
	}

	/**
	 * Whether a text is UTF-8 (as normalize_encoding() decides it).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private function is_utf8( $text ) {
		if ( function_exists( 'wp_is_valid_utf8' ) ) {
			return wp_is_valid_utf8( $text );
		}
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $text, 'UTF-8' );
		}

		return 1 === preg_match( '//u', $text );
	}

	/**
	 * The columns guessed from the file's names; with WooCommerce also the names a till's export uses (MPC, Bazna, JMJ),
	 * for a column the usual names did not find. Without WooCommerce as always.
	 *
	 * @param array<int, string>    $fields  Columns.
	 * @param bool                  $woo     WooCommerce is active.
	 * @param array<string, string> $guessed What the usual names found.
	 * @return array<string, string>
	 */
	private function till_guesses( $fields, $woo, $guessed ) {
		if ( ! $woo ) {
			return $guessed;
		}
		$parser = CX_Sidrena_Import_Parse::instance();
		foreach ( self::till_aliases() as $key => $names ) {
			if ( isset( $guessed[ $key ] ) && '' === $guessed[ $key ] ) {
				$guessed[ $key ] = $parser->guess_named_field( $fields, $names );
			}
		}

		return $guessed;
	}

	/**
	 * A new action of CX-SID-181 is refused without WooCommerce (there the bulk import is as it was), and for a user who
	 * may not manage the plugin. Returns only when it may go on.
	 */
	private function woo_guard() {
		check_ajax_referer( 'sidrena_cijena_import', 'nonce' );
		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			wp_send_json_error( array( 'message' => __( 'This import needs WooCommerce.', 'cx-sidrena' ) ) );
		}
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}
		$this->no_db_cache();
	}

	/**
	 * An error of CX-SID-181's actions: its code (for the screen) and its message.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $more    More data.
	 */
	private function fail( $code, $message, $more = array() ) {
		wp_send_json_error( array( 'code' => $code, 'message' => $message ) + $more );
	}

	/**
	 * The place a target names, letters in any case (CX_Sidrena_Poslovnice::code_key()), or null.
	 *
	 * @param string $cilj Target.
	 * @return array<string, mixed>|null
	 */
	private function place_by_code( $cilj ) {
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $place ) {
			if ( '' !== (string) $place['oznaka'] && CX_Sidrena_Poslovnice::code_key( $place['oznaka'] ) === CX_Sidrena_Poslovnice::code_key( $cilj ) ) {
				return $place;
			}
		}

		return null;
	}

	/**
	 * The text the owner confirms an imported price list with (Norman T50, T51), kept with it as shown. The price list of
	 * a place says which (1583 art. 3(1) al. 3 and 4: the bazna is the retail price on 10.9.2026, or on the day the goods
	 * or the service were first offered).
	 *
	 * @param string $kind  mjesto | usluge.
	 * @param string $naziv The place's name (its code when it has none).
	 * @return string
	 */
	public static function consent_text( $kind, $naziv = '' ) {
		if ( 'mjesto' === $kind ) {
			/* translators: %s: name of the place of sale */
			return sprintf( __( 'I confirm, on behalf of the shop owner, that this file is the price list of %s, and that each bazna cijena in it is the retail price applied there on the date given with it: 10 September 2026, or the day the goods or service were first offered, if that was later.', 'cx-sidrena' ), $naziv );
		}

		return __( "I confirm, on behalf of the shop owner, that this file is the webshop's price list of services, and that each bazna cijena in it is the retail price applied on the date given with it: 10 September 2026, or the day the service was first offered, if that was later.", 'cx-sidrena' );
	}

	/**
	 * How many of the webshop's products a place shows in its price list now: its goods file of the last build without
	 * its own rows. null before its first build.
	 *
	 * @param array<string, mixed> $place Place.
	 * @return int|null
	 */
	private function web_rows( $place ) {
		$meta = CX_Sidrena_Archive::instance()->get_outlet_meta( (string) $place['oznaka'] );
		if ( ! isset( $meta['vrste'] ) || ! is_array( $meta['vrste'] ) ) {
			return null;
		}
		$in_file = isset( $meta['vrste']['proizvod']['count'] ) ? (int) $meta['vrste']['proizvod']['count'] : 0;
		$catalog = CX_Sidrena_Catalog::instance();
		$own     = 0;
		foreach ( $catalog->all() as $row ) {
			if ( $catalog->row_poslovnica( $row ) === (string) $place['oznaka'] && 'usluga' !== $catalog->row_vrsta( $row ) ) {
				++$own;
			}
		}

		return max( 0, $in_file - $own );
	}

	/**
	 * What the Import screen offers as targets (CX-SID-181 1.a): every place with how it shows the webshop's products and
	 * its last imported price list, the webshop's services, and whether one price list is published for all places (113).
	 *
	 * @return array{places: array<int, array<string, mixed>>, webshop_services: int, isti_cjenik: bool}
	 */
	public function targets() {
		$places  = CX_Sidrena_Poslovnice::instance();
		$catalog = CX_Sidrena_Catalog::instance();
		$kept    = get_option( self::TARGETS_OPTION, array() );
		$kept    = is_array( $kept ) ? $kept : array();
		$count   = array();
		$web     = 0;
		foreach ( $catalog->all() as $row ) {
			// CX-SID-202: only what the price list shows, as Upisano and Povijest count it: a service that waits for its
			// date or bazna (CX-SID-130) is stored, but the build leaves it out of the files (objava "ne").
			if ( ! $catalog->is_public_row( $row ) ) {
				continue;
			}
			$code = $catalog->row_poslovnica( $row );
			$count[ $code ] = ( isset( $count[ $code ] ) ? $count[ $code ] : 0 ) + 1;
			if ( '' === $code && 'usluga' === $catalog->row_vrsta( $row ) ) {
				++$web;
			}
		}
		$out = array();
		foreach ( $places->all() as $place ) {
			$last = null;
			foreach ( $kept as $entry ) {
				if ( is_array( $entry ) && 'mjesto' === ( isset( $entry['kind'] ) ? $entry['kind'] : '' ) && isset( $entry['place_id'] ) && (string) $entry['place_id'] === (string) $place['id']
					&& ( null === $last || (int) $entry['at'] > (int) $last['at'] ) ) {
					$last = $entry;
				}
			}
			$dodjela = 'svi' === $places->dodjela( $place ) ? 'svi' : ( $places->carries_web_products( $place ) ? 'odabrani' : 'nijedan' );
			$out[]   = array(
				'oznaka'   => (string) $place['oznaka'],
				'naziv'    => '' !== (string) $place['naziv'] ? (string) $place['naziv'] : (string) $place['oznaka'],
				'dodjela'  => $dodjela,
				'products' => count( (array) $place['products'] ),
				'web_rows' => 'nijedan' === $dodjela ? 0 : $this->web_rows( $place ),
				'imported' => null === $last ? null : array(
					'at'    => (int) $last['at'],
					'items' => isset( $count[ (string) $place['oznaka'] ] ) ? $count[ (string) $place['oznaka'] ] : 0,
				),
				// CX-SID-198: a later import (new items get today's date, T96b/T97b), and the default date (T97).
				'later'        => $this->is_later( $place ),
				'default_date' => CX_Sidrena_Money::normalize_date( (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) ),
			);
		}

		return array(
			'places'           => $out,
			'webshop_services' => $web,
			'isti_cjenik'      => CX_Sidrena_Settings::instance()->is_isti_cjenik(), // CX-SID-113: the screen shows T19
		);
	}

	/**
	 * The row of the target this import row is (CX-SID-181), as upsert_working_row() finds it: a d-id of another price
	 * list's row is that row (CX-SID-164), then the šifra within the target, then the d-id.
	 *
	 * @param array<int, array<string, string>> $rows   Working rows.
	 * @param string                            $oznaka Target ('' = the webshop).
	 * @param string                            $sku    Šifra as stored.
	 * @param string                            $did    Manual row id, or ''.
	 * @return array{other: bool, index: int|null}
	 */
	private function find_in_target( $rows, $oznaka, $sku, $did ) {
		$catalog = CX_Sidrena_Catalog::instance();
		if ( '' !== $did ) {
			foreach ( $rows as $existing ) {
				if ( isset( $existing['id'] ) && (string) $existing['id'] === $did && $catalog->row_poslovnica( $existing ) !== $oznaka ) {
					return array( 'other' => true, 'index' => null );
				}
			}
		}
		if ( '' !== $sku ) {
			foreach ( $rows as $i => $existing ) {
				if ( isset( $existing['sku'] ) && (string) $existing['sku'] === $sku && $catalog->row_poslovnica( $existing ) === $oznaka ) {
					return array( 'other' => false, 'index' => $i );
				}
			}
		}
		if ( '' !== $did ) {
			foreach ( $rows as $i => $existing ) {
				if ( isset( $existing['id'] ) && (string) $existing['id'] === $did ) {
					return array( 'other' => false, 'index' => $i );
				}
			}
		}

		return array( 'other' => false, 'index' => null );
	}

	/**
	 * A row as the list shows its old or new values.
	 *
	 * @param array<string, string>|null $row Row.
	 * @return array<string, string>|null
	 */
	private function shown_values( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$catalog = CX_Sidrena_Catalog::instance();

		return array(
			'cijena'   => isset( $row['cijena_redovna'] ) ? (string) $row['cijena_redovna'] : '',
			'akcijska' => isset( $row['cijena_akcijska'] ) ? (string) $row['cijena_akcijska'] : '',
			'bazna'    => isset( $row['sidrena_cijena'] ) ? (string) $row['sidrena_cijena'] : '',
			'datum'    => $catalog->display_date( $row ),
		);
	}

	/**
	 * What of a row the owner sees, to tell "the same" from "changed".
	 *
	 * @param array<string, string> $row Row.
	 * @return string
	 */
	private function row_print( $row ) {
		$keys = array( 'naziv', 'sku', 'marka', 'cijena_redovna', 'cijena_akcijska', 'sidrena_cijena', 'sidrena_datum', 'vrsta', 'jedinica_mjere', 'cijena_jedinice_mjere', 'naziv_posebnog_oblika', 'cjm', 'cjm_suffix', 'dostupnost', 'poslovnica', 'url' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}

		return (string) wp_json_encode( $out );
	}

	/**
	 * Run with WooCommerce (CX-SID-181): the file's rows into the working copy of the target's price list, step by step
	 * from offset 0 to done. Writes nothing but the job (CX-SID-124): the catalog, the places and WooCommerce are written
	 * only by ajax_confirm(). No product is ever looked up (U1, U2). Without the target (a Settings page opened before
	 * the update, CX-SID-43): refused, nothing read (U3).
	 */
	private function ajax_run_woo() {
		$cilj = isset( $_POST['cilj'] ) ? sanitize_text_field( wp_unslash( $_POST['cilj'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_run()
		if ( '' === $cilj ) {
			$this->refuse_with_woocommerce();
		}
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import.', 'cx-sidrena' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_run()
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$map    = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->no_db_cache();
		$job = $this->load_job( $token );
		if ( null === $job ) {
			$this->fail( 'expired', __( 'This import was not found or has expired (an import is kept for an hour after the file is read). Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}

		$place = null;
		if ( 'webshop' === strtolower( $cilj ) ) {
			$kind   = 'usluge';
			$oznaka = '';
		} else {
			$place = $this->place_by_code( $cilj );
			if ( null === $place ) {
				$this->fail( 'no_place', __( 'The selected outlet does not exist.', 'cx-sidrena' ) );
			}
			$kind   = 'mjesto';
			$oznaka = (string) $place['oznaka'];
		}

		$fields    = $job['fields'];
		$sku_field = $this->mapped_field( $map, 'sku', $fields );
		$id_field  = $this->mapped_field( $map, 'id', $fields );
		$name      = $this->mapped_field( $map, 'name', $fields );
		$regular   = $this->mapped_field( $map, 'regular', $fields );
		if ( '' === $sku_field && '' === $id_field ) {
			$this->fail( 'no_columns', __( 'Select the column for SKU or ID.', 'cx-sidrena' ) );
		}
		if ( '' === $name || '' === $regular ) {
			$this->fail( 'no_columns', __( 'Choose a column for Name and for Regular price.', 'cx-sidrena' ) );
		}
		$sale               = $this->mapped_field( $map, 'sale', $fields );
		$sidrena            = $this->mapped_field( $map, 'sidrena', $fields );
		$url                = $this->mapped_field( $map, 'url', $fields );
		$dostupnost         = $this->mapped_field( $map, 'dostupnost', $fields );
		$datum              = $this->mapped_field( $map, 'datum', $fields );
		$jedinica           = $this->mapped_field( $map, 'jedinica', $fields );
		$jedinica_cijena    = $this->mapped_field( $map, 'jedinica_cijena', $fields );
		$marka              = $this->mapped_field( $map, 'marka', $fields );
		$oblik              = $this->mapped_field( $map, 'oblik', $fields );
		$cjm                = $this->mapped_field( $map, 'cjm', $fields );
		$cjm_suffix         = $this->mapped_field( $map, 'cjm_suffix', $fields );
		$vrsta_field        = $this->mapped_field( $map, 'vrsta', $fields );
		$retail_is_display  = $this->is_display_retail_column( $regular );
		$flag_field         = $this->special_form_flag_field( $fields );
		$amounts            = array_filter( array( $regular, $sale, $sidrena, $jedinica_cijena ) );
		$from_xml           = isset( $job['kind'] ) && 'xml' === $job['kind'];
		// A file read since CX-SID-196 (not XML): rows keep their real number, and group headings are no rows.
		$lines              = isset( $job['lines'] ) && is_array( $job['lines'] ) && ! $from_xml ? $job['lines'] : null;
		$headings           = null !== $lines;
		$code_fields        = array_values( array_filter( array( $sku_field, $id_field ) ) );
		$map_kept           = array_filter(
			array(
				'sku'             => $sku_field,
				'id'              => $id_field,
				'name'            => $name,
				'regular'         => $regular,
				'sale'            => $sale,
				'sidrena'         => $sidrena,
				'url'             => $url,
				'dostupnost'      => $dostupnost,
				'datum'           => $datum,
				'jedinica'        => $jedinica,
				'jedinica_cijena' => $jedinica_cijena,
				'marka'           => $marka,
				'oblik'           => $oblik,
				'cjm'             => $cjm,
				'cjm_suffix'      => $cjm_suffix,
				'vrsta'           => $vrsta_field,
			)
		);
		$map_print          = hash( 'sha256', (string) wp_json_encode( array( $cilj, $sku_field, $id_field, $name, $regular, $sale, $sidrena, $url, $dostupnost, $datum, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, $vrsta_field ) ) );
		$catalog            = CX_Sidrena_Catalog::instance();
		$places             = CX_Sidrena_Poslovnice::instance();

		if ( 0 === $offset ) {
			$print = 'mjesto' === $kind ? $places->import_fingerprint( $oznaka ) : null;
			if ( null !== $print && null === $print['row'] ) {
				$this->fail( 'no_place', __( 'The selected outlet does not exist.', 'cx-sidrena' ) );
			}
			if ( 'mjesto' === $kind && CX_Sidrena_Settings::instance()->is_isti_cjenik() ) {
				$this->fail( 'isti_cjenik', CX_Sidrena_Settings::isti_cjenik_refusal( $oznaka ) ); // CX-SID-113, T77
			}
			$state = array(
				'kind'     => $kind,
				'oznaka'   => $oznaka,
				'place_id' => null === $place ? '' : (string) $place['id'],
				'naziv'    => null === $place ? '' : ( '' !== (string) $place['naziv'] ? (string) $place['naziv'] : $oznaka ),
				'switch'   => null !== $place && $places->carries_web_products( $print['row'] ),
				'dodjela'  => null === $place ? '' : $places->dodjela( $print['row'] ),
				'web_rows' => null === $place ? null : $this->web_rows( $place ),
				'map'      => $map_print,
				'catalog'  => $this->catalog_sha(),
				'place'    => null === $print ? null : $print['fingerprint'],
				'raw'      => null === $print ? null : $print['raw'],
				'file'     => isset( $job['filename'] ) ? (string) $job['filename'] : '',
				'sha256'   => isset( $job['sha256'] ) ? (string) $job['sha256'] : '',
				'rows'     => $catalog->all(),
				'touched'  => array(),
				'seen'     => array(),
				'res'      => array(),
				'goods'    => 0,
				'services' => 0,
				'next'     => 0,
				'done'     => false,
				// CX-SID-196: group headings (counted, never a row of a list), the services block under "Usluga(e)" when
				// Vrsta is not mapped, and the file's shape, kept for the target once the import is confirmed.
				'sections' => array( 'count' => 0, 'names' => array() ),
				'in_usl'   => false,
				'has_usl'  => $headings && '' === $vrsta_field && $this->file_has_services( $token, $job, $code_fields, $amounts ),
				'shape'    => isset( $job['redak'] ) && ! $from_xml ? array(
					'redak'  => (int) $job['redak'],
					'fields' => array_values( (array) $job['fields'] ),
					'map'    => $map_kept,
				) : null,
				// CX-SID-198: today in the shop's zone, and for a place's later import the codes it had and the codes
				// it has or had as U9 compares them (the first of the place's rows, then of its history).
				'today'    => wp_date( 'Y-m-d' ),
				'today_d'  => wp_date( 'd.m.Y.' ),
				'later'    => null !== $place && $this->is_later( $place ),
				'hist'     => array(),
				'norm'     => array(),
			);
			if ( $state['later'] ) {
				$state['hist'] = (array) $this->place_history( $place );
				foreach ( $state['rows'] as $row ) {
					$sku = isset( $row['sku'] ) ? (string) $row['sku'] : '';
					$n   = self::code_norm( $sku );
					if ( '' !== $n && ! isset( $state['norm'][ $n ] ) && $catalog->row_poslovnica( $row ) === $oznaka ) {
						$state['norm'][ $n ] = $sku;
					}
				}
				foreach ( array_keys( $state['hist'] ) as $sku ) {
					$n = self::code_norm( (string) $sku );
					if ( '' !== $n && ! isset( $state['norm'][ $n ] ) ) {
						$state['norm'][ $n ] = (string) $sku;
					}
				}
			}
		} else {
			$state = $this->job_get( $token . '_u' );
			if ( ! is_array( $state ) || $state['map'] !== $map_print || (int) $state['next'] !== $offset || ! empty( $state['done'] ) ) {
				$this->delete_job( $token );
				$this->fail( 'missing', __( 'Part of this import is missing on the server. Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
			}
		}

		$total = (int) $job['total'];
		$slice = $this->job_rows( $token, $job, $offset, self::BATCH_SIZE );
		if ( null === $slice ) {
			$this->delete_job( $token );
			$this->fail( 'missing', __( 'Part of this import is missing on the server. Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}

		$processed = $offset;
		$parser    = CX_Sidrena_Import_Parse::instance();
		$catalog->begin_batch( $state['rows'] );
		foreach ( $slice as $row ) {
			++$processed;
			$line = null !== $lines ? CX_Sidrena_Import_Parse::line_of( $lines, $processed - 1 ) : ( $from_xml ? $processed : $processed + 1 );
			if ( ! is_array( $row ) ) {
				$state['res'][] = array( 'line' => $line, 'naziv' => '', 'sifra' => '', 'vrsta' => 'roba', 'list' => 'skipped', 'code' => 'no_name', 'detail' => '' );
				continue;
			}
			$heading = $headings ? $parser->heading_of( $row, $code_fields, $amounts ) : '';
			if ( '' !== $heading ) {
				// A group heading: counted, in no list; "Usluga(e)" starts the services block, any other heading ends it.
				++$state['sections']['count'];
				if ( count( $state['sections']['names'] ) < 20 ) {
					$state['sections']['names'][] = $heading;
				}
				$state['in_usl'] = $parser->is_services_heading( $heading );
				continue;
			}
			$vrsta_raw = $this->mapped_text( $row, $vrsta_field );
			if ( '' === $vrsta_field && ! empty( $state['has_usl'] ) ) {
				// The file names its services by a heading (Vrsta not mapped): under it services, every other row goods,
				// also for the webshop (goods_to_webshop, as T94w says).
				$vrsta = empty( $state['in_usl'] ) ? 'proizvod' : 'usluga';
			} else {
				$vrsta = null === $vrsta_raw ? ( 'usluge' === $kind ? 'usluga' : 'proizvod' ) : $catalog->normalize_vrsta( $vrsta_raw );
			}
			if ( 'usluga' === $vrsta ) {
				++$state['services'];
			} else {
				++$state['goods'];
			}
			$sku   = CX_Sidrena_Money::plain_text( null === $this->mapped_text( $row, $sku_field ) ? '' : $this->mapped_text( $row, $sku_field ) );
			$idraw = null === $this->mapped_text( $row, $id_field ) ? '' : $this->mapped_text( $row, $id_field );
			$did   = preg_match( '/^d(\d+)$/i', $idraw, $m ) ? $m[1] : ''; // a plain number is a WooCommerce product's id: never read
			$entry = array(
				'line'   => $line,
				'naziv'  => sanitize_text_field( null === $this->mapped_text( $row, $name ) ? '' : $this->mapped_text( $row, $name ) ),
				'sifra'  => '' !== $sku ? $sku : ( '' !== $did ? 'd' . $did : '' ),
				'vrsta'  => 'usluga' === $vrsta ? 'usluga' : 'roba',
			);
			$skip  = static function ( $code, $detail = '' ) use ( &$state, $entry ) {
				$state['res'][] = $entry + array( 'list' => 'skipped', 'code' => $code, 'detail' => (string) $detail );
			};

			if ( 'usluge' === $kind && 'usluga' !== $vrsta ) {
				$skip( 'goods_to_webshop' ); // the webshop's goods are in WooCommerce (U15)
				continue;
			}
			if ( '' === $sku && '' === $did ) {
				$skip( 'no_code' ); // only a šifra or the row's id says which row it is next time (Rook 4011)
				continue;
			}
			$found = $this->find_in_target( $catalog->batch_rows(), $oznaka, $sku, $did );
			if ( $found['other'] ) {
				$skip( 'other_place', $this->owner_of_id( $catalog->batch_rows(), $did ) );
				continue;
			}
			$before = null === $found['index'] ? null : $catalog->batch_rows()[ $found['index'] ];
			if ( 'usluge' === $kind && null !== $before && 'usluga' !== $catalog->row_vrsta( $before ) ) {
				// The webshop's own goods row (Additional items) stays goods and as it is (U15, Argus F1): a file of its
				// services never turns it into a service, by šifra, by d-id, or without a Vrsta column.
				$skip( 'goods_to_webshop' );
				continue;
			}
			$keys   = array_filter( array( '' !== $sku ? 's:' . $sku : '', '' !== $did ? 'd:' . $did : '', null !== $before ? 'r:' . $before['id'] : '' ) );
			$first  = null;
			foreach ( $keys as $key ) {
				if ( isset( $state['seen'][ $key ] ) ) {
					$first = $state['seen'][ $key ];
					break;
				}
			}
			if ( null !== $first ) {
				$skip( 'duplicate_code', $first ); // the first row of a šifra decides (Rook 4024, Q1)
				continue;
			}
			foreach ( $keys as $key ) {
				$state['seen'][ $key ] = $line;
			}
			if ( null !== $before ) {
				$state['touched'][ (string) $before['id'] ] = true; // in the file: never removed, also when its row is not read
			}
			$unread = $this->unread_amounts( $row, $amounts );
			if ( $unread ) {
				$skip( 'unclear_amount', reset( $unread ) ); // never a guess (CX-SID-116)
				continue;
			}
			if ( $retail_is_display && $this->special_form_unnamed( $row, $flag_field, $oblik ) ) {
				$skip( 'special_form' );
				continue;
			}

			$item               = $this->catalog_item_from_map( $row, $sku_field, $name, $url, $regular, $sale, $sidrena, $dostupnost, $jedinica, $jedinica_cijena, $marka, $oblik, $cjm, $cjm_suffix, '', $datum, '' );
			$item['sku']        = $sku;
			// A row known by its d-id with an empty šifra cell keeps its stored šifra (Argus X1): the admin's download
			// writes a service without one (art. 3(3), CX-SID-109), and that file sent back must change nothing. Only when
			// that šifra finds this same row, as upsert_working_row() looks by šifra first.
			if ( '' === $sku && null !== $before && '' !== (string) $before['sku']
				&& $this->find_in_target( $catalog->batch_rows(), $oznaka, (string) $before['sku'], '' )['index'] === $found['index'] ) {
				$item['sku'] = (string) $before['sku'];
			}
			$item['vrsta']      = $vrsta;
			$item['poslovnica'] = $oznaka;
			if ( '' !== $did ) {
				$item['id'] = $did;
			}
			// CX-SID-198: a code the place does not have, in its later import. A date in the file always stands; the
			// bazna and the date written here are the row's own (never the default date).
			$returned = null;
			$od_danas = false;
			if ( null === $before && '' !== $sku && ! empty( $state['later'] ) ) {
				$has_date  = isset( $item['sidrena_datum'] ) && '' !== trim( (string) $item['sidrena_datum'] );
				$has_bazna = '' !== (string) $item['sidrena_cijena'];
				if ( isset( $state['hist'][ $sku ] ) ) {
					// It had this very code: back with its bazna and date, or today with another bazna (Rook t-5534c8ba t2).
					if ( ! $has_date ) {
						$old = $state['hist'][ $sku ];
						if ( ! $has_bazna || CX_Sidrena_Money::same_amount( $item['sidrena_cijena'], $old['b'] ) ) {
							$item['sidrena_cijena'] = $old['b'];
							$item['sidrena_datum']  = $old['d'];
							$returned               = 'earlier';
						} else {
							$item['sidrena_datum'] = $state['today_d'];
							$returned              = 'today';
						}
					}
				} else {
					$n = self::code_norm( $sku );
					if ( '' !== $n && isset( $state['norm'][ $n ] ) ) {
						// U9: like a code it has or had (12.050 and 12050 can be two items): never a made-up bazna or date.
						if ( ! $has_date || ! $has_bazna ) {
							$state['res'][] = $entry + array( 'list' => 'like_code', 'code' => 'like_code', 'detail' => (string) $state['norm'][ $n ] );
							continue;
						}
					} elseif ( ! $has_date ) {
						// New to the place (1583 art. 3(1) al. 2 and 4): offered from today, its bazna the file's, else
						// the regular price, never a sale price (art. 3(5)).
						if ( ! $has_bazna ) {
							$item['sidrena_cijena'] = $item['cijena_redovna'];
						}
						$item['sidrena_datum'] = $state['today_d'];
						$od_danas              = '' !== (string) $item['sidrena_cijena'];
					}
				}
			}
			$result = $catalog->upsert_working_row( $item );
			if ( 'other' === $result ) {
				$skip( 'other_place', $this->owner_of_id( $catalog->batch_rows(), $did ) );
				continue;
			}
			if ( 'created' !== $result && 'updated' !== $result ) {
				// Only a new row is refused here: no name, no price, or goods without a bazna (Rook 4008).
				$skip( '' === trim( (string) $item['naziv'] ) ? 'no_name' : ( '' === (string) $item['cijena_redovna'] ? 'no_price' : 'no_bazna' ) );
				continue;
			}
			$after = $catalog->last_upsert_row();
			$entry += array(
				'old'           => $this->shown_values( $before ),
				'new'           => $this->shown_values( $after ),
				'datum_default' => 'usluga' !== $vrsta && '' === trim( (string) $after['sidrena_datum'] ),
			);
			$entry['returned'] = $returned; // CX-SID-198: an item the place had, back with its own date or today's
			if ( ! $catalog->is_public_row( $after ) ) {
				$why            = $catalog->service_state( $after )['reason'];
				$state['res'][] = $entry + array( 'list' => 'waits', 'code' => 'no_bazna' === $why ? 'no_bazna' : 'no_date' ); // a service (CX-SID-130)
			} elseif ( null === $before ) {
				$state['res'][] = $entry + array( 'list' => $od_danas ? 'od_danas' : 'new', 'code' => '' );
			} else {
				$state['res'][] = $entry + array( 'list' => $this->row_print( $before ) === $this->row_print( $after ) ? 'same' : 'changed', 'code' => '' );
			}
		}
		$state['rows'] = $catalog->batch_rows();
		$catalog->drop_batch();
		$state['next'] = $processed;

		$done = $processed >= $total;
		if ( $done ) {
			// The file is the target's price list (U9, Hrvoje): what it did not name goes. For the webshop only its own
			// services (goods are WooCommerce's, U15); a place's rows by agreement (CX-SID-76) are kept apart and stay.
			$keep = array();
			foreach ( $state['rows'] as $row ) {
				$id = isset( $row['id'] ) ? (string) $row['id'] : '';
				if ( '' !== $id && empty( $state['touched'][ $id ] ) && $catalog->row_poslovnica( $row ) === $oznaka
					&& ( 'mjesto' === $kind || 'usluga' === $catalog->row_vrsta( $row ) ) ) {
					$state['res'][] = array(
						'line'  => 0,
						'naziv' => (string) $row['naziv'],
						'sifra' => '' !== (string) $row['sku'] ? (string) $row['sku'] : 'd' . $id,
						'vrsta' => 'usluga' === $catalog->row_vrsta( $row ) ? 'usluga' : 'roba',
						'old'   => $this->shown_values( $row ),
						'new'   => null,
						'list'  => 'removed',
						'code'  => 'removed',
					);
					continue;
				}
				$keep[] = $row;
			}
			$state['rows'] = $keep;
			$state['done'] = true;
		}
		if ( ! $this->job_put( $token . '_u', $state ) ) {
			$this->delete_job( $token );
			$this->fail( 'missing', __( 'The import could not keep its progress in the database. Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}

		$answer = array(
			'offset'  => $processed,
			'total'   => $total,
			'percent' => $total ? (int) round( ( $processed / $total ) * 100 ) : 100,
			'done'    => $done,
		);
		if ( $done ) {
			$back = 0;
			foreach ( $state['res'] as $r ) {
				if ( isset( $r['returned'] ) && 'earlier' === $r['returned'] ) {
					++$back;
				}
			}
			$answer += $this->summary( $state ) + array(
				'sections'     => isset( $state['sections'] ) ? $state['sections'] : array( 'count' => 0, 'names' => array() ),
				// CX-SID-198: items back with their own bazna and date (T89a), and today in the shop's zone (T88a, T88b).
				'returned_earlier' => $back,
				'today'            => isset( $state['today'] ) ? $state['today'] : wp_date( 'Y-m-d' ),
				'consent'      => self::consent_text( $kind, $state['naziv'] ),
				'place_switch' => $state['switch'] ? array( 'dodjela' => $state['dodjela'], 'web_rows' => $state['web_rows'] ) : null,
			);
		}
		wp_send_json_success( $answer );
	}

	/**
	 * Whether a read file has a services heading ("Usluga" or "Usluge", CX-SID-196) by the run's map: then the rows under
	 * it are services and every other row goods. Read part by part, never the whole file at once.
	 *
	 * @param string               $token       Token.
	 * @param array<string, mixed> $job         Job.
	 * @param array<int, string>   $code_fields The mapped šifra and id columns.
	 * @param array<int, string>   $amounts     The mapped amount columns.
	 * @return bool
	 */
	private function file_has_services( $token, $job, $code_fields, $amounts ) {
		$parser = CX_Sidrena_Import_Parse::instance();
		foreach ( array_keys( (array) $job['parts'] ) as $n ) {
			foreach ( (array) $this->job_get( $token . '_' . $n ) as $row ) {
				if ( is_array( $row ) && $parser->is_services_heading( $parser->heading_of( $row, $code_fields, $amounts ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The name of the place a manual row belongs to (the webshop's name for the webshop's), for "another price list".
	 *
	 * @param array<int, array<string, string>> $rows Rows.
	 * @param string                            $id   Row id.
	 * @return string
	 */
	private function owner_of_id( $rows, $id ) {
		$catalog = CX_Sidrena_Catalog::instance();
		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === $id ) {
				$code = $catalog->row_poslovnica( $row );
				if ( '' === $code ) {
					return __( 'Webshop', 'cx-sidrena' );
				}
				$place = $this->place_by_code( $code );
				return null !== $place && '' !== (string) $place['naziv'] ? (string) $place['naziv'] : $code;
			}
		}

		return '';
	}

	/**
	 * The counts of a read file (CX-SID-181 1.c).
	 *
	 * @param array<string, mixed> $state Import state.
	 * @return array{counts: array<string, int>, goods: int, services: int, reasons: array<string, int>}
	 */
	private function summary( $state ) {
		$counts  = array( 'new' => 0, 'changed' => 0, 'same' => 0, 'waits' => 0, 'skipped' => 0, 'removed' => 0, 'od_danas' => 0, 'like_code' => 0 );
		$reasons = array();
		foreach ( $state['res'] as $r ) {
			++$counts[ $r['list'] ];
			if ( '' !== (string) $r['code'] && 'removed' !== $r['code'] ) {
				$reasons[ $r['code'] ] = ( isset( $reasons[ $r['code'] ] ) ? $reasons[ $r['code'] ] : 0 ) + 1;
			}
		}

		return array(
			'counts'   => $counts,
			'goods'    => (int) $state['goods'],
			'services' => (int) $state['services'],
			'reasons'  => $reasons,
		);
	}

	/**
	 * One page of a list of a read file (CX-SID-181 1.d): line, name, šifra, type, old and new values, the reason.
	 */
	public function ajax_list() {
		$this->woo_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- woo_guard()
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$list  = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$page  = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$state = null === $this->load_job( $token ) ? null : $this->job_get( $token . '_u' );
		if ( ! is_array( $state ) ) {
			$this->fail( 'expired', __( 'This import was not found or has expired (an import is kept for an hour after the file is read). Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}
		if ( empty( $state['done'] ) ) {
			$this->fail( 'not_ready', __( 'The file has not been read to the end yet.', 'cx-sidrena' ) );
		}
		$rows = array();
		foreach ( $state['res'] as $r ) {
			if ( $r['list'] === $list ) {
				$rows[] = array(
					'line'   => (int) $r['line'],
					'naziv'  => (string) $r['naziv'],
					'sifra'  => (string) $r['sifra'],
					'vrsta'  => (string) $r['vrsta'],
					'old'    => isset( $r['old'] ) ? $r['old'] : null,
					'new'    => isset( $r['new'] ) ? $r['new'] : null,
					'datum'  => isset( $r['new'] ) && is_array( $r['new'] ) ? array( 'value' => $r['new']['datum'], 'default' => ! empty( $r['datum_default'] ) ) : null,
					'reason' => '' === (string) $r['code'] ? null : array( 'code' => (string) $r['code'], 'detail' => isset( $r['detail'] ) ? (string) $r['detail'] : '' ),
					'returned' => isset( $r['returned'] ) ? $r['returned'] : null, // CX-SID-198: null, earlier or today
				);
			}
		}
		$pages = max( 1, (int) ceil( count( $rows ) / self::LIST_PAGE ) );
		wp_send_json_success(
			array(
				'rows'  => array_slice( $rows, ( min( $page, $pages ) - 1 ) * self::LIST_PAGE, self::LIST_PAGE ),
				'pages' => $pages,
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * The kept result of a confirmed import, by its token, or null.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	private function confirmed_result( $token ) {
		$kept = get_option( self::TARGETS_OPTION, array() );
		foreach ( is_array( $kept ) ? $kept : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['token'] ) && hash_equals( (string) $entry['token'], hash( 'sha256', (string) $token ) ) ) {
				return $entry['result'];
			}
		}

		return null;
	}

	/**
	 * Confirm a read file (CX-SID-181 1.e, 4): in one request, the confirmation record, the place (only when it showed
	 * the webshop's products: it then shows only its own rows, U8 v2), the catalog, then one build. Nothing is written
	 * when the catalog or the place changed since the file was read (CX-SID-124), and a place written before a catalog
	 * write that fails is set back. A second confirmation of the same file answers with the first one's result.
	 */
	public function ajax_confirm() {
		$this->woo_guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- woo_guard()
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$get   = static function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$state = null === $this->load_job( $token ) ? null : $this->job_get( $token . '_u' );
		if ( ! is_array( $state ) ) {
			$done = $this->confirmed_result( $token );
			if ( null !== $done ) {
				$this->fail( 'already_confirmed', __( 'This import is already confirmed.', 'cx-sidrena' ), $done + array( 'targets' => $this->screen_targets() ) );
			}
			$this->fail( 'expired', __( 'This import was not found or has expired (an import is kept for an hour after the file is read). Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}
		if ( empty( $state['done'] ) ) {
			$this->fail( 'not_ready', __( 'The file has not been read to the end yet.', 'cx-sidrena' ) );
		}
		if ( '1' !== $get( 'consent' ) ) {
			$this->fail( 'no_consent', __( 'Tick the confirmation.', 'cx-sidrena' ) );
		}
		$ok = CX_Sidrena_Fill::confirmation(
			array(
				'confirmed_by' => $get( 'confirmed_by' ),
				'confirmed_on' => $get( 'confirmed_on' ),
				'consent'      => self::consent_text( $state['kind'], $state['naziv'] ), // the server's text, never a posted one
			)
		);
		if ( is_wp_error( $ok ) ) {
			$this->fail( $ok->get_error_code(), $ok->get_error_message() );
		}
		$sum = $this->summary( $state );
		$in  = $sum['counts']['new'] + $sum['counts']['changed'] + $sum['counts']['same'] + $sum['counts']['od_danas'];
		if ( 0 === $in ) {
			// A file with no row for the price list never empties it (U9, T49).
			$this->fail( 'nothing', __( 'No row in this file can be imported. The reasons are in the table.', 'cx-sidrena' ) );
		}

		// Only one confirmation of this file gets past here: its state is taken (one row deleted).
		global $wpdb;
		if ( 1 !== (int) $wpdb->delete( $wpdb->options, array( 'option_name' => self::JOB . $token . '_u' ) ) ) { // phpcs:ignore WordPress.DB
			$done = $this->confirmed_result( $token );
			if ( null !== $done ) {
				$this->fail( 'already_confirmed', __( 'This import is already confirmed.', 'cx-sidrena' ), $done + array( 'targets' => $this->screen_targets() ) );
			}
			$this->fail( 'expired', __( 'This import was not found or has expired (an import is kept for an hour after the file is read). Nothing was imported. Upload the file again.', 'cx-sidrena' ) );
		}

		$changed = __( 'The price list changed after the preview, so nothing was written. Upload the file again.', 'cx-sidrena' );
		$moved   = __( 'The places of sale changed after the preview, so nothing was written. Upload the file again.', 'cx-sidrena' );
		$places  = CX_Sidrena_Poslovnice::instance();
		// 1. Everything as it was when the file was read.
		if ( $this->catalog_sha() !== $state['catalog'] ) {
			$this->delete_job( $token );
			$this->fail( 'changed_since_preview', $changed );
		}
		$print = 'mjesto' === $state['kind'] ? $places->import_fingerprint( $state['oznaka'] ) : null;
		if ( null !== $print && ( null === $print['fingerprint'] || $print['fingerprint'] !== $state['place'] ) ) {
			$this->delete_job( $token );
			$this->fail( 'place_changed_since_preview', $moved );
		}
		// The price list of all places was switched on after the run (CX-SID-113, N8): a place gets no rows of its own.
		if ( 'mjesto' === $state['kind'] && CX_Sidrena_Settings::instance()->is_isti_cjenik() ) {
			$this->delete_job( $token );
			$this->fail( 'isti_cjenik', CX_Sidrena_Settings::isti_cjenik_refusal( $state['oznaka'] ) );
		}
		// 2. The record.
		$conf = CX_Sidrena_History::start_confirmation(
			array(
				'kind'         => $state['kind'],
				'confirmed_by' => $ok['by'],
				'confirmed_on' => $ok['on'],
				'consent'      => $ok['consent'],
				'file_name'    => $state['file'],
				'file_sha256'  => $state['sha256'],
				'total'        => $in + $sum['counts']['waits'],
			)
		);
		if ( is_wp_error( $conf ) ) {
			$this->delete_job( $token );
			$this->fail( 'db', $conf->get_error_message() );
		}
		// 3. The place, only if the list is still what was read (write_if_unchanged).
		$switched = false;
		if ( null !== $print && $state['switch'] ) {
			$after = $places->switch_to_own_rows( $state['oznaka'], $print['raw'] );
			if ( false === $after ) {
				CX_Sidrena_History::drop_unfinished_confirmation( $conf );
				$this->delete_job( $token );
				$this->fail( 'place_changed_since_preview', $moved );
			}
			$switched = true;
		}
		// 4. The catalog, once; checked in the table. A failed write sets the place back.
		$rows_before = CX_Sidrena_Catalog::instance()->all(); // CX-SID-198: what the place had, for its history
		$raw_before  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CX_Sidrena_Catalog::OPTION_KEY ) ); // phpcs:ignore WordPress.DB
		$clean      = CX_Sidrena_Catalog::instance()->save( $state['rows'], false );
		$raw_after  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", CX_Sidrena_Catalog::OPTION_KEY ) ); // phpcs:ignore WordPress.DB
		if ( $raw_after !== maybe_serialize( $clean ) ) {
			$back = ! $switched || $places->undo_switch( $print['raw'], $after );
			CX_Sidrena_History::drop_unfinished_confirmation( $conf );
			$this->delete_job( $token );
			$this->fail(
				'db',
				$back ? __( 'The price list could not be saved, so nothing was written.', 'cx-sidrena' )
					: __( 'The price list could not be saved, and the place of sale could not be set back. Check it under Places of sale.', 'cx-sidrena' )
			);
		}
		// 5. Done: the record, the target kept with it, and one build when something changed.
		$result = array(
			'import_id'      => (int) $conf,
			'written'        => array( 'goods' => 0, 'services' => 0 ),
			'waits'          => $sum['counts']['waits'],
			'removed'        => $sum['counts']['removed'],
			'skipped'        => $sum['counts']['skipped'] + $sum['counts']['like_code'], // like_code: not imported (T95a)
			'od_danas'       => $sum['counts']['od_danas'], // CX-SID-198, T88c
			'place_switched' => $switched,
			'url'            => CX_Sidrena_Feed_Files::instance()->get_cjenik_url( $state['oznaka'] ),
		);
		foreach ( $state['res'] as $r ) {
			if ( in_array( $r['list'], array( 'new', 'changed', 'same', 'od_danas' ), true ) ) {
				++$result['written'][ 'usluga' === $r['vrsta'] ? 'services' : 'goods' ];
			}
		}
		CX_Sidrena_History::finish_confirmation(
			$conf,
			array(
				'updated'   => $sum['counts']['new'] + $sum['counts']['changed'] + $sum['counts']['od_danas'],
				'unchanged' => $sum['counts']['same'],
				'invalid'   => $sum['counts']['skipped'] + $sum['counts']['like_code'],
				'skipped'   => $sum['counts']['waits'],
			)
		);
		$kept = get_option( self::TARGETS_OPTION, array() );
		$kept = is_array( $kept ) ? $kept : array();
		$kept[ (string) $conf ] = array(
			'token'    => hash( 'sha256', $token ),
			'kind'     => $state['kind'],
			'place_id' => $state['place_id'],
			'oznaka'   => $state['oznaka'],
			'naziv'    => $state['naziv'],
			'removed'  => $sum['counts']['removed'],
			'place_switched' => $switched,
			'at'       => time(),
			'result'   => $result,
		);
		if ( count( $kept ) > self::TARGETS_KEEP ) {
			ksort( $kept, SORT_NUMERIC );
			$kept = array_slice( $kept, -self::TARGETS_KEEP, null, true );
		}
		update_option( self::TARGETS_OPTION, $kept, false );
		// CX-SID-196: the target remembers the file's shape (names row, its names, the columns mapped) for its next file.
		// Only names: never a row above the names row, never a row of the file.
		if ( ! empty( $state['shape'] ) && is_array( $state['shape'] ) ) {
			$key = 'usluge' === $state['kind'] ? 'webshop' : (string) $state['place_id'];
			if ( '' !== $key ) {
				$shapes         = get_option( self::SHAPE_OPTION, array() );
				$shapes         = is_array( $shapes ) ? $shapes : array();
				$shapes[ $key ] = $state['shape'] + array( 'oznaka' => (string) $state['oznaka'] );
				update_option( self::SHAPE_OPTION, $shapes, false );
			}
		}
		// CX-SID-198: the place keeps every code it had, with its bazna and date (the next file's "returned" items).
		if ( 'mjesto' === $state['kind'] && '' !== (string) $state['place_id'] ) {
			$this->keep_history( array( 'id' => $state['place_id'], 'oznaka' => $state['oznaka'] ), $rows_before, $clean );
		}
		$this->delete_job( $token );
		if ( $switched || $raw_before !== $raw_after ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}
		// CX-SID-201: the screen's targets as a reload would give them (this place is imported and later now), only in the
		// answer: the result kept above stays as it was.
		wp_send_json_success( $result + array( 'targets' => $this->screen_targets() ) );
	}

	/**
	 * The targets as the Import screen gets them on a page load (CX_Sidrena_Admin::targets_for_screen()), for the answer
	 * of a confirmation (CX-SID-201): after it the screen draws the place's state and the next file's sentences from these,
	 * not from the page load's.
	 *
	 * @return array<string, mixed>
	 */
	private function screen_targets() {
		return CX_Sidrena_Admin::targets_for_screen( $this->targets() );
	}

	/**
	 * The history of confirmed imports, all kinds in one list, the newest first (CX-SID-181 1.f): the bazna cijene of
	 * products (Import, CX-SID-22) and the price lists of places and of the webshop's services.
	 */
	public function ajax_history() {
		$this->woo_guard();
		global $wpdb;
		$page  = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- woo_guard()
		$table = CX_Sidrena_History::confirmations_table();
		$per   = 20;
		$kinds = "kind IN ('import','mjesto','usluge')";
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$kinds} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$kinds}" ); // phpcs:ignore WordPress.DB
		$kept  = get_option( self::TARGETS_OPTION, array() );
		$kept  = is_array( $kept ) ? $kept : array();
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$u      = get_userdata( (int) $r['user_id'] );
			$on     = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $r['confirmed_on'], $m ) ? "{$m[3]}.{$m[2]}.{$m[1]}." : '';
			$target = isset( $kept[ (string) $r['id'] ] ) ? $kept[ (string) $r['id'] ] : null;
			$out[]  = array(
				'import_id'    => (int) $r['id'],
				'kind'         => (string) $r['kind'],
				'target'       => null === $target ? null : array( 'oznaka' => (string) $target['oznaka'], 'naziv' => (string) $target['naziv'] ),
				'at'           => wp_date( 'd.m.Y. H:i', (int) $r['started_at'] ),
				'user'         => $u ? $u->user_login : '',
				'confirmed_by' => (string) $r['confirmed_by'],
				'confirmed_on' => $on,
				'updated'      => (int) $r['updated'],
				'removed'      => null === $target ? 0 : (int) $target['removed'],
				'file'         => (string) $r['file_name'],
				'short'        => substr( (string) $r['file_sha256'], 0, 8 ),
				'sha256'       => (string) $r['file_sha256'],
				'finished'     => ! empty( $r['finished_at'] ),
			);
		}
		wp_send_json_success(
			array(
				'rows'  => $out,
				'pages' => max( 1, (int) ceil( $total / $per ) ),
			)
		);
	}

	/**
	 * A template for a target (CX-SID-181 1.g): GET with cilj and nonce. Our columns as parse guesses them, without the
	 * place and the address; a place's has goods and a service, the webshop's a service.
	 */
	public function ajax_template() {
		$this->woo_guard();
		$cilj  = isset( $_GET['cilj'] ) ? sanitize_text_field( wp_unslash( $_GET['cilj'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- woo_guard()
		$web   = 'webshop' === strtolower( $cilj );
		$files = CX_Sidrena_Feed_Files::instance();
		$date  = CX_Sidrena_Catalog::instance()->normalize_date( (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) );
		$date  = '' === $date ? '10.09.2026.' : $date;
		$body  = $files->csv_line( array( 'naziv', 'sku', 'vrsta', 'marka', 'cijena_redovna', 'cijena_akcijska', 'naziv_posebnog_oblika', 'jedinica_mjere', 'cijena_jedinice_mjere', 'sidrena_cijena', 'sidrena_datum' ) );
		if ( ! $web ) {
			$body .= $files->csv_line( array( 'Primjer artikla', 'ART-001', 'proizvod', 'Marka', '10,00', '', '', 'kom', '', '10,00', $date ) );
		}
		$body .= $files->csv_line( array( 'Primjer usluge', 'USL-001', 'usluga', '', '25,00', '', '', '', '', '25,00', '10.09.2026.' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ( $web ? 'predlozak-usluge.csv' : 'predlozak-cjenik-mjesta.csv' ) . '"' );
		echo "\xEF\xBB\xBF" . $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; csv_line() quotes and neutralises formulas.
		exit;
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
