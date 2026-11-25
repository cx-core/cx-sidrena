<?php
/**
 * Legal filenames and the public archive (current price lists, and replaced ones for 15 days).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal filenames and the public archive (current price lists, and replaced ones for 15 days).
 */
class CX_Sidrena_Archive {

	/**
	 * The clock of the law the plugin is for.
	 */
	const LEGAL_TIMEZONE = 'Europe/Zagreb';

	/**
	 * Days a replaced file of a price list stays public after the day it was replaced, on the legal clock (NN 132/2026-1584
	 * art. 3(6): "osigurava dostupnost arhivskog cjenika 15 dana od njegove zamjene važećim cjenikom"; CX-SID-158). The
	 * current file is always public. Until 0.3.0 this was 30 days before the price list's newest file (the draft 10332).
	 */
	const PUBLIC_DAYS = 15;

	/**
	 * Folder of the kept files: uploads/cx-sidrena/cuvano-<random>, out of public reach.
	 */
	const KEPT_PREFIX = 'cuvano-';

	/**
	 * admin-post action that sends one kept file to the owner.
	 */
	const KEPT_ACTION = 'cx_sidrena_kept_download';

	/**
	 * Kept files listed on the Settings screen.
	 */
	const KEPT_LIST_MAX = 100;

	/**
	 * The storage counter of the webshop: a row of its own in the options table, the next storage number (CX-SID-119
	 * dopuna). An outlet's is this name, a dot and the outlet's id. Only raise_pohrana() writes it, and only upwards.
	 * Before, a build wrote the counter by saving the whole settings (or the whole outlet list) as it had read them when
	 * it started, which undid what the owner saved meanwhile: the OIB of 17.11. The old value in the settings or on the
	 * outlet stays a lower bound (0.2.2 and before, MJDD's take-over).
	 */
	const POHRANA_ROW = 'cx_sidrena_pohrana';

	/**
	 * The price list of all places of sale (CX-SID-113; NN 132/2026-1584 art. 6(4)): the part of its file name instead
	 * of oblik, adresa and oznaka, and the scope that the functions taking an outlet (array) or the webshop (null) take
	 * for it. Never an object code: a code has no underscore (Settings::normalize_oznaka()). It shares the webshop's
	 * storage counter and live address.
	 */
	const ALL_PLACES = 'ZA_SVA_PRODAJNA_MJESTA';

	/**
	 * When the price list of all places was last switched on or off (CX-SID-113), and the newest archive file of each
	 * name at that moment (switch_names()). None of those files becomes the current one again: switching on, off or on
	 * again always leaves a new file with a higher number, also with nothing changed (Rook 4030, Q4).
	 */
	const SWITCH_OPTION = 'cx_sidrena_isti_switch';

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
	 * The owner's download of a kept file. Also without WooCommerce (a shop that only presents its prices).
	 */
	public static function register() {
		add_action( 'admin_post_' . self::KEPT_ACTION, array( __CLASS__, 'download_kept' ) );
	}

	/**
	 * Time zone of everything an inspector reads: the time in a file name, "today" of the archive, the morning build,
	 * the generated time in a file and on the public pages. Croatian law, Croatian clock, whatever zone the site is set
	 * to (a shop left on UTC named its files an hour or two early and started "today" after midnight). Admin screens
	 * keep the site's own settings.
	 *
	 * @return DateTimeZone
	 */
	public static function legal_timezone() {
		$name = apply_filters( 'cx_sidrena_legal_timezone', self::LEGAL_TIMEZONE );
		try {
			return new DateTimeZone( is_string( $name ) && '' !== $name ? $name : self::LEGAL_TIMEZONE );
		} catch ( Exception $e ) {
			return new DateTimeZone( self::LEGAL_TIMEZONE );
		}
	}

	/**
	 * A time on the legal clock.
	 *
	 * @param string   $format    PHP date format.
	 * @param int|null $timestamp Unix time, null = now.
	 * @return string
	 */
	public static function legal_date( $format, $timestamp = null ) {
		return wp_date( $format, null === $timestamp ? time() : (int) $timestamp, self::legal_timezone() );
	}

	/**
	 * A price list time on the owner's screens (Status, Settings, wp cx-sidrena check): "today at 07:12" or
	 * "on 14.11.2026. at 07:12", the day and the time on the legal clock, so the number is the one in the file name and
	 * in "Updated" on the public page (CX-SID-59). No zone in the text (Hrvoje, 3473). The other WordPress times in
	 * admin stay on the site's settings.
	 *
	 * @param int      $timestamp Unix time.
	 * @param int|null $now       Unix time whose day is "today", null = now.
	 * @return string
	 */
	public static function legal_when( $timestamp, $now = null ) {
		if ( self::legal_date( 'Y-m-d', $timestamp ) === self::legal_date( 'Y-m-d', $now ) ) {
			/* translators: %s: time, e.g. 07:12 */
			return sprintf( __( 'today at %s', 'cx-sidrena' ), self::legal_date( 'H:i', $timestamp ) );
		}
		/* translators: 1: date, 2: time */
		return sprintf( __( 'on %1$s at %2$s', 'cx-sidrena' ), self::legal_date( 'j.n.Y.', $timestamp ), self::legal_date( 'H:i', $timestamp ) );
	}

	/**
	 * Name of a price list file by the Pravilnik o cjeniku (NN 132/2026-1584, art. 6(3)):
	 * [OIB_]oblik_adresa_oznaka_ROBA|USLUGA_pohrana_GGGGMMDD_SSMM.xml, e.g.
	 * 12345678903_prodavaonica_Ilica 150 Zagreb_P-01_ROBA_104_20261001_0745.xml
	 *
	 * The OIB (or registration number) first (Settings::legal_id()). Without it nothing new is published (CX-SID-159,
	 * CX_Sidrena_Export::waiting_for_legal_id()), so no file is made with the part left out; files named so before stay as
	 * they are. Spaces, hyphens and the typed case stay. Date and time on the
	 * legal clock (legal_timezone), with no separator, so no disk needs another name.
	 *
	 * The price list of all places of sale (ALL_PLACES, art. 6(4)): OIB_ZA_SVA_PRODAJNA_MJESTA_ROBA|USLUGA_pohrana_
	 * GGGGMMDD_SSMM, with no oblik, adresa or oznaka, and the webshop's storage number.
	 *
	 * @param string              $format    xml|csv.
	 * @param int                 $timestamp Unix time of publication, 0 = now.
	 * @param int                 $pohrana   Storage sequence, 0 = next unused number (does not consume).
	 * @param array<string,mixed>|string|null $outlet Outlet row, null for the single shop, or ALL_PLACES.
	 * @param string              $vrsta     proizvod (ROBA) or usluga (USLUGA).
	 * @return string
	 */
	public function legal_filename( $format = '', $timestamp = 0, $pohrana = 0, $outlet = null, $vrsta = 'proizvod' ) {
		$settings = CX_Sidrena_Settings::instance();
		$format   = '' === $format ? CX_Sidrena_Feed_Files::instance()->export_format() : CX_Sidrena_Feed_Files::instance()->sanitize_ext( $format );
		$tz       = self::legal_timezone();
		$dt       = $timestamp > 0 ? new DateTime( '@' . (int) $timestamp ) : new DateTime( 'now', $tz );
		$dt->setTimezone( $tz );

		if ( self::ALL_PLACES === $outlet ) {
			$seq   = $pohrana > 0 ? (int) $pohrana : $this->next_pohrana( self::ALL_PLACES, $outlet );
			$parts = array( self::ALL_PLACES, self::vrsta_token( $vrsta ), (string) $seq, $dt->format( 'Ymd' ), $dt->format( 'Hi' ) );
			$oib   = $settings->legal_id();
			if ( '' !== $oib ) {
				array_unshift( $parts, $oib );
			}

			return implode( '_', $parts ) . '.' . $format;
		}

		if ( is_array( $outlet ) ) {
			// A place's own kind and address, never the webshop's (CX-SID-74, Kai D2 and N5; art. 6(3) of
			// NN 132/2026-1584): a place saved before 0.3.0 without them has that part empty, and Status says so.
			$oblik  = $settings->sanitize_file_part( isset( $outlet['oblik'] ) ? $outlet['oblik'] : '', '' );
			$adresa = $settings->sanitize_file_part( isset( $outlet['adresa'] ) ? $outlet['adresa'] : '', '' );
			$oznaka = $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
		} else {
			$oblik  = $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' );
			$adresa = $settings->sanitize_file_part( $settings->get( 'file_adresa', '' ), '' );
			$oznaka = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
		}
		// No number given: the next one, as the build will take it (stored counter or archive + 1, next_pohrana()), so
		// "Next official name" and the field next to it show the same number (proposal 2923 point 3; Norman, 119).
		$seq = $pohrana > 0 ? (int) $pohrana : $this->next_pohrana( $this->scope_oznaka( $outlet ), $outlet );

		if ( '' === $adresa && ! is_array( $outlet ) ) {
			$adresa = $settings->sanitize_file_part( $settings->default_file_address(), 'webshop' );
		}
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$oib   = $settings->legal_id();
		$parts = array( $oblik, $adresa, $oznaka, self::vrsta_token( $vrsta ), (string) $seq, $dt->format( 'Ymd' ), $dt->format( 'Hi' ) );
		if ( '' !== $oib ) {
			array_unshift( $parts, $oib );
		}

		return implode( '_', $parts ) . '.' . $format;
	}

