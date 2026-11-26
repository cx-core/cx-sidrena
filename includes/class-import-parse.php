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
		// The escape character given as it has always been (PHP 8.4 deprecates leaving it out; seen in the bulk import's
		// log on PHP 8.4, CX-SID-110). The same parse as before.
		$headers   = $this->unique_fields( str_getcsv( $lines[0], $delimiter, '"', '\\' ) );
		$rows      = array();

		for ( $i = 1, $max = count( $lines ); $i < $max; $i++ ) {
			$cells = str_getcsv( $lines[ $i ], $delimiter, '"', '\\' );
			if ( count( $rows ) >= CX_Sidrena_Bulk_Import::MAX_ROWS ) {
				// CX-SID-199: a row more is refused, never cut. A line with no cell of content (";;;;", rows a sheet once
				// formatted) is still a row below the limit, as it always was, but is no reason to refuse.
				if ( $this->filled( $cells ) ) {
					return $this->too_many_rows();
				}
				continue;
			}

			$row = array();
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
	 * A CSV as the till wrote it (CX-SID-196, only with WooCommerce; without it parse_csv() as always). Rows keep their
	 * real number, as Excel shows it (an empty line counts); the names row is the one the owner chose ($redak, 0 = the
	 * file has none), the kept shape's row when its names are this file's, or guessed (guess_names_row()). Rows above
	 * the names row are never rows of the import: they are only in 'head' and 'options', for the answer to the screen.
	 * The delimiter: for guessing the one most lines use, then the names row's (detect_delimiter(), as before); a file
	 * with its names in the first line is split as it always was.
	 *
	 * @param string                                      $contents File body (UTF-8).
	 * @param int|null                                    $redak    Names row the owner chose, 0 = none; null = not chosen.
	 * @param callable|null                               $known    Whether a cell is a column name the import knows.
	 * @param array{redak: int, fields: array<int, string>}|null $kept     The shape kept for the target, or null.
	 * @return array<string, mixed>|WP_Error See table().
	 */
	public function parse_csv_table( $contents, $redak = null, $known = null, $kept = null ) {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $contents );
		$all      = preg_split( '/\r\n|\r|\n/', (string) $contents );
		if ( ! is_array( $all ) ) {
			return new WP_Error( 'csv', __( 'The CSV cannot be read.', 'cx-sidrena' ) );
		}
		$lines = array();
		foreach ( $all as $i => $line ) {
			if ( '' !== trim( (string) $line ) ) {
				$lines[ $i + 1 ] = (string) $line;
			}
		}
		unset( $all, $contents );

		$guess_delim = $this->most_used_delimiter( array_slice( $lines, 0, 50 ) );
		$split       = function ( $n, $delim ) use ( &$lines ) {
			return isset( $lines[ $n ] ) ? $this->cells( $lines[ $n ], $delim ) : array();
		};
		$names_at    = function ( $row ) use ( &$lines, $split, $guess_delim ) {
			if ( 0 === $row ) {
				$width = 0;
				foreach ( array_keys( $lines ) as $n ) {
					$width = max( $width, count( $split( $n, $guess_delim ) ) );
				}
				return $this->unique_fields( array_fill( 0, $width, '' ) );
			}
			$cells = isset( $lines[ $row ] ) ? $split( $row, $this->detect_delimiter( $lines[ $row ] ) ) : array();
			return $this->filled( $cells ) ? $this->unique_fields( $cells ) : null;
		};
		$first       = function () use ( &$lines, $split, $guess_delim ) {
			$out = array();
			foreach ( array_keys( $lines ) as $n ) {
				$cells = $split( $n, $guess_delim );
				if ( $this->filled( $cells ) ) {
					$out[ $n ] = $cells;
					if ( count( $out ) > self::GUESS_ROWS + 2 ) {
						break;
					}
				}
			}
			return $out;
		};
		$pick = $this->pick_names_row( $redak, $kept, $names_at, $first, $known );

		$delim = $pick['row'] > 0 && isset( $lines[ $pick['row'] ] ) ? $this->detect_delimiter( $lines[ $pick['row'] ] ) : $guess_delim;
		$width = null;
		if ( $pick['row'] > 0 && isset( $lines[ $pick['row'] ] ) ) {
			$width = count( $split( $pick['row'], $delim ) ); // the names row's cells, as before
		}

		return $this->table( $lines, $pick, $width, $delim );
	}

	/**
	 * Rows of an Excel sheet (CX_Sidrena_Import_Excel::read()) as parse_csv_table() reads the same sheet saved by Excel
	 * as CSV (CX-SID-195, 196): every row has as many cells as the widest row of the sheet (Excel's CSV writes its whole
	 * used range), cells are trimmed, empty rows are left out but keep their numbers. The grid is used up as it is read
	 * (each row freed once it is a row of the import): a 25 000-row sheet must fit in 128M (C195-big).
	 *
	 * @param array<int, array<int, string>>              $grid  Row number => column (0 = A) => displayed text. Emptied.
	 * @param int|null                                    $redak Names row the owner chose, 0 = none; null = not chosen.
	 * @param callable|null                               $known Whether a cell is a column name the import knows.
	 * @param array{redak: int, fields: array<int, string>}|null $kept  The shape kept for the target, or null.
	 * @return array<string, mixed>|WP_Error See table().
	 */
	public function parse_grid( &$grid, $redak = null, $known = null, $kept = null ) {
		$width = 0;
		foreach ( $grid as $cells ) {
			foreach ( $cells as $col => $text ) {
				if ( $col >= $width && '' !== trim( (string) $text ) ) {
					$width = (int) $col + 1;
				}
			}
		}
		$row_cells = static function ( $cells ) use ( $width ) {
			$out = array();
			for ( $c = 0; $c < $width; $c++ ) {
				$out[] = isset( $cells[ $c ] ) ? trim( (string) $cells[ $c ] ) : '';
			}
			return $out;
		};
		$names_at  = function ( $row ) use ( &$grid, $row_cells, $width ) {
			if ( 0 === $row ) {
				return $this->unique_fields( array_fill( 0, $width, '' ) );
			}
			$cells = isset( $grid[ $row ] ) ? $row_cells( $grid[ $row ] ) : array();
			return $this->filled( $cells ) ? $this->unique_fields( $cells ) : null;
		};
		$first     = function () use ( &$grid, $row_cells ) {
			$out = array();
			foreach ( $grid as $n => $cells ) {
				$cells = $row_cells( $cells );
				if ( $this->filled( $cells ) ) {
					$out[ $n ] = $cells;
					if ( count( $out ) > self::GUESS_ROWS + 2 ) {
						break;
					}
				}
			}
			return $out;
		};
		$pick = $this->pick_names_row( $redak, $kept, $names_at, $first, $known );

		return $this->table( $grid, $pick, $width, null );
	}

	/** How many rows with content the names row is looked for in (CX-SID-196, rules a to c). */
	const GUESS_ROWS = 10;

	/**
	 * Which row names the columns (CX-SID-196): the owner's choice; else the kept shape's row when this file has the
	 * same names there (the same names in the same order, or as many columns when there are no names); else guessed.
	 *
	 * @param int|null      $redak    Chosen row, 0 = none, or null.
	 * @param array|null    $kept     Kept shape: redak and fields.
	 * @param callable      $names_at Row => its fields (unique_fields()), or null when the row is not there or empty.
	 * @param callable      $first    () => the first rows with content (real number => cells).
	 * @param callable|null $known    Whether a cell is a column name the import knows.
	 * @return array{row: int, source: string, saved: string, chosen: bool}
	 */
	private function pick_names_row( $redak, $kept, $names_at, $first, $known ) {
		if ( null !== $redak ) {
			return array( 'row' => (int) $redak, 'source' => 'chosen', 'saved' => 'none', 'chosen' => true );
		}
		$saved = 'none';
		if ( is_array( $kept ) && isset( $kept['redak'], $kept['fields'] ) && is_array( $kept['fields'] ) ) {
			$there = $names_at( (int) $kept['redak'] );
			if ( null !== $there && array_values( $kept['fields'] ) === $there ) {
				return array( 'row' => (int) $kept['redak'], 'source' => 'saved', 'saved' => 'applied', 'chosen' => false );
			}
			$saved = 'different';
		}

		return array( 'row' => $this->guess_names_row( $first(), $known ), 'source' => 'guess', 'saved' => $saved, 'chosen' => false );
	}

	/**
	 * The names row by the rules of CX-SID-196 (4259, point 4), among the first ten rows with content:
	 * (a) the row with the most names the import knows (one is enough, the earlier wins a tie; a row with an amount or a
	 *     date is data, never names);
	 * (b) else the first row with at least two cells, no amount and no date, followed (headings skipped) by a row with an
	 *     amount;
	 * (c) else none (0), but only when the first row has an amount and its cells where the second row has them;
	 * (d) else the first row with content, as before 196.
	 *
	 * @param array<int, array<int, string>> $first Real row number => cells (trimmed), the first rows with content.
	 * @param callable|null                  $known Whether a cell is a column name the import knows.
	 * @return int Real row number, 0 = no names.
	 */
	public function guess_names_row( $first, $known ) {
		$nums = array_keys( $first );
		if ( ! $nums ) {
			return 1;
		}
		$top  = array_slice( $nums, 0, self::GUESS_ROWS );
		$best = 0;
		$most = 0;
		foreach ( $top as $n ) {
			$cells = $this->filled( $first[ $n ] );
			if ( null === $known || $this->has_amount_or_date( $cells ) ) {
				continue;
			}
			$k = 0;
			foreach ( $cells as $cell ) {
				if ( call_user_func( $known, $cell ) ) {
					++$k;
				}
			}
			if ( $k > $most ) {
				$most = $k;
				$best = $n;
			}
		}
		if ( $most > 0 ) {
			return $best;
		}
		foreach ( $top as $i => $n ) {
			$cells = $this->filled( $first[ $n ] );
			if ( count( $cells ) < 2 || $this->has_amount_or_date( $cells ) ) {
				continue;
			}
			for ( $j = $i + 1, $max = count( $nums ); $j < $max; $j++ ) {
				$next = $this->filled( $first[ $nums[ $j ] ] );
				if ( 1 === count( $next ) && ! $this->has_amount_or_date( $next ) ) {
					continue; // a group heading
				}
				if ( $this->has_amount( $next ) ) {
					return $n;
				}
				break;
			}
		}
		if ( count( $nums ) >= 2 && $this->has_amount( $this->filled( $first[ $nums[0] ] ) )
			&& array_keys( $this->filled( $first[ $nums[0] ] ) ) === array_keys( $this->filled( $first[ $nums[1] ] ) ) ) {
			return 0;
		}

		return $nums[0];
	}

	/**
	 * The table of a read file (CX-SID-196): names, rows of the import (below the names row, without empty rows, at most
	 * MAX_ROWS), the real number of each, and the rows the screen shows around the names row (head) and offers as names
	 * (options). The source is used up as it is read.
	 *
	 * @param array<int, mixed>                                              $src   Row number => cells, or a CSV line. Emptied.
	 * @param array{row: int, source: string, saved: string, chosen: bool} $pick  The names row.
	 * @param int|null                                                       $width Columns: the names row's (CSV), the sheet's (Excel), or null (the widest row).
	 * @param string|null                                                    $delim CSV delimiter; null = $src holds cells.
	 * @return array<string, mixed>|WP_Error fields, rows, lines ([index, row] where a row is not the one after the last),
	 *         header {row, source}, saved, head [[row, cells|null]], options [[row, first three cells]], width.
	 */
	private function table( &$src, $pick, $width, $delim ) {
		$row    = (int) $pick['row'];
		$cells  = function ( $n ) use ( &$src, $delim ) {
			if ( ! isset( $src[ $n ] ) ) {
				return array();
			}
			if ( null !== $delim ) {
				return $this->cells( $src[ $n ], $delim );
			}
			$c = array_map(
				static function ( $t ) {
					return trim( (string) $t );
				},
				$src[ $n ]
			);
			ksort( $c ); // an Excel row's columns are not always in order (CX_Sidrena_Import_Excel::read())
			return $c;
		};
		$filled = array();
		$last   = 0;
		foreach ( array_keys( $src ) as $n ) {
			if ( $n > $row && ( null !== $delim || $this->filled( $src[ $n ] ) ) ) {
				$last = $n; // the last row that may have content (a CSV line has some, by its trim)
			}
		}
		$bad = new WP_Error( $pick['chosen'] ? 'bad_row' : 'csv', $pick['chosen'] ? __( 'The file is not valid.', 'cx-sidrena' ) : __( 'The CSV must have a header and at least one row.', 'cx-sidrena' ) );
		if ( $row < 0 || ( $row > 0 && ! $this->filled( $cells( $row ) ) ) || 0 === $last ) {
			$src = array();
			return $bad;
		}
		if ( null === $width ) {
			$width = 0;
			foreach ( array_keys( $src ) as $n ) {
				$width = max( $width, count( $cells( $n ) ) );
			}
		}
		$pad = static function ( $c ) use ( $width ) {
			$out = array();
			for ( $i = 0; $i < $width; $i++ ) {
				$out[] = isset( $c[ $i ] ) ? (string) $c[ $i ] : '';
			}
			return $out;
		};
		$fields = $this->unique_fields( $row > 0 ? $pad( $cells( $row ) ) : array_fill( 0, $width, '' ) );

		// What the screen shows: rows 1 to the names row + 4 (at most 4 above it), or 1 to 5 without names; the rows it
		// offers as names: those with content from 1 to the names row + 3 (at least 4), at most ten, always the current.
		$lo      = $row > 5 ? $row - 4 : 1;
		$hi      = $row > 0 ? $row + 4 : 5;
		$head    = array();
		$options = array();
		$to      = max( $row, 1 ) + 3;
		for ( $n = $lo; $n <= min( $hi, max( $last, $row ) ); $n++ ) {
			$c      = $cells( $n );
			$head[] = array( $n, $this->filled( $c ) ? array_map( array( $this, 'cut' ), $pad( $c ) ) : null );
		}
		foreach ( array_keys( $src ) as $n ) {
			if ( $n > $to ) {
				break;
			}
			$c = $this->filled( $cells( $n ) );
			if ( $c ) {
				$options[] = array( $n, array_map( array( $this, 'cut' ), array_slice( array_values( $c ), 0, 3 ) ) );
			}
		}
		if ( count( $options ) > 10 ) {
			$options = array_slice( $options, 0, 10 );
			if ( $row > 0 && ! in_array( $row, array_column( $options, 0 ), true ) ) {
				$options[9] = array( $row, array_map( array( $this, 'cut' ), array_slice( array_values( $this->filled( $cells( $row ) ) ), 0, 3 ) ) );
			}
		}

		$rows  = array();
		$lines = array();
		$prev  = null;
		foreach ( array_keys( $src ) as $n ) {
			if ( $n <= $row ) {
				unset( $src[ $n ] );
				continue;
			}
			$c = $cells( $n );
			unset( $src[ $n ] );
			if ( ! $this->filled( $c ) ) {
				continue; // a line of empty cells is no row, and keeps its number
			}
			if ( count( $rows ) >= CX_Sidrena_Bulk_Import::MAX_ROWS ) {
				// CX-SID-199: one row with content more than an import may have. Refused, never cut: a place's import
				// replaces its price list, so the rows after the limit would be removed (U9).
				$src = array();
				return $this->too_many_rows();
			}
			$item = array();
			foreach ( $fields as $index => $field ) {
				$item[ $field ] = isset( $c[ $index ] ) ? (string) $c[ $index ] : '';
			}
			if ( null === $prev || $n !== $prev + 1 ) {
				$lines[] = array( count( $rows ), $n );
			}
			$prev   = $n;
			$rows[] = $item;
		}
		$src = array();
		if ( empty( $rows ) ) {
			return $bad;
		}

		return array(
			'fields'  => $fields,
			'rows'    => $rows,
			'lines'   => $lines,
			'header'  => array(
				'row'    => $row,
				'source' => $pick['source'],
			),
			'saved'   => $pick['saved'],
			'head'    => $head,
			'options' => $options,
			'width'   => $width,
		);
	}

	/**
	 * The real row number of row $i of an import, from table()'s lines: the last jump at or before $i, counted on.
	 *
	 * @param array<int, array{0: int, 1: int}> $lines Jumps [index, row number], by index.
	 * @param int                               $i     Index of the row in the import.
	 * @return int
	 */
	public static function line_of( $lines, $i ) {
		$lo = 0;
		$hi = count( $lines ) - 1;
		$at = -1;
		while ( $lo <= $hi ) {
			$mid = (int) ( ( $lo + $hi ) / 2 );
			if ( (int) $lines[ $mid ][0] <= $i ) {
				$at = $mid;
				$lo = $mid + 1;
			} else {
				$hi = $mid - 1;
			}
		}

		return $at < 0 ? $i + 1 : (int) $lines[ $at ][1] + ( $i - (int) $lines[ $at ][0] );
	}

	/**
	 * A group heading (CX-SID-196): a row with exactly one cell with content, which is not an amount or a date, is not in
	 * a price column, and in the šifra or ID column does not look like a code (has a space, or no digit: "Usluge",
	 * "Skupina 1" are headings, "R-3" is an item without a name).
	 *
	 * @param array<string, string> $row          Field => cell.
	 * @param array<int, string>    $code_fields  The šifra and ID columns.
	 * @param array<int, string>    $price_fields The amount columns.
	 * @return string The heading's text, or '' when the row is not one.
	 */
	public function heading_of( $row, $code_fields, $price_fields ) {
		$text  = '';
		$field = '';
		foreach ( (array) $row as $f => $v ) {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				continue;
			}
			if ( '' !== $text ) {
				return '';
			}
			$text  = $v;
			$field = (string) $f;
		}
		if ( '' === $text || $this->is_amount( $text ) || $this->is_date( $text ) || in_array( $field, $price_fields, true ) ) {
			return '';
		}
		if ( in_array( $field, $code_fields, true ) && ! preg_match( '/\s/u', $text ) && preg_match( '/\d/', $text ) ) {
			return '';
		}

		return $text;
	}

	/**
	 * Whether a heading's text names the services below it (CX-SID-196): "Usluga" or "Usluge", the whole cell, any case.
	 *
	 * @param string $text Heading.
	 * @return bool
	 */
	public function is_services_heading( $text ) {
		return (bool) preg_match( '/^uslug[ae]$/iu', trim( (string) $text ) );
	}

	/**
	 * An amount as a till writes it: digits with . , or spaces between thousands or before decimals, a sign, € or EUR
	 * before or after (CX-SID-196: for the names row and the headings only; amounts of the import are read by
	 * CX_Sidrena_Money::read_amount()).
	 *
	 * @param string $text Cell.
	 * @return bool
	 */
	public function is_amount( $text ) {
		$t = preg_replace( '/^(?:€|EUR)\s*|\s*(?:€|EUR)$/iu', '', trim( (string) $text ) );
		$t = trim( (string) $t );

		return '' !== $t && ( (bool) preg_match( '/^[+-]?(?:\d{1,3}(?:[ .\x{00A0}]\d{3})+|\d+)(?:[.,]\d+)?$/u', $t )
			|| (bool) preg_match( '/^[+-]?\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $t ) );
	}

	/**
	 * A date as d.m.gggg. (the dot after the year optional).
	 *
	 * @param string $text Cell.
	 * @return bool
	 */
	public function is_date( $text ) {
		return (bool) preg_match( '/^\d{1,2}\.\s?\d{1,2}\.\s?\d{4}\.?$/', trim( (string) $text ) );
	}

	/**
	 * Whether some cell is an amount.
	 *
	 * @param array<int, string> $cells Cells.
	 * @return bool
	 */
	private function has_amount( $cells ) {
		foreach ( $cells as $c ) {
			if ( $this->is_amount( $c ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether some cell is an amount or a date.
	 *
	 * @param array<int, string> $cells Cells.
	 * @return bool
	 */
	private function has_amount_or_date( $cells ) {
		foreach ( $cells as $c ) {
			if ( $this->is_amount( $c ) || $this->is_date( $c ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A file with more rows than an import may have (CX-SID-199): refused with T104, never cut.
	 *
	 * @return WP_Error
	 */
	private function too_many_rows() {
		return new WP_Error( 'too_many_rows', CX_Sidrena_Bulk_Import::too_many_rows_message() );
	}

	/**
	 * The cells with content, by their column.
	 *
	 * @param array<int, string> $cells Cells.
	 * @return array<int, string>
	 */
	private function filled( $cells ) {
		$out = array();
		foreach ( (array) $cells as $i => $c ) {
			$c = trim( (string) $c );
			if ( '' !== $c ) {
				$out[ $i ] = $c;
			}
		}
		return $out;
	}

	/**
	 * A CSV line's cells, trimmed.
	 *
	 * @param string $line  Line.
	 * @param string $delim Delimiter.
	 * @return array<int, string>
	 */
	private function cells( $line, $delim ) {
		// The escape character given as it has always been (PHP 8.4 deprecates leaving it out, CX-SID-110).
		return array_map( 'trim', str_getcsv( (string) $line, $delim, '"', '\\' ) );
	}

	/**
	 * A cell as the screen gets it: at most 200 characters.
	 *
	 * @param string $text Cell.
	 * @return string
	 */
	private function cut( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 200, 'UTF-8' );
		}
		return strlen( $text ) > 200 ? (string) preg_replace( '/^(.{200}).*$/us', '$1', $text ) : $text;
	}

	/**
	 * The delimiter most of the lines use (CX-SID-196, U8): ; , or tab, by how often each occurs in them; ; when none.
	 *
	 * @param array<int, string> $lines Lines.
	 * @return string
	 */
	private function most_used_delimiter( $lines ) {
		$count = array( ';' => 0, ',' => 0, "\t" => 0 );
		foreach ( $lines as $line ) {
			foreach ( array_keys( $count ) as $d ) {
				$count[ $d ] += substr_count( (string) $line, $d );
			}
		}
		$best = ';';
		foreach ( $count as $d => $n ) {
			if ( $n > $count[ $best ] ) {
				$best = $d;
			}
		}

		return $best;
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
				// CX-SID-199: as parse_csv(), an item with a value more is refused, an item with none is no reason to.
				foreach ( $item->children() as $child ) {
					if ( '' !== $child->getName() && '' !== trim( (string) $child ) ) {
						return $this->too_many_rows();
					}
				}
				continue;
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

		// Our own price list: goods and services side by side, each row under its own tag (CX-SID-110); both are rows,
		// else an edited service went unread.
		if ( array() === array_diff( array_keys( $names ), array( 'proizvod', 'usluga' ) ) && array_sum( $names ) > 1 ) {
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
