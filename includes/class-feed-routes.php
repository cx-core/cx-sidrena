<?php
/**
 * Pretty URLs and public HTTP serving for feeds, archive, and HTML cjenik.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pretty URLs and public HTTP serving for feeds, archive, and HTML cjenik.
 */
class CX_Sidrena_Feed_Routes {

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
	 * Pretty URLs: /sidrena-cijena.xml|.csv, /arhiva-sidrene-cijene, /sidrena-cjenik
	 */
	public function register_rewrite() {
		add_rewrite_rule( '^' . CX_Sidrena_Export::PUBLIC_STEM . '\\.(xml|csv)$', 'index.php?sidrena_cijena_xml=1&sidrena_cijena_ext=$matches[1]', 'top' );
		add_rewrite_rule(
			'^' . CX_Sidrena_Export::PUBLIC_STEM . '-([A-Za-z0-9-]+)\\.(xml|csv)$',
			'index.php?sidrena_cijena_xml=1&sidrena_cijena_oznaka=$matches[1]&sidrena_cijena_ext=$matches[2]',
			'top'
		);
		add_rewrite_rule( '^' . CX_Sidrena_Export::ARCHIVE_SLUG . '/?$', 'index.php?sidrena_cijena_arhiva=1', 'top' );
		add_rewrite_rule(
			'^' . CX_Sidrena_Export::ARCHIVE_SLUG . '/(.+\\.(xml|csv))$',
			'index.php?sidrena_cijena_arhiva_name=$matches[1]&sidrena_cijena_ext=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . CX_Sidrena_Export::CJENIK_SLUG . '/([A-Za-z0-9-]+)/?$',
			'index.php?sidrena_cijena_cjenik=1&sidrena_cijena_oznaka=$matches[1]',
			'top'
		);
		add_rewrite_rule( '^' . CX_Sidrena_Export::CJENIK_SLUG . '/?$', 'index.php?sidrena_cijena_cjenik=1', 'top' );
	}

	/**
	 * Rewrite option value including Woo vs standalone mode.
	 *
	 * @return string
	 */
	public static function rewrite_fingerprint() {
		$mode = CX_Sidrena_Plugin::woocommerce_active() ? 'woo' : 'solo';
		$page = CX_Sidrena_Settings::instance()->is_public_cjenik_enabled() ? 'page' : 'nopage';
		return CX_Sidrena_Export::REWRITE_VERSION . '-' . $mode . '-' . $page;
	}

	/**
	 * Flush permalinks once after adding archive routes.
	 */
	public function maybe_flush_rewrites() {
		if ( (string) get_option( CX_Sidrena_Export::REWRITE_OPTION, '' ) === self::rewrite_fingerprint() ) {
			return;
		}

		if ( wp_doing_ajax() || $this->is_api_request() ) {
			return;
		}

		$this->register_rewrite();
		flush_rewrite_rules( false );
		update_option( CX_Sidrena_Export::REWRITE_OPTION, self::rewrite_fingerprint(), false );
	}

	/**
	 * Expose the public XML and archive query vars.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'sidrena_cijena_xml';
		$vars[] = 'sidrena_cijena_oznaka';
		$vars[] = 'sidrena_cijena_ext';
		$vars[] = 'sidrena_cijena_arhiva';
		$vars[] = 'sidrena_cijena_arhiva_file';
		$vars[] = 'sidrena_cijena_arhiva_name';
		$vars[] = 'sidrena_cijena_cjenik';
		return $vars;
	}

	/**
	 * Public feed hits never build the catalog. A missing file is queued once.
	 */
	public function maybe_ensure_public_xml_on_init() {
		if ( is_admin() || $this->is_api_request() ) {
			return;
		}

		if ( ! $this->is_public_xml_request() ) {
			return;
		}

		$this->schedule_public_rebuild_if_missing();
	}