	/**
	 * ROBA or USLUGA, the part of the name that says what the file lists (art. 6(3)).
	 *
	 * @param string $vrsta proizvod|usluga.
	 * @return string
	 */
	public static function vrsta_token( $vrsta ) {
		return 'usluga' === $vrsta ? 'USLUGA' : 'ROBA';
	}

	/**
	 * The parts of an archive file name, read from the end (Argus, CX-SID-110 note 1):
	 * - since CX-SID-110: [OIB_]oblik_adresa_oznaka_ROBA|USLUGA_pohrana_GGGGMMDD_SSMM (7 or 8 parts);
	 * - the price list of all places of sale (CX-SID-113, art. 6(4)): OIB_ZA_SVA_PRODAJNA_MJESTA_ROBA|USLUGA_pohrana_
	 *   GGGGMMDD_SSMM (9 parts): oblik and adresa empty, oznaka ALL_PLACES;
	 * - before it: oblik_adresa_oznaka_pohrana_DD.MM.YYYY_HH:MM (or HH-MM on Windows), and the earlier
	 *   oblik_adresa_oznaka_pohrana_YYYYMMDD_HHMM (6 parts), both a file of goods.
	 * Other names (sidrena-cijena_DD-MM-YYYY) have no parts: null.
	 *
	 * @param string $name Basename.
	 * @return array{oib:string,oblik:string,adresa:string,oznaka:string,vrsta:string,pohrana:int,legal:bool}|null
	 *         legal: a name of the Pravilnik (with ROBA|USLUGA).
	 */
	public function parse_name( $name ) {
		$parts = explode( '_', (string) pathinfo( (string) $name, PATHINFO_FILENAME ) );
		$n     = count( $parts );

		if ( ( 7 === $n || 8 === $n )
			&& preg_match( '/^\d{4}$/D', $parts[ $n - 1 ] ) && preg_match( '/^\d{8}$/D', $parts[ $n - 2 ] )
			&& preg_match( '/^\d+$/D', $parts[ $n - 3 ] ) && in_array( $parts[ $n - 4 ], array( 'ROBA', 'USLUGA' ), true )
			&& ( 7 === $n || preg_match( '/^\d+$/D', $parts[0] ) ) ) {
			$o = 8 === $n ? 1 : 0;
			return array(
				'oib'     => 8 === $n ? $parts[0] : '',
				'oblik'   => $parts[ $o ],
				'adresa'  => $parts[ $o + 1 ],
				'oznaka'  => $parts[ $o + 2 ],
				'vrsta'   => 'USLUGA' === $parts[ $n - 4 ] ? 'usluga' : 'proizvod',
				'pohrana' => absint( $parts[ $n - 3 ] ),
				'legal'   => true,
			);
		}

		if ( 9 === $n && preg_match( '/^\d+$/D', $parts[0] ) && 'ZA' === $parts[1] && 'SVA' === $parts[2] && 'PRODAJNA' === $parts[3]
			&& 'MJESTA' === $parts[4] && in_array( $parts[5], array( 'ROBA', 'USLUGA' ), true ) && preg_match( '/^\d+$/D', $parts[6] )
			&& preg_match( '/^\d{8}$/D', $parts[7] ) && preg_match( '/^\d{4}$/D', $parts[8] ) ) {
			return array(
				'oib'     => $parts[0],
				'oblik'   => '',
				'adresa'  => '',
				'oznaka'  => self::ALL_PLACES,
				'vrsta'   => 'USLUGA' === $parts[5] ? 'usluga' : 'proizvod',
				'pohrana' => absint( $parts[6] ),
				'legal'   => true,
			);
		}

		if ( 6 !== $n ) {
			return null;
		}
		$date = $parts[4];
		$time = $parts[5];
		$old  = (bool) preg_match( '/^\d{8}$/', $date ) && (bool) preg_match( '/^\d{4}$/', $time );
		$new  = (bool) preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) && (bool) preg_match( '/^\d{2}[:\-]\d{2}$/', $time );
		if ( ! $old && ! $new ) {
			return null;
		}

