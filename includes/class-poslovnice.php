<?php
/**
 * Multiple sales outlets (poslovnice) for per-object public feeds.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stored outlet list: legal filename parts plus assigned product ids.
 */
class CX_Sidrena_Poslovnice {

	const OPTION_KEY = 'cx_sidrena_poslovnice';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Request-cached outlets.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $cached_all = null;

	/**
	 * Request cache of published_oznake().
	 *
	 * @var array<string, bool>|null
	 */
	private $published = null;

	/**
	 * Request cache: code => "oblik\0adresa" of its archive files => true.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private $published_parts = array();

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
	 * All outlets.
	 *
	 * @return array<int, array<string, mixed>>
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
				$out[] = self::as_typed( $clean, $row );
			}
		}

		$this->cached_all = $this->unique_oznake( $out );
		return $this->cached_all;
	}

	/**
	 * One outlet by its object code.
	 *
	 * @param string $oznaka Normalized code.
	 * @return array<string, mixed>|null
	 */
	public function get_by_oznaka( $oznaka ) {
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			return null;
		}

		foreach ( $this->all() as $row ) {
			if ( $row['oznaka'] === $oznaka ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Outlets that have at least one product.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function with_products() {
		$out = array();
		foreach ( $this->all() as $row ) {
			if ( $this->has_rows( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Whether a place has anything for its price list: all of the webshop's products (dodjela "svi", CX-SID-74), picked
	 * products, or manual rows of its own. With WooCommerce the picked ids are WooCommerce ids only, so a manual row of
	 * the webshop whose id happens to be one of them is never taken (N2).
	 *
	 * @param array<string, mixed> $row Outlet.
	 * @return bool
	 */
	public function has_rows( $row ) {
		if ( 'svi' === $this->dodjela( $row ) || ! empty( $row['products'] ) ) {
			return true;
		}

		return array() !== CX_Sidrena_Catalog::instance()->to_export_rows_for( false, false, $row['oznaka'], array() );
	}

	/**
	 * Whether a place offers anything: rows for its file (has_rows()), or rows by agreement of its own (CX-SID-76), which
	 * are on its page and never in a file (art. 4(2) of NN 132/2026-1584). Such a place saves, has its page and is not
	 * "nothing" on Status, but has no file (CX-SID-145, Kai 3338, Rook 3339). has_rows() stays the test for files.
	 *
	 * @param array<string, mixed> $row Outlet.
	 * @return bool
	 */
	public function has_offer( $row ) {
		return $this->has_rows( $row ) || array() !== CX_Sidrena_Upit::instance()->for_oznaka( (string) $row['oznaka'] );
	}

	/**
	 * The places that have a price list page (has_offer()): with_products() and those with only rows by agreement.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function with_pages() {
		$out = array();
		foreach ( $this->all() as $row ) {
			if ( $this->has_offer( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * svi (every published product of the webshop) or odabrani (only the picked ones). A place saved before CX-SID-74
	 * has no key: odabrani, which is what it published until then.
	 *
	 * @param array<string, mixed> $row Outlet.
	 * @return string
	 */
	public function dodjela( $row ) {
		return ( is_array( $row ) && isset( $row['dodjela'] ) && 'svi' === $row['dodjela'] ) ? 'svi' : 'odabrani';
	}

	/**
	 * Codes that differ only in letter case are one code (CX-SID-74, Rook with Linus).
	 *
	 * @param string $oznaka Code.
	 * @return string
	 */
	public static function code_key( $oznaka ) {
		return strtoupper( (string) $oznaka );
	}

	/**
	 * Codes with a price list file in the archive (goods or services, also the kept files and older name forms): a place
	 * with such a code has published, and its code no longer changes (D1, art. 6(2) of NN 132/2026-1584). Code as it
	 * stands in the file name => true. The webshop's code is in it too.
	 *
	 * @return array<string, bool>
	 */
	public function published_oznake() {
		if ( null !== $this->published ) {
			return $this->published;
		}

		$arch  = CX_Sidrena_Archive::instance();
		$names = array();
		foreach ( array( $arch->get_archive_dir(), $arch->kept_dir() ) as $dir ) {
			if ( '' === (string) $dir || ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( trailingslashit( $dir ) . '*' ) ?: array() as $path ) {
				$name = basename( $path );
				if ( '.gz' === substr( $name, -3 ) ) {
					$name = substr( $name, 0, -3 );
				}
				if ( preg_match( '/\.(xml|csv)$/', $name ) ) {
					$names[] = $name;
				}
			}
		}

		$out   = array();
		$parts = array();
		foreach ( $names as $name ) {
			$p = $arch->parse_name( $name );
			if ( null !== $p && '' !== (string) $p['oznaka'] ) {
				$out[ (string) $p['oznaka'] ] = true;
				$parts[ (string) $p['oznaka'] ][ $p['oblik'] . "\0" . $p['adresa'] ] = true;
			}
		}
		$this->published       = $out;
		$this->published_parts = $parts;

		return $out;
	}

	/**
	 * Whether this place has published (D1): a file with its own code, letter for letter (names from before 0.3.0 keep
	 * the case, so P01's files never make p01 published; Norman 3205 B1). A place whose code is the webshop's (MJDD gave
	 * 001 to a place without one) shares the files' code with the webshop, so there the kind and address in the file's
	 * name tell whose it is (art. 6(3)): a file that is not the webshop's is the place's (also under an earlier address
	 * of the place), and one with the place's own kind and address too (Norman 3205 A1). Without a kind or an address
	 * of its own (MJDD's files then had the webshop's), it cannot be told apart: published, the safer answer.
	 *
	 * @param array<string, mixed> $row Place as stored.
	 * @return bool
	 */
	public function is_row_published( $row ) {
		$code = isset( $row['oznaka'] ) ? (string) $row['oznaka'] : '';
		$this->published_oznake();
		if ( '' === $code || ! isset( $this->published_parts[ $code ] ) ) {
			return false;
		}

		$settings = CX_Sidrena_Settings::instance();
		$webshop  = $settings->normalize_oznaka( (string) $settings->get( 'file_oznaka', '001' ) ) ?: '001';
		if ( $code !== $webshop ) {
			return true;
		}

		$oblik  = $settings->sanitize_file_part( isset( $row['oblik'] ) ? $row['oblik'] : '', '' );
		$adresa = $settings->sanitize_file_part( isset( $row['adresa'] ) ? $row['adresa'] : '', '' );
		if ( '' === $oblik || '' === $adresa ) {
			return true;
		}
		$web_adresa = $settings->sanitize_file_part( $settings->get( 'file_adresa', '' ), '' );
		if ( '' === $web_adresa ) {
			$web_adresa = $settings->sanitize_file_part( $settings->default_file_address(), 'webshop' );
		}
		$web_pair = $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' ) . "\0" . $web_adresa;
		foreach ( array_keys( $this->published_parts[ $code ] ) as $pair ) {
			if ( $pair !== $web_pair || $pair === $oblik . "\0" . $adresa ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The places as the Outlets page gets them: what is stored, with dodjela and "objavljeno" (true when the code has
	 * published: the page shows it read-only, D1). The page never gets the availability (stock): only an import sets it,
	 * and the form never sends it back (Argus V3).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function form_rows() {
		$out = array();
		foreach ( $this->all() as $row ) {
			$row['dodjela']    = $this->dodjela( $row );
			$row['objavljeno'] = $this->is_row_published( $row );
			unset( $row['stock'] );
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * What Status says about the places (CX-SID-74; texts Norman 3178 and 3205, Kai 3191):
	 * - no_address, no_kind, no_both: a place saved before 0.3.0 without them (that part of its file name is empty);
	 * - same_code_webshop: the place has the webshop's code (MJDD gave 001 to a place without one); locked = the place
	 *   has published (is_row_published()): then the webshop's code is what changes (its addresses have no code);
	 * - same_code_pair: two places whose codes differ only in letter case (only from before 0.3.0), one line per pair;
	 *   naziv2/oznaka2 is the one that has not published when one has not; locked = both have;
	 * - nothing: a place with nothing to publish.
	 * The two same_code ones are red on Status, the others yellow.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function problems() {
		$settings = CX_Sidrena_Settings::instance();
		$webshop  = self::code_key( $settings->normalize_oznaka( (string) $settings->get( 'file_oznaka', '001' ) ) ?: '001' );
		$rows     = $this->all();
		$name     = static function ( $row ) {
			return '' !== $row['naziv'] ? $row['naziv'] : $row['oznaka'];
		};

		$out = array();
		foreach ( $rows as $i => $row ) {
			$one     = array( 'naziv' => $name( $row ), 'oznaka' => $row['oznaka'] );
			$address = '' !== trim( (string) $row['adresa'] );
			$kind    = '' !== trim( (string) $row['oblik'] );
			if ( ! $address || ! $kind ) {
				$out[] = array( 'type' => $address ? 'no_kind' : ( $kind ? 'no_address' : 'no_both' ) ) + $one;
			}
			if ( self::code_key( $row['oznaka'] ) === $webshop ) {
				$out[] = array( 'type' => 'same_code_webshop', 'locked' => $this->is_row_published( $row ) ) + $one;
			}
			foreach ( $rows as $j => $other ) {
				if ( $j <= $i || self::code_key( $other['oznaka'] ) !== self::code_key( $row['oznaka'] ) ) {
					continue;
				}
				$a = $this->is_row_published( $row );
				$b = $this->is_row_published( $other );
				list( $first, $second ) = ( $a || ! $b ) ? array( $row, $other ) : array( $other, $row );
				$out[] = array(
					'type'    => 'same_code_pair',
					'naziv'   => $name( $first ),
					'oznaka'  => $first['oznaka'],
					'naziv2'  => $name( $second ),
					'oznaka2' => $second['oznaka'],
					'locked'  => $a && $b,
				);
			}
			if ( ! $this->has_offer( $row ) ) { // rows by agreement alone are an offer, with no file (CX-SID-145)
				$out[] = array( 'type' => 'nothing' ) + $one;
			}
		}

		return $out;
	}

	/**
	 * Catalog / Woo ids assigned on any outlet card.
	 *
	 * @return array<string, bool>
	 */
	public function assigned_ids_lookup() {
		$ids = array();
		foreach ( $this->all() as $row ) {
			if ( 'svi' === $this->dodjela( $row ) ) {
				continue; // a place with all of the webshop's products takes none off the webshop (CX-SID-74)
			}
			foreach ( $row['products'] as $product_id ) {
				$ids[ (string) $product_id ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Persist rows.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function save( $rows ) {
		$clean = $this->clean_rows( $rows );

		update_option( self::OPTION_KEY, $clean, false );
		$this->cached_all = $clean;

		return $clean;
	}

	/**
	 * Rows as save() stores them: sanitized, codes unique among the places, every place with an id.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function clean_rows( $rows ) {
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

				if ( '' !== $item['id'] && isset( $used_ids[ $item['id'] ] ) ) {
					$item['id'] = '';
				}

				$clean[] = $item;
				if ( '' !== $item['id'] ) {
					$used_ids[ $item['id'] ] = true;
				}
			}
		}

		$clean = $this->unique_oznake( $clean );
		$next  = $this->next_id( $clean );
		foreach ( $clean as $index => $item ) {
			if ( '' === $item['id'] ) {
				$clean[ $index ]['id'] = 'p' . $next;
				++$next;
			}
		}

		return $clean;
	}

	/**
	 * Decode JSON from the Poslovnice tab and save.
	 *
	 * @param string $json Raw JSON.
	 * @return array<int, array<string, mixed>>
	 */
	public function save_from_json( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		return $this->save( $decoded );
	}

	/**
	 * The Outlets form's save (CX-SID-74 with CX-SID-131). It is the only writer of the list besides save() (tests and
	 * the take-over from MJDD): the import into a place no longer writes it (CX-SID-129). Any new writer must read fresh
	 * and write only if the list is still what it read (write_if_unchanged()), and never take a place's stock or products
	 * from a copy. All or nothing: when any place breaks a rule nothing is
	 * written and the error says which, as `reason:N` joined by "|" (N = the place's index in the posted list):
	 * - adresa: no address (it is in the price list's name, art. 6(3) of NN 132/2026-1584; never the webshop's);
	 * - vrsta: no kind of place (key oblik; also in the name, never the webshop's, Kai D2);
	 * - oznaka: the code is taken (by another place or the webshop, in any letter case), or the place has published and
	 *   the code changed (D1, art. 6(2): one stable address). A code that did not change is never refused here.
	 * An empty code gets the next free number (002, 003 …), never one the webshop, another place or the archive has.
	 *
	 * Others' writes are kept (Argus V3): the list is read fresh from the table and written only if it is still what was
	 * read (one more try, then "zauzeto"). A place's availability (stock) is never taken from the form, only kept. For
	 * "odabrani", products minus products_loaded (what the page showed) is applied to the stored list, so a product an
	 * import added meanwhile stays; for "svi" the stored picks are kept. $loaded are the ids of the places the page
	 * showed: such a place not posted back is deleted, a place the page never showed (added meanwhile) stays. Without
	 * $loaded (a page from before 0.3.0) the page showed every place; without products_loaded (the same page, which has
	 * no product picker) the stored products stay.
	 *
	 * @param mixed      $posted  Decoded list of places.
	 * @param array|null $loaded  Ids of the places the page showed, or null.
	 * @param string     $webshop The webshop's code (as posted on the same form, or stored).
	 * @return array{saved: array<int, array<string, mixed>>, deleted: array<int, string>, renamed: array<string, string>}|WP_Error
	 *         Code cx_outlet_error, its message the reasons.
	 */
	public function save_from_form( $posted, $loaded, $webshop ) {
		$posted = is_array( $posted ) ? array_values( $posted ) : array();
		for ( $try = 0; $try < 2; $try++ ) {
			$fresh = $this->read_fresh();
			$plan  = $this->plan_form_save( $posted, $loaded, $webshop, $fresh['rows'] );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$clean = $this->clean_rows( $plan['rows'] );
			if ( $this->write_if_unchanged( $clean, $fresh['raw'] ) ) {
				$this->cached_all = $clean;
				return array(
					'saved'   => $clean,
					'deleted' => $plan['deleted'],
					'renamed' => $plan['renamed'],
				);
			}
		}

		return new WP_Error( 'cx_outlet_error', 'zauzeto' );
	}

	/**
	 * Whether a posted list would delete every stored place (CX-SID-142): places are stored now (read fresh), and no posted
	 * card is a stored place or a new card with something in it (save_from_form() skips an empty new card). A page that
	 * did not read the list sends such a list; the owner who deleted every place says so (sidrena_cijena_poslovnice_cleared).
	 *
	 * @param mixed $posted Decoded list of places.
	 * @return bool
	 */
	public function form_empties( $posted ) {
		$stored = $this->read_fresh()['rows'];
		if ( array() === $stored ) {
			return false;
		}
		$ids = array();
		foreach ( $stored as $row ) {
			if ( '' !== $row['id'] ) {
				$ids[ $row['id'] ] = true;
			}
		}
		foreach ( is_array( $posted ) ? $posted : array() as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$id = isset( $raw['id'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $raw['id'] ) ) : '';
			if ( '' !== $id && isset( $ids[ $id ] ) ) {
				return false;
			}
			foreach ( array( 'naziv', 'adresa', 'oznaka' ) as $key ) {
				if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
					return false;
				}
			}
			if ( ! empty( $raw['products'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A stored place as the owner typed its name: one saved before 0.3.0 (no 'tekst' mark) has sanitize_text_field()'s
	 * entities, read back by legacy_text() (CX-SID-142, 3285). Also on the fresh read of a save, so a place the page did
	 * not show keeps its name.
	 *
	 * @param array<string, mixed> $clean Sanitized place.
	 * @param array<string, mixed> $raw   The place as stored.
	 * @return array<string, mixed>
	 */
	private static function as_typed( $clean, $raw ) {
		if ( empty( $raw['tekst'] ) ) {
			$clean['naziv'] = CX_Sidrena_Money::legacy_text( (string) $clean['naziv'] );
		}

		return $clean;
	}

	/**
	 * What save_from_form() writes, from a fresh read.
	 *
	 * @param array<int, mixed>                 $posted  Posted places.
	 * @param array|null                        $loaded  Ids the page showed.
	 * @param string                            $webshop Webshop's code.
	 * @param array<int, array<string, mixed>>  $stored  Places as stored now.
	 * @return array{rows: array<int, array<string, mixed>>, deleted: array<int, string>, renamed: array<string, string>}|WP_Error
	 */
	private function plan_form_save( $posted, $loaded, $webshop, $stored ) {
		$settings = CX_Sidrena_Settings::instance();
		$by_id    = array();
		foreach ( $stored as $row ) {
			if ( '' !== $row['id'] ) {
				$by_id[ $row['id'] ] = $row;
			}
		}
		$shown = array();
		if ( is_array( $loaded ) ) {
			foreach ( $loaded as $id ) {
				$shown[ strtolower( (string) $id ) ] = true;
			}
		} else {
			$shown = array_fill_keys( array_keys( $by_id ), true );
		}

		// Codes that stay as they are, first: the webshop's, each posted place whose code did not change, each place the
		// page never showed. A new or changed code is checked against all of them, whatever the order of the cards.
		// The webshop's code as posted (empty = 001, as Settings::save() stores it) and as stored.
		$web_new = $settings->normalize_oznaka( $webshop ) ?: '001';
		$web_old = $settings->normalize_oznaka( (string) $settings->get( 'file_oznaka', '001' ) ) ?: '001';
		$taken   = array( self::code_key( $web_new ) => 'webshop' );
		$sent  = array();
		foreach ( $posted as $raw ) {
			$id = is_array( $raw ) && isset( $raw['id'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $raw['id'] ) ) : '';
			if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$sent[ $id ] = true;
			$typed       = $settings->normalize_oznaka( isset( $raw['oznaka'] ) ? $raw['oznaka'] : '' );
			if ( '' !== $typed && self::code_key( $typed ) === self::code_key( $by_id[ $id ]['oznaka'] ) ) {
				$taken[ self::code_key( $typed ) ] = 'unchanged';
			}
		}
		foreach ( $stored as $row ) {
			if ( '' !== $row['id'] && ! isset( $sent[ $row['id'] ] ) && ! isset( $shown[ $row['id'] ] ) ) {
				$taken[ self::code_key( $row['oznaka'] ) ] = 'kept';
			}
		}

		$errors = array();
		$out    = array();
		foreach ( $posted as $n => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$id     = isset( $raw['id'] ) ? strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $raw['id'] ) ) : '';
			$before = isset( $by_id[ $id ] ) ? $by_id[ $id ] : null;
			$naziv  = isset( $raw['naziv'] ) ? CX_Sidrena_Money::plain_text( (string) $raw['naziv'] ) : ''; // as typed (CX-SID-142)
			$oblik  = isset( $raw['oblik'] ) ? trim( sanitize_text_field( (string) $raw['oblik'] ) ) : '';
			$adresa = isset( $raw['adresa'] ) ? trim( sanitize_text_field( (string) $raw['adresa'] ) ) : '';
			$typed  = $settings->normalize_oznaka( isset( $raw['oznaka'] ) ? $raw['oznaka'] : '' );
			if ( null === $before && '' === $naziv && '' === $adresa && '' === $typed && empty( $raw['products'] ) ) {
				continue; // a new card left empty: not a place
			}
			if ( '' === $adresa ) {
				$errors[] = 'adresa:' . $n;
			}
			if ( '' === $oblik ) {
				$errors[] = 'vrsta:' . $n;
			}

			$oznaka = $typed;
			if ( null !== $before && '' !== $typed && self::code_key( $typed ) === self::code_key( $before['oznaka'] ) ) {
				// Unchanged: it keeps its letters, published or not (a stored p01 in capitals would be P01, another place's).
				$oznaka = $before['oznaka'];
			} elseif ( null !== $before && $this->is_row_published( $before ) ) {
				$errors[] = 'oznaka:' . $n; // published: the code stays (D1)
			} elseif ( '' !== $typed ) {
				$oznaka = strtoupper( $typed );
				if ( isset( $taken[ self::code_key( $oznaka ) ] ) ) {
					$errors[] = 'oznaka:' . $n;
				}
				$taken[ self::code_key( $oznaka ) ] = $n;
			}

			$dodjela = $this->dodjela( $raw );
			$keep    = null !== $before ? $before['products'] : array();
			if ( 'svi' === $dodjela ) {
				$products = $keep;
			} elseif ( ! array_key_exists( 'products_loaded', $raw ) ) {
				$products = null !== $before ? $keep : $this->sanitize_products( isset( $raw['products'] ) ? $raw['products'] : array() );
			} else {
				$now      = $this->sanitize_products( isset( $raw['products'] ) ? $raw['products'] : array() );
				$was      = $this->sanitize_products( $raw['products_loaded'] );
				$products = array_values( array_diff( $keep, array_diff( $was, $now ) ) );
				foreach ( array_diff( $now, $was ) as $add ) {
					if ( ! in_array( $add, $products, true ) ) {
						$products[] = $add;
					}
				}
			}

			$out[] = array(
				'id'       => null !== $before ? $id : '',
				'naziv'    => $naziv,
				'oblik'    => $oblik,
				'adresa'   => $adresa,
				'oznaka'   => $oznaka,
				'pohrana'  => isset( $raw['pohrana'] ) ? $raw['pohrana'] : ( null !== $before ? $before['pohrana'] : 1 ),
				'products' => $products,
				'stock'    => null !== $before ? $before['stock'] : array(),
				'dodjela'  => $dodjela,
			);
		}

		// Places the page never showed (added meanwhile in another tab) stay, after the posted ones, with their codes taken.
		$deleted = array();
		foreach ( $stored as $row ) {
			if ( '' === $row['id'] || isset( $sent[ $row['id'] ] ) ) {
				continue;
			}
			if ( isset( $shown[ $row['id'] ] ) ) {
				$deleted[] = $row['oznaka'];
				continue;
			}
			$out[] = $row;
		}

		// The webshop's code changed to one a place has (in any letter case): refused (Rook, CX-SID-74). An unchanged one
		// that a place shares from before 0.3.0 does not block the save; Status shows it red, with the step to take.
		if ( self::code_key( $web_new ) !== self::code_key( $web_old ) ) {
			foreach ( $out as $row ) {
				if ( '' !== (string) $row['oznaka'] && self::code_key( $row['oznaka'] ) === self::code_key( $web_new ) ) {
					$errors[] = 'oznaka:webshop';
					break;
				}
			}
		}

		if ( array() !== $errors ) {
			return new WP_Error( 'cx_outlet_error', implode( '|', $errors ) );
		}

		// Empty codes: the next free number, never one the webshop, a place or the archive (a deleted place's files) has.
		foreach ( array_keys( $this->published_oznake() ) as $code ) {
			$taken[ self::code_key( $code ) ] = 'archive';
		}
		$next = 2;
		foreach ( $out as $i => $row ) {
			if ( '' !== $row['oznaka'] ) {
				continue;
			}
			while ( isset( $taken[ str_pad( (string) $next, 3, '0', STR_PAD_LEFT ) ] ) ) {
				++$next;
			}
			$out[ $i ]['oznaka'] = str_pad( (string) $next, 3, '0', STR_PAD_LEFT );
			$taken[ $out[ $i ]['oznaka'] ] = 'new';
		}

		// A place whose code changed (it had not published; also only its letter case) takes its own rows along.
		$renamed = array();
		foreach ( $out as $row ) {
			if ( '' !== $row['id'] && isset( $by_id[ $row['id'] ] ) && $by_id[ $row['id'] ]['oznaka'] !== $row['oznaka'] && isset( $sent[ $row['id'] ] ) ) {
				$renamed[ $by_id[ $row['id'] ]['oznaka'] ] = $row['oznaka'];
			}
		}

		return array(
			'rows'    => $out,
			'deleted' => $deleted,
			'renamed' => $renamed,
		);
	}

	/**
	 * The places as stored in the table now, past every cache, and the raw value they were read from.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, raw: string|null}
	 */
	private function read_fresh() {
		global $wpdb;

		CX_Sidrena_Archive::no_db_cache();
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_KEY ) ); // phpcs:ignore WordPress.DB
		$value = null === $raw ? array() : maybe_unserialize( $raw );
		$rows  = array();
		foreach ( is_array( $value ) ? $value : array() as $row ) {
			$clean = is_array( $row ) ? $this->sanitize_row( $row ) : null;
			if ( null !== $clean ) {
				$rows[] = self::as_typed( $clean, $row );
			}
		}

		return array(
			'rows' => $this->unique_oznake( $rows ),
			'raw'  => null === $raw ? null : (string) $raw,
		);
	}

	/**
	 * Write the list only if the table still holds $raw (null: no row yet), so a write made since the read (an import
	 * into a place, another tab) is never undone. The option's caches are dropped after.
	 *
	 * @param array<int, array<string, mixed>> $rows Clean rows.
	 * @param string|null                      $raw  What was read.
	 * @return bool
	 */
	private function write_if_unchanged( $rows, $raw ) {
		global $wpdb;

		$value = maybe_serialize( $rows );
		if ( $value === $raw ) {
			return true;
		}
		if ( null === $raw ) {
			$done = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", self::OPTION_KEY, $value ) ); // phpcs:ignore WordPress.DB
		} else {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION_KEY, $raw ) ); // phpcs:ignore WordPress.DB
		}
		wp_cache_delete( self::OPTION_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return 1 === (int) $done;
	}

	/**
	 * Raise the next storage number of one outlet, never lower it: its own counter row (CX_Sidrena_Archive::
	 * raise_pohrana(), CX-SID-119 dopuna). The outlet list is not saved: a build that saved it, as read when it started,
	 * undid an outlet change the owner saved meanwhile.
	 *
	 * @param string $id      Outlet id.
	 * @param int    $pohrana Next sequence, 1+.
	 * @return int The outlet's counter after.
	 */
	public function set_pohrana( $id, $pohrana ) {
		return CX_Sidrena_Archive::instance()->raise_pohrana( (string) $id, $pohrana );
	}

	/**
	 * Labels for product ids already assigned to outlets.
	 *
	 * @return array<string, string>
	 */
	public function product_labels() {
		$ids = array();
		foreach ( $this->all() as $row ) {
			foreach ( $row['products'] as $product_id ) {
				$ids[ (string) $product_id ] = true;
			}
		}

		$labels = array();
		foreach ( array_keys( $ids ) as $product_id ) {
			$labels[ $product_id ] = $this->product_label( $product_id );
		}

		return $labels;
	}

	/**
	 * Human label for one catalog row or Woo product.
	 *
	 * @param string $product_id Id.
	 * @return string
	 */
	public function product_label( $product_id ) {
		$product_id = (string) $product_id;

		if ( CX_Sidrena_Plugin::woocommerce_active() && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $product_id ) );
			if ( $product ) {
				return wp_strip_all_tags( $product->get_name() );
			}

			return '#' . $product_id;
		}

		$row = CX_Sidrena_Catalog::instance()->get( $product_id );
		if ( ! is_array( $row ) ) {
			return '#' . $product_id;
		}

		$label = $row['naziv'];
		if ( '' !== $row['sku'] ) {
			$label .= ' (' . $row['sku'] . ')';
		}

		return $label;
	}

	/**
	 * Keep a row that has a name, address, or products.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_row( $row ) {
		$settings = CX_Sidrena_Settings::instance();
		// The name as typed (CX-SID-142, 3285): every page escapes it. Kind and address stay sanitize_text_field(): they are
		// in the price list's file name (sanitize_file_part()), which must not change for a place that has published (Kai).
		$naziv    = isset( $row['naziv'] ) ? CX_Sidrena_Money::plain_text( (string) $row['naziv'] ) : '';
		$adresa   = isset( $row['adresa'] ) ? sanitize_text_field( (string) $row['adresa'] ) : '';
		$oblik    = isset( $row['oblik'] ) ? sanitize_text_field( (string) $row['oblik'] ) : '';
		$products = $this->sanitize_products( isset( $row['products'] ) ? $row['products'] : array() );

		if ( '' === $naziv && '' === $adresa && array() === $products && '' === $oblik ) {
			return null;
		}

		$id = isset( $row['id'] ) ? strtolower( (string) $row['id'] ) : '';
		$id = preg_replace( '/[^a-z0-9]/', '', $id );
		if ( ! is_string( $id ) || ! preg_match( '/^p\d+$/', $id ) ) {
			$id = '';
		}

		$oznaka = $settings->normalize_oznaka( isset( $row['oznaka'] ) ? $row['oznaka'] : '' );
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		return array(
			'id'       => $id,
			'naziv'    => $naziv,
			'tekst'    => '1', // the name is as typed (CX-SID-142); a stored place without it is from before (as_typed())
			'oblik'    => $oblik,
			'adresa'   => $adresa,
			'oznaka'   => $oznaka,
			'pohrana'  => $settings->sanitize_pohrana( isset( $row['pohrana'] ) ? $row['pohrana'] : 1 ),
			'products' => $products,
			'stock'    => $this->sanitize_stock( isset( $row['stock'] ) ? $row['stock'] : array() ),
			'dodjela'  => $this->dodjela( $row ),
		);
	}


	/**
	 * Stored availability for one outlet, keyed by product id.
	 *
	 * @param string $oznaka Outlet code.
	 * @return array<string, string>
	 */
	public function stock_map( $oznaka ) {
		$outlet = $this->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) || empty( $outlet['stock'] ) || ! is_array( $outlet['stock'] ) ) {
			return array();
		}

		return $outlet['stock'];
	}

	/**
	 * Product or catalog ids.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int, string>
	 */
	private function sanitize_products( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $id ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( ! is_string( $id ) || '' === $id || '0' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $id;
		}

		return $out;
	}

	/**
	 * Product id => dostupno|nedostupno. Unknown keys are dropped.
	 *
	 * @param mixed $raw Raw map.
	 * @return array<string, string>
	 */
	private function sanitize_stock( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $id => $flag ) {
			$id = preg_replace( '/\D/', '', (string) $id );
			if ( ! is_string( $id ) || '' === $id || '0' === $id ) {
				continue;
			}
			$out[ $id ] = CX_Sidrena_Money::normalize_dostupnost( $flag );
		}

		return $out;
	}

	/**
	 * Make object codes unique. Numeric codes increment; others gain a suffix.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function unique_oznake( $rows ) {
		$used = array();

		foreach ( $rows as $index => $row ) {
			$oznaka = (string) $row['oznaka'];
			$guard  = 0;
			while ( isset( $used[ $oznaka ] ) && $guard < 1000 ) {
				if ( ctype_digit( $oznaka ) ) {
					$oznaka = str_pad( (string) ( (int) $oznaka + 1 ), strlen( $oznaka ), '0', STR_PAD_LEFT );
				} else {
					$oznaka .= '2';
				}
				++$guard;
			}
			$rows[ $index ]['oznaka'] = $oznaka;
			$used[ $oznaka ]          = true;
		}

		return $rows;
	}

	/**
	 * Next numeric suffix for p1, p2, …
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return int
	 */
	private function next_id( $rows ) {
		$max = 0;
		foreach ( $rows as $row ) {
			if ( preg_match( '/^p(\d+)$/', (string) $row['id'], $match ) ) {
				$max = max( $max, (int) $match[1] );
			}
		}

		return $max + 1;
	}
}
