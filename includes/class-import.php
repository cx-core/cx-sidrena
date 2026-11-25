<?php
/**
 * Import of bazna cijene with the client's confirmation (spec §1.5, CX-SID-22). The screen is CX-SID-42's Import tab;
 * this is everything behind it: reading the file, matching, the preview, the confirmation and the writing, and the
 * same for WP-CLI. The JSON is the one agreed in work/cx-sid-23/IMPORT-API.md.
 *
 * Rules:
 * 1. Nothing changes before Confirm. Upload and preview only read.
 * 2. Only the bazna, its date and its source are written, and (optional column) the starting lowest price in 30 days
 *    of a product on sale now (K9). Never the regular or sale price, never stock (T8). Optional columns Brand, EAN and
 *    Barcode (CX-SID-122) write only our own fields (CX_Sidrena_Feed_Rows::META_*), never WooCommerce's, and only
 *    where the price list then shows something else; a dash removes our value.
 * 3. The file written is the file previewed: Confirm sends its sha256 back, and a row whose product changed after the
 *    preview is skipped, not overwritten.
 * 4. Every confirmed import is a confirmation record (who, when, the text, the file's sha256), and every change it made
 *    is a history row with its id: proof for an inspection. The file itself is not kept.
 * 5. An empty bazna cell never deletes a bazna (WooCommerce's own CSV import does; note 1397).
 * 6. A row without a date gets the product's own date, or the rule's (CX-SID-21); until the Dates are saved such a row
 *    waits (spec §1.1). A row with a date in the file, or for a product with its own date, does not wait.
 *
 * The preview is kept as a JSON file in the server's temporary folder (never in uploads: nginx ignores .htaccess, and
 * the file holds prices), one hour, like MJDD's import. The rows of a confirmed import stay until it has ended.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bazna import.
 */
final class CX_Sidrena_Import {

	const NONCE     = 'cx_sidrena_import';
	const PER_PAGE  = 50;
	const STEP_ROWS = 40;
	const TTL       = HOUR_IN_SECONDS;
	const MAX_BYTES = 20971520;
	const MAX_ROWS  = 25000;
	const LISTS     = array( 'update', 'unchanged', 'not_found', 'invalid' );

	/** Statuses a row never matches (the preview) and never writes to (the step): one rule for both. */
	const NOT_MATCHED = array( 'trash', 'auto-draft' );

	/** Header names (lower case, without diacritics) → column. */
	const COLUMNS = array(
		'sku'                      => 'sku',
		'sifra'                    => 'sku',
		'id'                       => 'id',
		'product name'             => 'name',
		'naziv'                    => 'name',
		'bazna cijena'             => 'bazna',
		'sidrena cijena'           => 'bazna',
		'date'                     => 'date',
		'datum'                    => 'date',
		'lowest price in 30 days'  => 'lowest',
		'najniza cijena u 30 dana' => 'lowest',
		// CX-SID-122: the owner's or the supplier's brand and codes; the price list's own names read back too.
		'brand'                    => 'marka',
		'marka'                    => 'marka',
		'ean'                      => 'ean',
		'ean barkod'               => 'ean',
		'ean barcode'              => 'ean',
		'barcode'                  => 'barkod',
		'barkod'                   => 'barkod',
	);

	/** The optional columns of CX-SID-122: column => our product field. */
	const CODE_FIELDS = array(
		'marka'  => CX_Sidrena_Feed_Rows::META_MARKA,
		'ean'    => CX_Sidrena_Feed_Rows::META_EAN,
		'barkod' => CX_Sidrena_Feed_Rows::META_BARKOD,
	);

	/**
	 * Rows of the running import, read once per request.
	 *
	 * @var array{token:string,rows:array}|null
	 */
	private static $run = null;