		return array(
			'oib'     => '',
			'oblik'   => $parts[0],
			'adresa'  => $parts[1],
			'oznaka'  => $parts[2],
			'vrsta'   => 'proizvod',
			'pohrana' => absint( $parts[3] ),
			'legal'   => false,
		);
	}

	/**
	 * Swap the time colon for a hyphen on Windows, where ":" cannot be a filename character.
	 *
	 * @param string $filename Legal basename.
	 * @return string
	 */
	public function archive_name_for_disk( $filename ) {
		if ( '\\' === DIRECTORY_SEPARATOR && false !== strpos( $filename, ':' ) ) {
			return str_replace( ':', '-', $filename );
		}

		return $filename;
	}

	/**
	 * Last published legal filename, or a preview of the next one.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function published_filename( $format = '' ) {
		$meta = CX_Sidrena_Export::instance()->get_public_meta();
		if ( ! empty( $meta['filename'] ) && is_string( $meta['filename'] ) ) {
			$name = $this->sanitize_archive_name( $meta['filename'] );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$stamp = ! empty( $meta['generated'] ) ? (int) $meta['generated'] : 0;

		return $this->legal_filename( $format, $stamp );
	}

	/**
	 * Directory for dated archive XML files.
	 *
	 * @return string
	 */
	public function get_archive_dir() {
		$dir = CX_Sidrena_Feed_Files::instance()->get_public_dir();
		if ( '' === $dir ) {
			return '';
		}

		return trailingslashit( $dir ) . 'arhiva';
	}

	/**
	 * Publish one price list, the webshop's or one outlet's (CX-SID-27). A new archive file whenever what it says
	 * differs from the newest file of that object code, and with the first build of each day (the morning file); the
	 * live file is then a byte copy of it. An archive file is never written over: a new one gets the next storage
	 * number and the time of this build in its name.
	 *
	 * The same content as the newest file, which is from today and has the same name parts and format: nothing new is
	 * written (an order that only moved a stock count), the live file is made sure to be that file. "The same" leaves
	 * out the generated time in the XML.
	 *
	 * @param string                    $body      Price list in $format.
	 * @param string                    $format    xml|csv.
	 * @param array<string, mixed>|null $outlet    Outlet, or null for the webshop.
	 * @param int                       $timestamp When the body was made (its generated time), 0 = now.
	 * @param string                    $vrsta     proizvod|usluga: which of the scope's two files (CX-SID-110).
	 * @param int                       $pohrana   Storage number of this build, 0 = next_pohrana(). One build of goods
	 *                                             and services gives both new files the same number.
	 * @param string                    $live      Live path, '' = the kind's own (Feed_Files::get_public_path()).
	 * @return array{filename:string,pohrana:int,generated:int,published:int,new:bool,bytes:int}|WP_Error
	 *         generated = this build; published = the moment of the file now live (its name's time).
	 */
	public function publish( $body, $format, $outlet = null, $timestamp = 0, $vrsta = 'proizvod', $pohrana = 0, $live = '' ) {
		$ff        = CX_Sidrena_Feed_Files::instance();
		$format    = $ff->sanitize_ext( $format );
		$timestamp = $timestamp > 0 ? (int) $timestamp : time();
		$dir       = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_archive_mkdir', __( 'The archive folder could not be created.', 'cx-sidrena' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$oznaka = $this->scope_oznaka( $outlet );
		if ( '' === $live ) {
			$live = $ff->get_public_path( $format, is_array( $outlet ) && isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '', $vrsta );
		}
		$newest = $this->newest_of_list( $outlet, $vrsta, $timestamp );
		if ( null !== $newest && $this->switched_from( $newest['name'] ) ) {
			$newest = null; // it was current when the price list of all places was switched (CX-SID-113, Q4): a new file
		}

		if ( null !== $newest && $this->is_current( $newest, $body, $format, $outlet, $timestamp, $vrsta ) ) {
			if ( ! file_exists( $live ) || hash_file( 'sha256', $live ) !== hash_file( 'sha256', $newest['path'] ) ) {
				if ( ! $ff->copy_atomic( $newest['path'], $live ) ) {
					return new WP_Error( 'sidrena_xml_write', __( 'The public price list could not be saved.', 'cx-sidrena' ) );
				}
			}

			return array(
				'filename'  => $newest['name'],
				'pohrana'   => $this->archive_pohrana_from_name( $newest['name'] ),
				'generated' => $timestamp,
				'published' => $this->archive_file_timestamp( $newest['name'], $newest['path'] ),
				'new'       => false,
				'bytes'     => (int) filesize( $newest['path'] ),
			);
		}

		$pohrana = $pohrana > 0 ? (int) $pohrana : $this->next_pohrana( $oznaka, $outlet );
		$name    = $this->legal_filename( $format, $timestamp, $pohrana, $outlet, $vrsta );
		for ( $tries = 0; $this->name_taken( $name ) && $tries < 1000; $tries++ ) {
			++$pohrana;
			$name = $this->legal_filename( $format, $timestamp, $pohrana, $outlet, $vrsta );
		}
		if ( $this->name_taken( $name ) ) {
			return new WP_Error( 'sidrena_xml_archive_copy', __( 'The archive price list could not be saved.', 'cx-sidrena' ) );
		}

		$path    = trailingslashit( $dir ) . $name;
		$written = $ff->write_atomic( $path, $body );
		if ( false === $written ) {
			return new WP_Error( 'sidrena_xml_archive_copy', __( 'The archive price list could not be saved.', 'cx-sidrena' ) );
		}
		if ( ! $ff->copy_atomic( $path, $live ) ) {
			return new WP_Error( 'sidrena_xml_write', __( 'The public price list could not be saved.', 'cx-sidrena' ) );
		}

		// Only this scope's counter goes up. The build saves neither the settings nor the outlet list, so what the owner
		// saved while it ran (an OIB, an address, an outlet's products) stays (CX-SID-119 dopuna).
		$this->raise_pohrana( is_array( $outlet ) && isset( $outlet['id'] ) ? $outlet['id'] : '', $pohrana + 1 );

		// The new file replaces the one before it: files replaced more than 15 days ago leave the public archive.
		$this->move_old();

		return array(
			'filename'  => $name,
			'pohrana'   => $pohrana,
			'generated' => $timestamp,
			'published' => $timestamp,
			'new'       => true,
			'bytes'     => (int) $written,
		);
	}

	/**
	 * Whether the newest file already is this price list: same format, same name parts, from today, same content.
	 *
	 * @param array<string, mixed>      $newest    Newest archive file of the object code.
	 * @param string                    $body      New body.
	 * @param string                    $format    xml|csv.
	 * @param array<string, mixed>|null $outlet    Outlet, or null.
	 * @param int                       $timestamp This build.
	 * @return bool
	 */
	private function is_current( $newest, $body, $format, $outlet, $timestamp, $vrsta = 'proizvod' ) {
		if ( $newest['ext'] !== $format ) {
			return false;
		}
		if ( $this->name_key( $newest['name'] ) !== $this->name_key( $this->legal_filename( $format, $timestamp, 1, $outlet, $vrsta ) ) ) {
			return false;
		}
		if ( $newest['date'] !== self::legal_date( 'd-m-Y', $timestamp ) ) {
			return false; // the first build of a day always leaves its own file
		}

		$old = file_get_contents( $newest['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $old ) && self::without_generated( $old ) === self::without_generated( $body );
	}

	/**
	 * A price list without the moment it was made (the XML root's generated attribute).
	 *
	 * @param string $body File content.
	 * @return string
	 */
	public static function without_generated( $body ) {
		return (string) preg_replace( '/(<cjenik\b[^>]*?)\s+generated="[^"]*"/', '$1', (string) $body, 1 );
	}

	/**
	 * What a file name says besides storage number, date and time: OIB, oblik, adresa, oznaka, ROBA|USLUGA and the
	 * format. A name from before the Pravilnik never has the key of a new one, so the first build after the update
	 * leaves a file with the legal name.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function name_key( $name ) {
		$ext = '.' . strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$p   = $this->parse_name( $name );
		if ( null === $p ) {
			return 'other:' . (string) pathinfo( $name, PATHINFO_FILENAME ) . $ext;
		}

		return ( $p['legal'] ? '' : 'old:' ) . implode( '_', array( $p['oib'], $p['oblik'], $p['adresa'], $p['oznaka'], self::vrsta_token( $p['vrsta'] ) ) ) . $ext;
	}

	/**
	 * Name key of the next file with the settings as they are now, for the webshop or one outlet.
	 *
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return string
	 */
	public function current_name_key( $outlet = null ) {
		return $this->name_key( $this->legal_filename( '', time(), 1, $outlet ) );
	}

	/**
	 * Object code of a scope's files, the webshop's (null) or an outlet's.
	 *
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return string
	 */
	public function oznaka_of( $outlet ) {
		return $this->scope_oznaka( $outlet );
	}

	/**
	 * Object code a scope's files carry.
	 *
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return string
	 */
	private function scope_oznaka( $outlet ) {
		if ( self::ALL_PLACES === $outlet ) {
			return self::ALL_PLACES; // never an object code (no underscore in one), so its files are its own (CX-SID-113)
		}
		$settings = CX_Sidrena_Settings::instance();
		$oznaka   = is_array( $outlet )
			? $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' )
			: $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );

		return '' !== $oznaka ? $oznaka : '001';
	}

	/**
	 * Newest archive file of an object code and kind (a file from before the Pravilnik is one of goods).
	 *
	 * @param string $oznaka Object code.
	 * @param string $vrsta  proizvod|usluga.
	 * @return array<string, mixed>|null
	 */
	public function newest_file( $oznaka, $vrsta = 'proizvod' ) {
		foreach ( $this->get_archive_files() as $file ) {
			$p = $this->parse_name( $file['name'] );
			if ( null !== $p && $p['oznaka'] === $oznaka && $p['vrsta'] === $vrsta ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Newest archive file of this price list, the one a build compares with: the newest of its code and kind, but in a
	 * collision of codes (a place MJDD left with the webshop's code) not one with the kind and address the other list
	 * writes (CX-SID-143). Without that, each build compared with the other list's file and left a new file of a price
	 * list that had not changed. Without a collision, or when both write the same kind and address, as newest_file().
	 *
	 * @param array<string, mixed>|null $outlet    Outlet, or null for the webshop.
	 * @param string                    $vrsta     proizvod|usluga.
	 * @param int                       $timestamp Time of the build (for the names compared).
	 * @return array<string, mixed>|null
	 */
	private function newest_of_list( $outlet, $vrsta, $timestamp ) {
		if ( self::ALL_PLACES === $outlet ) {
			return $this->newest_file( self::ALL_PLACES, $vrsta ); // only its own files, never the webshop's or a place's (Q4)
		}
		$oznaka = $this->scope_oznaka( $outlet );
		$other  = false;
		if ( is_array( $outlet ) ) {
			if ( $oznaka === $this->scope_oznaka( null ) ) {
				$other = null; // the webshop's list
			}
		} else {
			$place = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			if ( is_array( $place ) ) {
				$other = $place;
			}
		}
		if ( false === $other ) {
			return $this->newest_file( $oznaka, $vrsta );
		}

		$pair   = function ( $scope ) use ( $timestamp, $vrsta ) {
			$p = $this->parse_name( $this->legal_filename( '', $timestamp, 1, $scope, $vrsta ) );
			return null === $p ? '' : $p['oblik'] . "\0" . $p['adresa'];
		};
		$theirs = $pair( $other );
		if ( $theirs === $pair( $outlet ) ) {
			return $this->newest_file( $oznaka, $vrsta );
		}
		foreach ( $this->get_archive_files() as $file ) {
			$p = $this->parse_name( $file['name'] );
			if ( null !== $p && $p['oznaka'] === $oznaka && $p['vrsta'] === $vrsta && $p['oblik'] . "\0" . $p['adresa'] !== $theirs ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Every archive file name of the shop, for the storage numbers and for names already used.
	 *
	 * @return array<int, string>
	 */
	private function all_archive_names() {
		$names = array();
		foreach ( $this->get_archive_files() as $file ) {
			$names[] = $file['name'];
		}
		foreach ( $this->kept_files() as $file ) {
			$names[] = $file['name'];
		}

		return $names;
	}

	/**
	 * Whether a file of that name exists already.
	 *
	 * @param string $name Legal basename.
	 * @return bool
	 */
	private function name_taken( $name ) {
		return in_array( $name, $this->all_archive_names(), true );
	}

	/**
	 * Next storage number of an object code: the stored counter, or one above the highest number in a file name of
	 * that code, whichever is higher. So a shop whose settings were lost (uninstalled and installed again) goes on
	 * from its files, and an owner who typed a higher number keeps it.
	 *
	 * The webshop and the price list of all places of sale are one sequence (CX-SID-113, Q5): they share the counter,
	 * and each counts the other's files too, so a lost counter never gives either a number the other has used.
	 *
	 * @param string                           $oznaka Object code (ALL_PLACES for the price list of all places).
	 * @param array<string, mixed>|string|null $outlet Outlet, null for the webshop, or ALL_PLACES.
	 * @return int
	 */
	public function next_pohrana( $oznaka, $outlet = null ) {
		$stored  = $this->stored_pohrana( $outlet );
		$highest = 0;
		$codes   = array( $oznaka => true );
		if ( ! is_array( $outlet ) ) {
			$codes[ self::ALL_PLACES ]             = true;
			$codes[ $this->scope_oznaka( null ) ] = true;
		}
		foreach ( $this->all_archive_names() as $name ) {
			if ( isset( $codes[ $this->archive_oznaka_from_name( $name ) ] ) ) {
				$highest = max( $highest, $this->archive_pohrana_from_name( $name ) );
			}
		}

		return max( $stored, $highest + 1 );
	}

	/**
	 * The stored next storage number of a scope: its counter row or the old value in the settings (or on the outlet),
	 * whichever is higher. Without the archive (next_pohrana() adds that). Read without DONOTCACHEDB of its own: the
	 * build and the admin screens have it already, and a public download name may be a minute old.
	 *
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return int
	 */
	public function stored_pohrana( $outlet = null ) {
		$settings = CX_Sidrena_Settings::instance();
		$old      = is_array( $outlet )
			? $settings->sanitize_pohrana( isset( $outlet['pohrana'] ) ? $outlet['pohrana'] : 1 )
			: $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
		$row      = $this->read_pohrana_row( $this->pohrana_row_name( is_array( $outlet ) && isset( $outlet['id'] ) ? $outlet['id'] : '' ) );

		return max( $old, null === $row ? 0 : $row );
	}

	/**
	 * Raise a scope's counter to $to, never lower it (CX-SID-119 dopuna; Kai: the number only grows, and the owner may
	 * only raise it). Atomic as the rebuild lock: INSERT IGNORE when there is no row, else an UPDATE that only succeeds
	 * when the row still holds what was read. A request that loses the race reads again; a higher value written
	 * meanwhile is kept. Direct SQL, never update_option, and never through a database query cache (DONOTCACHEDB): a
	 * stale read would make the UPDATE miss every time.
	 *
	 * @param string $outlet_id Outlet id, '' for the webshop.
	 * @param int    $to        The next storage number wanted.
	 * @return int The counter after: $to, or a higher value already there.
	 */
	public function raise_pohrana( $outlet_id, $to ) {
		global $wpdb;

		self::no_db_cache();
		$name = $this->pohrana_row_name( $outlet_id );
		$to   = CX_Sidrena_Settings::instance()->sanitize_pohrana( $to );
		for ( $try = 0; $try < 5; $try++ ) {
			$have = $this->read_pohrana_row( $name );
			if ( null === $have ) {
				$suppress = $wpdb->suppress_errors( true );
				$ins      = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, (string) $to ) ); // phpcs:ignore WordPress.DB
				$wpdb->suppress_errors( $suppress );
				if ( $ins ) {
					return $to;
				}
				continue; // another request made the row in between: read it
			}
			if ( $have >= $to ) {
				return $have;
			}
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $to, $name, (string) $have ) ); // phpcs:ignore WordPress.DB
			if ( 1 === (int) $done ) {
				return $to;
			}
		}

		$have = $this->read_pohrana_row( $name );

		return null === $have ? 0 : $have;
	}

	/**
	 * The counter row of a scope, or null when there is none.
	 *
	 * @param string $name Row name (pohrana_row_name()).
	 * @return int|null
	 */
	private function read_pohrana_row( $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB

		return null === $value ? null : (int) $value;
	}

	/**
	 * @param string $outlet_id Outlet id, '' for the webshop.
	 * @return string
	 */
	private function pohrana_row_name( $outlet_id ) {
		$id = preg_replace( '/[^a-z0-9]/i', '', (string) $outlet_id );

		return '' === $id ? self::POHRANA_ROW : self::POHRANA_ROW . '.' . strtolower( $id );
	}

	/**
	 * Our queries in this request are not served from a database query cache (W3 Total Cache's dbcache honours
	 * DONOTCACHEDB through dbcache.reject.constants; also others that do). Called by the build (the rebuild lock), by
	 * the counter's writer and by the admin screens that show it; on a public visit only when it reaches the rebuild
	 * check (CX_Sidrena_Export::rebuild_running()), never on an ordinary page view (Rook, 2925). As CX-SID-124's import.
	 */
	public static function no_db_cache() {
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
	}

	/**
	 * The archive is written by the build itself (publish(), under the rebuild lock). Kept for callers that still run
	 * it after regenerate_public_xml(): the webshop's newest published file.
	 *
	 * @return string|WP_Error Path or error.
	 */
	public function archive_public_xml() {
		$meta = CX_Sidrena_Export::instance()->get_public_meta();
		$name = isset( $meta['filename'] ) ? $this->sanitize_archive_name( $meta['filename'] ) : '';
		$path = '' !== $name ? trailingslashit( $this->get_archive_dir() ) . $name : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return new WP_Error( 'sidrena_xml_archive_source', __( 'The public price list does not exist yet.', 'cx-sidrena' ) );
		}

		return $path;
	}

	/**
	 * Archived feed files, newest first. Older days keep their original xml/csv type.
	 *
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int}>
	 */
	public function get_archive_files() {
		$dir   = $this->get_archive_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}

		$found = glob(
			trailingslashit( $dir ) . '*.{xml,csv}',
			defined( 'GLOB_BRACE' ) ? GLOB_BRACE : 0
		);
		if ( ! is_array( $found ) || array() === $found ) {
			$found = array_merge(
				glob( trailingslashit( $dir ) . '*.xml' ) ?: array(),
				glob( trailingslashit( $dir ) . '*.csv' ) ?: array()
			);
		}

		$files = array();
		foreach ( $found as $path ) {
			$base = basename( $path );
			$ext  = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'xml', 'csv' ), true ) ) {
				continue;
			}

			$files[] = array(
				'date'  => $this->archive_file_date( $base ),
				'ext'   => $ext,
				'name'  => $base,
				'path'  => $path,
				'url'   => CX_Sidrena_Feed_Files::instance()->get_archive_file_url( $base, $ext ),
				'bytes' => (int) filesize( $path ),
				'stamp' => $this->archive_file_timestamp( $base, $path ),
			);
		}

		usort( $files, array( $this, 'compare_newest_first' ) );

		return $files;
	}

	/**
	 * Order of archive files, newest first: by time; two files in the same minute by storage number, the higher is the
	 * later one ("_10_" sorts before "_9_"); then by name.
	 *
	 * @param array<string, mixed> $a File.
	 * @param array<string, mixed> $b File.
	 * @return int
	 */
	public function compare_newest_first( $a, $b ) {
		$cmp = (int) $b['stamp'] <=> (int) $a['stamp'];
		if ( 0 !== $cmp ) {
			return $cmp;
		}
		$cmp = $this->archive_pohrana_from_name( $b['name'] ) <=> $this->archive_pohrana_from_name( $a['name'] );
		if ( 0 !== $cmp ) {
			return $cmp;
		}

		return strcmp( $b['name'], $a['name'] );
	}

	/**
	 * Archive bucket of a file, the price list it is a file of (public_files()): its owner's (file_owner()), goods and
	 * services apart, or legacy for names without a code. Goods keep the group of the files from before the Pravilnik.
	 *
	 * @param string     $name Basename.
	 * @param array|null $ctx  owner_context(), null = now.
	 * @return string webshop[:usluge] | place:<code>[:usluge] | oznaka:<code>[:usluge] | all[:usluge] | legacy
	 */
	public function archive_group_key( $name, $ctx = null ) {
		return $this->owner_group_key( $this->file_owner( $name, $ctx ) );
	}

	/**
	 * @param array{owner: string, code: string, vrsta: string} $o file_owner().
	 * @return string
	 */
	private function owner_group_key( $o ) {
		if ( 'legacy' === $o['owner'] ) {
			return 'legacy';
		}
		if ( 'all' === $o['owner'] ) {
			$key = 'all'; // the price list of all places of sale (CX-SID-113)
		} else {
			$key = 'webshop' === $o['owner'] ? 'webshop' : ( 'place' === $o['owner'] ? 'place:' : 'oznaka:' ) . $o['code'];
		}

		return $key . ( 'usluga' === $o['vrsta'] ? ':usluge' : '' );
	}

	/**
	 * Who can have written a file now, for file_owner() (CX-SID-143): the webshop's code, and for the webshop and each
	 * place (by its code) the kind and address pairs its names can carry. That is the pair it writes now and the ones it
	 * wrote before its kind or address was set: until 0.3.0 a missing kind was "webshop" and a missing address the
	 * shop's (default_file_address()), for the webshop and the places alike. Pair = kind "\0" address, as in the name.
	 * Built per call, as settings and places change; pass it to file_owner() for a whole list.
	 *
	 * @return array{web: string, web_pairs: array<string, bool>, places: array<string, array<string, bool>>}
	 */
	public function owner_context() {
		$settings = CX_Sidrena_Settings::instance();
		$default  = $settings->sanitize_file_part( $settings->default_file_address(), 'webshop' );
		$pairs    = static function ( $oblik, $adresa ) use ( $default ) {
			$out = array( $oblik . "\0" . $adresa => true );
			foreach ( array( $oblik, 'webshop' ) as $o ) {
				foreach ( array( $adresa, $default ) as $a ) {
					if ( '' !== $o && '' !== $a ) {
						$out[ $o . "\0" . $a ] = true;
					}
				}
			}
			return $out;
		};

		$places = array();
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $row ) {
			$places[ (string) $row['oznaka'] ] = $pairs(
				$settings->sanitize_file_part( isset( $row['oblik'] ) ? $row['oblik'] : '', '' ),
				$settings->sanitize_file_part( isset( $row['adresa'] ) ? $row['adresa'] : '', '' )
			);
		}
		$adresa = $settings->sanitize_file_part( $settings->get( 'file_adresa', '' ), '' );

		return array(
			'web'       => $this->scope_oznaka( null ),
			'web_pairs' => $pairs( $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' ), '' !== $adresa ? $adresa : $default ),
			'places'    => $places,
		);
	}

	/**
	 * Whose an archive file is (CX-SID-143; art. 6(3) of NN 132/2026-1584: the kind and address in the name say whose
	 * it is). The names never change (art. 3(6)): after the webshop takes another code, its old files keep the old one.
	 * - A code no place has: the webshop's when it is the webshop's code, else only that code's (an old code, a
	 *   deleted place: as before 0.3.1).
	 * - A code a place has: the webshop's when only the webshop can have written its kind and address (an old code of
	 *   the webshop's, or the webshop in a collision with a place MJDD left with its code); the place's otherwise (also
	 *   under an earlier address of the place, which is not kept). A file both could have written (for example
	 *   "webshop" and the shop's address, which a place without kind and address wrote until 0.3.0) is attributed to
	 *   neither under the webshop's code or 001 (MJDD's code for a place without one): only that code's (Kai 143,
	 *   decision e: a wrongly attributed price is worse than a neutral heading). Under another code of a place it stays
	 *   the place's, as before.
	 *
	 * @param string     $name Basename.
	 * @param array|null $ctx  owner_context(), null = now.
	 * @return array{owner: string, code: string, vrsta: string} owner webshop|place|oznaka (only the code's)|all (the price
	 *         list of all places of sale, CX-SID-113)|legacy.
	 */
	public function file_owner( $name, $ctx = null ) {
		$p    = $this->parse_name( $name );
		if ( null !== $p && self::ALL_PLACES === $p['oznaka'] ) {
			return array( 'owner' => 'all', 'code' => self::ALL_PLACES, 'vrsta' => $p['vrsta'] ); // CX-SID-113
		}
		$code = null === $p ? '' : CX_Sidrena_Settings::instance()->normalize_oznaka( (string) $p['oznaka'] );
		if ( '' === $code ) {
			return array( 'owner' => 'legacy', 'code' => '', 'vrsta' => 'proizvod' );
		}
		$ctx  = null === $ctx ? $this->owner_context() : $ctx;
		$out  = array( 'owner' => 'oznaka', 'code' => $code, 'vrsta' => $p['vrsta'] );
		if ( ! isset( $ctx['places'][ $code ] ) ) {
			$out['owner'] = $code === $ctx['web'] ? 'webshop' : 'oznaka';
			return $out;
		}

		$pair  = $p['oblik'] . "\0" . $p['adresa'];
		$web   = isset( $ctx['web_pairs'][ $pair ] );
		$place = isset( $ctx['places'][ $code ][ $pair ] );
		if ( $web && $place ) {
			$out['owner'] = $code === $ctx['web'] || '001' === $code ? 'oznaka' : 'place';
		} else {
			$out['owner'] = $web ? 'webshop' : 'place';
		}

		return $out;
	}

	/**
	 * Object code from an archive file name (parse_name()), or empty.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_oznaka_from_name( $name ) {
		$p = $this->parse_name( $name );

		return null === $p ? '' : (string) $p['oznaka'];
	}

	/**
	 * Validate an archive date (d-m-Y).
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	public function sanitize_archive_date( $date ) {
		$date = is_string( $date ) ? $date : '';
		if ( ! preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $date, $match ) ) {
			return '';
		}

		if ( ! checkdate( (int) $match[2], (int) $match[1], (int) $match[3] ) ) {
			return '';
		}

		return $match[1] . '-' . $match[2] . '-' . $match[3];
	}

	/**
	 * Legacy sidrena-cijena_DD-MM-YYYY names can sit in a pretty URL path.
	 * Ministry names have spaces and a time colon, which Apache/nginx/WordPress
	 * often fail to route, so those files are opened with a query argument.
	 *
	 * @param string $name Basename.
	 * @return bool
	 */
	public function archive_name_allows_pretty_path( $name ) {
		return (bool) preg_match( '/^[A-Za-z0-9._-]+\.(xml|csv)$/', $name );
	}

	/**
	 * Safe archive basename.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	public function sanitize_archive_name( $name ) {
		$name = is_string( $name ) ? $name : '';
		$name = rawurldecode( $name );
		$name = str_replace( '\\', '/', $name );
		$name = basename( $name );

		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return '';
		}

		if ( $this->archive_name_allows_pretty_path( $name ) ) {
			return $name;
		}

		if ( ! preg_match( '/^[\p{L}\p{N} .,\'_\-:]+\.(xml|csv)$/u', $name ) ) {
			return '';
		}

		if ( strlen( $name ) > 240 ) {
			return '';
		}

		return $name;
	}

	/**
	 * Display date from an archive filename.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_file_date( $name ) {
		if ( preg_match( '/_(\d{2})\.(\d{2})\.(\d{4})_\d{2}[:\-]\d{2}\.(xml|csv)$/', $name, $match ) ) {
			return $match[1] . '-' . $match[2] . '-' . $match[3];
		}

		if ( preg_match( '/_(\d{8})_\d{4}\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!Ymd', $match[1], self::legal_timezone() );
			return $parsed instanceof DateTime ? $parsed->format( 'd-m-Y' ) : $match[1];
		}

		if ( preg_match( '/_(\d{2}-\d{2}-\d{4})\.(xml|csv)$/', $name, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * Unix timestamp for sorting an archive file.
	 *
	 * @param string $name Basename.
	 * @param string $path Absolute path.
	 * @return int
	 */
	public function archive_file_timestamp( $name, $path ) {
		if ( preg_match( '/_(\d{2})\.(\d{2})\.(\d{4})_(\d{2})[:\-](\d{2})\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!d.m.Y H:i', $match[1] . '.' . $match[2] . '.' . $match[3] . ' ' . $match[4] . ':' . $match[5], self::legal_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $parsed->getTimestamp();
			}
		}

		if ( preg_match( '/_(\d{8})_(\d{4})\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!YmdHi', $match[1] . $match[2], self::legal_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $parsed->getTimestamp();
			}
		}

		$legacy = $this->archive_file_date( $name );
		if ( '' !== $legacy ) {
			$stamp = $this->archive_date_timestamp( $legacy );
			if ( $stamp ) {
				return $stamp;
			}
		}

		return file_exists( $path ) ? (int) filemtime( $path ) : 0;
	}

	/**
	 * Unix timestamp for an archive date (d-m-Y).
	 *
	 * @param string $date Archive date.
	 * @return int
	 */
	public function archive_date_timestamp( $date ) {
		$parsed = DateTime::createFromFormat( '!d-m-Y', $date, self::legal_timezone() );
		return $parsed instanceof DateTime ? $parsed->getTimestamp() : 0;
	}

	/**
	 * Storage number from a legal archive basename, or 0 when the name has none.
	 *
	 * @param string $name Basename.
	 * @return int
	 */
	public function archive_pohrana_from_name( $name ) {
		$p = $this->parse_name( $name );

		return null === $p || '' === $p['oznaka'] ? 0 : (int) $p['pohrana'];
	}

	/**
	 * Normalized object code from an archive basename, or empty when the name has none.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_file_oznaka( $name ) {
		return CX_Sidrena_Settings::instance()->normalize_oznaka( $this->archive_oznaka_from_name( $name ) );
	}

	/**
	 * Archive files the public sees (CX-SID-158, art. 3(6) of NN 132/2026-1584): of each price list (its owner,
	 * archive_group_key()) the current file, its newest, and every file replaced less than PUBLIC_DAYS days ago. A file is
	 * replaced by the next file of its price list, and stays public to the end of the 15th day after the day of that:
	 * whole calendar days on the legal clock, as the law counts (across the spring clock change an hour less than 15 x 24
	 * hours). Nothing goes while a price list gets no new file: its current file is the price list. A file of only its code (143, decision e) is replaced by the next file of that code and kind, whoever
	 * wrote it; a name from before the legal names (no code) by the next file of the shop. Newest first.
	 *
	 * @param array<int, array<string, mixed>>|null $files Archive files, null = all in the archive folder.
	 * @param int|null                              $now   Unix time, null = now.
	 * @return array<int, array<string, mixed>>
	 */
	public function public_files( $files = null, $now = null ) {
		$files = null === $files ? $this->get_archive_files() : $files;
		$now   = null === $now ? time() : (int) $now;
		$ctx   = $this->owner_context();
		$order = array_keys( $files );
		usort(
			$order,
			function ( $a, $b ) use ( $files ) {
				return $this->compare_newest_first( $files[ $a ], $files[ $b ] );
			}
		);

		// The price list of all places of sale (CX-SID-113) replaces the webshop's and every place's of its kind.
		$owners = array();
		$all    = array( 'proizvod' => array(), 'usluga' => array() );
		foreach ( $files as $i => $file ) {
			$owners[ $i ] = $this->file_owner( $file['name'], $ctx );
			if ( 'all' === $owners[ $i ]['owner'] ) {
				$all[ $owners[ $i ]['vrsta'] ][] = $file;
			}
		}

		// Newest to oldest: per price list, the file seen just before is the next newer one, the one that replaced this one.
		$next = array();
		$keep = array();
		foreach ( $order as $i ) {
			$file    = $files[ $i ];
			$o       = $owners[ $i ];
			$key     = $this->owner_group_key( $o );
			$by_code = 'legacy' === $key ? $key : $this->owner_group_key( array( 'owner' => 'oznaka' ) + $o );
			$list    = 'legacy' === $key ? '*' : ( 'oznaka' === $o['owner'] ? $by_code : $key );
			$by      = isset( $next[ $list ] ) ? $next[ $list ] : null;
			if ( 'all' === $o['owner'] ) {
				// Also the webshop's next file replaces it: one storage counter, so their order is right within a minute.
				$web = 'usluga' === $o['vrsta'] ? 'webshop:usluge' : 'webshop';
				$by  = isset( $next[ $web ] ) && ( null === $by || $next[ $web ] < $by ) ? $next[ $web ] : $by;
			} elseif ( 'legacy' !== $key ) {
				$za = $this->all_places_after( $file, $all[ $o['vrsta'] ] );
				$by = null !== $za && ( null === $by || $za < $by ) ? $za : $by;
			}
			if ( null === $by || $now < $this->public_until( $by ) ) {
				$keep[ $i ] = true;
			}
			foreach ( array_unique( array( $key, $by_code, '*' ) ) as $k ) {
				$next[ $k ] = (int) $file['stamp'];
			}
		}

		$out = array();
		foreach ( $files as $i => $file ) {
			if ( isset( $keep[ $i ] ) ) {
				$out[] = $file;
			}
		}

		return $out;
	}

	/**
	 * When the price list of all places of sale first replaced a file of the webshop or a place (CX-SID-113): the oldest
	 * of its files of that kind that came after it, or null. Within one minute the names cannot tell (a place's numbers
	 * are its own), so the files' own times decide, and a tie is not "after": a file stays public rather than go early.
	 *
	 * @param array<string, mixed>             $file The file.
	 * @param array<int, array<string, mixed>> $all  The files of all places of the same kind.
	 * @return int|null Unix time.
	 */
	private function all_places_after( $file, $all ) {
		$first = null;
		foreach ( $all as $za ) {
			$after = (int) $za['stamp'] > (int) $file['stamp'];
			if ( ! $after && (int) $za['stamp'] === (int) $file['stamp'] && isset( $za['path'], $file['path'] ) && is_file( $za['path'] ) && is_file( $file['path'] ) ) {
				$after = filemtime( $za['path'] ) > filemtime( $file['path'] );
			}
			if ( $after && ( null === $first || (int) $za['stamp'] < $first ) ) {
				$first = (int) $za['stamp'];
			}
		}

		return $first;
	}

	/**
	 * Record a switch of the price list of all places of sale, on or off (CX-SID-113): the newest archive file of each
	 * name now. None of them becomes current again (publish()), so the switch always leaves new files with higher
	 * numbers, also when nothing in the price list changed (Rook 4030, Q4): the old name is never live again.
	 *
	 * @param bool $on Switched on.
	 */
	public function mark_switch( $on ) {
		$seen  = array();
		$names = array();
		foreach ( $this->get_archive_files() as $file ) {
			$key = $this->name_key( $file['name'] );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$names[]      = $file['name'];
			}
		}
		update_option( self::SWITCH_OPTION, array( 'at' => time(), 'on' => (bool) $on, 'names' => $names ), false );
	}

	/**
	 * Whether a file was the newest of its name at the last switch (mark_switch()).
	 *
	 * @param string $name Basename.
	 * @return bool
	 */
	private function switched_from( $name ) {
		$switch = get_option( self::SWITCH_OPTION, array() );

		return is_array( $switch ) && isset( $switch['names'] ) && in_array( $name, (array) $switch['names'], true );
	}

	/**
	 * Whether one archive file is public now.
	 *
	 * @param string $name Basename.
	 * @return bool
	 */
	public function is_public( $name ) {
		foreach ( $this->public_files() as $file ) {
			if ( $file['name'] === $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Until when a file replaced at $replaced is public: midnight (legal clock) after the 15th day after that day.
	 *
	 * @param int $replaced Unix time of the file that replaced it.
	 * @return int
	 */
	private function public_until( $replaced ) {
		$day = new DateTime( '@' . (int) $replaced );
		$day->setTimezone( self::legal_timezone() );
		$day->setTime( 0, 0, 0 );
		$day->modify( '+' . ( self::PUBLIC_DAYS + 1 ) . ' days' );

		return $day->getTimestamp();
	}

	/**
	 * The folder of kept files, found by its prefix (its name is random, so nobody guesses it; a reinstall finds the
	 * same one). Created on request, with a deny rule for Apache and IIS and an empty index.
	 *
	 * @param bool $create Create it when there is none.
	 * @return string Path, or '' when there is none or it cannot be made.
	 */
	public function kept_dir( $create = false ) {
		$base = CX_Sidrena_Feed_Files::instance()->get_public_dir();
		if ( '' === $base ) {
			return '';
		}

		if ( $create ) {
			// The folder above the kept one, so a listing never shows its name (Argus N1, CX-SID-27).
			CX_Sidrena_Feed_Files::instance()->ensure_dir_index( $base );
		}

		$found = glob( trailingslashit( $base ) . self::KEPT_PREFIX . '*', defined( 'GLOB_ONLYDIR' ) ? GLOB_ONLYDIR : 0 );
		if ( is_array( $found ) && array() !== $found ) {
			sort( $found );
			foreach ( $found as $dir ) {
				if ( is_dir( $dir ) ) {
					return $dir;
				}
			}
		}

		if ( ! $create ) {
			return '';
		}

		$dir = trailingslashit( $base ) . self::KEPT_PREFIX . strtolower( wp_generate_password( 20, false ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		file_put_contents( $dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
		// phpcs:enable

		return $dir;
	}

	/**
	 * Move archive files that are no longer public into the kept folder, compressed when zlib is there. The original
	 * goes only after the kept copy reads back to the same sha256. A copy that does not is removed and the original
	 * stays public (logged), so a file is never lost.
	 *
	 * @return int Files moved.
	 */
	public function move_old() {
		$files  = $this->get_archive_files();
		$public = array();
		foreach ( $this->public_files( $files ) as $file ) {
			$public[ $file['name'] ] = true;
		}

		$moved = 0;
		$dir   = '';
		foreach ( $files as $file ) {
			if ( isset( $public[ $file['name'] ] ) ) {
				continue;
			}
			if ( '' === $dir ) {
				$dir = $this->kept_dir( true );
				if ( '' === $dir ) {
					return $moved;
				}
			}
			$moved += $this->keep_one( $file['path'], $dir ) ? 1 : 0;
		}

		return $moved;
	}

	/**
	 * @param string $path Archive file.
	 * @param string $dir  Kept folder.
	 * @return bool Moved.
	 */
	private function keep_one( $path, $dir ) {
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $body ) ) {
			return false;
		}

		$sha  = hash( 'sha256', $body );
		$gz   = function_exists( 'gzencode' ) && function_exists( 'gzdecode' );
		$dest = trailingslashit( $dir ) . basename( $path ) . ( $gz ? '.gz' : '' );
		$ff   = CX_Sidrena_Feed_Files::instance();

		if ( file_exists( $dest ) ) {
			// Kept before (a move cut short after the copy): the original goes only if that copy is this file.
			$ok = $sha === $this->kept_sha( $dest );
		} else {
			$ok = false !== $ff->write_atomic( $dest, $gz ? gzencode( $body, 9 ) : $body ) && $sha === $this->kept_sha( $dest );
			if ( ! $ok && file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
		}

		if ( ! $ok ) {
			CX_Sidrena_Plugin::log_exception( new RuntimeException( 'CX Sidrena: an archive file was not moved, its kept copy did not read back the same: ' . basename( $path ) ) );
			return false;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}

	/**
	 * sha256 of what a kept file holds (decompressed), or '' when it cannot be read.
	 *
	 * @param string $path Kept file.
	 * @return string
	 */
	private function kept_sha( $path ) {
		$body = $this->kept_body( $path );

		return is_string( $body ) ? hash( 'sha256', $body ) : '';
	}

	/**
	 * What a kept file holds, decompressed.
	 *
	 * @param string $path Kept file.
	 * @return string|false
	 */
	private function kept_body( $path ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) ) {
			return false;
		}
		if ( '.gz' !== substr( $path, -3 ) ) {
			return $raw;
		}

		return function_exists( 'gzdecode' ) ? @gzdecode( $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Kept files, newest first. name is the archive name (without .gz), bytes the size of the file as published.
	 *
	 * @return array<int, array{name:string,path:string,date:string,stamp:int,bytes:int,ext:string}>
	 */
	public function kept_files() {
		$dir = $this->kept_dir();
		if ( '' === $dir ) {
			return array();
		}

		$found = array_merge(
			glob( trailingslashit( $dir ) . '*.xml' ) ?: array(),
			glob( trailingslashit( $dir ) . '*.csv' ) ?: array(),
			glob( trailingslashit( $dir ) . '*.xml.gz' ) ?: array(),
			glob( trailingslashit( $dir ) . '*.csv.gz' ) ?: array()
		);

		$files = array();
		foreach ( $found as $path ) {
			$gz   = '.gz' === substr( $path, -3 );
			$name = basename( $path, $gz ? '.gz' : '' );
			$size = (int) filesize( $path );
			if ( $gz && $size >= 18 ) {
				// The last four bytes of a gzip file are the size of what it holds (mod 4 GB).
				$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $fh ) {
					fseek( $fh, -4, SEEK_END );
					$tail = unpack( 'V', (string) fread( $fh, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$size = is_array( $tail ) ? (int) $tail[1] : $size;
				}
			}
			$files[] = array(
				'name'  => $name,
				'path'  => $path,
				'date'  => $this->archive_file_date( $name ),
				'stamp' => $this->archive_file_timestamp( $name, $path ),
				'bytes' => $size,
				'ext'   => strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ),
			);
		}

		usort(
			$files,
			function ( $a, $b ) {
				$cmp = $b['stamp'] <=> $a['stamp'];
				return 0 !== $cmp ? $cmp : $this->archive_pohrana_from_name( $b['name'] ) <=> $this->archive_pohrana_from_name( $a['name'] );
			}
		);

		return $files;
	}

	/**
	 * Kept files by month on the legal clock (Y-m), newest month first, in each month newest file first.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function kept_months() {
		$months = array();
		foreach ( $this->kept_files() as $file ) {
			$months[ $file['stamp'] ? self::legal_date( 'Y-m', $file['stamp'] ) : '0000-00' ][] = $file;
		}
		krsort( $months );

		return $months;
	}

	/**
	 * One kept file as published.
	 *
	 * @param string $name Archive name (without .gz).
	 * @return string|WP_Error
	 */
	public function read_kept( $name ) {
		$name = $this->sanitize_archive_name( $name );
		foreach ( '' !== $name ? $this->kept_files() : array() as $file ) {
			if ( $file['name'] === $name ) {
				$body = $this->kept_body( $file['path'] );
				if ( is_string( $body ) ) {
					return $body;
				}
				break;
			}
		}

		return new WP_Error( 'cx_sidrena_kept_missing', __( 'Archive price list not found.', 'cx-sidrena' ) );
	}

	/**
	 * Days a kept file is kept (archive_keep_days): 0 = for ever, else at least 30.
	 *
	 * @return int
	 */
	public static function keep_days() {
		return CX_Sidrena_Settings::instance()->sanitize_keep_days( CX_Sidrena_Settings::instance()->get( 'archive_keep_days', CX_Sidrena_Settings::KEEP_DAYS_DEFAULT ) );
	}

	/**
	 * Delete kept files older than the owner's period (the morning build). Nothing else deletes them.
	 *
	 * @param int|null $now Unix time, null = now.
	 * @return int Files deleted.
	 */
	public function purge_kept( $now = null ) {
		$days = self::keep_days();
		if ( 0 === $days ) {
			return 0;
		}

		$limit = new DateTime( '@' . ( null === $now ? time() : (int) $now ) );
		$limit->setTimezone( self::legal_timezone() );
		$limit->setTime( 0, 0, 0 );
		$limit->modify( '-' . $days . ' days' );

		$deleted = 0;
		foreach ( $this->kept_files() as $file ) {
			if ( $file['stamp'] > 0 && $file['stamp'] < $limit->getTimestamp() ) {
				wp_delete_file( $file['path'] );
				$deleted += file_exists( $file['path'] ) ? 0 : 1;
			}
		}

		return $deleted;
	}

	/**
	 * The morning's upkeep: files no longer public are kept, kept files past their period go.
	 */
	public function maintain() {
		$this->move_old();
		$this->purge_kept();
	}

	/**
	 * Link that sends one kept file (Settings).
	 *
	 * @param string $name Archive name.
	 * @return string
	 */
	public static function kept_url( $name ) {
		return wp_nonce_url( add_query_arg( array( 'action' => self::KEPT_ACTION, 'name' => rawurlencode( $name ) ), admin_url( 'admin-post.php' ) ), self::KEPT_ACTION );
	}

	/**
	 * admin-post: one kept file to the owner, as it was published.
	 */
	public static function download_kept() {
		check_admin_referer( self::KEPT_ACTION );
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cx-sidrena' ), '', array( 'response' => 403 ) );
		}

		$name = isset( $_GET['name'] ) ? (string) wp_unslash( $_GET['name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_archive_name() in read_kept()
		$body = self::instance()->read_kept( $name );
		if ( is_wp_error( $body ) ) {
			wp_die( esc_html( $body->get_error_message() ), '', array( 'response' => 404, 'back_link' => true ) );
		}

		$name = self::instance()->sanitize_archive_name( $name );
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ? 'text/csv' : 'application/xml' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the file as published.
		exit;
	}

	/**
	 * File list for the [sidrena-arhiva] shortcode. Name and date only.
	 *
	 * An oznaka keeps the files of whoever has that code (file_owner(), CX-SID-143): the webshop's code its files, also
	 * those under an old code of its; a place's code the place's files. A code that the webshop and a place both have
	 * keeps every file with it, as the code cannot say whose list is asked for; a code nobody has, its files. Names the
	 * parser does not recognize stay in the unfiltered list only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		$files  = $this->public_files();
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' !== $oznaka ) {
			$ctx     = $this->owner_context();
			$both    = $oznaka === $ctx['web'] && isset( $ctx['places'][ $oznaka ] );
			$want    = $oznaka === $ctx['web'] ? 'webshop' : ( isset( $ctx['places'][ $oznaka ] ) ? 'place' : 'oznaka' );
			$matched = array();
			foreach ( $files as $file ) {
				$o = $this->file_owner( isset( $file['name'] ) ? (string) $file['name'] : '', $ctx );
				if ( 'legacy' === $o['owner'] ) {
					continue;
				}
				// The price list of all places of sale is every place's and the webshop's (CX-SID-113).
				if ( 'all' === $o['owner'] || ( $both ? $o['code'] === $oznaka : ( $o['owner'] === $want && ( 'webshop' === $want || $o['code'] === $oznaka ) ) ) ) {
					$matched[] = $file;
				}
			}
			$files = $matched;
		}

		if ( array() === $files ) {
			return '<p class="sidrena-arhiva-empty">' . esc_html__( 'The archive is empty.', 'cx-sidrena' ) . '</p>';
		}

		$html = '<ol class="sidrena-arhiva">';
		foreach ( $files as $file ) {
			$stamp = isset( $file['stamp'] ) ? (int) $file['stamp'] : 0;
			$label = $stamp ? self::legal_date( 'd.m.Y. H:i', $stamp ) : ( isset( $file['date'] ) ? (string) $file['date'] : '' );
			$name  = isset( $file['name'] ) ? (string) $file['name'] : '';
			$url   = isset( $file['url'] ) ? (string) $file['url'] : '';
			$html .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			if ( '' !== $label ) {
				$html .= '<span>' . esc_html( $label ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		return $html;
	}

	/**
	 * Last generation meta for one outlet.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>
	 */
	public function get_outlet_meta( $oznaka ) {
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		$stored = CX_Sidrena_Export::instance()->get_public_meta();
		if ( '' === $oznaka || empty( $stored['outlets'] ) || ! is_array( $stored['outlets'] ) ) {
			return array();
		}

		if ( isset( $stored['outlets'][ $oznaka ] ) && is_array( $stored['outlets'][ $oznaka ] ) ) {
			return $stored['outlets'][ $oznaka ];
		}

		return array();
	}
}
