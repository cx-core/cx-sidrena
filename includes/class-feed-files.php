<?php
/**
 * Write and stream XML, CSV, and HTML pricelist files.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Write and stream XML, CSV, and HTML pricelist files.
 */
class CX_Sidrena_Feed_Files {

	/**
	 * Products shown on one public cjenik page.
	 */
	const CJENIK_PAGE_SIZE = 200;

	/**
	 * Columns of a public price list file for goods, in the order of the items of art. 3(2) of the Pravilnik o cjeniku
	 * (NN 132/2026-1584): naziv, šifra, marka, neto količina, jedinica mjere, maloprodajna cijena and whether it is
	 * one of a special form of sale, cijena za jedinicu mjere, barkod, EAN barkod, bazna cijena. Art. 3(4): this order.
	 */
	const COLUMNS_ROBA = array( 'naziv', 'sifra', 'marka', 'neto_kolicina', 'jedinica_mjere', 'maloprodajna_cijena', 'poseban_oblik', 'cijena_jedinice_mjere', 'barkod', 'ean_barkod', 'bazna_cijena' );

	/**
	 * Columns for services, art. 3(3): naziv, maloprodajna cijena and whether it is one of a special form of sale,
	 * bazna cijena.
	 */
	const COLUMNS_USLUGA = array( 'naziv', 'maloprodajna_cijena', 'poseban_oblik', 'bazna_cijena' );

	/**
	 * Only in the price list downloaded from the admin, after the columns of the Pravilnik (CX-SID-110, Argus 110-D):
	 * what the bulk import needs to put an edited row back where it came from (the id), to keep a service a service, and
	 * to keep the sale and the date (whether the retail price is a sale price, and the bazna cijena's own date). The
	 * public files, the archive and the stable addresses never have them: the legal price list is the public address.
	 */
	const COLUMNS_ADMIN = array( 'id', 'vrsta', 'naziv_posebnog_oblika', 'sidrena_datum' );

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
	 * CSV with one product example and one service example for Uvoz.
	 */
	public function send_import_template() {
		$date = CX_Sidrena_Catalog::instance()->normalize_date( (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) );
		if ( '' === $date ) {
			$date = '10.09.2026.';
		}

		$keys = array( 'naziv', 'sku', 'vrsta', 'marka', 'url', 'cijena_redovna', 'cijena_akcijska', 'naziv_posebnog_oblika', 'jedinica_mjere', 'cijena_jedinice_mjere', 'sidrena_cijena', 'sidrena_datum', 'barkod', 'dostupnost', 'poslovnica' );
		$lines   = array();
		$lines[] = $this->csv_line( $keys );
		$lines[] = $this->csv_line(
			array(
				'Primjer proizvoda',
				'SKU-001',
				'proizvod',
				'Marka',
				'',
				'10,00',
				'',
				'',
				'kg',
				'10,00',
				'10,00',
				$date,
				'3850000000000',
				'dostupno',
				'',
			)
		);
		$lines[] = $this->csv_line(
			array(
				'Primjer usluge (dostava HP do 2 kg)',
				'USL-HP-2KG',
				'usluga',
				'',
				'',
				'4,50',
				'',
				'',
				'',
				'',
				'4,50',
				$date,
				'',
				'dostupno',
				'',
			)
		);

		$this->headers( 'text/csv; charset=utf-8', 'sidrena-cijena-predlozak.csv' );
		echo chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) . implode( '', $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Send CSV download.
	 *
	 * @param string                             $filename File name.
	 * @param array<int, array<string, string>>  $rows     Rows.
	 * @param bool                               $public   Client cjenik columns, as the public feed, plus COLUMNS_ADMIN.
	 */
	public function send_csv( $filename, $rows, $public = false ) {
		$this->headers( 'text/csv; charset=utf-8', $filename );

		if ( $public ) {
			echo $this->build_csv_string( $rows, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; fields formula-neutralized and quoted in csv_line().
			exit;
		}

		$lines   = array();
		$lines[] = $this->csv_line( array( 'naziv', 'sku', 'cijena_redovna', 'cijena_akcijska', 'sidrena_cijena' ) );

		foreach ( $rows as $row ) {
			$lines[] = $this->csv_line(
				array(
					$row['naziv'],
					$row['sku'],
					$this->csv_amount( $row['cijena_redovna'] ),
					$this->csv_amount( $row['cijena_akcijska'] ),
					$this->csv_amount( $row['sidrena_cijena'] ),
				)
			);
		}

		echo chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) . implode( '', $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; fields formula-neutralized and quoted in csv_line().
		exit;
	}

	/**
	 * One CSV row with a semicolon delimiter.
	 *
	 * @param array<int, string> $fields Fields.
	 * @return string
	 */
	public function csv_line( $fields ) {
		$escaped = array();

		foreach ( $fields as $field ) {
			$escaped[] = $this->csv_field( $field );
		}

		return implode( ';', $escaped ) . "\r\n";
	}

	/**
	 * Neutralize spreadsheet formulas, then quote for CSV syntax.
	 *
	 * @param mixed $field Raw field.
	 * @return string
	 */
	public function csv_field( $field ) {
		$field = $this->csv_neutralize_formula( $field );

		if ( false !== strpbrk( $field, ";\"\n\r" ) ) {
			$field = '"' . str_replace( '"', '""', $field ) . '"';
		}

		return $field;
	}

	/**
	 * Prefix spreadsheet formula triggers so Excel/LibreOffice treat the cell as text.
	 *
	 * Number values are left unchanged: a pure numeric value cannot form a valid formula.
	 *
	 * @see https://owasp.org/www-community/attacks/CSV_Injection
	 *
	 * @param mixed $field Raw field.
	 * @return string
	 */
	public function csv_neutralize_formula( $field ) {
		if ( is_int( $field ) || is_float( $field ) ) {
			return (string) $field;
		}

		$field = (string) $field;
		if ( '' === $field ) {
			return $field;
		}

		// 0x09: Tab (\t), 0x0D: Carriage Return (\r).
		$triggers = array( '=', '+', '-', '@', chr( 0x09 ), chr( 0x0D ) );
		$first    = function_exists( 'mb_substr' ) ? mb_substr( $field, 0, 1, 'UTF-8' ) : substr( $field, 0, 1 );

		if ( in_array( $first, $triggers, true ) ) {
			return "'" . $field;
		}

		return $field;
	}

	/**
	 * Price columns written with a Croatian decimal comma in CSV only.
	 *
	 * @return array<int, string>
	 */
	public function csv_price_keys() {
		return array( 'cijena_redovna', 'cijena_akcijska', 'cijena_jedinice_mjere', 'maloprodajna_cijena', 'sidrena_cijena', 'bazna_cijena' );
	}

	/**
	 * Pure dot-decimal amounts become commas so Excel does not read them as dates.
	 *
	 * Empty values, integers, and dates such as 10.09.2026. stay unchanged.
	 *
	 * @param mixed $value Raw amount.
	 * @return string
	 */
	public function csv_amount( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^-?\d+\.\d+$/', $value ) ) {
			return str_replace( '.', ',', $value );
		}

		return $value;
	}

	/**
	 * Public CSV (semicolon, UTF-8 BOM), same columns as the public XML feed.
	 *
	 * @param array<int, array<string, string>> $rows  Rows.
	 * @param bool                              $admin The admin's download: COLUMNS_ADMIN after the legal ones.
	 * @return string
	 */
	public function build_csv_string( $rows, $admin = false ) {
		$keys  = $this->public_column_keys( $rows );
		$lines = array();
		$lines[] = $this->csv_line( $admin ? array_merge( $keys, self::COLUMNS_ADMIN ) : $keys );

		$price_keys = $this->csv_price_keys();

		foreach ( $rows as $row ) {
			$legal = $this->legal_row( $row );
			// A service in a file with goods: the goods-only columns stay empty (art. 3(3) lists only these for services).
			$own   = $this->public_column_keys( array(), $this->row_vrsta( $row ) );
			$cells = array();
			foreach ( $keys as $key ) {
				$cell = in_array( $key, $own, true ) ? $legal[ $key ] : '';
				if ( in_array( $key, $price_keys, true ) ) {
					$cell = $this->csv_amount( $cell );
				}
				$cells[] = $cell;
			}
			if ( $admin ) {
				$cells = array_merge( $cells, array_values( $this->admin_row( $row ) ) );
			}
			$lines[] = $this->csv_line( $cells );
		}

		return chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) . implode( '', $lines );
	}