	/**
	 * Hook up (WooCommerce active).
	 */
	public static function register() {
		add_filter( 'cx_sidrena_import_available', '__return_true' );
		foreach ( array( 'upload', 'preview', 'confirm', 'step', 'history', 'current', 'cancel' ) as $a ) {
			add_action( 'wp_ajax_cx_sidrena_import_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'admin_post_cx_sidrena_import_template', array( __CLASS__, 'download_template' ) );
	}

	/**
	 * The text the client confirms an import with (spec §1.5), kept with it as shown. [Kai] temporary text, at most 300
	 * characters. The same string as the Import tab (CX-SID-42).
	 *
	 * A file with Brand, EAN or Barcode says where they come from (Kai 3337, Norman T5), one sentence for each kind of file.
	 *
	 * @param array<string,mixed>|null $p The preview (read()); null = a file with a bazna only.
	 * @return string
	 */
	public static function consent_text( $p = null ) {
		$codes = is_array( $p ) && ! empty( $p['has_codes'] );
		$bazna = ! is_array( $p ) || ! isset( $p['has_bazna'] ) || ! empty( $p['has_bazna'] );
		if ( $codes && ! $bazna ) {
			return __( 'I confirm, on behalf of the shop owner, that the brand, EAN and barcode in this file are taken from the product, its packaging or the supplier, and that they may be imported.', 'cx-sidrena' );
		}
		if ( $codes ) {
			return __( 'I confirm, on behalf of the shop owner, that the prices in this file were the products\' prices on the dates given (bazna cijena), that the brand, EAN and barcode are taken from the product, its packaging or the supplier, and that they may be imported.', 'cx-sidrena' );
		}

		return __( 'I confirm, on behalf of the shop owner, that the prices in this file were the products\' prices on the dates given (bazna cijena), and that they may be imported.', 'cx-sidrena' );
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Reading the file
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Read and match a file. Reads only.
	 *
	 * @param string $raw  File body.
	 * @param string $name File name (shown in History).
	 * @return array<string,mixed>|WP_Error The preview: name, size, sha256, has_lowest, counts, lists.
	 */
	public static function read( $raw, $name ) {
		if ( '' === trim( (string) $raw ) ) {
			return new WP_Error( 'no_rows', __( 'The file has no rows.', 'cx-sidrena' ) );
		}
		// A UTF-16 file (Excel's "Unicode Text") or anything binary (a picture renamed to .csv): text in UTF-8 or CP1250
		// never has a NUL byte. Say what to do, not "Column missing: SKU." (Argus, CX-SID-22).
		if ( 0 === strncmp( (string) $raw, "\xFF\xFE", 2 ) || 0 === strncmp( (string) $raw, "\xFE\xFF", 2 ) || false !== strpos( (string) $raw, "\0" ) ) {
			return new WP_Error( 'type', __( 'Upload a CSV file. From Excel: Save as, "CSV UTF-8".', 'cx-sidrena' ) );
		}
		$sha  = hash( 'sha256', $raw );
		$text = CX_Sidrena_Import_Parse::instance()->normalize_encoding( (string) $raw );
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		$records = self::records( $text );
		if ( ! $records || ! array_filter( $records[0], 'strlen' ) ) {
			return new WP_Error( 'no_header', __( 'The first row must name the columns.', 'cx-sidrena' ) );
		}
		$cols = array();
		foreach ( $records[0] as $i => $h ) {
			// An underscore as a space: the price list downloaded from the admin names its columns bazna_cijena, sifra
			// (CX-SID-109), and 0.2.1's sidrena_cijena; read back, they are this file's Bazna cijena and SKU (CX-SID-110).
			$key = strtolower( remove_accents( trim( preg_replace( '/[\s_]+/u', ' ', (string) $h ) ) ) );
			if ( isset( self::COLUMNS[ $key ] ) && ! isset( $cols[ self::COLUMNS[ $key ] ] ) ) {
				$cols[ self::COLUMNS[ $key ] ] = $i;
			}
		}
		if ( ! isset( $cols['sku'] ) && ! isset( $cols['id'] ) ) {
			return new WP_Error( 'missing_column', __( 'Column missing: SKU.', 'cx-sidrena' ) );
		}
		$has_codes = (bool) array_intersect_key( $cols, self::CODE_FIELDS );
		// CX-SID-177: our own price list (public or admin download) has a maloprodajna cijena column, and its amounts
		// include tax (get_price_to_display(), since CX-SID-160), the bazna cijena too. The bulk import's rule for the
		// same file (file_has_display_retail()); any other file (our Import template, Export bazna) is stored amounts.
		$listed = false;
		foreach ( $records[0] as $h ) {
			$listed = $listed || self::is_retail_column( $h );
		}
		if ( ! isset( $cols['bazna'] ) && ! $has_codes ) {
			return new WP_Error( 'missing_column', __( 'Column missing: Bazna cijena (or Brand, EAN or Barcode).', 'cx-sidrena' ) );
		}
		$data = array();
		foreach ( array_slice( $records, 1, null, true ) as $n => $rec ) {
			if ( ! array_filter( array_map( 'trim', $rec ), 'strlen' ) ) {
				continue; // an empty line
			}
			$cell = static function ( $c ) use ( $cols, $rec ) {
				return isset( $cols[ $c ], $rec[ $cols[ $c ] ] ) ? self::cell( $rec[ $cols[ $c ] ] ) : '';
			};
			$data[] = array(
				'line'   => $n + 1, // the row number a spreadsheet shows; the header is row 1
				'sku'    => $cell( 'sku' ),
				'id'     => $cell( 'id' ),
				'name'   => $cell( 'name' ),
				'bazna'  => $cell( 'bazna' ),
				'date'   => $cell( 'date' ),
				'lowest' => $cell( 'lowest' ),
				'marka'  => $cell( 'marka' ),
				'ean'    => $cell( 'ean' ),
				'barkod' => $cell( 'barkod' ),
			);
		}
		if ( ! $data ) {
			return new WP_Error( 'no_rows', __( 'The file has no rows.', 'cx-sidrena' ) );
		}
		if ( count( $data ) > self::MAX_ROWS ) {
			/* translators: %d: maximum number of rows */
			return new WP_Error( 'too_many_rows', sprintf( __( 'The file has more than %d rows. Split it into smaller files.', 'cx-sidrena' ), self::MAX_ROWS ) );
		}

		$lists = self::match( $data, $listed );

		return array(
			'v'          => 1,
			'name'       => (string) $name,
			'size'       => strlen( $raw ),
			'sha256'     => $sha,
			'created'    => time(),
			'user'       => get_current_user_id(),
			'has_lowest' => isset( $cols['lowest'] ),
			'has_bazna'  => isset( $cols['bazna'] ),
			'has_codes'  => $has_codes,
			'amounts'    => $listed ? 'display' : 'stored', // CX-SID-177: the file's bazna as the price list shows it, or stored
			'counts'     => self::counts( $lists ),
			'lists'      => $lists,
		);
	}

	/**
	 * CSV records. The separator (; , or tab) is the one the header line uses most, outside quotes. A quoted cell may
	 * hold a line break.
	 *
	 * @param string $text UTF-8.
	 * @return array<int,array<int,string>>
	 */
	private static function records( $text ) {
		$first = '';
		$quote = false;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( '"' === $ch ) {
				$quote = ! $quote;
			} elseif ( ! $quote && ( "\n" === $ch || "\r" === $ch ) ) {
				break;
			}
			if ( ! $quote ) {
				$first .= $ch;
			}
		}
		$counts = array(
			';'  => substr_count( $first, ';' ),
			','  => substr_count( $first, ',' ),
			"\t" => substr_count( $first, "\t" ),
		);
		arsort( $counts );
		$sep = (int) reset( $counts ) > 0 ? (string) key( $counts ) : ',';

		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $h, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $h );
		$out = array();
		while ( false !== ( $rec = fgetcsv( $h, 0, $sep, '"', '' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( count( $out ) > self::MAX_ROWS + 1 ) {
				$out[] = array( 'x' ); // enough to say "too many"
				break;
			}
			$out[] = array_map( 'strval', (array) $rec );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $out;
	}

	/**
	 * A cell as typed: trimmed, and without the apostrophe a spreadsheet export puts before =, +, -, @ (our own export
	 * does, CX_Sidrena_Feed_Files::csv_neutralize_formula).
	 *
	 * @param string $v Raw.
	 * @return string
	 */
	private static function cell( $v ) {
		$v = trim( str_replace( "\xc2\xa0", ' ', (string) $v ) );
		if ( strlen( $v ) > 1 && "'" === $v[0] && false !== strpos( "=+-@\t\r", $v[1] ) ) {
			$v = substr( $v, 1 );
		}

		return $v;
	}

	/**
	 * An amount as typed in a Croatian or English spreadsheet: "19,90", "19.90", "1.234,56", "1,234.56", "19,90 €".
	 *
	 * @param string $v Cell.
	 * @return string|null Stored form ("1234.56"), or null when it is not an amount above zero with at most the shop's
	 *                     number of decimals.
	 */
	public static function amount( $v ) {
		$v = preg_replace( '/(\s|€|eur)/iu', '', (string) $v );
		if ( '' === $v || ! preg_match( '/^\d[\d.,]*$/', $v ) ) {
			return null;
		}
		$dot   = strrpos( $v, '.' );
		$comma = strrpos( $v, ',' );
		$dec   = false === $dot ? $comma : ( false === $comma ? $dot : max( $dot, $comma ) );
		if ( false !== $dec ) {
			$int  = substr( $v, 0, $dec );
			$frac = substr( $v, $dec + 1 );
			$mark = $v[ $dec ];
			$sep  = ',' === $mark ? '.' : ',';
			if ( false !== strpos( $int, $mark ) || false !== strpos( $frac, '.' ) || false !== strpos( $frac, ',' ) ) {
				return null; // two decimal marks
			}
			if ( false !== strpos( $int, $sep ) && ! preg_match( '/^\d{1,3}(' . preg_quote( $sep, '/' ) . '\d{3})+$/', $int ) ) {
				return null; // thousands not in groups of three
			}
			$int = str_replace( $sep, '', $int );
			$v   = $int . '.' . $frac;
		}
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $v ) ) {
			return null;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$frac     = false !== strpos( $v, '.' ) ? strlen( substr( $v, strpos( $v, '.' ) + 1 ) ) : 0;
		if ( $frac > max( 0, $decimals ) || (float) $v <= 0 ) {
			return null;
		}
		$v = ltrim( $v, '0' );

		return ( '' === $v || '.' === $v[0] ) ? '0' . $v : $v;
	}

	/**
	 * A date in the file: d.m.Y (the last dot and leading zeros optional) or Y-m-d, real and not later than today.
	 *
	 * @param string $v Cell.
	 * @return string|null Stored form "10.09.2026.", or null.
	 */
	public static function date( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m ) ) {
			$v = $m[3] . '.' . $m[2] . '.' . $m[1] . '.';
		}
		$d = CX_Sidrena_History::parse_date( $v );
		if ( '' === $d ) {
			return null;
		}

		return self::ymd( $d ) > (int) wp_date( 'Ymd' ) ? null : $d;
	}

	/**
	 * @param string $d "10.09.2026.".
	 * @return int 20260910.
	 */
	private static function ymd( $d ) {
		return preg_match( '/^(\d{2})\.(\d{2})\.(\d{4})\.$/', (string) $d, $m ) ? (int) ( $m[3] . $m[2] . $m[1] ) : 0;
	}

	/**
	 * @param string $a Amount.
	 * @param string $b Amount.
	 * @return bool
	 */
	private static function same_amount( $a, $b ) {
		if ( '' === trim( (string) $a ) || '' === trim( (string) $b ) ) {
			return trim( (string) $a ) === trim( (string) $b );
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( (float) $a, $decimals ) === round( (float) $b, $decimals );
	}

	/**
	 * Two stored amounts, exactly: a bazna converted from our price list keeps every decimal (12,47 at 25 % is 9.976), and
	 * the next cent (12,48: 9.984) is another amount, though both round to 9.98 at the shop's decimals (CX-SID-177, Q2).
	 *
	 * @param string $a Amount.
	 * @param string $b Amount.
	 * @return bool
	 */
	private static function same_stored( $a, $b ) {
		$a = trim( (string) $a );
		$b = trim( (string) $b );
		if ( '' === $a || '' === $b || ! is_numeric( $a ) || ! is_numeric( $b ) ) {
			return $a === $b;
		}

		return abs( (float) $a - (float) $b ) < 1e-9;
	}

	/**
	 * A header cell that is the retail price of our price list: the bulk import's rule (is_display_retail_column() after
	 * unique_fields()'s trim), so both imports read the same file the same way (CX-SID-177; trimmed: Argus SHOULD 1).
	 *
	 * @param string $h Header cell.
	 * @return bool
	 */
	private static function is_retail_column( $h ) {
		$h = str_replace( array( ' ', '-', '.' ), '_', strtolower( remove_accents( trim( (string) $h ) ) ) );

		return in_array( $h, array( 'maloprodajna_cijena', 'maloprodajna' ), true );
	}

	/**
	 * The bazna of a row of our price list (with tax) against the product's (CX-SID-177). Compared as the price list
	 * shows them, so a list read back changes nothing and one cent more is a change (Q2); a change is stored as the shop
	 * enters prices (display_price_to_stored(): without tax when it enters them without), and only when that stored
	 * amount shows exactly the file's amount again.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $file    Amount of the file (amount()).
	 * @param string     $cur     Stored bazna, or ''.
	 * @return array{changes:bool,store:string}|WP_Error
	 */
	private static function listed_bazna( $product, $file, $cur ) {
		$meta = CX_Sidrena_Product_Meta::instance();
		$now  = '' !== $cur ? $meta->get_price_to_display( $product, $cur ) : null;
		if ( null !== $now && self::same_amount( $file, (string) $now ) ) {
			return array( 'changes' => false, 'store' => $cur );
		}
		$store = (string) $meta->display_price_to_stored( $product, $file, $cur );
		$back  = '' !== $store ? $meta->get_price_to_display( $product, $store ) : null;
		if ( null === $back || ! self::same_amount( (string) $back, $file ) ) {
			$money = static function ( $a ) {
				return html_entity_decode( wp_strip_all_tags( wc_price( (float) $a ) ), ENT_QUOTES, 'UTF-8' );
			};
			return new WP_Error(
				'bad_tax',
				/* translators: 1: the amount in the file, 2: the amount the price list would show (both as the price list writes them, e.g. 12,50 €) */
				sprintf( __( 'The bazna cijena is not imported: the file has %1$s, but the price list would show %2$s, because your webshop\'s theme or another plugin changes the amounts it shows. If you don\'t know what changes them, tell us.', 'cx-sidrena' ), $money( $file ), $money( null === $back ? 0 : $back ) )
			);
		}
		// Nothing to convert (no tax added to this product): the amount as the file has it, as before.
		if ( self::same_stored( $store, $file ) ) {
			$store = $file;
		}

		return array( 'changes' => true, 'store' => $store );
	}

	/**
	 * Whether the price list adds the shop's tax to this product's stored amounts: taxes on, the product taxable, prices
	 * entered without tax (CX_Sidrena_Product_Meta::adds_base_tax(), which is private there).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	private static function adds_tax( $product ) {
		return class_exists( 'WC_Tax' ) && function_exists( 'wc_prices_include_tax' ) && $product->is_taxable() && ! wc_prices_include_tax();
	}

	/**
	 * What the price list shows for a row of our price list, when it is not the stored amount: the shop adds its tax to
	 * this product (prices entered without tax). Null otherwise; the screen then shows now and new (CX-SID-177, 185).
	 *
	 * @param WC_Product           $product Product or variation.
	 * @param array<string,mixed>|null $now Stored now.
	 * @param array<string,mixed>|null $new Stored new.
	 * @return array{now:?string,new:?string}|null
	 */
	private static function shown( $product, $now, $new ) {
		if ( ! self::adds_tax( $product ) ) {
			return null;
		}
		$meta     = CX_Sidrena_Product_Meta::instance();
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$show     = static function ( $v ) use ( $meta, $product, $decimals ) {
			$a = is_array( $v ) && isset( $v['bazna'] ) && '' !== (string) $v['bazna'] ? $meta->get_price_to_display( $product, (string) $v['bazna'] ) : null;

			return null === $a ? null : number_format( (float) $a, $decimals, '.', '' );
		};
		$out = array(
			'now' => $show( $now ),
			'new' => $show( $new ),
		);

		return null === $out['now'] && null === $out['new'] ? null : $out;
	}

	/**
	 * A product or variation a row may match and be written to: what the preview matches by SKU or ID, and what the
	 * step checks again (a product moved to the trash or deleted since the preview is skipped; a draft or private one
	 * that was in the preview is written, as the preview said).
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	private static function matchable( $id ) {
		return in_array( get_post_type( (int) $id ), array( 'product', 'product_variation' ), true )
			&& ! in_array( get_post_status( (int) $id ), self::NOT_MATCHED, true );
	}

	/**
	 * The product whose šifra in the admin's download is this ID-<n> (CX-SID-122, CX-SID-153): the cell names product n
	 * only if n has no SKU of its own, as the file said (CX_Sidrena_Feed_Rows::admin_sifra(), one rule for both): a
	 * product without any SKU, or a variation without its own. The caller has checked that no SKU is this text.
	 *
	 * @param string $code Cell of the SKU column.
	 * @return int Product, or 0.
	 */
	private static function id_code_product( $code ) {
		if ( 1 !== preg_match( '/^ID-([1-9]\d{0,17})$/iD', (string) $code, $m ) || ! self::matchable( (int) $m[1] ) ) {
			return 0;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $m[1] ) : null;

		return $product && 'ID-' . (int) $m[1] === CX_Sidrena_Feed_Rows::instance()->admin_sifra( $product ) ? (int) $m[1] : 0;
	}

	/**
	 * The Brand, EAN and Barcode cells of a row (CX-SID-122), as they would be stored: '' = remove ours (a dash), absent =
	 * an empty cell, which changes nothing. An EAN without its spaces and dashes (packaging prints '3 850012 345678'),
	 * of 8 or 13 digits and with a right check digit; a code that Excel turned into a number is said first (Norman T3c),
	 * so the owner gets the real cause.
	 *
	 * @param array<string,mixed> $r Row of the file.
	 * @return array<string,string>|WP_Error
	 */
	private static function code_cells( array $r ) {
		$out = array();
		foreach ( array_keys( self::CODE_FIELDS ) as $f ) {
			$v = isset( $r[ $f ] ) ? trim( (string) $r[ $f ] ) : '';
			if ( '' === $v ) {
				continue;
			}
			if ( 1 === preg_match( '/^[\x{2D}\x{2013}\x{2014}]+$/uD', $v ) ) {
				$out[ $f ] = ''; // '-', '–', '—': remove what was imported before
				continue;
			}
			if ( 'marka' === $f ) {
				$out[ $f ] = CX_Sidrena_Money::plain_text( $v );
				continue;
			}
			if ( 1 === preg_match( '/^\d+(?:[.,]\d+)?E\+?\d+$/iD', $v ) ) {
				return new WP_Error(
					'excel_number',
					sprintf(
						/* translators: 1: EAN or barcode, 2: the cell as in the file */
						__( 'The %1$s came as %2$s: Excel turned it into a number and cut it. In Excel set the column to Text, enter the codes again and save as "CSV UTF-8".', 'cx-sidrena' ),
						'ean' === $f ? __( 'EAN', 'cx-sidrena' ) : __( 'barcode', 'cx-sidrena' ),
						$v
					)
				);
			}
			if ( 'barkod' === $f ) {
				$out[ $f ] = CX_Sidrena_Feed_Rows::instance()->clean_barcode( $v );
				continue;
			}
			$ean = (string) preg_replace( '/[\s\x{2D}\x{2010}-\x{2015}]+/u', '', $v );
			if ( ! CX_Sidrena_Feed_Rows::is_ean( $ean ) ) {
				/* translators: %s: the cell as in the file */
				return new WP_Error( 'bad_ean', sprintf( __( 'The EAN %s must have 8 or 13 digits. Put a code of another length in Barcode.', 'cx-sidrena' ), $v ) );
			}
			if ( ! self::ean_check_ok( $ean ) ) {
				/* translators: %s: the cell as in the file */
				return new WP_Error( 'bad_ean_check', sprintf( __( 'The EAN %s is not valid: its check digit does not match. Check it on the packaging or with the supplier.', 'cx-sidrena' ), $v ) );
			}
			$out[ $f ] = $ean;
		}

		return $out;
	}

	/**
	 * The check digit of an EAN-8 or EAN-13 (GS1): the other digits from the right, weighted 3, 1, 3, …
	 *
	 * @param string $ean 8 or 13 digits.
	 * @return bool
	 */
	public static function ean_check_ok( $ean ) {
		$ean = (string) $ean;
		$sum = 0;
		for ( $i = strlen( $ean ) - 2, $w = 3; $i >= 0; $i--, $w = 4 - $w ) {
			$sum += (int) $ean[ $i ] * $w;
		}

		return (int) $ean[ strlen( $ean ) - 1 ] === ( 10 - $sum % 10 ) % 10;
	}

	/**
	 * What a row's Brand, EAN and Barcode would change (CX-SID-122). A field is written only when the price list then
	 * shows something else (WooCommerce's GTIN and brand terms come first: CX_Sidrena_Feed_Rows::product_codes() and
	 * CX_Sidrena_Settings::product_marka(), one rule for both); one that would change nothing is left as it is.
	 *
	 * @param int                  $pid   Product.
	 * @param array<string,string> $cells code_cells().
	 * @return array{write:array<string,string>,was:array<string,string>,shown:array<int,array<int,string>>,kept:array<int,array<int,string>>}
	 */
	private static function code_changes( $pid, array $cells ) {
		$out     = array( 'write' => array(), 'was' => array(), 'shown' => array(), 'kept' => array() );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			return $out;
		}
		$ours = array();
		foreach ( self::CODE_FIELDS as $f => $key ) {
			$ours[ $f ] = (string) get_post_meta( $pid, $key, true );
		}
		$want = $ours;
		foreach ( $cells as $f => $v ) {
			$want[ $f ] = $v;
		}
		$rows = CX_Sidrena_Feed_Rows::instance();
		$set  = CX_Sidrena_Settings::instance();
		$show = static function ( array $o ) use ( $rows, $set, $product ) {
			$c = $rows->product_codes( $product, $o );

			return array(
				'marka'  => $set->product_marka( $product, $o['marka'] ),
				'ean'    => $c['ean_barkod'],
				'barkod' => $c['barkod'],
			);
		};
		$now = $show( $ours );
		// A field is kept only if leaving it out would change what the price list shows (an EAN under WooCommerce's
		// GTIN, a brand under the brand terms, a barcode that is the EAN's number).
		foreach ( $cells as $f => $v ) {
			if ( $want[ $f ] === $ours[ $f ] ) {
				continue;
			}
			$without       = $want;
			$without[ $f ] = $ours[ $f ];
			if ( $show( $without ) === $show( $want ) ) {
				$want[ $f ] = $ours[ $f ];
				if ( '' !== $v && '' !== $now[ $f ] && $now[ $f ] !== $v ) {
					$out['kept'][] = array( $f, $now[ $f ] ); // "EAN: … from WooCommerce stays"
				}
			}
		}
		$after = $show( $want );
		foreach ( self::CODE_FIELDS as $f => $key ) {
			if ( $want[ $f ] !== $ours[ $f ] ) {
				$out['write'][ $f ] = $want[ $f ];
				$out['was'][ $f ]   = $ours[ $f ];
			}
			if ( $after[ $f ] !== $now[ $f ] ) {
				$out['shown'][] = array( $f, $now[ $f ], $after[ $f ] );
			}
		}

		return $out;
	}

	/**
	 * Match the rows to products and sort them into the four lists.
	 *
	 * @param array<int,array<string,mixed>> $data   Rows.
	 * @param bool                           $listed Our price list: the bazna with tax, as it shows it (CX-SID-177).
	 * @return array<string,array>
	 */
	private static function match( array $data, $listed = false ) {
		global $wpdb;
		$lists = array_fill_keys( self::LISTS, array() );

		// SKU → product, one query; an exact match first, then one that differs only in case.
		$skus = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_sku' AND pm.meta_value <> '' AND p.post_type IN ('product','product_variation')
			AND p.post_status NOT IN ('" . implode( "','", self::NOT_MATCHED ) . "')"
		);
		$exact = array();
		$lower = array();
		foreach ( (array) $skus as $s ) {
			$exact[ (string) $s->meta_value ] = (int) $s->post_id;
			$l                                = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s->meta_value ) : strtolower( (string) $s->meta_value );
			$lower[ $l ]                      = isset( $lower[ $l ] ) ? 0 : (int) $s->post_id; // 0: not unique
		}

