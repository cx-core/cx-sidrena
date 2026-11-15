<?php
/**
 * CSV and XML readers for bulk import.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turn an uploaded file body into field names and rows.
 */
class CX_Sidrena_Import_Parse {

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
	 * Parse CSV contents.
	 *
	 * @param string $contents File body.
	 * @return array{fields: array<int, string>, rows: array<int, array<string, string>>}|WP_Error
	 */
	public function parse_csv( $contents ) {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$lines    = preg_split( '/\r\n|\r|\n/', (string) $contents );
		if ( ! is_array( $lines ) ) {
			return new WP_Error( 'csv', __( 'The CSV cannot be read.', 'cx-sidrena' ) );
		}

		$lines = array_values(
			array_filter(
				$lines,
				static function ( $line ) {
					return '' !== trim( (string) $line );
				}
			)
		);

		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'csv', __( 'The CSV must have a header and at least one row.', 'cx-sidrena' ) );
		}

		$delimiter = $this->detect_delimiter( $lines[0] );
		$headers   = $this->unique_fields( str_getcsv( $lines[0], $delimiter ) );
		$rows      = array();

		for ( $i = 1, $max = count( $lines ); $i < $max; $i++ ) {
			if ( count( $rows ) >= CX_Sidrena_Bulk_Import::MAX_ROWS ) {
				break;
			}

			$cells = str_getcsv( $lines[ $i ], $delimiter );
			$row   = array();
			foreach ( $headers as $index => $field ) {
				$row[ $field ] = isset( $cells[ $index ] ) ? trim( (string) $cells[ $index ] ) : '';
			}
			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'csv', __( 'The CSV has no rows to import.', 'cx-sidrena' ) );
		}

		return array(
			'fields' => $headers,
			'rows'   => $rows,
		);
	}

	/**
	 * Parse XML contents into flat rows of first-level child nodes.
	 *
	 * @param string $contents File body.
	 * @return array{fields: array<int, string>, rows: array<int, array<string, string>>}|WP_Error
	 */
	public function parse_xml( $contents ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return new WP_Error( 'xml', __( 'PHP SimpleXML is not available on the server.', 'cx-sidrena' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $contents, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new WP_Error( 'xml', __( 'The XML is not valid.', 'cx-sidrena' ) );
		}

		$items = $this->find_xml_items( $xml );
		if ( empty( $items ) ) {
			return new WP_Error( 'xml', __( 'No repeating product records were found in the XML.', 'cx-sidrena' ) );
		}

		$fields = array();
		$rows   = array();

		foreach ( $items as $item ) {
			if ( count( $rows ) >= CX_Sidrena_Bulk_Import::MAX_ROWS ) {
				break;
			}

			$row = array();
			foreach ( $item->children() as $child ) {
				$name = $child->getName();
				if ( '' === $name ) {
					continue;
				}
				$fields[ $name ] = true;
				$row[ $name ]    = trim( (string) $child );
			}

			if ( ! empty( $row ) ) {
				$rows[] = $row;
			}
		}

		$fields = array_keys( $fields );
		if ( empty( $fields ) || empty( $rows ) ) {
			return new WP_Error( 'xml', __( 'The XML has no nodes that can be mapped.', 'cx-sidrena' ) );
		}

		foreach ( $rows as &$row ) {
			foreach ( $fields as $field ) {
				if ( ! isset( $row[ $field ] ) ) {
					$row[ $field ] = '';
				}
			}
		}
		unset( $row );

		return array(
			'fields' => $fields,
			'rows'   => $rows,
		);
	}

	/**
	 * Find repeating product-like nodes.
	 *
	 * @param SimpleXMLElement $xml Root.
	 * @return array<int, SimpleXMLElement>
	 */
	public function find_xml_items( $xml ) {
		$children = array();
		foreach ( $xml->children() as $child ) {
			$children[] = $child;
		}

		if ( empty( $children ) ) {
			return array();
		}

		$names = array();
		foreach ( $children as $child ) {
			$name = $child->getName();
			$names[ $name ] = isset( $names[ $name ] ) ? $names[ $name ] + 1 : 1;
		}

		if ( 1 === count( $names ) && reset( $names ) > 1 ) {
			return $children;
		}

		if ( 1 === count( $children ) ) {
			return $this->find_xml_items( $children[0] );
		}

		arsort( $names );
		$top = array_key_first( $names );
		if ( $top && $names[ $top ] > 1 ) {
			$matched = array();
			foreach ( $children as $child ) {
				if ( $child->getName() === $top ) {
					$matched[] = $child;
				}
			}
			return $matched;
		}

		return $children;
	}

	/**
	 * Unique, non-empty CSV headers.
	 *
	 * @param array<int, string> $headers Raw headers.
	 * @return array<int, string>
	 */
	public function unique_fields( $headers ) {
		$used   = array();
		$fields = array();

		foreach ( $headers as $index => $header ) {
			$name = trim( (string) $header );
			if ( '' === $name ) {
				$name = 'stupac_' . ( $index + 1 );
			}

			$base = $name;
			$n    = 2;
			while ( isset( $used[ $name ] ) ) {
				$name = $base . '_' . $n;
				++$n;
			}

			$used[ $name ] = true;
			$fields[]      = $name;
		}

		return $fields;
	}

	/**
	 * Detect CSV delimiter from the header line.
	 *
	 * @param string $header First line.
	 * @return string
	 */
	public function detect_delimiter( $header ) {
		$candidates = array(
			';'  => substr_count( $header, ';' ),
			','  => substr_count( $header, ',' ),
			"\t" => substr_count( $header, "\t" ),
		);
		arsort( $candidates );
		$best = array_key_first( $candidates );

		return $best && $candidates[ $best ] > 0 ? $best : ';';
	}

	/**
	 * Guess the SKU column name.
	 *
	 * @param array<int, string> $fields Field names.
	 * @return string
	 */
	public function guess_sku_field( $fields ) {
		$needles = array( 'sku', 'sifra', 'šifra', 'sifra_artikla', 'article', 'artikl', 'product_sku', 'productsku' );

		foreach ( $fields as $field ) {
			$norm = strtolower( remove_accents( (string) $field ) );
			$norm = str_replace( array( ' ', '-', '.' ), '_', $norm );
			if ( in_array( $norm, $needles, true ) ) {
				return $field;
			}
		}

		return '';
	}

	/**
	 * Guess a named column from common aliases.
	 *
	 * @param array<int, string>    $fields  Field names.
	 * @param array<int, string>    $needles Normalized aliases.
	 * @return string
	 */
	public function guess_named_field( $fields, $needles ) {
		foreach ( $fields as $field ) {
			$norm = strtolower( remove_accents( (string) $field ) );
			$norm = str_replace( array( ' ', '-', '.' ), '_', $norm );
			if ( in_array( $norm, $needles, true ) ) {
				return $field;
			}
		}

		return '';
	}

	/**
	 * Convert an imported file to UTF-8.
	 *
	 * Valid UTF-8 is kept. Anything else is tried as ISO-8859-2, then CP1250.
	 * mbstring has no CP1250, so conversion goes through strict iconv.
	 *
	 * @param string $contents Raw bytes.
	 * @return string
	 */
	public function normalize_encoding( $contents ) {
		if ( function_exists( 'wp_is_valid_utf8' ) ) {
			$is_utf8 = wp_is_valid_utf8( $contents );
		} elseif ( function_exists( 'mb_check_encoding' ) ) {
			$is_utf8 = mb_check_encoding( $contents, 'UTF-8' );
		} else {
			$is_utf8 = false !== preg_match( '//u', $contents );
		}

		if ( $is_utf8 ) {
			return $contents;
		}

		if ( ! function_exists( 'iconv' ) ) {
			return $contents;
		}

		// 0x80-0x9F is undefined in ISO-8859-2 and holds š/ž in CP1250.
		$encodings = preg_match( '/[\x80-\x9F]/', $contents )
			? array( 'CP1250' )
			: array( 'ISO-8859-2', 'CP1250' );

		foreach ( $encodings as $encoding ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An iconv notice would corrupt the AJAX JSON body.
			$converted = @iconv( $encoding, 'UTF-8', $contents );
			if ( is_string( $converted ) ) {
				return $converted;
			}
		}

		return $contents;
	}
}
