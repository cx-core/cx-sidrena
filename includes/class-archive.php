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
	 * Archive name from the 18 September 2026 ministry example:
	 * prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.xml
	 *
	 * Spaces, hyphens, and the typed case stay. Date is DD.MM.YYYY and time
	 * is HH:MM in the site timezone. A Windows disk cannot store the colon,
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
		$tz       = wp_timezone();
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
	 * Copy today's live feed into the rolling 30-day archive.
	 *
	 * A calendar day keeps one file per object code. Regenerating that day
	 * replaces the file and does not advance the storage number.
	 *
	 * @return string|WP_Error Destination path or error.
	 */
	public function archive_public_xml() {
		if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			return $this->archive_poslovnice();
		}

		$format = CX_Sidrena_Feed_Files::instance()->export_format();
		$source = CX_Sidrena_Feed_Files::instance()->get_public_path( $format );
		if ( ! file_exists( $source ) ) {
			return new WP_Error( 'sidrena_xml_archive_source', __( 'The public price list does not exist yet.', 'cx-sidrena' ) );
		}

		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_archive_mkdir', __( 'The archive folder could not be created.', 'cx-sidrena' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$settings = CX_Sidrena_Settings::instance();
		$pohrana  = $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
		$placed   = $this->place_daily_archive( $dir, $source, $format, $pohrana, null );
		if ( is_wp_error( $placed ) ) {
			return $placed;
		}

		if ( empty( $placed['replaced'] ) ) {
			$settings->save( array( 'file_pohrana' => $pohrana + 1 ) );
		}

		$meta             = CX_Sidrena_Export::instance()->get_public_meta();
		$meta['filename'] = $placed['filename'];
		$meta['pohrana']  = $placed['pohrana'];
		update_option( CX_Sidrena_Export::PUBLIC_OPTION, $meta );

		$this->prune_archive();

		return $placed['path'];
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

				return strcmp( $b['name'], $a['name'] );
			}
		);

		return $files;
	}

	/**
	 * Keep at most 30 archive files; drop the oldest.
	 */
	public function prune_archive() {
		$files = $this->get_archive_files();
		if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			$groups = array();
			foreach ( $files as $file ) {
				$key               = $this->archive_group_key( $file['name'] );
				$groups[ $key ][]  = $file;
			}
			foreach ( $groups as $group ) {
				$extra = array_slice( $group, CX_Sidrena_Export::ARCHIVE_MAX );
				foreach ( $extra as $old ) {
					if ( ! empty( $old['path'] ) && file_exists( $old['path'] ) ) {
						wp_delete_file( $old['path'] );
					}
				}
			}
			return;
		}

		while ( count( $files ) > CX_Sidrena_Export::ARCHIVE_MAX ) {
			$old = array_pop( $files );
			if ( ! empty( $old['path'] ) && file_exists( $old['path'] ) ) {
				wp_delete_file( $old['path'] );
			}
		}
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
			$parsed = DateTime::createFromFormat( '!Ymd', $match[1], wp_timezone() );
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
			$parsed = DateTime::createFromFormat( '!d.m.Y H:i', $match[1] . '.' . $match[2] . '.' . $match[3] . ' ' . $match[4] . ':' . $match[5], wp_timezone() );
			if ( $parsed instanceof DateTime ) {
				return $parsed->getTimestamp();
			}
		}

		if ( preg_match( '/_(\d{8})_(\d{4})\.(xml|csv)$/', $name, $match ) ) {
			$parsed = DateTime::createFromFormat( '!YmdHi', $match[1] . $match[2], wp_timezone() );
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
		$parsed = DateTime::createFromFormat( '!d-m-Y', $date, wp_timezone() );
		return $parsed instanceof DateTime ? $parsed->getTimestamp() : 0;
	}

	/**
	 * Today's archive files for one object code, newest first.
	 *
	 * @param string $oznaka Object code.
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int,stamp:int}>
	 */
	public function same_day_archive_files( $oznaka ) {
		$today  = wp_date( 'd-m-Y' );
		$oznaka = CX_Sidrena_Settings::instance()->normalize_oznaka( $oznaka );
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$matches = array();
		foreach ( $this->get_archive_files() as $file ) {
			if ( ! isset( $file['date'] ) || $file['date'] !== $today ) {
				continue;
			}
			if ( $this->archive_oznaka_from_name( $file['name'] ) !== $oznaka ) {
				continue;
			}
			$matches[] = $file;
		}

		return $matches;
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
	 * Copy a live feed into the one archive file for this calendar day and object code.
	 *
	 * An existing file is replaced and keeps its name, time, and storage number.
	 * A format change keeps that stem and swaps xml/csv. Extra files from the
	 * same day are removed. A new day uses the next legal name.
	 *
	 * @param string                   $dir     Archive directory.
	 * @param string                   $source  Live file.
	 * @param string                   $format  xml|csv.
	 * @param int                      $pohrana Next storage number when this is a new day.
	 * @param array<string, mixed>|null $outlet Outlet row, or null for the webshop.
	 * @return array{path:string,filename:string,pohrana:int,replaced:bool}|WP_Error
	 */
	private function place_daily_archive( $dir, $source, $format, $pohrana, $outlet = null ) {
		$settings = CX_Sidrena_Settings::instance();
		if ( is_array( $outlet ) ) {
			$oznaka = $settings->normalize_oznaka( isset( $outlet['oznaka'] ) ? $outlet['oznaka'] : '' );
		} else {
			$oznaka = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );
		}
		if ( '' === $oznaka ) {
			$oznaka = '001';
		}

		$format   = CX_Sidrena_Feed_Files::instance()->sanitize_ext( $format );
		$today    = $this->same_day_archive_files( $oznaka );
		$replaced = array() !== $today;

		if ( $replaced ) {
			$stem     = (string) pathinfo( $today[0]['name'], PATHINFO_FILENAME );
			$filename = $stem . '.' . $format;
			$used     = $this->archive_pohrana_from_name( $today[0]['name'] );
			if ( $used < 1 ) {
				$used = (int) $pohrana;
			}
		} else {
			$filename = $this->legal_filename( $format, time(), $pohrana, $outlet );
			$used     = (int) $pohrana;
		}

		$dest = trailingslashit( $dir ) . $filename;
		if ( ! copy( $source, $dest ) ) {
			return new WP_Error( 'sidrena_xml_archive_copy', __( 'The archive price list could not be saved.', 'cx-sidrena' ) );
		}

		foreach ( $today as $old ) {
			if ( ! isset( $old['name'] ) || $old['name'] === $filename ) {
				continue;
			}
			if ( ! empty( $old['path'] ) && file_exists( $old['path'] ) ) {
				wp_delete_file( $old['path'] );
			}
		}

		return array(
			'path'     => $dest,
			'filename' => $filename,
			'pohrana'  => $used,
			'replaced' => $replaced,
		);
	}

	/**
	 * Copy each outlet's live feed into the archive.
	 *
	 * A new calendar day advances that outlet's storage sequence. The same day
	 * replaces its file and leaves the sequence where it is.
	 *
	 * @return true|WP_Error
	 */
	public function archive_poslovnice() {
		$dir = $this->get_archive_dir();
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'sidrena_xml_archive_mkdir', __( 'The archive folder could not be created.', 'cx-sidrena' ) );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\ndefined( 'ABSPATH' ) || exit;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$format  = CX_Sidrena_Feed_Files::instance()->export_format();
		$meta    = CX_Sidrena_Export::instance()->get_public_meta();
		$stored  = ( isset( $meta['outlets'] ) && is_array( $meta['outlets'] ) ) ? $meta['outlets'] : array();
		$changed = false;

		$web_source = CX_Sidrena_Feed_Files::instance()->get_public_path( $format, '' );
		if ( file_exists( $web_source ) ) {
			$settings = CX_Sidrena_Settings::instance();
			$pohrana  = $settings->sanitize_pohrana( $settings->get( 'file_pohrana', 1 ) );
			$placed   = $this->place_daily_archive( $dir, $web_source, $format, $pohrana, null );
			if ( is_wp_error( $placed ) ) {
				return $placed;
			}
			if ( empty( $placed['replaced'] ) ) {
				$settings->save( array( 'file_pohrana' => $pohrana + 1 ) );
			}
			$meta['filename'] = $placed['filename'];
			$meta['pohrana']  = $placed['pohrana'];
			$changed          = true;
		}

		foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
			$source = CX_Sidrena_Feed_Files::instance()->get_public_path( $format, $outlet['oznaka'] );
			if ( ! file_exists( $source ) ) {
				continue;
			}

			$pohrana = CX_Sidrena_Settings::instance()->sanitize_pohrana( $outlet['pohrana'] );
			$placed  = $this->place_daily_archive( $dir, $source, $format, $pohrana, $outlet );
			if ( is_wp_error( $placed ) ) {
				return $placed;
			}

			if ( empty( $placed['replaced'] ) ) {
				CX_Sidrena_Poslovnice::instance()->set_pohrana( $outlet['id'], $pohrana + 1 );
			}

			$current                     = isset( $stored[ $outlet['oznaka'] ] ) && is_array( $stored[ $outlet['oznaka'] ] ) ? $stored[ $outlet['oznaka'] ] : array();
			$current['filename']         = $placed['filename'];
			$current['pohrana']          = $placed['pohrana'];
			$stored[ $outlet['oznaka'] ] = $current;
			$changed                     = true;
		}

		if ( $changed ) {
			$meta['outlets'] = $stored;
			update_option( CX_Sidrena_Export::PUBLIC_OPTION, $meta );
			$this->prune_archive();
		}

		return true;
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
	 * File list for the [sidrena-arhiva] shortcode. Name and date only.
	 *
	 * An oznaka keeps files for that object code. Names the parser does not
	 * recognize stay in the unfiltered list only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		$files  = $this->get_archive_files();
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
			$label = $stamp ? wp_date( 'd.m.Y. H:i', $stamp ) : ( isset( $file['date'] ) ? (string) $file['date'] : '' );
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