		$found = array();
		foreach ( $data as $i => $r ) {
			$pid = 0;
			if ( '' !== $r['sku'] ) {
				$l   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $r['sku'] ) : strtolower( $r['sku'] );
				$pid = isset( $exact[ $r['sku'] ] ) ? $exact[ $r['sku'] ] : ( ! empty( $lower[ $l ] ) ? $lower[ $l ] : 0 );
				if ( ! $pid && ! isset( $lower[ $l ] ) ) {
					$pid = self::id_code_product( $r['sku'] );
				}
			} elseif ( '' !== $r['id'] && ctype_digit( $r['id'] ) ) {
				$pid = self::matchable( (int) $r['id'] ) ? (int) $r['id'] : 0;
			} elseif ( '' === $r['id'] ) {
				$lists['invalid'][] = self::bad( $r, 'no_sku', __( 'The row has no SKU (and no ID).', 'cx-sidrena' ) );
				continue;
			}
			if ( ! $pid ) {
				$lists['not_found'][] = array(
					'line' => $r['line'],
					'sku'  => '' !== $r['sku'] ? $r['sku'] : $r['id'],
					'name' => $r['name'],
				);
				continue;
			}
			$found[ $i ] = $pid;
		}

		// The same product twice: every line of it is invalid, so the owner decides which one is right.
		$seen = array();
		foreach ( $found as $i => $pid ) {
			$seen[ $pid ][] = $data[ $i ]['line'];
		}

		// Everything the rows read, loaded in a few queries.
		$ids     = array_values( array_unique( $found ) );
		$parents = array();
		if ( $ids ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, false, true );
			}
			foreach ( $ids as $id ) {
				$p = get_post( $id );
				if ( $p && 'product_variation' === $p->post_type && $p->post_parent ) {
					$parents[] = (int) $p->post_parent;
				}
			}
			$parents = array_values( array_unique( $parents ) );
			if ( $parents && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $parents, false, true );
			}
			update_object_term_cache( array_merge( $ids, $parents ), 'product' );
		}

		$meta    = CX_Sidrena_Product_Meta::instance();
		$parents = array_flip( $parents );
		foreach ( $found as $i => $pid ) {
			$r = $data[ $i ];
			if ( count( $seen[ $pid ] ) > 1 ) {
				/* translators: %s: row numbers */
				$lists['invalid'][] = self::bad( $r, 'duplicate_sku', sprintf( __( 'This product is in the file more than once (rows %s).', 'cx-sidrena' ), implode( ', ', $seen[ $pid ] ) ) );
				continue;
			}
			$post = get_post( $pid );
			if ( 'product' === $post->post_type ) {
				$types = get_the_terms( $pid, 'product_type' ); // primed above
				if ( is_array( $types ) && array_intersect( wp_list_pluck( $types, 'slug' ), array( 'variable', 'grouped', 'variable-subscription' ) ) ) {
					$lists['invalid'][] = self::bad(
						$r,
						'variable_parent',
						'' !== $r['bazna'] ? __( 'Set the bazna cijena per variation (use the variation SKUs).', 'cx-sidrena' )
							: __( 'This is a variable product: import per variation (use the variation SKUs).', 'cx-sidrena' ) // Norman T3d
					);
					continue;
				}
			}

			$bazna = '' === $r['bazna'] ? '' : self::amount( $r['bazna'] );
			if ( null === $bazna ) {
				$lists['invalid'][] = self::bad( $r, 'bad_price', __( 'The bazna cijena must be an amount above zero, e.g. 19,90.', 'cx-sidrena' ) );
				continue;
			}
			$fdate = '';
			if ( '' !== $r['date'] ) {
				if ( '' === $bazna ) {
					$lists['invalid'][] = self::bad( $r, 'bad_date', __( 'A date needs a bazna cijena in the same row.', 'cx-sidrena' ) );
					continue;
				}
				$fdate = self::date( $r['date'] );
				if ( null === $fdate ) {
					$lists['invalid'][] = self::bad( $r, 'bad_date', __( 'The date must be a real date, not later than today, e.g. 10.9.2026.', 'cx-sidrena' ) );
					continue;
				}
			}
			$lowest = '';
			// The lowest price as it is now, with or without the column: the write checks it again. A product on sale
			// has one already (lowest-30.php keeps it), so without it here every such row was "Changed since the preview".
			$low_on = (string) get_post_meta( $pid, '_bazna_najniza30', true );
			if ( '' !== $r['lowest'] ) {
				$lowest = self::amount( $r['lowest'] );
				if ( null === $lowest ) {
					$lists['invalid'][] = self::bad( $r, 'bad_lowest', __( 'The lowest price in 30 days must be an amount above zero.', 'cx-sidrena' ) );
					continue;
				}
				$product = wc_get_product( $pid );
				if ( ! $product || ! $product->is_on_sale( 'edit' ) ) {
					$lists['invalid'][] = self::bad( $r, 'lowest_not_on_sale', __( 'The lowest price in 30 days is only for a product on sale now.', 'cx-sidrena' ) );
					continue;
				}
			}

			// CX-SID-122: Brand, EAN, Barcode. A row with a code that is not one is invalid as a whole (Norman T3).
			$cells = self::code_cells( $r );
			if ( is_wp_error( $cells ) ) {
				$lists['invalid'][] = self::bad( $r, $cells->get_error_code(), $cells->get_error_message() );
				continue;
			}
			$codes = $cells ? self::code_changes( $pid, $cells ) : array( 'write' => array(), 'was' => array(), 'shown' => array(), 'kept' => array() );

			$cur  = trim( $meta->get_price( $pid ) );
			$own  = trim( (string) get_post_meta( $pid, CX_SIDRENA_META_DATE, true ) );
			$d    = CX_Sidrena_Dates::date_for( $pid );
			$now  = '' !== $cur ? array(
				'bazna'  => $cur,
				'date'   => $d['date'],
				'source' => CX_Sidrena_History::izvor_label( CX_Sidrena_History::izvor( $pid ) ),
			) : null;
			$name = $post->post_title;
			$edit = (string) get_edit_post_link( 'product_variation' === $post->post_type && $post->post_parent ? $post->post_parent : $pid, 'raw' );

			$lprod = $listed && '' !== $bazna ? wc_get_product( $pid ) : null;
			if ( $lprod ) {
				$lb = self::listed_bazna( $lprod, $bazna, $cur );
				if ( is_wp_error( $lb ) ) {
					$lists['invalid'][] = self::bad( $r, $lb->get_error_code(), $lb->get_error_message() );
					continue;
				}
				$bazna_changes = $lb['changes'];
				$bazna         = $lb['store']; // the stored form from here on: the write, the history, now and new
			} else {
				$bazna_changes = '' !== $bazna && ( '' === $cur || ! self::same_amount( $bazna, $cur ) );
			}
			// A file date that is the date the product shows already changes nothing.
			$date_changes = '' !== $fdate && ( null === $now || $fdate !== $now['date'] );
			$low_changes  = '' !== $lowest && ! self::same_amount( $lowest, (string) $low_on );

			$row = array(
				'line'       => $r['line'],
				'sku'        => '' !== $r['sku'] ? $r['sku'] : $r['id'],
				'product_id' => $pid,
				'name'       => wp_strip_all_tags( $name ),
				'edit_url'   => $edit,
				'now'        => $now,
				'new'        => null,
				'lowest'     => '' !== $lowest ? array(
					'now' => '' !== (string) $low_on ? (string) $low_on : null,
					'new' => $lowest,
				) : null,
				// What the write checks again (the state seen here) and what it writes. Not sent to the screen.
				'_cur'       => $cur,
				'_own'       => $own,
				'_low'       => (string) $low_on,
			);
			if ( $codes['shown'] ) {
				$row['codes'] = $codes['shown']; // what the price list shows now and after, per field that changes
			}
			if ( $lprod && self::adds_tax( $lprod ) ) {
				$row['_listed'] = true; // a converted bazna: the write compares it exactly (same_stored())
			}
			$price_changes = $bazna_changes || $date_changes || $low_changes;
			if ( ! $price_changes && ! $codes['write'] ) {
				$row['new'] = $now;
				$shown = $lprod ? self::shown( $lprod, $now, $now ) : null;
				if ( $shown ) {
					$row['shown'] = $shown; // what the price list shows (CX-SID-177), for the screen (185)
				}
				if ( $codes['kept'] ) {
					$row['codes_kept'] = $codes['kept']; // Norman T4 SHOULD, Rook 3348 (1): why a code changes nothing
				}
				$lists['unchanged'][] = $row;
				continue;
			}
			$row['_codes']     = $codes['write'];
			$row['_codes_was'] = $codes['was'];
			$row['_price']     = $price_changes;
			if ( ! $price_changes ) {
				// Only codes change: the bazna and its date stay ('no change' on the screen, Norman T4).
				$row['new']        = $now;
				$row['bazna_same'] = true;
				$shown = $lprod ? self::shown( $lprod, $now, $now ) : null;
				if ( $shown ) {
					$row['shown'] = $shown; // what the price list shows (CX-SID-177), for the screen (185)
				}
				$row['_date']      = null;
				$row['_date_from'] = '';
				$row['_bazna']     = '';
				$row['_lowest']    = '';
				$lists['update'][] = $row;
				continue;
			}

			$new_bazna = '' !== $bazna && ( $bazna_changes || $date_changes ) ? $bazna : $cur;
			if ( '' !== $fdate ) {
				$new = array( 'bazna' => $new_bazna, 'date' => $fdate, 'date_from' => 'file' );
				$row['_date']      = $fdate;
				$row['_date_from'] = 'own';
			} elseif ( '' !== $own ) {
				$new = array( 'bazna' => $new_bazna, 'date' => $d['date'], 'date_from' => 'own' );
				$row['_date']      = null; // the own date stays
				$row['_date_from'] = 'own';
			} elseif ( ! $bazna_changes ) {
				// Only the lowest price changes: the bazna and its date stay as they are.
				$new = '' !== $cur ? array( 'bazna' => $cur, 'date' => $d['date'], 'date_from' => 'kept' ) : null;
				$row['_date']      = null;
				$row['_date_from'] = '';
			} else {
				$new = array( 'bazna' => $new_bazna, 'date' => $d['rule_date'], 'date_from' => 'added' === $d['rule_from'] ? 'day_added' : 'category' );
				$row['_date']      = $d['rule_date']; // stored on the product, so it never moves later (CX-SID-21)
				$row['_date_from'] = $d['rule_from'];
			}
			$row['new']    = $new;
			$shown = $lprod ? self::shown( $lprod, $now, $new ) : null;
			if ( $shown ) {
				$row['shown'] = $shown; // what the price list shows now and after (CX-SID-177), for the screen (185)
			}
			$row['_bazna'] = $bazna_changes || $date_changes ? $new_bazna : '';
			$row['_lowest'] = $low_changes ? $lowest : '';
			$lists['update'][] = $row;
		}

		foreach ( self::LISTS as $l ) {
			usort(
				$lists[ $l ],
				static function ( $a, $b ) {
					return $a['line'] - $b['line'];
				}
			);
		}

		return $lists;
	}

	/**
	 * @param array<string,mixed> $r       Row.
	 * @param string              $code    Reason code.
	 * @param string              $message Shown as is.
	 * @return array<string,mixed>
	 */
	private static function bad( array $r, $code, $message ) {
		return array(
			'line'        => $r['line'],
			'sku'         => '' !== $r['sku'] ? $r['sku'] : $r['id'],
			'reason_code' => $code,
			'message'     => $message,
		);
	}

	/**
	 * Whether a row of "update" waits for the Dates (it needs the rule's date).
	 *
	 * @param array<string,mixed> $row Row.
	 * @return bool
	 */
	private static function waits( array $row ) {
		return isset( $row['new']['date_from'] ) && in_array( $row['new']['date_from'], array( 'category', 'day_added' ), true );
	}

	/**
	 * @param array<string,array> $lists Lists.
	 * @return array<string,int>
	 */
	private static function counts( array $lists ) {
		$out = array();
		foreach ( self::LISTS as $l ) {
			$out[ $l ] = count( $lists[ $l ] );
		}
		$out['no_date'] = count( array_filter( $lists['update'], array( __CLASS__, 'waits' ) ) );

		return $out;
	}

	// ----------------------------------------------------------------------------------------------------------------
	// The preview file
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Our folder in the server's temporary folder: per site, so two shops on one server never touch each other's files.
	 *
	 * @return string Empty when it cannot be made.
	 */
	private static function dir() {
		$base = get_temp_dir();
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}
		$dir = trailingslashit( $base ) . 'cx-sidrena-import-' . substr( md5( ABSPATH . '|' . home_url() ), 0, 12 );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		foreach ( array(
			'index.php'  => "<?php\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		) as $f => $body ) {
			if ( ! file_exists( "$dir/$f" ) ) {
				file_put_contents( "$dir/$f", $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		return $dir;
	}

	/**
	 * @param string $token Token.
	 * @param string $ext   preview|run.
	 * @return string
	 */
	private static function path( $token, $ext ) {
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
		$dir   = self::dir();

		return 32 === strlen( $token ) && '' !== $dir ? "$dir/$token.$ext.json" : '';
	}

	/**
	 * Old previews go; the rows of a running import never.
	 */
	private static function purge() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}
		$job   = CX_Sidrena_Bulk::running();
		$keep  = $job && 'import' === $job['kind'] ? (string) $job['file'] : '';
		$files = glob( "$dir/*.json" );
		foreach ( is_array( $files ) ? $files : array() as $f ) {
			if ( '' !== $keep && 0 === strpos( basename( $f ), $keep . '.' ) ) {
				continue;
			}
			if ( filemtime( $f ) < time() - self::TTL ) {
				wp_delete_file( $f );
			}
		}
	}

	/**
	 * @param array<string,mixed> $preview From read().
	 * @return string|WP_Error Token.
	 */
	public static function store( array $preview ) {
		self::purge();
		$token = strtolower( wp_generate_password( 32, false, false ) );
		$path  = self::path( $token, 'preview' );
		if ( '' === $path || false === file_put_contents( $path, wp_json_encode( $preview ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'unreadable', __( 'The file could not be kept for the preview. The server\'s temporary folder is not writable.', 'cx-sidrena' ) );
		}

		return $token;
	}

	/**
	 * @param string $token Token.
	 * @return array<string,mixed>|null Null when it has expired.
	 */
	public static function load( $token ) {
		$path = self::path( $token, 'preview' );
		if ( '' === $path || ! is_readable( $path ) || filemtime( $path ) < time() - self::TTL ) {
			return null;
		}
		$p = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_array( $p ) && isset( $p['lists'] ) ? $p : null;
	}

	/**
	 * One page of one list, as the screen gets it (no internal fields).
	 *
	 * @param array<string,mixed> $p    Preview.
	 * @param string              $list List.
	 * @param int                 $page From 1.
	 * @return array<string,mixed>
	 */
	public static function page( array $p, $list, $page ) {
		$list  = in_array( $list, self::LISTS, true ) ? $list : 'update';
		$all   = $p['lists'][ $list ];
		$pages = max( 1, (int) ceil( count( $all ) / self::PER_PAGE ) );
		$page  = min( max( 1, (int) $page ), $pages );
		$rows  = array();
		foreach ( array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $r ) {
			$rows[] = array_filter(
				$r,
				static function ( $k ) {
					return '_' !== $k[0];
				},
				ARRAY_FILTER_USE_KEY
			);
		}

		return array(
			'list'  => $list,
			'page'  => $page,
			'pages' => $pages,
			'total' => count( $all ),
			'rows'  => $rows,
		);
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Confirm and write
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Confirm a previewed file. Writing starts as the shop's background job.
	 *
	 * @param string              $token Preview.
	 * @param array<string,mixed> $c     sha256, consent ('1'), confirmed_by, confirmed_on (Y-m-d).
	 * @return array{import_id:int,total:int}|WP_Error expired, changed, no_consent, no_name, bad_date, running,
	 *                                                  dates_not_saved, nothing.
	 */
	public static function confirm( $token, array $c ) {
		$p = self::load( $token );
		if ( ! $p ) {
			return new WP_Error( 'expired', __( 'The upload has expired. Upload the file again.', 'cx-sidrena' ) );
		}
		if ( ! isset( $c['sha256'] ) || ! hash_equals( (string) $p['sha256'], strtolower( (string) $c['sha256'] ) ) ) {
			return new WP_Error( 'changed', __( 'The file is not the one in the preview. Upload it again.', 'cx-sidrena' ) );
		}
		if ( ! isset( $c['consent'] ) || '1' !== (string) $c['consent'] ) {
			return new WP_Error( 'no_consent', __( 'Tick the confirmation.', 'cx-sidrena' ) );
		}
		$ok = CX_Sidrena_Fill::confirmation(
			array(
				'confirmed_by' => isset( $c['confirmed_by'] ) ? $c['confirmed_by'] : '',
				'confirmed_on' => isset( $c['confirmed_on'] ) ? $c['confirmed_on'] : '',
				'consent'      => self::consent_text( $p ), // the text the screen shows, never one sent by the browser
			)
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( CX_Sidrena_Bulk::running() ) {
			return self::running_error();
		}
		$saved = CX_Sidrena_Dates::dates_saved();
		$rows  = array_values(
			array_filter(
				$p['lists']['update'],
				static function ( $r ) use ( $saved ) {
					return $saved || ! self::waits( $r );
				}
			)
		);
		if ( ! $rows ) {
			return $p['lists']['update'] ? CX_Sidrena_Fill::dates_not_saved() : new WP_Error( 'nothing', __( 'Nothing to import: every row is unchanged, not found or invalid.', 'cx-sidrena' ) );
		}
		$counts = $p['counts'];
		$job    = CX_Sidrena_Bulk::start(
			array(
				'kind'      => 'import',
				'via'       => 'import',
				'by'        => $ok['by'],
				'on'        => $ok['on'],
				'total'     => count( $rows ),
				'file'      => strtolower( (string) $token ),
				'unchanged' => (int) $counts['unchanged'],
				'not_found' => (int) $counts['not_found'],
				'invalid'   => (int) $counts['invalid'],
				'waited'    => count( $p['lists']['update'] ) - count( $rows ),
			),
			null,
			// Under the start lock, with nothing running: only one confirmation of this preview gets here.
			static function () use ( $token, $rows, $p, $ok ) {
				$preview = self::path( $token, 'preview' );
				if ( '' === $preview || ! file_exists( $preview ) ) {
					return new WP_Error( 'expired', __( 'The upload has expired. Upload the file again.', 'cx-sidrena' ) );
				}
				$run = self::path( $token, 'run' );
				if ( '' === $run || false === file_put_contents( $run, wp_json_encode( $rows ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					return new WP_Error( 'unreadable', __( 'The file could not be kept for the import. The server\'s temporary folder is not writable.', 'cx-sidrena' ) );
				}
				$conf = CX_Sidrena_History::start_confirmation(
					array(
						'kind'         => 'import',
						'confirmed_by' => $ok['by'],
						'confirmed_on' => $ok['on'],
						'consent'      => $ok['consent'],
						'file_name'    => $p['name'],
						'file_sha256'  => $p['sha256'],
						'total'        => count( $rows ),
					)
				);
				if ( is_wp_error( $conf ) ) {
					wp_delete_file( $run );
					return $conf;
				}
				// The preview is used up: the same preview cannot be confirmed twice.
				wp_delete_file( $preview );

				return array( 'conf' => (int) $conf );
			}
		);
		if ( is_wp_error( $job ) ) {
			return 'running' === $job->get_error_code() ? self::running_error() : $job;
		}

		return array(
			'import_id' => (int) $job['conf'],
			'total'     => count( $rows ),
		);
	}

	/**
	 * "running", with what runs, so the screen can show it and offer to follow or stop it (Argus F1).
	 *
	 * @return WP_Error
	 */
	private static function running_error() {
		$e   = new WP_Error( 'running', __( 'Another fill or import is running. Wait for it to finish.', 'cx-sidrena' ) );
		$job = CX_Sidrena_Bulk::running();
		if ( $job ) {
			$e->add_data( array( 'running' => self::running_shape( $job ) ) );
		}

		return $e;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array{kind:string,import_id:int,processed:int,total:int,by:string,started:string}
	 */
	private static function running_shape( array $job ) {
		return array(
			'kind'      => (string) $job['kind'],
			'import_id' => 'import' === $job['kind'] ? (int) $job['conf'] : 0,
			'processed' => (int) $job['pos'],
			'total'     => (int) $job['total'],
			'by'        => (string) $job['by'],
			'started'   => wp_date( 'd.m.Y. H:i', (int) $job['started'] ),
		);
	}

	/**
	 * The import running now (for the screen after a reload or a lost connection), or null.
	 *
	 * @return array|null
	 */
	public static function current() {
		$job = CX_Sidrena_Bulk::running();

		return $job && 'import' === $job['kind'] ? self::running_shape( $job ) : null;
	}

	/**
	 * Progress of an import; a read also writes the next rows.
	 *
	 * @param int $import_id From confirm().
	 * @return array|null
	 */
	public static function progress( $import_id ) {
		$j = CX_Sidrena_Bulk::get();
		if ( ! $j || 'import' !== $j['kind'] || (int) $j['conf'] !== (int) $import_id ) {
			return null;
		}
		if ( empty( $j['finished'] ) ) {
			$j = CX_Sidrena_Bulk::step( CX_Sidrena_Bulk::POLL_BUDGET, self::STEP_ROWS );
		}

		return $j ? CX_Sidrena_Bulk::shape( $j ) : null;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function bulk_rows( array $job ) {
		if ( null !== self::$run && self::$run['token'] === $job['file'] ) {
			return self::$run['rows'];
		}
		$path = self::path( $job['file'], 'run' );
		$rows = '' !== $path && is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'expired', __( 'The import\'s rows were removed from the server\'s temporary folder before it ended. Upload the file again.', 'cx-sidrena' ) );
		}
		self::$run = array(
			'token' => $job['file'],
			'rows'  => $rows,
		);

		return $rows;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return string
	 */
	public static function bulk_label( $row ) {
		return is_array( $row ) && isset( $row['sku'] ) ? (string) $row['sku'] : '';
	}

	/**
	 * Write one row, if its product is still as the preview saw it. Idempotent: a row this import already wrote (a
	 * request that dropped before it saved its position) counts as written, and is not written again.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $row Row.
	 * @return array{result:string,label:string,message:string,already?:bool}
	 */
	public static function bulk_write( array $job, $row ) {
		$pid   = (int) $row['product_id'];
		$label = self::bulk_label( $row );
		$cur   = trim( CX_Sidrena_Product_Meta::instance()->get_price( $pid ) );
		$own   = trim( (string) get_post_meta( $pid, CX_SIDRENA_META_DATE, true ) );
		$low   = (string) get_post_meta( $pid, '_bazna_najniza30', true );
		// A row of a preview made before CX-SID-122 is a price row without codes.
		$price = ! isset( $row['_price'] ) || ! empty( $row['_price'] );
		$codes = isset( $row['_codes'] ) && is_array( $row['_codes'] ) ? $row['_codes'] : array();
		$was   = isset( $row['_codes_was'] ) && is_array( $row['_codes_was'] ) ? $row['_codes_was'] : array();
		$ours  = array();
		foreach ( $codes as $f => $v ) {
			$ours[ $f ] = isset( self::CODE_FIELDS[ $f ] ) ? (string) get_post_meta( $pid, self::CODE_FIELDS[ $f ], true ) : (string) $v;
		}
		$codes_done = true;
		foreach ( $codes as $f => $v ) {
			if ( $ours[ $f ] !== (string) $v ) {
				$codes_done = false;
				// Each code as the preview saw it, or as this import already wrote it (a request that dropped midway).
				if ( ! isset( $was[ $f ] ) || $ours[ $f ] !== (string) $was[ $f ] ) {
					return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'Changed since the preview.', 'cx-sidrena' ) );
				}
			}
		}

		// A row dated by the rule takes the rule as it is now: Dates may have been saved between the preview (when such
		// rows wait) and the confirmation. The rule never reads the own date, so a row already written gets the same.
		$date = $row['_date'];
		$from = (string) $row['_date_from'];
		if ( $price && isset( $row['new']['date_from'] ) && in_array( $row['new']['date_from'], array( 'category', 'day_added' ), true ) ) {
			$d    = CX_Sidrena_Dates::date_for( $pid );
			$date = $d['rule_date'];
			$from = $d['rule_from'];
		}
		$price_done = true;
		if ( $price ) {
			$want_bazna = '' !== $row['_bazna'] ? $row['_bazna'] : $row['_cur'];
			$want_own   = null !== $date ? $date : $row['_own'];
			$want_low   = '' !== $row['_lowest'] ? $row['_lowest'] : $row['_low'];
			// A row of our price list: the bazna exactly, one cent of the list is less than one of the stored (CX-SID-177).
			$same_bazna = ! empty( $row['_listed'] ) ? self::same_stored( $cur, $want_bazna ) : self::same_amount( $cur, $want_bazna );
			$price_done = $same_bazna && $own === $want_own && self::same_amount( $low, $want_low );
			if ( $price_done ) {
				$izvor = CX_Sidrena_History::izvor( $pid );
				$mine  = (int) $izvor['ref'] === (int) $job['conf'] || ( '' === $row['_bazna'] && self::low_is_mine( $pid, $job ) );
				if ( ! $mine ) {
					return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'Changed since the preview.', 'cx-sidrena' ) );
				}
			}
		}
		if ( $price_done && $codes_done ) {
			return array( 'result' => 'updated', 'label' => $label, 'message' => '', 'already' => true );
		}
		if ( ! self::matchable( $pid ) ) {
			return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'The product was deleted or moved to the trash since the preview.', 'cx-sidrena' ) );
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'Changed since the preview.', 'cx-sidrena' ) );
		}

		if ( ! $price_done ) {
			$as_seen = ( ! empty( $row['_listed'] ) ? self::same_stored( $cur, $row['_cur'] ) : self::same_amount( $cur, $row['_cur'] ) )
				&& $own === $row['_own'] && self::same_amount( $low, $row['_low'] );
			if ( ! $as_seen ) {
				return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'Changed since the preview.', 'cx-sidrena' ) );
			}
			if ( '' !== $row['_lowest'] && ! $product->is_on_sale( 'edit' ) ) {
				return array( 'result' => 'skipped', 'label' => $label, 'message' => __( 'The sale ended since the preview.', 'cx-sidrena' ) );
			}

			$context = array(
				'type'      => 'import',
				'by'        => $job['by'],
				'on'        => $job['on'],
				'ref'       => (int) $job['conf'],
				'date_from' => $from,
			);
			if ( '' !== $row['_bazna'] ) {
				$res = CX_Sidrena_History::set_bazna( $pid, $row['_bazna'], $date, $context );
				if ( is_wp_error( $res ) ) {
					return array( 'result' => 'skipped', 'label' => $label, 'message' => $res->get_error_message() );
				}
			}
			if ( '' !== $row['_lowest'] ) {
				self::write_lowest( $product, $row['_lowest'], $context );
			}
		}

		// CX-SID-122: our own fields only; '' removes ours (a dash in the file).
		foreach ( $codes as $f => $v ) {
			if ( ! isset( self::CODE_FIELDS[ $f ] ) || $ours[ $f ] === (string) $v ) {
				continue;
			}
			if ( '' === (string) $v ) {
				delete_post_meta( $pid, self::CODE_FIELDS[ $f ] );
			} else {
				update_post_meta( $pid, self::CODE_FIELDS[ $f ], (string) $v );
			}
		}

		return array( 'result' => 'updated', 'label' => $label, 'message' => '' );
	}

	/**
	 * K9: the starting lowest price in 30 days of a sale that ran before the price history began, from the owner.
	 * _bazna_akcija_od is set too (kept, if the sale already has one): without it the next save of the product or the
	 * next order sees a sale without a start and overwrites the owner's figure (lowest-30.php). The end of the sale
	 * removes it, as it removes every lowest price.
	 *
	 * @param WC_Product          $product Product on sale.
	 * @param string              $amount  Lowest price.
	 * @param array<string,mixed> $context History context.
	 */
	private static function write_lowest( $product, $amount, array $context ) {
		$id = $product->get_id();
		CX_Sidrena_History::with(
			$context,
			static function () use ( $id, $product, $amount ) {
				update_post_meta( $id, '_bazna_najniza30', $amount );
				update_post_meta( $id, '_bazna_najniza30_izvor', 'vlasnik' );
				if ( '' === (string) get_post_meta( $id, '_bazna_akcija_od', true ) ) {
					$from = $product->get_date_on_sale_from( 'edit' );
					$at   = $from && $from->getTimestamp() <= time() ? $from->getTimestamp() : time();
					update_post_meta( $id, '_bazna_akcija_od', $at );
				}
			}
		);
	}

	/**
	 * Whether this import wrote the product's lowest price (a row with only the lowest price has no bazna source).
	 *
	 * @param int                 $pid Product.
	 * @param array<string,mixed> $job Job.
	 * @return bool
	 */
	private static function low_is_mine( $pid, array $job ) {
		global $wpdb;
		$t = CX_Sidrena_History::table();
		$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE product_id = %d AND meta_key = '_bazna_najniza30' AND ref = %d", $pid, (int) $job['conf'] ) ); // phpcs:ignore WordPress.DB

		return (int) $n > 0;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	public static function bulk_finish( array $job ) {
		CX_Sidrena_History::finish_confirmation(
			(int) $job['conf'],
			array(
				'updated'   => (int) $job['updated'],
				'skipped'   => (int) $job['skipped'],
				'unchanged' => isset( $job['unchanged'] ) ? (int) $job['unchanged'] : 0,
				'not_found' => isset( $job['not_found'] ) ? (int) $job['not_found'] : 0,
				'invalid'   => isset( $job['invalid'] ) ? (int) $job['invalid'] : 0,
			)
		);
		$path = self::path( $job['file'], 'run' );
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		self::$run = null;
	}

	/**
	 * Confirmed imports, newest first (History).
	 *
	 * @param int $page From 1.
	 * @return array{rows:array,pages:int}
	 */
	public static function history( $page ) {
		$c   = CX_Sidrena_History::confirmations( max( 1, (int) $page ), 20, 'import' );
		$out = array();
		foreach ( $c['rows'] as $r ) {
			$u     = get_userdata( (int) $r['user_id'] );
			$on    = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $r['confirmed_on'], $m ) ? "{$m[3]}.{$m[2]}.{$m[1]}." : '';
			$out[] = array(
				'import_id'    => (int) $r['id'],
				'at'           => wp_date( 'd.m.Y. H:i', (int) $r['started_at'] ),
				'user'         => $u ? $u->user_login : '',
				'confirmed_by' => (string) $r['confirmed_by'],
				'confirmed_on' => $on,
				'updated'      => (int) $r['updated'],
				'file'         => (string) $r['file_name'],
				'short'        => substr( (string) $r['file_sha256'], 0, 8 ),
				'sha256'       => (string) $r['file_sha256'],
				'finished'     => ! empty( $r['finished_at'] ),
			);
		}

		return array(
			'rows'  => $out,
			'pages' => max( 1, (int) ceil( $c['total'] / 20 ) ),
		);
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Endpoints (IMPORT-API.md)
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Nonce and capability, or a JSON error.
	 */
	private static function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'You are not allowed to do this.', 'cx-sidrena' ) ), 403 );
		}
	}

	/**
	 * @param WP_Error $e Error.
	 */
	private static function fail( WP_Error $e ) {
		$data = $e->get_error_data();
		wp_send_json_error(
			array(
				'code'    => $e->get_error_code(),
				'message' => $e->get_error_message(),
			) + ( is_array( $data ) && isset( $data['running'] ) ? array( 'running' => $data['running'] ) : array() )
		);
	}

	/**
	 * 1. Upload: read and match; nothing is written to products.
	 */
	public static function ajax_upload() {
		self::guard();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- guard()
		$f = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $f || UPLOAD_ERR_NO_FILE === (int) $f['error'] ) {
			self::fail( new WP_Error( 'no_file', __( 'Choose a file.', 'cx-sidrena' ) ) );
		}
		if ( in_array( (int) $f['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $f['size'] > self::MAX_BYTES ) {
			self::fail( new WP_Error( 'too_big', __( 'The file is larger than 20 MB.', 'cx-sidrena' ) ) );
		}
		if ( ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::fail( new WP_Error( 'unreadable', __( 'The file could not be read.', 'cx-sidrena' ) ) );
		}
		$name = sanitize_file_name( wp_unslash( (string) $f['name'] ) );
		// phpcs:enable
		if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'csv', 'txt' ), true ) ) {
			self::fail( new WP_Error( 'type', __( 'Upload a CSV file. From Excel: Save as, "CSV UTF-8".', 'cx-sidrena' ) ) );
		}
		$raw = file_get_contents( $f['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			self::fail( new WP_Error( 'unreadable', __( 'The file could not be read.', 'cx-sidrena' ) ) );
		}
		$p = self::read( $raw, $name );
		if ( is_wp_error( $p ) ) {
			self::fail( $p );
		}
		$token = self::store( $p );
		if ( is_wp_error( $token ) ) {
			self::fail( $token );
		}
		wp_send_json_success(
			array(
				'job'         => $token,
				'file'        => array(
					'name'   => $p['name'],
					'size'   => $p['size'],
					'sha256' => $p['sha256'],
					'short'  => substr( $p['sha256'], 0, 8 ),
				),
				'counts'      => $p['counts'],
				'has_lowest'  => $p['has_lowest'],
				'has_bazna'   => $p['has_bazna'],
				'has_codes'   => $p['has_codes'],
				'consent'     => self::consent_text( $p ),
				'dates_saved' => CX_Sidrena_Dates::dates_saved(),
			)
		);
	}

	/**
	 * 2. Preview: one page of one list.
	 */
	public static function ajax_preview() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard()
		$p = self::load( isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '' );
		if ( ! $p ) {
			self::fail( new WP_Error( 'expired', __( 'The upload has expired. Upload the file again.', 'cx-sidrena' ) ) );
		}
		$list = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : 'update';
		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		// phpcs:enable
		wp_send_json_success( self::page( $p, $list, $page ) );
	}

	/**
	 * 3. Confirm.
	 */
	public static function ajax_confirm() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard()
		$get = static function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		};
		$r   = self::confirm(
			sanitize_key( $get( 'job' ) ),
			array(
				'sha256'       => $get( 'sha256' ),
				'consent'      => $get( 'consent' ),
				'confirmed_by' => $get( 'confirmed_by' ),
				'confirmed_on' => $get( 'confirmed_on' ),
			)
		);
		// phpcs:enable
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		wp_send_json_success( $r );
	}

	/**
	 * 4. Step: the next rows. A dropped request may be sent again; nothing is written twice.
	 */
	public static function ajax_step() {
		self::guard();
		$id = isset( $_POST['import_id'] ) ? absint( $_POST['import_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$s  = self::progress( $id );
		if ( ! $s ) {
			self::fail( new WP_Error( 'not_found', __( 'This import is not running any more. Open History to see how it ended.', 'cx-sidrena' ) ) );
		}
		wp_send_json_success(
			array(
				'processed' => $s['processed'],
				'total'     => $s['total'],
				'finished'  => $s['finished'],
			) + ( isset( $s['result'] ) ? array( 'result' => $s['result'] ) : array() )
		);
	}

	/**
	 * 7. The running import, or null: the Import tab asks on load and follows it, as Status does for Fix all (Argus F1).
	 */
	public static function ajax_current() {
		self::guard();
		wp_send_json_success( array( 'running' => self::current() ) );
	}

	/**
	 * 8. Stop the running import: the rows written stay (with their history), the rest is not written.
	 */
	public static function ajax_cancel() {
		self::guard();
		$id  = isset( $_POST['import_id'] ) ? absint( $_POST['import_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job = CX_Sidrena_Bulk::running();
		if ( ! $job || 'import' !== $job['kind'] || (int) $job['conf'] !== $id ) {
			self::fail( new WP_Error( 'not_found', __( 'This import is not running any more. Open History to see how it ended.', 'cx-sidrena' ) ) );
		}
		$user = wp_get_current_user();
		$r    = CX_Sidrena_Bulk::cancel( $user->exists() ? $user->user_login : '' );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		wp_send_json_success( CX_Sidrena_Bulk::shape( $r ) );
	}

	/**
	 * 5. History.
	 */
	public static function ajax_history() {
		self::guard();
		wp_send_json_success( self::history( isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The template: the columns and two made-up rows, in the form a Croatian spreadsheet opens (";", decimal comma).
	 *
	 * @return string
	 */
	public static function template() {
		$f = CX_Sidrena_Feed_Files::instance();

		return "\xEF\xBB\xBF"
			. $f->csv_line( array( 'SKU', 'Product name', 'Bazna cijena', 'Date', 'Lowest price in 30 days', 'Brand', 'EAN', 'Barcode' ) )
			// Brand, EAN and Barcode empty (Norman T1): an example EAN would be a made-up code (CX-SID-122).
			. $f->csv_line( array( 'EXAMPLE-1', 'Example product', '19,90', '10.9.2026.', '', '', '', '' ) )
			. $f->csv_line( array( 'EXAMPLE-2-M', 'Example product 2 – M', '24,00', '', '', '', '', '' ) );
	}

	/**
	 * The export of bazna cijene, in the columns of the template plus ID, so it imports back unchanged (a product with
	 * no SKU is found by its ID; decision 1606 b). Published simple products and variations that have a bazna cijena.
	 * Read only. Settings › Advanced (CX_Sidrena_Admin_Save::handle_export_bazna) and `wp cx-sidrena export-bazna`.
	 *
	 * @param array<int,object>|null $rows export_rows(), or null to read them.
	 * @return string CSV with a BOM, separator ;.
	 */
	public static function export_csv( $rows = null ) {
		$rows  = null === $rows ? self::export_rows() : $rows;
		$files = CX_Sidrena_Feed_Files::instance();
		$out   = "\xEF\xBB\xBF" . $files->csv_line( array( 'SKU', 'ID', 'Product name', 'Bazna cijena', 'Date', 'Lowest price in 30 days' ) );
		foreach ( $rows as $r ) {
			$out .= $files->csv_line( array( (string) $r->sku, (string) $r->ID, self::export_name( $r ), (string) $r->bazna, (string) $r->datum, (string) $r->lowest ) );
		}

		return $out;
	}

	/**
	 * The rows of the export.
	 *
	 * @return array<int,object> ID, post_type, post_title, post_excerpt, parent_title, sku, bazna, datum, lowest.
	 */
	public static function export_rows() {
		global $wpdb;

		$skip_types = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug IN ('variable','grouped')";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_title, p.post_excerpt, par.post_title AS parent_title,
					sku.meta_value AS sku, pr.meta_value AS bazna, dt.meta_value AS datum, lo.meta_value AS lowest
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s AND pr.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				LEFT JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = '_bazna_najniza30'
				WHERE p.post_status = 'publish'
				AND ( ( p.post_type = 'product' AND p.ID NOT IN ( $skip_types ) )
					OR ( p.post_type = 'product_variation' AND par.post_status = 'publish' ) )
				ORDER BY COALESCE( NULLIF( p.post_parent, 0 ), p.ID ), p.ID",
				CX_SIDRENA_META_PRICE,
				CX_SIDRENA_META_DATE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return (array) $rows;
	}

	/**
	 * A product's name as the export and the sale-rule file show it: a variation as "Parent – attributes".
	 *
	 * @param object $r Row with post_type, post_title, post_excerpt, parent_title.
	 * @return string
	 */
	public static function export_name( $r ) {
		$name = 'product_variation' === $r->post_type
			? trim( (string) $r->parent_title . ( '' !== trim( (string) $r->post_excerpt ) ? ' – ' . $r->post_excerpt : '' ) )
			: (string) $r->post_title;

		return wp_strip_all_tags( $name );
	}

	/**
	 * 6. Download the template.
	 */
	public static function download_template() {
		check_admin_referer( 'cx_sidrena_import_template' );
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cx-sidrena' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bazna-cijene-template.csv"' );
		echo self::template(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV
		exit;
	}
}
