<?php
/**
 * Legal filenames and the 30-day public archive.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legal filenames and the 30-day public archive.
 */
class CX_Sidrena_Archive {

	/**
	 * The clock of the law the plugin is for.
	 */
	const LEGAL_TIMEZONE = 'Europe/Zagreb';

	/**
	 * Days before the newest file of a price list that its files stay public (nacrt 10332 čl. 3 st. 5).
	 */
	const PUBLIC_DAYS = 30;

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
	 * Archive name from the 18 September 2026 ministry example:
	 * prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml
	 *
	 * Spaces, hyphens, and the typed case stay. Date is DD.MM.YYYY and time
	 * is HH:MM on the legal clock (legal_timezone). A Windows disk cannot store the colon,
	 * so that server writes 07-45 instead.
	 *
	 * @param string              $format    xml|csv.
	 * @param int                 $timestamp Unix time of publication, 0 = now.
	 * @param int                 $pohrana   Storage sequence, 0 = next unused number (does not consume).
	 * @param array<string,mixed>|null $outlet Outlet row, or null for the single shop.
	 * @return string
	 */
	public function legal_filename( $format = '', $timestamp = 0, $pohrana = 0, $outlet = null ) {
		$settings = CX_Sidrena_Settings::instance();
		$format   = '' === $format ? CX_Sidrena_Feed_Files::instance()->export_format() : CX_Sidrena_Feed_Files::instance()->sanitize_ext( $format );
		$tz       = self::legal_timezone();
		$dt       = $timestamp > 0 ? new DateTime( '@' . (int) $timestamp ) : new DateTime( 'now', $tz );
		$dt->setTimezone( $tz );

		if ( is_array( $outlet ) ) {
			$oblik  = $settings->sanitize_file_part( isset( $outlet['oblik'] ) ? $outlet['oblik'] : '', 'webshop' );
			$adresa = $settings->sanitize_file_part( isset( $outlet['adresa'] ) ? $outlet['adresa'] : '', '' );
			$oznaka = $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
			$seq    = $pohrana > 0 ? (int) $pohrana : $settings->sanitize_pohrana( isset( $outlet['pohrana'] ) ? $outlet['pohrana'] : 1 );
		} else {
			$oblik  = $settings->sanitize_file_part( $settings->get( 'file_oblik', 'webshop' ), 'webshop' );
			$adresa = $settings->sanitize_file_part( $settings->get( 'file_adresa', '' ), '' );
			$oznaka = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
			$seq    = $pohrana > 0 ? (int) $pohrana : $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
		}

		if ( '' === $adresa ) {
			$adresa = $settings->sanitize_file_part( $settings->default_file_address(), 'webshop' );
		}
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$name = $oblik . '_' . $adresa . '_' . $oznaka . '_' . (string) $seq . '_' . $dt->format( 'd.m.Y' ) . '_' . $dt->format( 'H:i' ) . '.' . $format;

		return $this->archive_name_for_disk( $name );
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
	 * @return array{filename:string,pohrana:int,generated:int,published:int,new:bool,bytes:int}|WP_Error
	 *         generated = this build; published = the moment of the file now live (its name's time).
	 */
	public function publish( $body, $format, $outlet = null, $timestamp = 0 ) {
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
		$live   = $ff->get_public_path( $format, is_array( $outlet ) && isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
		$newest = $this->newest_file( $oznaka );

		if ( null !== $newest && $this->is_current( $newest, $body, $format, $outlet, $timestamp ) ) {
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

		$pohrana = $this->next_pohrana( $oznaka, $outlet );
		$name    = $this->legal_filename( $format, $timestamp, $pohrana, $outlet );
		for ( $tries = 0; $this->name_taken( $name ) && $tries < 1000; $tries++ ) {
			++$pohrana;
			$name = $this->legal_filename( $format, $timestamp, $pohrana, $outlet );
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

		if ( is_array( $outlet ) && isset( $outlet['id'] ) ) {
			CX_Sidrena_Poslovnice::instance()->set_pohrana( $outlet['id'], $pohrana + 1 );
		} else {
			CX_Sidrena_Settings::instance()->save( array( 'file_pohrana' => $pohrana + 1 ) );
		}

		// The new file can push older ones out of the public 30 days.
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
	private function is_current( $newest, $body, $format, $outlet, $timestamp ) {
		if ( $newest['ext'] !== $format ) {
			return false;
		}
		if ( $this->name_key( $newest['name'] ) !== $this->name_key( $this->legal_filename( $format, $timestamp, 1, $outlet ) ) ) {
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
	 * What a file name says besides storage number, date and time: oblik_adresa_oznaka and the format.
	 *
	 * @param string $name Legal basename.
	 * @return string
	 */
	public function name_key( $name ) {
		$parts = explode( '_', (string) pathinfo( $name, PATHINFO_FILENAME ) );

		return implode( '_', array_slice( $parts, 0, 3 ) ) . '.' . strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
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
	 * Object code a scope's files carry.
	 *
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return string
	 */
	private function scope_oznaka( $outlet ) {
		$settings = CX_Sidrena_Settings::instance();
		$oznaka   = is_array( $outlet )
			? $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' )
			: $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );

		return '' !== $oznaka ? $oznaka : '001';
	}

	/**
	 * Newest archive file of an object code.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>|null
	 */
	private function newest_file( $oznaka ) {
		foreach ( $this->get_archive_files() as $file ) {
			if ( $this->archive_oznaka_from_name( $file['name'] ) === $oznaka ) {
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
	 * @param string                    $oznaka Object code.
	 * @param array<string, mixed>|null $outlet Outlet, or null for the webshop.
	 * @return int
	 */
	public function next_pohrana( $oznaka, $outlet = null ) {
		$settings = CX_Sidrena_Settings::instance();
		$stored   = is_array( $outlet )
			? $settings->sanitize_pohrana( isset( $outlet['pohrana'] ) ? $outlet['pohrana'] : 1 )
			: $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
		$highest  = 0;
		foreach ( $this->all_archive_names() as $name ) {
			if ( $this->archive_oznaka_from_name( $name ) === $oznaka ) {
				$highest = max( $highest, $this->archive_pohrana_from_name( $name ) );
			}
		}

		return max( $stored, $highest + 1 );
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

		usort(
			$files,
			function ( $a, $b ) {
				$cmp = $b['stamp'] <=> $a['stamp'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				// Two files in the same minute: the higher storage number is the later one ("_10_" sorts before "_9_").
				$cmp = $this->archive_pohrana_from_name( $b['name'] ) <=> $this->archive_pohrana_from_name( $a['name'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}

				return strcmp( $b['name'], $a['name'] );
			}
		);

		return $files;
	}

	/**
	 * Archive bucket: object code from the legal filename, or legacy for older names.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_group_key( $name ) {
		$oznaka = $this->archive_oznaka_from_name( $name );
		return '' !== $oznaka ? 'oznaka:' . $oznaka : 'legacy';
	}

	/**
	 * Object code from oblik_adresa_oznaka_pohrana_datum_vrijeme.
	 *
	 * @param string $name Basename.
	 * @return string
	 */
	public function archive_oznaka_from_name( $name ) {
		$base  = (string) pathinfo( $name, PATHINFO_FILENAME );
		$parts = explode( '_', $base );
		if ( 6 !== count( $parts ) ) {
			return '';
		}

		$time = $parts[5];
		$date = $parts[4];
		$old  = (bool) preg_match( '/^\d{8}$/', $date ) && (bool) preg_match( '/^\d{4}$/', $time );
		$new  = (bool) preg_match( '/^\d{2}\.\d{2}\.\d{4}$/', $date ) && (bool) preg_match( '/^\d{2}[:\-]\d{2}$/', $time );
		if ( ! $old && ! $new ) {
			return '';
		}

		return (string) $parts[2];
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
		if ( '' === $this->archive_oznaka_from_name( $name ) ) {
			return 0;
		}

		$base  = (string) pathinfo( $name, PATHINFO_FILENAME );
		$parts = explode( '_', $base );

		return isset( $parts[3] ) ? absint( $parts[3] ) : 0;
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
	 * Archive files the public sees: of each price list (object code), every file from the 30 days before its newest
	 * one. The anchor is the last publication, not today, so nothing goes while a shop publishes nothing. Names from
	 * before the legal names have no code: their anchor is the newest file of the shop. Newest first.
	 *
	 * @param array<int, array<string, mixed>>|null $files Archive files, null = all in the archive folder.
	 * @return array<int, array<string, mixed>>
	 */
	public function public_files( $files = null ) {
		$files  = null === $files ? $this->get_archive_files() : $files;
		$newest = array();
		$all    = 0;
		foreach ( $files as $file ) {
			$key            = $this->archive_group_key( $file['name'] );
			$newest[ $key ] = max( isset( $newest[ $key ] ) ? $newest[ $key ] : 0, (int) $file['stamp'] );
			$all            = max( $all, (int) $file['stamp'] );
		}

		$out = array();
		foreach ( $files as $file ) {
			$key    = $this->archive_group_key( $file['name'] );
			$anchor = 'legacy' === $key ? $all : $newest[ $key ];
			if ( (int) $file['stamp'] >= $this->window_start( $anchor ) ) {
				$out[] = $file;
			}
		}

		return $out;
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
	 * Midnight (legal clock) of the first public day for a newest file at $anchor.
	 *
	 * @param int $anchor Unix time of the newest file.
	 * @return int
	 */
	private function window_start( $anchor ) {
		$day = new DateTime( '@' . (int) $anchor );
		$day->setTimezone( self::legal_timezone() );
		$day->setTime( 0, 0, 0 );
		$day->modify( '-' . self::PUBLIC_DAYS . ' days' );

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
	 * The morning's upkeep: files out of the public 30 days are kept, kept files past their period go.
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
	 * An oznaka keeps files for that object code. Names the parser does not
	 * recognize stay in the unfiltered list only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		$files  = $this->public_files();
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' !== $oznaka ) {
			$matched = array();
			foreach ( $files as $file ) {
				$name = isset( $file['name'] ) ? (string) $file['name'] : '';
				if ( $this->archive_file_oznaka( $name ) === $oznaka ) {
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
