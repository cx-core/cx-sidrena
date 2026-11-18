<?php
/**
 * Standalone product catalog when WooCommerce is not active.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repeater-backed product list used for XML/CSV and the public HTML cjenik.
 */
class CX_Sidrena_Catalog {

	const OPTION_KEY = 'cx_sidrena_catalog';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * In-memory rows during a batch upsert.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private $working = null;

	/**
	 * Request-cached rows.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private $cached_all = null;

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
	 * One stored row by numeric id.
	 *
	 * @param mixed $id Row id.
	 * @return array<string, string>|null
	 */
	public function get( $id ) {
		$id = preg_replace( '/\D/', '', (string) $id );
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}

		foreach ( $this->all() as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * All stored rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function all() {
		if ( null !== $this->cached_all ) {
			return $this->cached_all;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$this->cached_all = array();
			return $this->cached_all;
		}

		$out = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$clean = $this->sanitize_row( $row, false );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}

		$this->cached_all = $out;
		return $this->cached_all;
	}

	/**
	 * Persist rows and optionally queue a public feed rebuild.
	 *
	 * @param mixed $rows  Raw rows.
	 * @param bool  $queue Queue XML/CSV regenerate.
	 * @return array<int, array<string, string>>
	 */
	public function save( $rows, $queue = true ) {
		$clean   = array();
		$used_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$item = $this->sanitize_row( $row, true );
				if ( null === $item ) {
					continue;
				}

				if ( isset( $used_ids[ $item['id'] ] ) ) {
					$item['id'] = '';
				}

				$clean[] = $item;
				if ( '' !== $item['id'] ) {
					$used_ids[ $item['id'] ] = true;
				}
			}
		}

		$next = $this->next_id( $clean );
		foreach ( $clean as $index => $item ) {
			if ( '' === $item['id'] ) {
				$clean[ $index ]['id'] = (string) $next;
				++$next;
			}
		}

		update_option( self::OPTION_KEY, $clean, false );
		$this->cached_all = $clean;

		if ( $queue && class_exists( 'CX_Sidrena_Export' ) ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}

		return $clean;
	}

	/**
	 * Decode JSON posted from the repeater and save.
	 *
	 * @param string $json Raw JSON.
	 * @return array<int, array<string, string>>
	 */
	public function save_from_json( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		return $this->save( $decoded, true );
	}

	/**
	 * Drop manual rows that belong to one outlet.
	 *
	 * @param string $oznaka Outlet code.
	 * @return array<int, array<string, string>>
	 */
	public function delete_for_poslovnica( $oznaka ) {
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			return $this->all();
		}

		$keep = array();
		foreach ( $this->all() as $row ) {
			if ( $this->row_poslovnica( $row ) !== $oznaka ) {
				$keep[] = $row;
			}
		}

		return $this->save( $keep, true );
	}

	/**
	 * Rows for XML/CSV export.
	 *
	 * @param bool $missing_only Only rows without sidrena price.
	 * @param bool $public       Include id and url.
	 * @return array<int, array<string, string>>
	 */
	public function to_export_rows( $missing_only = false, $public = false ) {
		$out = array();

		foreach ( $this->all() as $row ) {
			if ( $missing_only && '' !== $row['sidrena_cijena'] ) {
				continue;
			}

			$item = array(
				'id'              => $row['id'],
				'naziv'           => $row['naziv'],
				'sku'             => $row['sku'],
				'cijena_redovna'  => $row['cijena_redovna'],
				'cijena_akcijska' => $row['cijena_akcijska'],
				'sidrena_cijena'  => $row['sidrena_cijena'],
				'vrsta'           => $this->row_vrsta( $row ),
				'sidrena_datum'   => $this->display_date( $row ),
				'poslovnica'      => $this->row_poslovnica( $row ),
			);

			if ( $public ) {
				$legal                    = $this->public_price_fields( $row );
				$item['id']               = $row['id'];
				$item['url']              = $row['url'];
				$item['marka']            = CX_Sidrena_Settings::instance()->row_marka( isset( $row['marka'] ) ? $row['marka'] : '' );
				$item['barkod']           = '';
				$item['dostupnost']       = isset( $row['dostupnost'] ) ? $row['dostupnost'] : 'dostupno';
				$item['jedinica_mjere']   = $legal['jedinica_mjere'];
				// The amounts of the files with two decimals, as WooCommerce's rows have them (CX-SID-116): zeros added,
				// never rounded. What is stored stays as it is.
				$item['cijena_jedinice_mjere'] = CX_Sidrena_Money::two_decimals( $legal['cijena_jedinice_mjere'] );
				$item['maloprodajna_cijena']   = CX_Sidrena_Money::two_decimals( $legal['maloprodajna_cijena'] );
				$item['sidrena_cijena']        = CX_Sidrena_Money::two_decimals( $row['sidrena_cijena'] );
				$item['naziv_posebnog_oblika'] = $legal['naziv_posebnog_oblika'];
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Start a batch upsert (one option write at commit).
	 *
	 * @param array<int, array<string, string>>|null $rows Rows to go on from, or null for the stored catalog.
	 */
	public function begin_batch( $rows = null ) {
		// A bulk import keeps its working copy between its requests (CX-SID-124): it goes on from there.
		$this->working = is_array( $rows ) ? $rows : $this->all();
	}

	/**
	 * The batch's rows as they stand, without writing them (the bulk import keeps them in its job until the end).
	 *
	 * @return array<int, array<string, string>>
	 */
	public function batch_rows() {
		return is_array( $this->working ) ? $this->working : array();
	}

	/**
	 * Forget the batch without writing it.
	 */
	public function drop_batch() {
		$this->working = null;
	}

	/**
	 * Insert or update one imported row by SKU, then by catalog id, otherwise append.
	 *
	 * @param array<string, string> $data Sanitized-ish fields.
	 * @return string updated|created|skipped
	 */
	public function upsert_working_row( $data ) {
		if ( ! is_array( $this->working ) ) {
			$this->begin_batch();
		}

		$row = $this->sanitize_row(
			array(
				'id'              => isset( $data['id'] ) ? $data['id'] : '',
				'naziv'           => isset( $data['naziv'] ) ? $data['naziv'] : '',
				'sku'             => isset( $data['sku'] ) ? $data['sku'] : '',
				'marka'           => isset( $data['marka'] ) ? $data['marka'] : '',
				'url'             => isset( $data['url'] ) ? $data['url'] : '',
				'cijena_redovna'        => isset( $data['cijena_redovna'] ) ? $data['cijena_redovna'] : '',
				'cijena_akcijska'       => isset( $data['cijena_akcijska'] ) ? $data['cijena_akcijska'] : '',
				'sidrena_cijena'        => isset( $data['sidrena_cijena'] ) ? $data['sidrena_cijena'] : '',
				'dostupnost'            => ( isset( $data['dostupnost'] ) && '' !== trim( (string) $data['dostupnost'] ) ) ? $data['dostupnost'] : '',
				'cjm'                   => isset( $data['cjm'] ) ? $data['cjm'] : '',
				'cjm_suffix'            => isset( $data['cjm_suffix'] ) ? $data['cjm_suffix'] : '',
				'naziv_posebnog_oblika' => isset( $data['naziv_posebnog_oblika'] ) ? $data['naziv_posebnog_oblika'] : '',
				'jedinica_mjere'        => isset( $data['jedinica_mjere'] ) ? $data['jedinica_mjere'] : '',
				'cijena_jedinice_mjere' => isset( $data['cijena_jedinice_mjere'] ) ? $data['cijena_jedinice_mjere'] : '',
				'vrsta'                 => isset( $data['vrsta'] ) ? $data['vrsta'] : '',
				'sidrena_datum'         => isset( $data['sidrena_datum'] ) ? $data['sidrena_datum'] : '',
				'poslovnica'            => isset( $data['poslovnica'] ) ? $data['poslovnica'] : '',
			),
			false
		);

		if ( null === $row ) {
			return 'skipped';
		}

		$sku    = $row['sku'];
		$target = $row['poslovnica'];
		if ( '' !== $sku ) {
			foreach ( $this->working as $index => $existing ) {
				if ( isset( $existing['sku'] ) && (string) $existing['sku'] === $sku && $this->row_poslovnica( $existing ) === $target ) {
					$row = $this->fill_blanks( $row, $existing, $data );

					$row['id']                 = $existing['id'];
					$this->working[ $index ] = $row;
					return 'updated';
				}
			}
		}

		$match_id = $row['id'];
		if ( '' !== $match_id ) {
			foreach ( $this->working as $index => $existing ) {
				if ( isset( $existing['id'] ) && (string) $existing['id'] === $match_id ) {
					$row = $this->fill_blanks( $row, $existing, $data );

					$row['id']                = $existing['id'];
					$this->working[ $index ] = $row;
					return 'updated';
				}
			}
		}

		if ( '' === $row['naziv'] || '' === $row['cijena_redovna'] || '' === $row['sidrena_cijena'] ) {
			return 'skipped';
		}

		$this->working[] = $row;
		return 'created';
	}

	/**
	 * Keep stored values when the import left a field empty.
	 *
	 * @param array<string, string> $row      Sanitized incoming row.
	 * @param array<string, string> $existing Stored row.
	 * @param array<string, string> $data     Raw mapped import fields.
	 * @return array<string, string>
	 */
	private function fill_blanks( $row, $existing, $data ) {
		$empty_keep = array( 'naziv', 'url', 'cijena_redovna', 'cijena_akcijska', 'sidrena_cijena' );
		foreach ( $empty_keep as $key ) {
			if ( '' === $row[ $key ] && isset( $existing[ $key ] ) ) {
				$row[ $key ] = $existing[ $key ];
			}
		}

		if ( ! array_key_exists( 'marka', $data ) ) {
			$row['marka'] = isset( $existing['marka'] ) ? $existing['marka'] : '';
		} elseif ( '' === $row['marka'] && isset( $existing['marka'] ) ) {
			$row['marka'] = $existing['marka'];
		}

		$incoming_stock = ( isset( $data['dostupnost'] ) && '' !== trim( (string) $data['dostupnost'] ) );
		if ( ! $incoming_stock ) {
			$row['dostupnost'] = isset( $existing['dostupnost'] ) ? $existing['dostupnost'] : 'dostupno';
		}

		$key_if_absent = array( 'cjm', 'cjm_suffix', 'naziv_posebnog_oblika', 'jedinica_mjere', 'cijena_jedinice_mjere', 'poslovnica' );
		foreach ( $key_if_absent as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$row[ $key ] = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
			}
		}

		if ( ! array_key_exists( 'vrsta', $data ) || '' === $row['vrsta'] ) {
			$row['vrsta'] = isset( $existing['vrsta'] ) ? $existing['vrsta'] : 'proizvod';
		}

		if ( ! array_key_exists( 'sidrena_datum', $data ) ) {
			$row['sidrena_datum'] = isset( $existing['sidrena_datum'] ) ? $existing['sidrena_datum'] : '';
		} elseif ( '' === $row['sidrena_datum'] && isset( $existing['sidrena_datum'] ) ) {
			$row['sidrena_datum'] = $existing['sidrena_datum'];
		}

		// A value that is what the row shows now, not what it stores (CX-SID-110, Argus 110-D): the downloaded price list
		// writes the shown brand (the default when the row has none), the shown name of a sale ("akcija" when the row
		// has none of its own) and the shown date (the default date when the row has none). Read back unchanged, the row
		// keeps what it stored, so it still follows the default when that changes.
		$shown = $this->public_price_fields( $existing );
		$same  = array(
			'marka'                 => CX_Sidrena_Settings::instance()->row_marka( isset( $existing['marka'] ) ? $existing['marka'] : '' ),
			'naziv_posebnog_oblika' => $shown['naziv_posebnog_oblika'],
			'sidrena_datum'         => $this->display_date( $existing ),
		);
		foreach ( $same as $key => $value ) {
			if ( array_key_exists( $key, $data ) && '' !== $value && $row[ $key ] === $this->normalize_shown( $key, $value ) ) {
				$row[ $key ] = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
			}
		}

		// An amount that is the same amount as the stored one keeps the stored text (CX-SID-116): Excel saves 23,50 as
		// 23.5 and 19,00 as 19, and the price list must not get a new version when no price changed.
		foreach ( array( 'cijena_redovna', 'cijena_akcijska', 'sidrena_cijena', 'cijena_jedinice_mjere' ) as $key ) {
			if ( isset( $existing[ $key ] ) && '' !== $row[ $key ] && CX_Sidrena_Money::same_amount( $row[ $key ], $existing[ $key ] ) ) {
				$row[ $key ] = $existing[ $key ];
			}
		}

		return $row;
	}

	/**
	 * A shown value as sanitize_row() would store it, so the two compare.
	 *
	 * @param string $key   Field.
	 * @param string $value Shown value.
	 * @return string
	 */
	private function normalize_shown( $key, $value ) {
		return 'sidrena_datum' === $key ? $this->normalize_date( $value ) : sanitize_text_field( (string) $value );
	}

	/**
	 * Write the batch and optionally queue the public feed.
	 *
	 * @param bool $queue Queue regenerate.
	 */
	public function commit_batch( $queue = true ) {
		if ( ! is_array( $this->working ) ) {
			return;
		}

		$this->save( $this->working, $queue );
		$this->working = null;
	}

	/**
	 * Format a stored amount for the public HTML table.
	 *
	 * @param string $raw Stored decimal.
	 * @return string
	 */
	public function format_amount( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '-';
		}

		$normalized = $this->normalize_price( $raw );
		if ( '' === $normalized || ! is_numeric( $normalized ) ) {
			return $raw;
		}

		return number_format( (float) $normalized, 2, ',', '.' ) . ' €';
	}

	/**
	 * Normalize a price string to a Woo-like decimal (dot separator).
	 *
	 * @param mixed $price Raw value.
	 * @return string
	 */
	public function normalize_price( $price ) {
		return CX_Sidrena_Money::normalize_price_catalog( $price );
	}

	/**
	 * Sanitize one catalog row.
	 *
	 * @param array<string, mixed> $row   Raw row.
	 * @param bool                 $strict Drop incomplete rows.
	 * @return array<string, string>|null
	 */
	private function sanitize_row( $row, $strict ) {
		$id = isset( $row['id'] ) ? preg_replace( '/\D/', '', (string) $row['id'] ) : '';
		$id = is_string( $id ) ? $id : '';
		$naziv  = isset( $row['naziv'] ) ? sanitize_text_field( wp_unslash( (string) $row['naziv'] ) ) : '';
		$sku    = isset( $row['sku'] ) ? sanitize_text_field( wp_unslash( (string) $row['sku'] ) ) : '';
		$marka  = isset( $row['marka'] ) ? sanitize_text_field( wp_unslash( (string) $row['marka'] ) ) : '';
		$url    = isset( $row['url'] ) ? esc_url_raw( trim( (string) $row['url'] ), array( 'http', 'https' ) ) : '';
		$reg         = isset( $row['cijena_redovna'] ) ? $this->normalize_price( $row['cijena_redovna'] ) : '';
		$sale        = isset( $row['cijena_akcijska'] ) ? $this->normalize_price( $row['cijena_akcijska'] ) : '';
		$sidrena     = isset( $row['sidrena_cijena'] ) ? $this->normalize_price( $row['sidrena_cijena'] ) : '';
		$dostupnost  = ( isset( $row['dostupnost'] ) && '' !== trim( (string) $row['dostupnost'] ) ) ? $this->normalize_dostupnost( $row['dostupnost'] ) : '';
		$cjm_raw     = isset( $row['cjm'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( (string) $row['cjm'] ) ) ) ) : '';
		$cjm         = in_array( $cjm_raw, array( 'yes', '1', 'true' ), true ) ? 'yes' : '';
		$cjm_suffix  = isset( $row['cjm_suffix'] ) ? sanitize_text_field( wp_unslash( (string) $row['cjm_suffix'] ) ) : '';
		$cjm_suffix  = is_string( $cjm_suffix ) ? $cjm_suffix : '';
		$oblik       = isset( $row['naziv_posebnog_oblika'] ) ? sanitize_text_field( wp_unslash( (string) $row['naziv_posebnog_oblika'] ) ) : '';
		$jedinica    = isset( $row['jedinica_mjere'] ) ? sanitize_text_field( wp_unslash( (string) $row['jedinica_mjere'] ) ) : '';
		$jed_cijena  = isset( $row['cijena_jedinice_mjere'] ) ? $this->normalize_price( $row['cijena_jedinice_mjere'] ) : '';
		$vrsta       = $this->normalize_vrsta( isset( $row['vrsta'] ) ? $row['vrsta'] : '' );
		$datum       = $this->normalize_date( isset( $row['sidrena_datum'] ) ? $row['sidrena_datum'] : '' );
		$poslovnica  = $this->row_poslovnica( $row );
		$oblik       = is_string( $oblik ) ? $oblik : '';
		$jedinica    = is_string( $jedinica ) ? $jedinica : '';

		$naziv = is_string( $naziv ) ? $naziv : '';
		$sku   = is_string( $sku ) ? $sku : '';
		$marka = is_string( $marka ) ? $marka : '';
		$url   = is_string( $url ) ? $url : '';
		$id    = is_string( $id ) ? $id : '';

		if ( $strict && ( '' === $naziv || '' === $reg || '' === $sidrena ) ) {
			return null;
		}

		if ( ! $strict && '' === $naziv && '' === $sku && '' === $url && '' === $reg && '' === $sale && '' === $sidrena ) {
			return null;
		}

		if ( '' === $dostupnost ) {
			$dostupnost = 'dostupno';
		}

		return array(
			'id'              => $id,
			'naziv'           => $naziv,
			'sku'             => $sku,
			'marka'           => $marka,
			'url'             => $url,
			'cijena_redovna'  => $reg,
			'cijena_akcijska' => $sale,
			'sidrena_cijena'  => $sidrena,
			'dostupnost'            => $dostupnost,
			'cjm'                   => $cjm,
			'cjm_suffix'            => $cjm_suffix,
			'naziv_posebnog_oblika' => $oblik,
			'jedinica_mjere'        => $jedinica,
			'cijena_jedinice_mjere' => $jed_cijena,
			'vrsta'                 => $vrsta,
			'sidrena_datum'         => $datum,
			'poslovnica'            => $poslovnica,
		);
	}

	/**
	 * proizvod or usluga. Anything else becomes proizvod.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public function normalize_vrsta( $value ) {
		return CX_Sidrena_Money::normalize_vrsta( $value );
	}

	/**
	 * Stored row type.
	 *
	 * @param array<string, string> $row Row.
	 * @return string
	 */
	public function row_vrsta( $row ) {
		return $this->normalize_vrsta( isset( $row['vrsta'] ) ? $row['vrsta'] : '' );
	}

	/**
	 * Outlet code on a catalog row. Empty is the webshop.
	 *
	 * @param array<string, string> $row Row.
	 * @return string
	 */
	public function row_poslovnica( $row ) {
		$value = CX_Sidrena_Settings::instance()->normalize_oznaka( isset( $row['poslovnica'] ) ? $row['poslovnica'] : '' );
		if ( 'webshop' === strtolower( $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Date shown for a catalog row: override, otherwise shop default.
	 *
	 * @param array<string, string> $row Row.
	 * @return string
	 */
	public function display_date( $row ) {
		$custom = $this->normalize_date( isset( $row['sidrena_datum'] ) ? $row['sidrena_datum'] : '' );
		if ( '' !== $custom ) {
			return $custom;
		}

		return $this->normalize_date( (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) );
	}

	/**
	 * Trailing period on a Croatian date, or empty.
	 *
	 * @param mixed $date Raw.
	 * @return string
	 */
	public function normalize_date( $date ) {
		return CX_Sidrena_Money::normalize_date( $date );
	}

	/**
	 * Whether a catalog row belongs on the webshop feed.
	 *
	 * Empty poslovnica, unless the row id is assigned to a physical outlet.
	 *
	 * @param array<string, string> $row           Row.
	 * @param array<string, bool>   $assigned_ids  Catalog ids picked on outlet cards.
	 * @return bool
	 */
	public function belongs_to_webshop( $row, $assigned_ids = array() ) {
		if ( '' !== $this->row_poslovnica( $row ) ) {
			return false;
		}

		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( '' !== $id && isset( $assigned_ids[ $id ] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a catalog row belongs on one outlet feed.
	 *
	 * @param array<string, string> $row          Row.
	 * @param string                $oznaka       Outlet code.
	 * @param array<string, bool>   $assigned_ids Ids picked on that outlet card.
	 * @return bool
	 */
	public function belongs_to_outlet( $row, $oznaka, $assigned_ids = array() ) {
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			return $this->belongs_to_webshop( $row, $assigned_ids );
		}

		if ( $this->row_poslovnica( $row ) === $oznaka ) {
			return true;
		}

		$id = isset( $row['id'] ) ? (string) $row['id'] : '';

		return '' !== $id && isset( $assigned_ids[ $id ] ) && '' === $this->row_poslovnica( $row );
	}

	/**
	 * Catalog rows for one public feed.
	 *
	 * @param bool               $missing_only Empty sidrena only.
	 * @param bool               $public       Public columns.
	 * @param string|null        $oznaka       null = all, '' = webshop, code = outlet.
	 * @param array<int, string> $assigned_ids Outlet picker ids.
	 * @return array<int, array<string, string>>
	 */
	public function to_export_rows_for( $missing_only, $public, $oznaka, $assigned_ids = array() ) {
		$lookup = array();
		foreach ( $assigned_ids as $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( is_string( $id ) && '' !== $id ) {
				$lookup[ $id ] = true;
			}
		}

		$out = array();
		foreach ( $this->to_export_rows( $missing_only, $public ) as $row ) {
			if ( null === $oznaka ) {
				$out[] = $row;
				continue;
			}

			$match = ( '' === $oznaka )
				? $this->belongs_to_webshop( $row, $lookup )
				: $this->belongs_to_outlet( $row, $oznaka, $lookup );
			if ( $match ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Legal price columns for one manual row. Sale price wins; empty override becomes "akcija".
	 *
	 * @param array<string, string> $row Stored row.
	 * @return array<string, string>
	 */
	public function public_price_fields( $row ) {
		$sale     = isset( $row['cijena_akcijska'] ) ? trim( (string) $row['cijena_akcijska'] ) : '';
		$regular  = isset( $row['cijena_redovna'] ) ? (string) $row['cijena_redovna'] : '';
		$on_sale  = '' !== $sale;
		$override = isset( $row['naziv_posebnog_oblika'] ) ? trim( (string) $row['naziv_posebnog_oblika'] ) : '';

		return array(
			'maloprodajna_cijena'    => $on_sale ? $sale : $regular,
			'naziv_posebnog_oblika'  => $on_sale ? ( '' !== $override ? $override : 'akcija' ) : '',
			'jedinica_mjere'         => isset( $row['jedinica_mjere'] ) ? (string) $row['jedinica_mjere'] : '',
			'cijena_jedinice_mjere'  => isset( $row['cijena_jedinice_mjere'] ) ? (string) $row['cijena_jedinice_mjere'] : '',
		);
	}

	/**
	 * Legal availability flag stored on a catalog row.
	 *
	 * @param mixed $value Raw value.
	 * @return string dostupno|nedostupno
	 */
	public function normalize_dostupnost( $value ) {
		return CX_Sidrena_Money::normalize_dostupnost( $value );
	}

	/**
	 * Next unused numeric id.
	 *
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return int
	 */
	private function next_id( $rows ) {
		$max = 0;
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id > $max ) {
				$max = $id;
			}
		}

		return $max + 1;
	}
}