	/**
	 * Public CSV/XML columns, by the Pravilnik o cjeniku (NN 132/2026-1584): art. 3(4) wants them in the order of the
	 * items in art. 3(2) for goods and art. 3(3) for services, and nothing else is in the file. Without a type, the
	 * columns of the whole file: services only when every row is one, else goods (a CSV has one header).
	 *
	 * @param array<int, array<string, string>> $rows  Rows.
	 * @param string|null                       $vrsta proizvod|usluga for one row's columns, null for the file's.
	 * @return array<int, string>
	 */
	public function public_column_keys( $rows, $vrsta = null ) {
		if ( null === $vrsta ) {
			$vrsta = $this->feed_is_services_only( $rows ) ? 'usluga' : 'proizvod';
		}

		return 'usluga' === $vrsta ? self::COLUMNS_USLUGA : self::COLUMNS_ROBA;
	}

	/**
	 * A public row under the names of the price list file. The rows keep their own keys (sku, sidrena_cijena,
	 * naziv_posebnog_oblika), which the HTML table, the shortcode and the admin read; only the file uses these.
	 *
	 * @param array<string, string> $row Public row (CX_Sidrena_Feed_Rows::finish_public_rows()).
	 * @return array<string, string>
	 */
	public function legal_row( $row ) {
		$value = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		};
		$codes = $this->split_barcode( $value( 'barkod' ) );