	/**
	 * Queue one cron rebuild when the live file for this request is missing.
	 */
	public function schedule_public_rebuild_if_missing() {
		$ext = $this->requested_live_ext();
		if ( $ext !== CX_Sidrena_Feed_Files::instance()->export_format() ) {
			return;
		}

		$oznaka = '';
		if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			$oznaka = $this->requested_oznaka();
		}

		if ( file_exists( CX_Sidrena_Feed_Files::instance()->get_public_path( $ext, $oznaka ) ) ) {
			return;
		}

		$this->schedule_public_rebuild();
	}

	/**
	 * Schedule a single background rebuild. Repeat visits wait out the cooldown.
	 */
	public function schedule_public_rebuild() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return; // nothing is published (CX-SID-28)
		}

		if ( get_transient( CX_Sidrena_Export::REBUILD_COOLDOWN ) || get_transient( CX_Sidrena_Export::REBUILD_LOCK ) ) {
			return;
		}

		CX_Sidrena_Export::instance()->queue_public_xml();
		set_transient( CX_Sidrena_Export::REBUILD_COOLDOWN, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * REST/AJAX/JSON requests. REST_REQUEST is not set yet during init.
	 *
	 * @return bool
	 */
	public function is_api_request() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return is_string( $uri ) && false !== strpos( $uri, '/wp-json/' );
	}

	/**
	 * Serve public XML, archive index, or an archived file.
	 */
	public function maybe_serve_public_xml() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->is_cjenik_request() ) {
			if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
				$this->serve_b2b_not_published();
			}
			$this->serve_cjenik_page();
			return;
		}

		$archive = $this->get_requested_archive_file();
		if ( null !== $archive ) {
			$this->serve_archive_file( $archive['name'] );
			return;
		}

		if ( $this->is_archive_request() ) {
			$this->serve_archive_page();
			return;
		}

		if ( ! $this->is_public_xml_request() ) {
			return;
		}

		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			$this->serve_b2b_not_published();
		}

		$ext    = $this->requested_live_ext();
		$oznaka = $this->requested_oznaka();
		$multi  = CX_Sidrena_Settings::instance()->is_multi_poslovnica();

		if ( $multi ) {
			$this->redirect_old_format( $ext, $oznaka );
			if ( '' === $oznaka ) {
				$path = CX_Sidrena_Feed_Files::instance()->get_public_path( $ext, '' );
				if ( ! file_exists( $path ) ) {
					$this->serve_missing_public_feed( $ext );
				}
				CX_Sidrena_Feed_Files::instance()->send_public_file( $path, CX_Sidrena_Archive::instance()->published_filename( $ext ), $ext );
				return;
			}
			$this->serve_outlet_feed( $oznaka, $ext );
			return;
		}

		if ( '' !== $oznaka ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'The outlet price list is not enabled.', 'cx-sidrena' );
			exit;
		}

		$this->redirect_old_format( $ext, '' );

		$path = CX_Sidrena_Feed_Files::instance()->get_public_path( $ext );

		if ( ! file_exists( $path ) ) {
			$this->serve_missing_public_feed( $ext );
		}

		CX_Sidrena_Feed_Files::instance()->send_public_file( $path, CX_Sidrena_Archive::instance()->published_filename( $ext ), $ext );
	}

	/**
	 * A format change, at the price list's address. The format the shop no longer publishes: 302 to the current one,
	 * once that file exists; its own file is gone after the first build in the new format (remove_other_format_live),
	 * and the address a bot or an inspector kept leads to the current price list. Until that build, the old file is
	 * still the price list: the old address serves it and the new one redirects to it, so neither answers 503 in
	 * between (Argus I2, CX-SID-27; the Settings save queues that build, CX_Sidrena_Admin_Save).
	 *
	 * @param string $ext    Requested xml|csv.
	 * @param string $oznaka Requested outlet code, empty for the webshop.
	 */
	public function redirect_old_format( $ext, $oznaka ) {
		$ff      = CX_Sidrena_Feed_Files::instance();
		$current = $ff->export_format();
		$other   = 'csv' === $current ? 'xml' : 'csv';
		if ( $ext !== $current ) {
			if ( file_exists( $ff->get_public_path( $current, $oznaka ) ) ) {
				wp_safe_redirect( $ff->get_public_url( $current, $oznaka ), 302, 'CX Sidrena' );
				exit;
			}
			return; // the new format is not built yet: this file is still the price list
		}

		if ( ! file_exists( $ff->get_public_path( $current, $oznaka ) ) && file_exists( $ff->get_public_path( $other, $oznaka ) ) ) {
			$this->schedule_public_rebuild();
			wp_safe_redirect( $ff->get_public_url( $other, $oznaka ), 302, 'CX Sidrena' );
			exit;
		}
	}

	/**
	 * 404 for the live price list and the HTML page of a shop that sells only to businesses (CX-SID-28): there is no
	 * price list and none is coming, so not 503 with Retry-After. The archive's addresses keep answering (CX-SID-27).
	 */
	public function serve_b2b_not_published() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'This shop sells only to businesses and publishes no price list.', 'cx-sidrena' );
		exit;
	}

	/**
	 * 503 a missing live feed. Rebuild is queued, never run inside this request.
	 *
	 * @param string $ext xml|csv.
	 */
	public function serve_missing_public_feed( $ext ) {
		if ( $ext === CX_Sidrena_Feed_Files::instance()->export_format() ) {
			$this->schedule_public_rebuild();
		}

		status_header( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Retry-After: 120' );
		echo esc_html__( 'The public price list has not been generated yet.', 'cx-sidrena' );
		exit;
	}

	/**
	 * Whether the current request is the public XML feed.
	 *
	 * @return bool
	 */
	public function is_public_xml_request() {
		if ( isset( $_GET['sidrena_cijena_xml'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_xml' ) ) {
			return true;
		}

		$path = $this->get_request_path();

		return (bool) preg_match( '#^/' . preg_quote( CX_Sidrena_Export::PUBLIC_STEM, '#' ) . '(?:-[A-Za-z0-9-]+)?\\.(xml|csv)$#', $path );
	}

	/**
	 * Extension for the live feed request.
	 *
	 * @return string
	 */
	public function requested_live_ext() {
		$from_query = $this->ext_from_request();
		if ( '' !== $from_query ) {
			return $from_query;
		}

		$path = $this->get_request_path();
		if ( preg_match( '#^/' . preg_quote( CX_Sidrena_Export::PUBLIC_STEM, '#' ) . '(?:-[A-Za-z0-9-]+)?\\.(xml|csv)$#', $path, $match ) ) {
			return $match[1];
		}

		return CX_Sidrena_Feed_Files::instance()->export_format();
	}

	/**
	 * Object code from /sidrena-cijena-{oznaka}.xml or the query argument.
	 *
	 * @return string
	 */
	public function requested_oznaka() {
		$path = $this->get_request_path();
		if ( preg_match( '#^/' . preg_quote( CX_Sidrena_Export::PUBLIC_STEM, '#' ) . '-([A-Za-z0-9-]+)\\.(xml|csv)$#', $path, $match ) ) {
			return CX_Sidrena_Settings::instance()->normalize_oznaka( $match[1] );
		}

		$raw = '';
		if ( isset( $_GET['sidrena_cijena_oznaka'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET['sidrena_cijena_oznaka'] );
		} elseif ( function_exists( 'get_query_var' ) ) {
			$raw = get_query_var( 'sidrena_cijena_oznaka' );
		}

		return CX_Sidrena_Settings::instance()->normalize_oznaka( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Plain-text list when the single-shop URL is opened in multi-outlet mode.
	 */
	public function serve_poslovnice_directory() {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'The price list is published per outlet.', 'cx-sidrena' ) . "\n";
		foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
			$name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			echo esc_html( $name . ' ' . CX_Sidrena_Feed_Files::instance()->get_public_url( '', $outlet['oznaka'] ) ) . "\n";
		}
		exit;
	}

	/**
	 * Stream one outlet's live feed.
	 *
	 * @param string $oznaka Object code.
	 * @param string $ext    xml|csv.
	 */
	public function serve_outlet_feed( $oznaka, $ext ) {
		$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'The outlet has no published price list.', 'cx-sidrena' );
			exit;
		}

		$path = CX_Sidrena_Feed_Files::instance()->get_public_path( $ext, $oznaka );
		if ( ! file_exists( $path ) ) {
			$this->serve_missing_public_feed( $ext );
		}

		CX_Sidrena_Feed_Files::instance()->send_public_file( $path, $this->outlet_download_name( $outlet, $ext ), $ext );
	}

	/**
	 * Download name for an outlet feed: last archive name, or the next legal name.
	 *
	 * @param array<string, mixed> $outlet Outlet.
	 * @param string               $ext    xml|csv.
	 * @return string
	 */
	public function outlet_download_name( $outlet, $ext ) {
		$meta = CX_Sidrena_Archive::instance()->get_outlet_meta( $outlet['oznaka'] );
		if ( ! empty( $meta['filename'] ) && is_string( $meta['filename'] ) ) {
			$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( $meta['filename'] );
			if ( '' !== $name ) {
				return $name;
			}
		}

		$stamp = ! empty( $meta['generated'] ) ? (int) $meta['generated'] : 0;
		return CX_Sidrena_Archive::instance()->legal_filename( $ext, $stamp, 0, $outlet );
	}

	/**
	 * xml|csv from query string or rewrite var.
	 *
	 * @return string
	 */
	public function ext_from_request() {
		if ( isset( $_GET['sidrena_cijena_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = sanitize_key( wp_unslash( $_GET['sidrena_cijena_ext'] ) );
			if ( in_array( $raw, array( 'xml', 'csv' ), true ) ) {
				return $raw;
			}
		}

		if ( function_exists( 'get_query_var' ) ) {
			$raw = sanitize_key( (string) get_query_var( 'sidrena_cijena_ext' ) );
			if ( in_array( $raw, array( 'xml', 'csv' ), true ) ) {
				return $raw;
			}
		}

		return '';
	}

	/**
	 * Whether the current request is the archive HTML index.
	 *
	 * @return bool
	 */
	public function is_archive_request() {
		if ( isset( $_GET['sidrena_cijena_arhiva'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_arhiva' ) ) {
			return true;
		}

		return '/' . CX_Sidrena_Export::ARCHIVE_SLUG === $this->get_request_path();
	}

	/**
	 * Whether the current request is the standalone HTML pricelist.
	 *
	 * @return bool
	 */
	public function is_cjenik_request() {
		if ( isset( $_GET['sidrena_cijena_cjenik'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( function_exists( 'get_query_var' ) && get_query_var( 'sidrena_cijena_cjenik' ) ) {
			return true;
		}

		$path = $this->get_request_path();
		$slug = '/' . CX_Sidrena_Export::CJENIK_SLUG;
		if ( $path === $slug ) {
			return true;
		}

		return (bool) preg_match( '#^' . preg_quote( $slug, '#' ) . '/[A-Za-z0-9-]+$#', $path );
	}

	/**
	 * Requested archive file basename.
	 *
	 * @return array{name:string}|null
	 */
	public function get_requested_archive_file() {
		$name = '';
		$path = $this->get_raw_request_path();
		if ( preg_match( '#^/' . preg_quote( CX_Sidrena_Export::ARCHIVE_SLUG, '#' ) . '/(.+\\.(xml|csv))$#', $path, $match ) ) {
			$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( $match[1] );
		}

		if ( '' === $name && isset( $_GET['sidrena_cijena_arhiva_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( wp_unslash( $_GET['sidrena_cijena_arhiva_name'] ) );
		} elseif ( '' === $name && function_exists( 'get_query_var' ) ) {
			$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( (string) get_query_var( 'sidrena_cijena_arhiva_name' ) );
		}

		if ( '' === $name ) {
			$date = '';
			if ( isset( $_GET['sidrena_cijena_arhiva_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$date = CX_Sidrena_Archive::instance()->sanitize_archive_date( sanitize_text_field( wp_unslash( $_GET['sidrena_cijena_arhiva_file'] ) ) );
			} elseif ( function_exists( 'get_query_var' ) ) {
				$date = CX_Sidrena_Archive::instance()->sanitize_archive_date( (string) get_query_var( 'sidrena_cijena_arhiva_file' ) );
			}

			$ext = $this->ext_from_request();
			if ( '' !== $date ) {
				$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( CX_Sidrena_Export::PUBLIC_STEM . '_' . $date . '.' . ( '' !== $ext ? $ext : 'xml' ) );
			}
		}

		if ( '' === $name ) {
			return null;
		}

		return array(
			'name' => $name,
		);
	}

	/**
	 * Request path relative to the site home, without a trailing slash.
	 *
	 * @return string
	 */
	public function get_raw_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( ! is_string( $uri ) ) {
			return '/';
		}

		$uri  = str_replace( "\0", '', $uri );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$path = untrailingslashit( $path );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = untrailingslashit( $home );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Request path without the query string, relative to the site.
	 *
	 * @return string
	 */
	public function get_request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( $path );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = untrailingslashit( $home );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Stream one archived feed file.
	 *
	 * @param string $name Basename.
	 */
	public function serve_archive_file( $name ) {
		$name = CX_Sidrena_Archive::instance()->sanitize_archive_name( $name );
		$path = '' !== $name ? trailingslashit( CX_Sidrena_Archive::instance()->get_archive_dir() ) . $name : '';
		$ext  = '' !== $name ? strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) : 'xml';

		// Older than the public 30 days: not public, also before the move has put it away (CX-SID-27).
		if ( '' === $name || ! file_exists( $path ) || ! CX_Sidrena_Archive::instance()->is_public( $name ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'Archive price list not found.', 'cx-sidrena' );
			exit;
		}

		CX_Sidrena_Feed_Files::instance()->send_public_file( $path, $name, $ext );
	}

	/**
	 * Simple public HTML listing of archived XML files.
	 */
	public function serve_archive_page() {
		$files = CX_Sidrena_Archive::instance()->public_files();
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = __( 'Sidrena cijena archive', 'cx-sidrena' );

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $title ) . '</title>';
		wp_register_style(
			'mjdd-sidrena-cijena-archive',
			CX_SIDRENA_URL . 'assets/css/archive.css',
			array(),
			CX_SIDRENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-archive' );
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html( $shop ) . ' - ' . esc_html(
			CX_Sidrena_Settings::instance()->is_multi_poslovnica()
				? __( 'Price lists from the last 30 days. A new file is made at each price change and every morning, for each outlet separately.', 'cx-sidrena' )
				: __( 'Price lists from the last 30 days. A new file is made at each price change and every morning.', 'cx-sidrena' )
		) . '</p>';

		if ( empty( $files ) ) {
			echo '<p class="empty">' . esc_html__( 'The archive is still empty. The first price list will appear here the next morning or at the first price change.', 'cx-sidrena' ) . '</p>';
		} elseif ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			$this->render_archive_groups( $files );
		} else {
			echo '<ol>';
			$this->render_archive_file_items( $files );
			echo '</ol>';
		}

		echo '<p class="back"><a href="' . esc_url( home_url( '/' ) ) . '">';
		echo esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Back to %s', 'cx-sidrena' ),
				$shop
			)
		);
		echo '</a></p>';

		echo '</main></body></html>';
		exit;
	}

	/**
	 * Object code for this HTML cjenik request. Empty is the webshop page.
	 *
	 * The XML path parser stays in requested_oznaka(). This route reads the
	 * cjenik path or the query var set by the rewrite.
	 *
	 * @return string
	 */
	public function cjenik_requested_oznaka() {
		$path = $this->get_request_path();
		$slug = '/' . CX_Sidrena_Export::CJENIK_SLUG;
		if ( preg_match( '#^' . preg_quote( $slug, '#' ) . '/([A-Za-z0-9-]+)$#', $path, $match ) ) {
			return CX_Sidrena_Settings::instance()->normalize_oznaka( $match[1] );
		}

		return $this->requested_oznaka();
	}

	/**
	 * Webshop object code used on buttons and archive headings.
	 *
	 * @return string
	 */
	public function webshop_oznaka() {
		$settings = CX_Sidrena_Settings::instance();
		$oznaka   = $settings->normalize_oznaka( $settings->get( 'file_oznaka', '001' ) );

		return '' !== $oznaka ? $oznaka : '001';
	}

	/**
	 * One list item per archive file.
	 *
	 * @param array<int, array<string, mixed>> $files Archive rows.
	 */
	private function render_archive_file_items( $files ) {
		foreach ( $files as $file ) {
			$stamp = isset( $file['stamp'] ) ? (int) $file['stamp'] : CX_Sidrena_Archive::instance()->archive_date_timestamp( isset( $file['date'] ) ? $file['date'] : '' );
			$label = $stamp ? CX_Sidrena_Archive::legal_date( 'd.m.Y. H:i', $stamp ) : ( isset( $file['date'] ) ? (string) $file['date'] : '' );
			$name  = isset( $file['name'] ) ? (string) $file['name'] : '';
			$url   = isset( $file['url'] ) ? (string) $file['url'] : '';
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			echo '<span>' . esc_html( $label ) . '</span></li>';
		}
	}

	/**
	 * Archive index grouped by object code: webshop, outlets, then older names.
	 *
	 * @param array<int, array<string, mixed>> $files Archive rows, newest first.
	 */
	private function render_archive_groups( $files ) {
		$archive = CX_Sidrena_Archive::instance();
		$buckets = array();
		foreach ( $files as $file ) {
			$name = isset( $file['name'] ) ? (string) $file['name'] : '';
			$code = $archive->archive_file_oznaka( $name );
			$key  = '' !== $code ? $code : 'legacy';
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array();
			}
			$buckets[ $key ][] = $file;
		}

		$order = array( $this->webshop_oznaka() );
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
			if ( ! in_array( $outlet['oznaka'], $order, true ) ) {
				$order[] = $outlet['oznaka'];
			}
		}
		foreach ( array_keys( $buckets ) as $code ) {
			if ( 'legacy' !== $code && ! in_array( $code, $order, true ) ) {
				$order[] = $code;
			}
		}
		$order[] = 'legacy';

		foreach ( $order as $code ) {
			if ( empty( $buckets[ $code ] ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $this->archive_group_heading( $code ) ) . '</h2>';
			echo '<ol>';
			$this->render_archive_file_items( $buckets[ $code ] );
			echo '</ol>';
		}
	}

	/**
	 * Heading for one archive group.
	 *
	 * @param string $code Object code, or legacy.
	 * @return string
	 */
	private function archive_group_heading( $code ) {
		if ( 'legacy' === $code ) {
			return __( 'Older files', 'cx-sidrena' );
		}

		if ( $code === $this->webshop_oznaka() ) {
			return $code;
		}

		$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $code );
		if ( is_array( $outlet ) ) {
			return $this->outlet_label( $outlet );
		}

		return $code;
	}

	/**
	 * Public HTML pricelist. Standalone catalogs always have it; Woo shops only when the setting is on.
	 */
	public function serve_cjenik_page() {
		$settings = CX_Sidrena_Settings::instance();
		$oznaka   = $this->cjenik_requested_oznaka();
		$outlet   = null;

		if ( '' !== $oznaka ) {
			$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
			if ( ! is_array( $outlet ) ) {
				$this->serve_cjenik_empty_page();
				return;
			}
		}

		$snapshot = CX_Sidrena_Feed_Files::instance()->read_cjenik_snapshot( $oznaka );
		if ( null === $snapshot ) {
			$this->schedule_public_rebuild();
			$this->serve_cjenik_pending_page();
			return;
		}

		$count     = (int) $snapshot['count'];
		$shop      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title     = __( 'Price list', 'cx-sidrena' );
		$scope     = is_array( $outlet ) ? $this->outlet_label( $outlet ) : ( $settings->is_multi_poslovnica() ? $this->webshop_oznaka() : '' );
		$format    = CX_Sidrena_Feed_Files::instance()->export_format();
		$feed_lbl  = 'csv' === $format ? __( 'Download CSV', 'cx-sidrena' ) : __( 'Download XML', 'cx-sidrena' );
		$generated = CX_Sidrena_Export::instance()->get_public_meta();
		if ( '' !== $oznaka && isset( $generated['outlets'][ $oznaka ] ) && is_array( $generated['outlets'][ $oznaka ] ) ) {
			$generated = $generated['outlets'][ $oznaka ];
		}
		// The moment of the file this page shows, as in its name (a build that found nothing changed keeps it), on
		// the legal clock in the Croatian form: a site on UTC with English formats showed "November 15, 2026 2:30 pm".
		$when      = ! empty( $generated['published'] ) ? (int) $generated['published'] : ( ! empty( $generated['generated'] ) ? (int) $generated['generated'] : 0 );
		$stamp     = $when
			? CX_Sidrena_Archive::legal_date( 'd.m.Y. H:i', $when )
			: CX_Sidrena_Archive::legal_date( 'd.m.Y.' );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		echo '<meta name="description" content="' . esc_attr( sprintf( /* translators: %s shop name */ __( 'Current price list with sidrena cijena: %s', 'cx-sidrena' ), $shop ) ) . '" />';
		wp_register_style(
			'mjdd-sidrena-cijena-cjenik',
			CX_SIDRENA_URL . 'assets/css/cjenik.css',
			array(),
			CX_SIDRENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-cjenik' );
		echo '</head><body><main>';
		echo '<header class="cjenik-hero">';
		echo '<p class="cjenik-kicker">' . esc_html( $shop ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $scope ) {
			echo '<p>' . esc_html( $scope ) . '</p>';
		}
		// No single bazna date under the title (CX-SID-79): since CX-SID-21 each product has its own (by category, or the
		// day it was added), and it is in its row. The old line showed MJDD's one reference date above rows with others.
		echo '<p class="cjenik-meta">' . esc_html(
			sprintf(
				/* translators: 1: datetime, 2: count */
				__( 'Updated %1$s · %2$d products', 'cx-sidrena' ),
				$stamp,
				$count
			)
		) . '</p>';
		echo '<div class="cjenik-actions">';
		$this->render_cjenik_downloads( $oznaka, $feed_lbl );
		echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( CX_Sidrena_Feed_Files::instance()->get_archive_url() ) . '">' . esc_html__( 'Archive', 'cx-sidrena' ) . '</a>';
		$this->render_cjenik_scope_links( $oznaka );
		echo '</div>';
		echo '</header>';
		echo str_replace( CX_Sidrena_Export::CJENIK_DOM_TOKEN, 'sidrena-cjenik-1', $snapshot['html'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- snapshot is escaped when the feed is generated.

		echo '<p class="back"><a href="' . esc_url( home_url( '/' ) ) . '">';
		echo esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Back to %s', 'cx-sidrena' ),
				$shop
			)
		);
		echo '</a></p>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Visible name for one outlet row.
	 *
	 * @param array<string, mixed> $outlet Outlet row.
	 * @return string
	 */
	private function outlet_label( $outlet ) {
		$name = isset( $outlet['naziv'] ) ? trim( (string) $outlet['naziv'] ) : '';
		if ( '' !== $name ) {
			return $name;
		}

		return isset( $outlet['oznaka'] ) ? (string) $outlet['oznaka'] : '';
	}

	/**
	 * Download buttons for the table on this page.
	 *
	 * The webshop page lists the webshop feed first, then each outlet feed.
	 * An outlet page lists only that outlet's feed.
	 *
	 * @param string $oznaka   Empty for the webshop page.
	 * @param string $feed_lbl Preuzmi XML or Preuzmi CSV.
	 */
	private function render_cjenik_downloads( $oznaka, $feed_lbl ) {
		$files    = CX_Sidrena_Feed_Files::instance();
		$settings = CX_Sidrena_Settings::instance();

		if ( ! $settings->is_multi_poslovnica() ) {
			echo '<a class="cjenik-btn" href="' . esc_url( $files->get_public_url() ) . '">' . esc_html( $feed_lbl ) . '</a>';
			return;
		}

		if ( '' === $oznaka ) {
			echo '<a class="cjenik-btn" href="' . esc_url( $files->get_public_url() ) . '">' . esc_html( $feed_lbl . ' - ' . $this->webshop_oznaka() ) . '</a>';
			foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
				echo '<a class="cjenik-btn" href="' . esc_url( $files->get_public_url( '', $outlet['oznaka'] ) ) . '">' . esc_html( $feed_lbl . ' - ' . $this->outlet_label( $outlet ) ) . '</a>';
			}
			return;
		}

		$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
		$name   = is_array( $outlet ) ? $this->outlet_label( $outlet ) : $oznaka;
		echo '<a class="cjenik-btn" href="' . esc_url( $files->get_public_url( '', $oznaka ) ) . '">' . esc_html( $feed_lbl . ' - ' . $name ) . '</a>';
	}

	/**
	 * Links to the other HTML cjenik pages when more than one scope exists.
	 *
	 * @param string $current Empty for the webshop page.
	 */
	private function render_cjenik_scope_links( $current ) {
		$settings = CX_Sidrena_Settings::instance();
		if ( ! $settings->is_multi_poslovnica() ) {
			return;
		}

		$files = CX_Sidrena_Feed_Files::instance();
		if ( '' !== $current ) {
			echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( $files->get_cjenik_url() ) . '">' . esc_html( $this->webshop_oznaka() ) . '</a>';
		}

		foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
			if ( $outlet['oznaka'] === $current ) {
				continue;
			}
			echo '<a class="cjenik-btn cjenik-btn--ghost" href="' . esc_url( $files->get_cjenik_url( $outlet['oznaka'] ) ) . '">' . esc_html( $this->outlet_label( $outlet ) ) . '</a>';
		}
	}

	/**
	 * Unknown outlet code. Does not fall back to the webshop table.
	 */
	public function serve_cjenik_empty_page() {
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = __( 'Price list', 'cx-sidrena' );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		wp_register_style(
			'mjdd-sidrena-cijena-cjenik',
			CX_SIDRENA_URL . 'assets/css/cjenik.css',
			array(),
			CX_SIDRENA_VERSION
		);
		wp_print_styles( 'mjdd-sidrena-cijena-cjenik' );
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="empty">' . esc_html__( 'The price list is empty.', 'cx-sidrena' ) . '</p>';
		echo '<p class="back"><a href="' . esc_url( CX_Sidrena_Feed_Files::instance()->get_cjenik_url() ) . '">' . esc_html__( 'Price list', 'cx-sidrena' ) . '</a></p>';
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Short page while the HTML snapshot is still being generated.
	 */
	public function serve_cjenik_pending_page() {
		$shop  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = __( 'Price list', 'cx-sidrena' );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		echo '<!DOCTYPE html><html lang="hr"><head><meta charset="utf-8" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $shop . ' - ' . $title ) . '</title>';
		echo '</head><body><main>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html__( 'The price list is being prepared. Refresh the page in a few minutes.', 'cx-sidrena' ) . '</p>';
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: site title */
				__( 'Back to %s', 'cx-sidrena' ),
				$shop
			)
		) . '</a></p>';
		echo '</main></body></html>';
		exit;
	}
}
