<?php
/**
 * Services whose price is agreed on request. HTML cjenik only.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repeater rows that print at the bottom of the HTML cjenik.
 */
class CX_Sidrena_Upit {

	const OPTION_KEY = 'cx_sidrena_upit';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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

			$clean = $this->sanitize_row( $row );
			if ( null !== $clean ) {
				if ( empty( $row['tekst'] ) ) {
					// Stored before 0.3.0 by sanitize_text_field(): read as typed (CX-SID-142).
					$clean['naziv']     = CX_Sidrena_Money::legacy_text( $clean['naziv'] );
					$clean['kriteriji'] = CX_Sidrena_Money::legacy_text( $clean['kriteriji'] );
				}
				$out[] = $clean;
			}
		}

		$this->cached_all = $out;
		return $this->cached_all;
	}

	/**
	 * Rows for one HTML cjenik file.
	 *
	 * An empty code is the webshop. A single shop shows every row.
	 *
	 * @param string $oznaka Outlet code.
	 * @return array<int, array<string, string>>
	 */
	public function for_oznaka( $oznaka ) {
		$oznaka = $this->row_poslovnica( array( 'poslovnica' => $oznaka ) );
		if ( '' === $oznaka && ! CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			return $this->all();
		}

		$out = array();
		foreach ( $this->all() as $row ) {
			if ( $this->row_poslovnica( $row ) === $oznaka ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Persist rows and queue a public HTML rebuild.
	 *
	 * @param mixed $rows  Raw rows.
	 * @param bool  $queue Queue the public feed rebuild.
	 * @return array<int, array<string, string>>
	 */
	public function save( $rows, $queue = true ) {
		$clean    = array();
		$used_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$item = $this->sanitize_row( $row );
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
	 * usluga or proizvod (CX-SID-76). These rows are for services whose price is set case by case (art. 4(2) of
	 * NN 132/2026-1584); a product here is allowed but warned about (ZZP art. 7(1): goods on offer show their price).
	 * A row without the key (MJDD's, and ours before 0.3.0) is a service.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	public static function row_vrsta( $row ) {
		$value = is_array( $row ) && isset( $row['vrsta'] ) ? strtolower( trim( (string) $row['vrsta'] ) ) : '';

		return in_array( $value, array( 'proizvod', 'roba', 'product' ), true ) ? 'proizvod' : 'usluga';
	}

	/**
	 * Rows marked as a product, in every place (Status, CX-SID-76).
	 *
	 * @return int
	 */
	public function count_products() {
		$n = 0;
		foreach ( $this->all() as $row ) {
			$n += 'proizvod' === $row['vrsta'] ? 1 : 0;
		}

		return $n;
	}

	/**
	 * Move the rows of one place to its new code (CX-SID-74: a place renamed before it published, or its code written in
	 * capitals). Exact codes: the rows of another place are never touched.
	 *
	 * @param string $from Old code.
	 * @param string $to   New code.
	 */
	public function move_poslovnica( $from, $to ) {
		if ( '' === $from || $from === $to ) {
			return;
		}

		$rows = $this->all();
		$moved = false;
		foreach ( $rows as $i => $row ) {
			if ( $this->row_poslovnica( $row ) === $from ) {
				$rows[ $i ]['poslovnica'] = $to;
				$moved                    = true;
			}
		}
		if ( $moved ) {
			$this->save( $rows, true );
		}
	}

	/**
	 * Drop rows that belong to one outlet.
	 *
	 * @param string $oznaka Outlet code.
	 * @return array<int, array<string, string>>
	 */
	public function delete_for_poslovnica( $oznaka ) {
		$oznaka = $this->row_poslovnica( array( 'poslovnica' => $oznaka ) );
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
	 * Outlet code on a row. Empty is the webshop.
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
	 * Keep a row only when it has a name and criteria.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, string>|null
	 */
	private function sanitize_row( $row ) {
		$id = isset( $row['id'] ) ? preg_replace( '/\D/', '', (string) $row['id'] ) : '';
		$id = is_string( $id ) ? $id : '';

		// Text as typed (CX-SID-142): the page escapes it.
		$naziv = isset( $row['naziv'] ) ? CX_Sidrena_Money::plain_text( wp_unslash( (string) $row['naziv'] ) ) : '';
		$naziv = is_string( $naziv ) ? $naziv : '';

		$kriteriji = isset( $row['kriteriji'] ) ? CX_Sidrena_Money::plain_text( wp_unslash( (string) $row['kriteriji'] ) ) : '';
		$kriteriji = is_string( $kriteriji ) ? $kriteriji : '';

		if ( '' === $naziv || '' === $kriteriji ) {
			return null;
		}

		return array(
			'id'         => $id,
			'naziv'      => $naziv,
			'kriteriji'  => $kriteriji,
			'poslovnica' => $this->row_poslovnica( $row ),
			'vrsta'      => self::row_vrsta( $row ),
			'tekst'      => '1', // CX-SID-142: stored as typed; a stored row without it is from before 0.3.0 (all())
		);
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