		return array(
			'naziv'                 => $value( 'naziv' ),
			'sifra'                 => $value( 'sku' ),
			'marka'                 => $value( 'marka' ),
			'neto_kolicina'         => '', // no such field yet (v0.3); the column is in its place, empty
			'jedinica_mjere'        => $value( 'jedinica_mjere' ),
			'maloprodajna_cijena'   => $value( 'maloprodajna_cijena' ),
			'poseban_oblik'         => 'da' === $value( 'poseban_oblik' ) ? 'DA' : 'NE',
			'cijena_jedinice_mjere' => $value( 'cijena_jedinice_mjere' ),
			'barkod'                => $codes['barkod'],
			'ean_barkod'            => $codes['ean_barkod'],
			'bazna_cijena'          => $value( 'sidrena_cijena' ),
		);
	}

	/**
	 * COLUMNS_ADMIN of one row, as the rows hold them: the id (a manual row's with its "d" when WooCommerce is on), the
	 * type, the name of the special form of sale (empty when the retail price is the regular one), the bazna cijena's
	 * date.
	 *
	 * @param array<string, string> $row Public row.
	 * @return array<string, string>
	 */
	public function admin_row( $row ) {
		$out = array();
		foreach ( self::COLUMNS_ADMIN as $key ) {
			$out[ $key ] = 'vrsta' === $key ? $this->row_vrsta( $row ) : ( isset( $row[ $key ] ) ? (string) $row[ $key ] : '' );
		}

		return $out;
	}

	/**
	 * The product's one code in its column: exactly 8 or 13 digits is an EAN (EAN-8, EAN-13), anything else (UPC-A's
	 * 12, GTIN-14, letters) a barcode. The check digit is not checked.
	 *
	 * @param string $code Code from CX_Sidrena_Feed_Rows::product_barcode().
	 * @return array{barkod:string,ean_barkod:string}
	 */
	public function split_barcode( $code ) {
		$code = (string) $code;
		if ( 1 === preg_match( '/^(?:\d{8}|\d{13})$/D', $code ) ) {
			return array( 'barkod' => '', 'ean_barkod' => $code );
		}

		return array( 'barkod' => $code, 'ean_barkod' => '' );
	}

	/**
	 * proizvod or usluga.
	 *
	 * @param array<string, string> $row Row.
	 * @return string
	 */
	public function row_vrsta( $row ) {
		return ( isset( $row['vrsta'] ) && 'usluga' === $row['vrsta'] ) ? 'usluga' : 'proizvod';
	}

	/**
	 * True when every row is an usluga.
	 *
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return bool
	 */
	public function feed_is_services_only( $rows ) {
		if ( array() === $rows ) {
			return false;
		}

		foreach ( $rows as $row ) {
			$vrsta = isset( $row['vrsta'] ) ? $row['vrsta'] : 'proizvod';
			if ( 'usluga' !== $vrsta ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * xml or csv from settings.
	 *
	 * @return string
	 */
	public function export_format() {
		if ( class_exists( 'CX_Sidrena_Settings' ) ) {
			return CX_Sidrena_Settings::instance()->export_format();
		}

		return 'xml';
	}

	/**
	 * xml or csv.
	 *
	 * @param mixed $ext Raw.
	 * @return string
	 */
	public function sanitize_ext( $ext ) {
		$ext = is_string( $ext ) ? strtolower( $ext ) : '';

		return 'csv' === $ext ? 'csv' : 'xml';
	}

	/**
	 * Live public filename for a format.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function live_filename( $format = '', $oznaka = '', $vrsta = 'proizvod' ) {
		$format = '' === $format ? $this->export_format() : $this->sanitize_ext( $format );
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( 'usluga' === $vrsta ) {
			// Services (CX-SID-110). An underscore, which no object code can have, so the file of an outlet whose code
			// is "usluge" is never this one; the address is sidrena-cijena-usluge[-oznaka] (get_public_url()).
			return CX_Sidrena_Export::PUBLIC_STEM . '_usluge' . ( '' !== $oznaka ? '-' . $oznaka : '' ) . '.' . $format;
		}
		if ( '' !== $oznaka ) {
			return CX_Sidrena_Export::PUBLIC_STEM . '-' . $oznaka . '.' . $format;
		}

		return CX_Sidrena_Export::PUBLIC_STEM . '.' . $format;
	}

	/**
	 * Uploads basedir from wp_upload_dir() only (no WP_CONTENT_DIR fallback).
	 *
	 * @return string
	 */
	public function get_uploads_basedir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return (string) $uploads['basedir'];
	}

	/**
	 * Absolute directory for the public XML file.
	 *
	 * @return string
	 */
	public function get_public_dir() {
		$base = $this->get_uploads_basedir();
		if ( '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . 'cx-sidrena';
	}

	/**
	 * Absolute path of the live public feed.
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_path( $format = '', $oznaka = '', $vrsta = 'proizvod' ) {
		$dir = $this->get_public_dir();
		if ( '' === $dir ) {
			return '';
		}

		return trailingslashit( $dir ) . $this->live_filename( $format, $oznaka, $vrsta );
	}

	/**
	 * Absolute path of the generated HTML table for the public page and shortcode.
	 *
	 * @param string $oznaka Outlet code, empty for the full catalog.
	 * @return string
	 */
	public function get_cjenik_html_path( $oznaka = '' ) {
		$dir = $this->get_public_dir();
		if ( '' === $dir ) {
			return '';
		}

		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		$name   = '' === $oznaka ? 'sidrena-cijena-cjenik.html' : 'sidrena-cijena-cjenik-' . $oznaka . '.html';

		return trailingslashit( $dir ) . $name;
	}

	/**
	 * Public URL (pretty permalink, or query fallback).
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_url( $format = '', $oznaka = '', $vrsta = 'proizvod' ) {
		$format     = '' === $format ? $this->export_format() : $this->sanitize_ext( $format );
		$oznaka     = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		$permalinks = get_option( 'permalink_structure' );
		$usluge     = 'usluga' === $vrsta;

		if ( is_string( $permalinks ) && '' !== $permalinks ) {
			$stem = CX_Sidrena_Export::PUBLIC_STEM . ( $usluge ? '-usluge' : '' );
			return home_url( '/' . $stem . ( '' !== $oznaka ? '-' . $oznaka : '' ) . '.' . $format );
		}

		$args = array(
			'sidrena_cijena_xml' => '1',
			'sidrena_cijena_ext' => $format,
		);
		if ( '' !== $oznaka ) {
			$args['sidrena_cijena_oznaka'] = $oznaka;
		}
		if ( $usluge ) {
			$args['sidrena_cijena_vrsta'] = 'usluga';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Public archive index URL.
	 *
	 * @return string
	 */
	public function get_archive_url() {
		$permalinks = get_option( 'permalink_structure' );

		if ( is_string( $permalinks ) && '' !== $permalinks ) {
			return home_url( '/' . CX_Sidrena_Export::ARCHIVE_SLUG . '/' );
		}

		return add_query_arg( 'sidrena_cijena_arhiva', '1', home_url( '/' ) );
	}

	/**
	 * Public HTML pricelist URL. Empty oznaka is the webshop page.
	 *
	 * @param string $oznaka Outlet code, empty for the webshop page.
	 * @return string
	 */
	public function get_cjenik_url( $oznaka = '' ) {
		$oznaka     = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		$permalinks = get_option( 'permalink_structure' );

		if ( is_string( $permalinks ) && '' !== $permalinks ) {
			$path = '/' . CX_Sidrena_Export::CJENIK_SLUG . '/';
			if ( '' !== $oznaka ) {
				$path .= $oznaka . '/';
			}

			return home_url( $path );
		}

		$args = array(
			'sidrena_cijena_cjenik' => '1',
		);
		if ( '' !== $oznaka ) {
			$args['sidrena_cijena_oznaka'] = $oznaka;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Public URL for one archived feed file.
	 *
	 * @param string $name_or_date Legal filename or legacy d-m-Y.
	 * @param string $ext          xml|csv (legacy date URLs).
	 * @return string
	 */
	public function get_archive_file_url( $name_or_date, $ext = 'xml' ) {
		$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( $name_or_date );
		if ( '' === $name ) {
			$date = CX_Sidrena_Archive::instance()->sanitize_archive_date( $name_or_date );
			$ext  = $this->sanitize_ext( $ext );
			if ( '' === $date ) {
				return $this->get_archive_url();
			}
			$name = CX_Sidrena_Export::PUBLIC_STEM . '_' . $date . '.' . $ext;
		}

		$permalinks = get_option( 'permalink_structure' );
		$pretty     = is_string( $permalinks ) && '' !== $permalinks
			&& CX_Sidrena_Archive::instance()->archive_name_allows_pretty_path( $name );

		if ( $pretty ) {
			return home_url( '/' . CX_Sidrena_Export::ARCHIVE_SLUG . '/' . rawurlencode( $name ) );
		}

		return add_query_arg(
			array(
				'sidrena_cijena_arhiva_name' => $name,
			),
			home_url( '/' )
		);
	}

	/**
	 * Send XML download.
	 *
	 * @param string                             $filename     File name.
	 * @param array<int, array<string, string>>  $rows         Rows.
	 * @param bool                               $missing_only Audit export.
	 * @param bool                               $public       Client cjenik columns, same as the public feed.
	 */
	public function send_xml( $filename, $rows, $missing_only, $public = false ) {
		$this->headers( 'application/xml; charset=utf-8', $filename );
		echo $this->build_xml_string( $rows, $missing_only ? 'nedostaje' : 'cjenik', $public, 0, $public ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML download; values escaped with esc_xml() in xml_value().
		exit;
	}

	/**
	 * Build an XML document string.
	 *
	 * @param array<int, array<string, string>> $rows           Rows.
	 * @param string                            $root           Root element name.
	 * @param bool                              $public_fields  The public price list's columns (public_column_keys()).
	 * @param int                               $timestamp      Its generated time, 0 = now.
	 * @param bool                              $admin          The admin's download: COLUMNS_ADMIN after the legal ones.
	 * @return string
	 */
	public function build_xml_string( $rows, $root, $public_fields = false, $timestamp = 0, $admin = false ) {
		$root = preg_replace( '/[^a-z0-9_\-]/i', '', $root );
		if ( '' === $root ) {
			$root = 'cjenik';
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<' . $root . ' generated="' . $this->xml_value( CX_Sidrena_Archive::legal_date( 'c', $timestamp > 0 ? (int) $timestamp : null ) ) . '" count="' . $this->xml_value( (string) count( $rows ) ) . '" shop="' . $this->xml_value( home_url( '/' ) ) . '">' . "\n";

		foreach ( $rows as $row ) {
			// Public: each row its own columns, <proizvod> those of goods and <usluga> those of services (art. 3(2)-(3)).
			$row_tag = $public_fields ? $this->row_vrsta( $row ) : 'proizvod';
			$xml    .= "\t<" . $row_tag . ">\n";
			if ( $public_fields ) {
				$legal = $this->legal_row( $row );
				foreach ( $this->public_column_keys( array(), $row_tag ) as $key ) {
					$xml .= "\t\t<" . $key . ">" . $this->xml_value( $legal[ $key ] ) . "</" . $key . ">\n";
				}
				if ( $admin ) {
					foreach ( $this->admin_row( $row ) as $key => $value ) {
						$xml .= "\t\t<" . $key . ">" . $this->xml_value( $value ) . "</" . $key . ">\n";
					}
				}
			} else {
				$xml .= "\t\t<naziv>" . $this->xml_value( $row['naziv'] ) . "</naziv>\n";
				$xml .= "\t\t<sku>" . $this->xml_value( $row['sku'] ) . "</sku>\n";
				$xml .= "\t\t<cijena_redovna>" . $this->xml_value( $row['cijena_redovna'] ) . "</cijena_redovna>\n";
				$xml .= "\t\t<cijena_akcijska>" . $this->xml_value( $row['cijena_akcijska'] ) . "</cijena_akcijska>\n";
				$xml .= "\t\t<sidrena_cijena>" . $this->xml_value( $row['sidrena_cijena'] ) . "</sidrena_cijena>\n";
			}
			$xml .= "\t</" . $row_tag . ">\n";
		}

		$xml .= '</' . $root . '>';

		return $xml;
	}

	/**
	 * Publish one price list: the archive file and the live file (CX_Sidrena_Archive::publish()), then the HTML table.
	 *
	 * @param array<int, array<string, string>> $rows   Rows.
	 * @param string                            $oznaka Outlet code, empty for the single shop feed.
	 * @param array<string, mixed>|null         $outlet The outlet of that code, or null for the webshop.
	 * @return array<string, mixed>|WP_Error
	 */
	public function write_feed_file( $rows, $oznaka = '', $outlet = null ) {
		$format = $this->export_format();
		$now    = time(); // one instant: generated in the file, the time in its name, "Updated"
		$dir    = $this->get_public_dir();

		if ( '' === $dir ) {
			return new WP_Error( 'sidrena_xml_uploads', __( 'Uploads folder is not available.', 'cx-sidrena' ) );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_mkdir', __( 'The public price list folder could not be created.', 'cx-sidrena' ) );
		}
		$this->ensure_dir_index( $dir );

		if ( '' === (string) $oznaka ) {
			$outlet = null;
		} elseif ( ! is_array( $outlet ) ) {
			$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			if ( ! is_array( $outlet ) ) {
				return new WP_Error( 'sidrena_poslovnica_missing', __( 'The outlet has no published price list.', 'cx-sidrena' ) );
			}
		}
		$kinds = $this->rows_by_vrsta( $rows );
		$main  = array() !== $kinds['proizvod'] || array() === $kinds['usluga'] ? 'proizvod' : 'usluga';
		$arch  = CX_Sidrena_Archive::instance();
		// One storage number for this build, whichever of its files are new (art. 6(3); CX-SID-110).
		$seq   = $arch->next_pohrana( $arch->oznaka_of( $outlet ), $outlet );
		$code  = is_array( $outlet ) && isset( $outlet['oznaka'] ) ? (string) $outlet['oznaka'] : '';
		$vrste = array();

		foreach ( array( 'proizvod', 'usluga' ) as $vrsta ) {
			// Goods have their file even when empty, unless the scope has only services: then the main address holds those.
			if ( array() === $kinds[ $vrsta ] && $vrsta !== $main ) {
				if ( 'usluga' === $vrsta ) {
					$this->drop_live( $this->get_public_path( $format, $code, $vrsta ) );
				}
				continue; // goods: the main address gets the services file below, never left empty in between
			}
			$body      = 'csv' === $format ? $this->build_csv_string( $kinds[ $vrsta ] ) : $this->build_xml_string( $kinds[ $vrsta ], 'cjenik', true, $now );
			$published = $arch->publish( $body, $format, $outlet, $now, $vrsta, $seq );
			if ( is_wp_error( $published ) ) {
				return $published;
			}
			$vrste[ $vrsta ] = $published + array( 'count' => count( $kinds[ $vrsta ] ) );
		}
		if ( 'usluga' === $main && ! $this->copy_atomic( $this->get_public_path( $format, $code, 'usluga' ), $this->get_public_path( $format, $code ) ) ) {
			return new WP_Error( 'sidrena_xml_write', __( 'The public price list could not be saved.', 'cx-sidrena' ) );
		}

		$html_result = $this->write_cjenik_html( $rows, $oznaka );
		if ( is_wp_error( $html_result ) ) {
			return $html_result;
		}

		$first = $vrste[ $main ];
		$new   = false;
		$bytes = 0;
		$top   = 0;
		foreach ( $vrste as $one ) {
			$new    = $new || $one['new'];
			$bytes += (int) $one['bytes'];
			$top    = max( $top, (int) $one['published'] );
		}

		return array(
			'generated' => $first['generated'],
			'published' => $top,
			'count'     => count( $rows ),
			'bytes'     => $bytes,
			'format'    => $format,
			'filename'  => $first['filename'], // the file at the main address (goods, or services in a shop of services)
			'pohrana'   => $first['pohrana'],
			'new'       => $new,
			'main'      => $main,
			'vrste'     => $vrste,
		);
	}

	/**
	 * Rows split into goods and services, each in its order.
	 *
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return array{proizvod: array<int, array<string, string>>, usluga: array<int, array<string, string>>}
	 */
	public function rows_by_vrsta( $rows ) {
		$out = array( 'proizvod' => array(), 'usluga' => array() );
		foreach ( $rows as $row ) {
			$out[ $this->row_vrsta( $row ) ][] = $row;
		}

		return $out;
	}

	/**
	 * Remove a live file of a kind the scope no longer has. Its archive files stay.
	 *
	 * @param string $path Live path.
	 */
	private function drop_live( $path ) {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Write a public file in one step: the body goes to a temporary file in the same folder, which then replaces the
	 * target with rename(). A request that reads the target meanwhile gets the old file or the new one, never part of
	 * one (written straight into the target, a reader during the write got half a price list). The temporary name
	 * starts with a dot and does not end in .xml/.csv/.html, so no list or route ever shows it.
	 *
	 * @param string $path Target.
	 * @param string $body Content.
	 * @return int|false Bytes written.
	 */
	public function write_atomic( $path, $body ) {
		$tmp = $this->temp_path( $path );
		// A folder the server cannot write to: the build returns its error (and keeps the old file) without a PHP warning
		// on every build (Argus N1).
		$put = @file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $put || strlen( $body ) !== $put ) {
			$this->drop_temp( $tmp );
			return false;
		}

		return $this->replace_with( $tmp, $path ) ? $put : false;
	}

	/**
	 * Copy a file to a target in one step, as write_atomic().
	 *
	 * @param string $source Source file.
	 * @param string $path   Target.
	 * @return bool
	 */
	public function copy_atomic( $source, $path ) {
		$tmp = $this->temp_path( $path );
		if ( ! @copy( $source, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->drop_temp( $tmp );
			return false;
		}

		return $this->replace_with( $tmp, $path );
	}

	/**
	 * Temporary name next to the target.
	 *
	 * @param string $path Target.
	 * @return string
	 */
	private function temp_path( $path ) {
		return dirname( $path ) . '/.' . basename( $path ) . '.tmp-' . wp_generate_password( 12, false );
	}

	/**
	 * Put a complete temporary file in place of the target. The temporary file never stays behind, also when
	 * something in between throws.
	 *
	 * @param string $tmp  Complete temporary file.
	 * @param string $path Target.
	 * @return bool
	 */
	private function replace_with( $tmp, $path ) {
		$done = false;
		try {
			// Test seam: the new content is complete and the target still holds the old one.
			do_action( 'cx_sidrena_feed_tmp_written', $tmp, $path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			$done = @rename( $tmp, $path );
			if ( ! $done ) {
				// A host whose rename() cannot replace a file: copy over it, as before, rather than no price list.
				$done = @copy( $tmp, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		} finally {
			$this->drop_temp( $tmp );
		}

		return $done;
	}

	/**
	 * @param string $tmp Temporary file.
	 */
	private function drop_temp( $tmp ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * After a build in one format, the live files of the other one go: a shop that moved from XML to CSV no longer
	 * serves yesterday's XML as if it were current (its address now redirects, CX_Sidrena_Feed_Routes). Archived files
	 * of either format stay, they were published.
	 *
	 * @param string $format The format just built, xml|csv.
	 * @return int Files removed.
	 */
	public function remove_other_format_live( $format ) {
		$dir = $this->get_public_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$other   = 'csv' === $this->sanitize_ext( $format ) ? 'xml' : 'csv';
		$stem    = trailingslashit( $dir ) . CX_Sidrena_Export::PUBLIC_STEM;
		$found   = array_merge( glob( $stem . '.' . $other ) ?: array(), glob( $stem . '-*.' . $other ) ?: array(), glob( $stem . '_usluge*.' . $other ) ?: array() );
		$removed = 0;
		foreach ( $found as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
				$removed += file_exists( $path ) ? 0 : 1;
			}
		}

		return $removed;
	}

	/**
	 * An empty index.php in a folder, when it has none. uploads/cx-sidrena holds the kept folder, whose random name is
	 * its protection: on a host that lists folders (Apache Options Indexes, nginx autoindex) the listing would show it
	 * (Argus N1, CX-SID-27; arhiva/ and cuvano-* have their own).
	 *
	 * @param string $dir Folder.
	 */
	public function ensure_dir_index( $dir ) {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( is_dir( $dir ) && ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- a read-only folder: the build reports its own error
		}
	}

	/**
	 * Remove every live price list (webshop and outlets, both formats) and its HTML table: a shop that sells only to
	 * businesses publishes none (CX-SID-28). The archive folders are not touched: what was published stays as CX-SID-27
	 * keeps it.
	 *
	 * @return int Files removed.
	 */
	public function remove_live_files() {
		$dir = $this->get_public_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$stem    = trailingslashit( $dir ) . CX_Sidrena_Export::PUBLIC_STEM;
		$removed = 0;
		foreach ( glob( $stem . '*' ) ?: array() as $path ) {
			if ( is_file( $path ) && preg_match( '/\.(xml|csv|html)$/', $path ) ) {
				wp_delete_file( $path );
				$removed += file_exists( $path ) ? 0 : 1;
			}
		}

		return $removed;
	}

	/**
	 * Write the HTML table next to the live feed. Public requests only read this file.
	 *
	 * @param array<int, array<string, string>> $rows   Catalog rows.
	 * @param string                            $oznaka Outlet code, empty for the full catalog.
	 * @return true|WP_Error
	 */
	public function write_cjenik_html( $rows, $oznaka = '' ) {
		$dir = $this->get_public_dir();
		if ( '' === $dir ) {
			return new WP_Error( 'sidrena_xml_uploads', __( 'Uploads folder is not available.', 'cx-sidrena' ) );
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_mkdir', __( 'The public price list folder could not be created.', 'cx-sidrena' ) );
		}

		$path = $this->get_cjenik_html_path( $oznaka );
		// Customers read this file: the shop's language, not that of the admin, cron or WP-CLI that built it.
		$html = CX_Sidrena_Plugin::in_shop_locale(
			function () use ( $rows, $oznaka ) {
				return $this->build_cjenik_embed_html( $rows, CX_Sidrena_Export::CJENIK_DOM_TOKEN, $oznaka );
			}
		);
		$body = '<!-- sidrena-count:' . count( $rows ) . " -->\n" . $html;
		$written = $this->write_atomic( $path, $body );

		if ( false === $written ) {
			return new WP_Error( 'sidrena_cjenik_write', __( 'The HTML price list could not be saved.', 'cx-sidrena' ) );
		}

		return true;
	}

	/**
	 * HTML table markup. The DOM id is a token in the stored file and a real id when printed.
	 *
	 * @param array<int, array<string, string>> $rows   Catalog rows.
	 * @param string                            $dom_id Element id.
	 * @param string                            $oznaka Outlet code, empty for the webshop.
	 * @return string
	 */
	public function build_cjenik_embed_html( $rows, $dom_id, $oznaka = '' ) {
		$catalog = CX_Sidrena_Catalog::instance();
		$upit    = CX_Sidrena_Upit::instance()->for_oznaka( $oznaka );
		$html    = '<div class="sidrena-cjenik" id="' . esc_attr( $dom_id ) . '">';

		if ( array() === $rows && array() === $upit ) {
			$html .= '<p class="empty">' . esc_html__( 'The price list is empty.', 'cx-sidrena' ) . '</p>';
			$html .= '</div>';
			return $html;
		}

		$html .= '<div class="cjenik-toolbar">';
		$html .= '<label class="cjenik-search"><span class="screen-reader-text">' . esc_html__( 'Search price list', 'cx-sidrena' ) . '</span>';
		$html .= '<input type="search" class="cjenik-filter" placeholder="' . esc_attr__( 'Search name, SKU, brand, or barcode…', 'cx-sidrena' ) . '" /></label>';
		$html .= '</div>';

		if ( array() === $rows ) {
			$html .= $this->build_upit_section( $upit );
			$html .= '<p class="cjenik-empty-filter" hidden>' . esc_html__( 'No products match the search.', 'cx-sidrena' ) . '</p>';
			$html .= '</div>';
			$html .= $this->cjenik_filter_script( $dom_id );
			return $html;
		}

		$columns    = $this->cjenik_visible_columns( $rows );
		$with_stock = isset( $columns['dostupnost'] );

		$html .= '<div class="cjenik-table-wrap"><table class="cjenik-table">';
		$html .= '<thead><tr>';
		foreach ( $columns as $column ) {
			$html .= '<th>' . esc_html( $column['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		$row_index = 0;
		foreach ( $rows as $row ) {
			$naziv       = isset( $row['naziv'] ) ? (string) $row['naziv'] : '';
			$sku         = isset( $row['sku'] ) ? (string) $row['sku'] : '';
			$marka       = isset( $row['marka'] ) ? trim( (string) $row['marka'] ) : '';
			$url         = isset( $row['url'] ) ? (string) $row['url'] : '';
			$barkod      = isset( $row['barkod'] ) ? trim( (string) $row['barkod'] ) : '';
			$jedinica    = isset( $row['jedinica_mjere'] ) ? trim( (string) $row['jedinica_mjere'] ) : '';
			$oblik       = isset( $row['naziv_posebnog_oblika'] ) ? trim( (string) $row['naziv_posebnog_oblika'] ) : '';
			$vrsta       = ( isset( $row['vrsta'] ) && 'usluga' === $row['vrsta'] ) ? 'usluga' : 'proizvod';
			$vrsta_label = 'usluga' === $vrsta ? __( 'Service', 'cx-sidrena' ) : __( 'Product', 'cx-sidrena' );
			$datum       = isset( $row['sidrena_datum'] ) ? trim( (string) $row['sidrena_datum'] ) : '';
			$dostupnost  = ( isset( $row['dostupnost'] ) && 'nedostupno' === $row['dostupnost'] ) ? 'nedostupno' : 'dostupno';
			$stock_label = 'nedostupno' === $dostupnost
				? __( 'Out of stock', 'cx-sidrena' )
				: __( 'In stock', 'cx-sidrena' );
			$search_src  = $naziv . ' ' . $sku . ' ' . $marka . ' ' . $barkod . ' ' . $jedinica . ' ' . $oblik . ' ' . $vrsta_label . ( $with_stock ? ' ' . $stock_label : '' );
			$search      = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search_src, 'UTF-8' ) : strtolower( $search_src );

			$naziv_html = '' !== $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $naziv ) . '</a>'
				: esc_html( $naziv );
			$codes      = $this->split_barcode( $barkod );

			$cells = array(
				'naziv'                 => $naziv_html,
				'sku'                   => esc_html( $sku ),
				'marka'                 => esc_html( '' !== $marka ? $marka : '-' ),
				'neto_kolicina'         => '-',
				'jedinica_mjere'        => esc_html( '' !== $jedinica ? $jedinica : '-' ),
				'maloprodajna_cijena'   => esc_html( $catalog->format_amount( isset( $row['maloprodajna_cijena'] ) ? $row['maloprodajna_cijena'] : '' ) ),
				'poseban_oblik'         => esc_html( ( isset( $row['poseban_oblik'] ) && 'da' === $row['poseban_oblik'] ) ? __( 'Yes', 'cx-sidrena' ) : __( 'No', 'cx-sidrena' ) ),
				'cijena_jedinice_mjere' => esc_html( $catalog->format_amount( isset( $row['cijena_jedinice_mjere'] ) ? $row['cijena_jedinice_mjere'] : '' ) ),
				'barkod'                => esc_html( '' !== $codes['barkod'] ? $codes['barkod'] : '-' ),
				'ean_barkod'            => esc_html( '' !== $codes['ean_barkod'] ? $codes['ean_barkod'] : '-' ),
				'sidrena_cijena'        => esc_html( $catalog->format_amount( isset( $row['sidrena_cijena'] ) ? $row['sidrena_cijena'] : '' ) ),
				'sidrena_datum'         => esc_html( '' !== $datum ? $datum : '-' ),
				'dostupnost'            => '<span class="cjenik-stock is-' . esc_attr( $dostupnost ) . '">' . esc_html( $stock_label ) . '</span>',
			);

			$hidden = $row_index >= self::CJENIK_PAGE_SIZE ? ' hidden' : '';
			++$row_index;

			$html .= '<tr data-search="' . esc_attr( $search ) . '"' . $hidden . '>';
			foreach ( $columns as $key => $column ) {
				$html .= '<td data-label="' . esc_attr( $column['label'] ) . '">' . $cells[ $key ] . '</td>';
			}
			$html .= '</tr>';
		}

		$html .= '</tbody></table></div>';
		$html .= '<nav class="cjenik-pager" data-page-size="' . (int) self::CJENIK_PAGE_SIZE . '" hidden aria-label="' . esc_attr__( 'Price list pages', 'cx-sidrena' ) . '"></nav>';
		$html .= $this->build_upit_section( $upit );
		$html .= '<p class="cjenik-empty-filter" hidden>' . esc_html__( 'No products match the search.', 'cx-sidrena' ) . '</p>';
		$html .= '</div>';
		$html .= $this->cjenik_filter_script( $dom_id );

		return $html;
	}

	/**
	 * "Cijena na upit" table for one outlet. Empty when that outlet has no such rows.
	 *
	 * @param array<int, array<string, string>> $rows Upit rows.
	 * @return string
	 */
	private function build_upit_section( $rows ) {
		if ( array() === $rows ) {
			return '';
		}

		$label_naziv     = __( 'Name', 'cx-sidrena' );
		$label_kriteriji = __( 'Criteria', 'cx-sidrena' );
		$label_cijena    = __( 'Price', 'cx-sidrena' );
		$cijena          = __( 'Price on request', 'cx-sidrena' );

		$html  = '<h2 class="cjenik-upit-title">' . esc_html__( 'Price on request', 'cx-sidrena' ) . '</h2>';
		$html .= '<div class="cjenik-table-wrap"><table class="cjenik-upit">';
		$html .= '<thead><tr>';
		$html .= '<th>' . esc_html( $label_naziv ) . '</th>';
		$html .= '<th>' . esc_html( $label_kriteriji ) . '</th>';
		$html .= '<th>' . esc_html( $label_cijena ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$naziv     = isset( $row['naziv'] ) ? (string) $row['naziv'] : '';
			$kriteriji = isset( $row['kriteriji'] ) ? (string) $row['kriteriji'] : '';
			$search    = $naziv . ' ' . $kriteriji . ' ' . $cijena;
			$search    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search, 'UTF-8' ) : strtolower( $search );

			$html .= '<tr data-search="' . esc_attr( $search ) . '">';
			$html .= '<td data-label="' . esc_attr( $label_naziv ) . '">' . esc_html( $naziv ) . '</td>';
			$html .= '<td data-label="' . esc_attr( $label_kriteriji ) . '">' . esc_html( $kriteriji ) . '</td>';
			$html .= '<td data-label="' . esc_attr( $label_cijena ) . '">' . esc_html( $cijena ) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table></div>';

		return $html;
	}

	/**
	 * Filter the priced table and the upit table, and page the priced rows.
	 *
	 * @param string $dom_id Element id.
	 * @return string
	 */
	private function cjenik_filter_script( $dom_id ) {
		$script = <<<'JS'
(function(){
var root=document.getElementById(__DOM__);
if(!root)return;
var input=root.querySelector(".cjenik-filter");
if(!input)return;
var productRows=root.querySelectorAll(".cjenik-table tbody tr");
var upitRows=root.querySelectorAll(".cjenik-upit tbody tr");
var empty=root.querySelector(".cjenik-empty-filter");
var pager=root.querySelector(".cjenik-pager");
var table=root.querySelector(".cjenik-table");
var wrap=table&&table.closest?table.closest(".cjenik-table-wrap"):null;
var PAGE=200;
if(pager){
var size=parseInt(pager.getAttribute("data-page-size"),10);
if(size>0)PAGE=size;
}
var page=1;
function matches(row,q){
return !q||(row.getAttribute("data-search")||"").indexOf(q)!==-1;
}
function pageList(total,current){
var list=[];
var n;
if(total<=9){
for(n=1;n<=total;n++)list.push(n);
return list;
}
var keep={};
keep[1]=true;
keep[total]=true;
for(n=current-2;n<=current+2;n++){
if(n>=1&&n<=total)keep[n]=true;
}
var nums=[];
for(n=1;n<=total;n++){
if(keep[n])nums.push(n);
}
var prev=0;
for(n=0;n<nums.length;n++){
if(prev&&nums[n]-prev>1)list.push(0);
list.push(nums[n]);
prev=nums[n];
}
return list;
}
function addButton(num){
var btn=document.createElement("button");
btn.type="button";
btn.className="cjenik-page"+(num===page?" is-current":"");
btn.textContent=String(num);
if(num===page)btn.setAttribute("aria-current","page");
btn.addEventListener("click",function(){
page=num;
render(false);
if(wrap&&wrap.scrollIntoView)wrap.scrollIntoView(true);
});
pager.appendChild(btn);
}
function render(resetPage){
var q=(input.value||"").toLowerCase().trim();
if(resetPage)page=1;
var matched=[];
var i;
var upitShown=0;
for(i=0;i<productRows.length;i++){
if(matches(productRows[i],q))matched.push(productRows[i]);
else productRows[i].hidden=true;
}
for(i=0;i<upitRows.length;i++){
var showUpit=matches(upitRows[i],q);
upitRows[i].hidden=!showUpit;
if(showUpit)upitShown++;
}
var pages=Math.ceil(matched.length/PAGE);
if(pages<1)pages=1;
if(page>pages)page=pages;
var start=(page-1)*PAGE;
var end=start+PAGE;
for(i=0;i<matched.length;i++){
matched[i].hidden=i<start||i>=end;
}
if(empty)empty.hidden=(matched.length+upitShown)!==0;
if(!pager)return;
pager.innerHTML="";
if(pages<=1){
pager.hidden=true;
return;
}
pager.hidden=false;
var items=pageList(pages,page);
for(i=0;i<items.length;i++){
if(items[i]===0){
var gap=document.createElement("span");
gap.className="cjenik-page-gap";
gap.textContent="\u2026";
gap.setAttribute("aria-hidden","true");
pager.appendChild(gap);
}else{
addButton(items[i]);
}
}
}
input.addEventListener("input",function(){render(true);});
render(false);
})();
JS;

		$script = str_replace( '__DOM__', wp_json_encode( $dom_id ), $script );

		return '<script>' . $script . '</script>';
	}

	/**
	 * HTML table columns that have at least one value. Name and prices stay. The order is the price list file's
	 * (art. 3(4) of the Pravilnik o cjeniku), then the bazna date and availability, which only this page shows.
	 *
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return array<string, array{label:string}>
	 */
	public function cjenik_visible_columns( $rows ) {
		$all = array(
			'naziv'                 => array( 'label' => __( 'Name', 'cx-sidrena' ), 'always' => true ),
			'sku'                   => array( 'label' => __( 'SKU', 'cx-sidrena' ), 'always' => false ),
			'marka'                 => array( 'label' => __( 'Brand', 'cx-sidrena' ), 'always' => false ),
			'neto_kolicina'         => array( 'label' => __( 'Net quantity', 'cx-sidrena' ), 'always' => false ),
			'jedinica_mjere'        => array( 'label' => __( 'Unit of measure', 'cx-sidrena' ), 'always' => false ),
			'maloprodajna_cijena'   => array( 'label' => __( 'Retail price', 'cx-sidrena' ), 'always' => true ),
			'poseban_oblik'         => array( 'label' => __( 'Special form of sale', 'cx-sidrena' ), 'always' => true ),
			'cijena_jedinice_mjere' => array( 'label' => __( 'Unit price', 'cx-sidrena' ), 'always' => false ),
			'barkod'                => array( 'label' => __( 'Barcode', 'cx-sidrena' ), 'always' => false ),
			'ean_barkod'            => array( 'label' => __( 'EAN barcode', 'cx-sidrena' ), 'always' => false ),
			// The Pravilnik's term (Hrvoje, 109-2: "Po pravilniku idemo"), as in the file's bazna_cijena.
			'sidrena_cijena'        => array( 'label' => __( 'Bazna cijena', 'cx-sidrena' ), 'always' => true ),
			'sidrena_datum'         => array( 'label' => __( 'Date of bazna cijena', 'cx-sidrena' ), 'always' => false ),
			'dostupnost'            => array( 'label' => __( 'Availability', 'cx-sidrena' ), 'always' => false ),
		);

		$filled = array();
		foreach ( $rows as $row ) {
			// One code, two columns: an EAN fills only EAN barcode, any other code only Barcode (as in the file).
			$row = array_merge( $row, $this->split_barcode( isset( $row['barkod'] ) ? trim( (string) $row['barkod'] ) : '' ) );
			foreach ( $all as $key => $column ) {
				if ( isset( $filled[ $key ] ) ) {
					continue;
				}
				$value = isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
				if ( '' !== $value && '-' !== $value ) {
					$filled[ $key ] = true;
				}
			}
		}

		// The availability column only when the owner turned it on, as in the files (CX-SID-28).
		if ( ! CX_Sidrena_Settings::instance()->feed_has_dostupnost() ) {
			unset( $filled['dostupnost'] );
		}

		$out = array();
		foreach ( $all as $key => $column ) {
			if ( ! empty( $column['always'] ) || isset( $filled[ $key ] ) ) {
				$out[ $key ] = array( 'label' => $column['label'] );
			}
		}

		return $out;
	}

	/**
	 * Generated HTML table, or null when the file is not there yet.
	 *
	 * @param string $oznaka Outlet code, empty for the full catalog.
	 * @return array{html:string,count:int}|null
	 */
	public function read_cjenik_snapshot( $oznaka ) {
		$path = $this->get_cjenik_html_path( $oznaka );
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $html ) || '' === $html ) {
			return null;
		}

		$count = 0;
		if ( preg_match( '/<!-- sidrena-count:(\d+) -->/', $html, $match ) ) {
			$count = (int) $match[1];
		}

		return array(
			'html'  => $html,
			'count' => $count,
		);
	}

	/**
	 * Table HTML from the generated file. Unknown or empty outlets stay empty and do not queue a rebuild.
	 *
	 * @param string $oznaka Outlet code.
	 * @param string $dom_id Element id for this print.
	 * @return string
	 */
	public function cjenik_snapshot_embed( $oznaka, $dom_id ) {
		// A shop that sells only to businesses publishes no price list, so the shortcode prints nothing (CX-SID-28).
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return '';
		}

		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' !== $oznaka ) {
			$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			if ( ! is_array( $outlet ) ) {
				return '<div class="sidrena-cjenik"><p class="empty">' . esc_html__( 'The price list is empty.', 'cx-sidrena' ) . '</p></div>';
			}
		}

		$snapshot = $this->read_cjenik_snapshot( $oznaka );
		if ( null === $snapshot && CX_Sidrena_Export::instance()->awaiting_bazna() ) {
			// Before the first bazna: not being prepared, not published yet, and no build queued (CX-SID-68).
			return '<p class="sidrena-cjenik-pending">' . esc_html( CX_Sidrena_Export::not_published_message() ) . '</p>';
		}
		if ( null === $snapshot ) {
			CX_Sidrena_Feed_Routes::instance()->schedule_public_rebuild();
			return '<p class="sidrena-cjenik-pending">' . esc_html__( 'The price list is being prepared. Refresh the page in a few minutes.', 'cx-sidrena' ) . '</p>';
		}

		return str_replace( CX_Sidrena_Export::CJENIK_DOM_TOKEN, $dom_id, $snapshot['html'] );
	}

	/**
	 * Searchable pricelist table for the public page and the [sidrena-cjenik] shortcode.
	 *
	 * Reads the file written with the public feed. A missing file queues one rebuild.
	 *
	 * @param string                                  $oznaka Outlet code, empty for the full list.
	 * @param array<int, array<string, string>>|null $rows   Already built rows. Public calls leave this null.
	 * @return string
	 */
	public function get_cjenik_embed_html( $oznaka = '', $rows = null ) {
		static $instance = 0;

		++$instance;
		$dom_id = 'sidrena-cjenik-' . $instance;

		if ( is_array( $rows ) ) {
			return $this->build_cjenik_embed_html( $rows, $dom_id, $oznaka );
		}

		return $this->cjenik_snapshot_embed( $oznaka, $dom_id );
	}

	/**
	 * Escape an XML text node.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function xml_value( $value ) {
		return esc_xml( (string) $value );
	}

	/**
	 * Download headers.
	 *
	 * @param string $content_type MIME type.
	 * @param string $filename     File name.
	 */
	public function headers( $content_type, $filename ) {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	/**
	 * Stream a public file with type-specific headers.
	 *
	 * @param string $path     Absolute path.
	 * @param string $filename Download name.
	 * @param string $ext      xml|csv.
	 */
	/**
	 * The plain filename= of a Content-Disposition header: ASCII only (RFC 6266), letters with diacritics without them
	 * (Čakovec -> Cakovec), anything else non-ASCII as "_". The full name is in filename*, which browsers prefer. A raw
	 * UTF-8 filename= is refused by some servers (the Playground's answered 500 for an address in Čakovec, CX-SID-110).
	 *
	 * @param string $filename Legal name.
	 * @return string
	 */
	public static function ascii_filename( $filename ) {
		$name = function_exists( 'remove_accents' ) ? remove_accents( (string) $filename ) : (string) $filename;
		$name = (string) preg_replace( '/[^\x20-\x7E]/', '_', $name );

		return str_replace( array( '"', '\\' ), '', $name );
	}

	public function send_public_file( $path, $filename, $ext ) {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		$ext = $this->sanitize_ext( $ext );
		if ( 'csv' === $ext ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
		} else {
			header( 'Content-Type: application/xml; charset=utf-8' );
		}

		header( 'Content-Disposition: inline; filename="' . self::ascii_filename( $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Cache-Control: public, max-age=300' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
