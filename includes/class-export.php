<?php
/**
 * XML / CSV catalog and missing-price exports.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloadable pricelist export.
 */
class CX_Sidrena_Export {

	const BATCH_SIZE       = 100;
	const PUBLIC_STEM      = 'sidrena-cijena';
	const PUBLIC_OPTION    = 'cx_sidrena_public_xml';
	const DAILY_HOOK       = 'cx_sidrena_daily_xml';
	const GENERATE_HOOK    = 'cx_sidrena_generate_xml';
	const DAILY_HOUR       = 0;
	const DAILY_MINUTE     = 5;
	const ARCHIVE_SLUG     = 'arhiva-sidrene-cijene';
	const CJENIK_SLUG      = 'sidrena-cjenik';
	const CJENIK_DOM_TOKEN = 'sidrena-cjenik-dom-7f3a9c';
	const ARCHIVE_MAX      = 30;
	const REWRITE_OPTION   = 'cx_sidrena_rewrite_ver';
	const REWRITE_VERSION  = '11';
	const REBUILD_LOCK     = 'cx_sidrena_rebuild_lock';
	const REBUILD_COOLDOWN = 'cx_sidrena_rebuild_cooldown';
	const CHANGE_DELAY     = 60;
	// The columns of the public files (CX_Sidrena_Feed_Files::COLUMNS_*). Every build stores it in the public meta; a
	// shop whose meta has another one (an update) gets one rebuild on the next visit, not at 00:05 (CX-SID-109).
	const FEED_LAYOUT      = '110'; // 110: the file names of art. 6(3), goods and services in their own files

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * While a feed is being written, product hooks must not schedule another run.
	 *
	 * @var bool
	 */
	private $suppress_change_queue = false;

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
	 * Hook download, regenerate, rewrite, and public serve.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_action( 'admin_init', array( $this, 'maybe_flush_rewrites' ) );
		add_action( 'init', array( $this, 'sync_daily_schedule' ), 20 );
		add_action( 'init', array( $this, 'maybe_ensure_public_xml_on_init' ), 30 );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily_regenerate' ) );
		add_action( self::GENERATE_HOOK, array( $this, 'run_queued_generate' ) );

		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			add_action( 'woocommerce_new_product', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_update_product', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_product_set_stock', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'queue_feed_after_change' ) );
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_feed_after_change' ) );
			add_action( 'trashed_post', array( $this, 'queue_feed_after_product_post' ) );
			add_action( 'untrashed_post', array( $this, 'queue_feed_after_product_post' ) );
			add_action( 'before_delete_post', array( $this, 'queue_feed_after_product_post' ) );
		}
		// A settings change that changes what is published: B2B on or off, the availability column (CX-SID-28). Caught
		// on the option itself, so it works whoever writes it (the Settings form, CX-SID-31's switch, WP-CLI).
		add_action( 'update_option_' . CX_Sidrena_Settings::OPTION_KEY, array( $this, 'after_settings_change' ), 10, 2 );
		add_action( 'cx_sidrena_b2b_only_changed', array( $this, 'apply_b2b_only' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_public_xml' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_public_xml' ), 0 );
		add_action( 'admin_init', array( $this, 'handle_download' ) );
	}

	/**
	 * Pretty URLs: /sidrena-cijena.xml|.csv, /arhiva-sidrene-cijene, /sidrena-cjenik
	 */
	public function register_rewrite() {
		CX_Sidrena_Feed_Routes::instance()->register_rewrite();
	}

	/**
	 * Rewrite option value including Woo vs standalone mode.
	 *
	 * @return string
	 */
	public static function rewrite_fingerprint() {
		return CX_Sidrena_Feed_Routes::rewrite_fingerprint();
	}

	/**
	 * Flush permalinks once after adding archive routes.
	 */
	public function maybe_flush_rewrites() {
		CX_Sidrena_Feed_Routes::instance()->maybe_flush_rewrites();
	}

	/**
	 * Expose the public XML and archive query vars.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_var( $vars ) {
		return CX_Sidrena_Feed_Routes::instance()->register_query_var($vars);
	}

	/**
	 * Stream a file when the settings export form is submitted.
	 */

	public function handle_download() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_action'] ) );
		if ( ! in_array( $action, array( 'export_cjenik', 'export_missing', 'export_template' ), true ) ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_export', 'sidrena_cijena_export_nonce' );

		if ( 'export_template' === $action ) {
			CX_Sidrena_Feed_Files::instance()->send_import_template();
			return;
		}

		$format       = ( isset( $_POST['sidrena_cijena_export_format'] ) && 'xml' === $_POST['sidrena_cijena_export_format'] ) ? 'xml' : 'csv';
		$missing_only = ( 'export_missing' === $action );
		$public       = ! $missing_only;
		$rows         = $this->collect_rows( $missing_only, $public );
		$stamp        = gmdate( 'Y-m-d' );
		$basename     = $missing_only ? 'sidrena-nedostaje-' . $stamp : 'sidrena-cjenik-' . $stamp;

		if ( 'xml' === $format ) {
			CX_Sidrena_Feed_Files::instance()->send_xml( $basename . '.xml', $rows, $missing_only, $public );
		} else {
			CX_Sidrena_Feed_Files::instance()->send_csv( $basename . '.csv', $rows, $public );
		}
	}

	/**
	 * Collect catalog rows: name, sku, prices.
	 *
	 * @param bool $missing_only Only products/variations without sidrena price.
	 * @param bool $public       Published catalog for the public XML (adds id/url).
	 * @return array<int, array<string, string>>
	 */
	public function collect_rows( $missing_only = false, $public = false, $only_ids = null, $catalog_scope = null ) {
		return CX_Sidrena_Feed_Rows::instance()->collect_rows($missing_only, $public, $only_ids, $catalog_scope);
	}

	/**
	 * xml or csv from settings.
	 *
	 * @return string
	 */
	public function export_format() {
		return CX_Sidrena_Feed_Files::instance()->export_format();
	}

	/**
	 * Live public filename for a format.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function live_filename( $format = '', $oznaka = '' ) {
		return CX_Sidrena_Feed_Files::instance()->live_filename($format, $oznaka);
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
		return CX_Sidrena_Archive::instance()->legal_filename($format, $timestamp, $pohrana, $outlet);
	}

	/**
	 * Last published legal filename, or a preview of the next one.
	 *
	 * @param string $format xml|csv.
	 * @return string
	 */
	public function published_filename( $format = '' ) {
		return CX_Sidrena_Archive::instance()->published_filename($format);
	}

	/**
	 * Build an XML document string.
	 *
	 * @param array<int, array<string, string>> $rows           Rows.
	 * @param string                            $root           Root element name.
	 * @param bool                              $public_fields  The public price list's columns.
	 * @return string
	 */
	public function build_xml_string( $rows, $root, $public_fields = false ) {
		return CX_Sidrena_Feed_Files::instance()->build_xml_string($rows, $root, $public_fields);
	}

	/**
	 * Absolute directory for the public XML file.
	 *
	 * @return string
	 */
	public function get_public_dir() {
		return CX_Sidrena_Feed_Files::instance()->get_public_dir();
	}

	/**
	 * Absolute path of the live public feed.
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_path( $format = '', $oznaka = '' ) {
		return CX_Sidrena_Feed_Files::instance()->get_public_path($format, $oznaka);
	}

	/**
	 * Absolute path of the generated HTML table for the public page and shortcode.
	 *
	 * @param string $oznaka Outlet code, empty for the full catalog.
	 * @return string
	 */
	public function get_cjenik_html_path( $oznaka = '' ) {
		return CX_Sidrena_Feed_Files::instance()->get_cjenik_html_path($oznaka);
	}

	/**
	 * Public URL (pretty permalink, or query fallback).
	 *
	 * @param string $format xml|csv, empty = current setting.
	 * @return string
	 */
	public function get_public_url( $format = '', $oznaka = '' ) {
		return CX_Sidrena_Feed_Files::instance()->get_public_url($format, $oznaka);
	}

	/**
	 * Public archive index URL.
	 *
	 * @return string
	 */
	public function get_archive_url() {
		return CX_Sidrena_Feed_Files::instance()->get_archive_url();
	}

	/**
	 * Public HTML pricelist URL (standalone mode).
	 *
	 * @return string
	 */
	public function get_cjenik_url( $oznaka = '' ) {
		return CX_Sidrena_Feed_Files::instance()->get_cjenik_url( $oznaka );
	}

	/**
	 * Public URL for one archived feed file.
	 *
	 * @param string $name_or_date Legal filename or legacy d-m-Y.
	 * @param string $ext          xml|csv (legacy date URLs).
	 * @return string
	 */
	public function get_archive_file_url( $name_or_date, $ext = 'xml' ) {
		return CX_Sidrena_Feed_Files::instance()->get_archive_file_url($name_or_date, $ext);
	}

	/**
	 * Directory for dated archive XML files.
	 *
	 * @return string
	 */
	public function get_archive_dir() {
		return CX_Sidrena_Archive::instance()->get_archive_dir();
	}

	/**
	 * The webshop's newest archive file. The build itself archives (CX-SID-27); callers that still run this after
	 * regenerate_public_xml() change nothing.
	 *
	 * @return string|WP_Error Path or error.
	 */
	public function archive_public_xml() {
		return CX_Sidrena_Archive::instance()->archive_public_xml();
	}

	/**
	 * Archived feed files, newest first. Older days keep their original xml/csv type.
	 *
	 * @return array<int, array{date:string,ext:string,name:string,path:string,url:string,bytes:int}>
	 */
	public function get_archive_files() {
		return CX_Sidrena_Archive::instance()->get_archive_files();
	}

	/**
	 * Align the daily cron with the setting: 00:05 on the legal clock (CX_Sidrena_Archive::legal_timezone).
	 */
	public function sync_daily_schedule() {
		if ( 'yes' !== CX_Sidrena_Settings::instance()->get( 'daily_xml' ) ) {
			wp_clear_scheduled_hook( self::DAILY_HOOK );
			return;
		}

		$next = wp_next_scheduled( self::DAILY_HOOK );
		if ( $next && $this->timestamp_is_daily_hour( $next ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_schedule_event( $this->next_daily_timestamp(), 'daily', self::DAILY_HOOK );
	}

	/**
	 * Cron callback, 00:05: the morning file. The build archives what it publishes (CX_Sidrena_Archive::publish()),
	 * then the archive's upkeep (CX_Sidrena_Archive::maintain()).
	 */

	public function run_daily_regenerate() {
		$this->regenerate_public_xml();
		// Also when the build failed: files past the public 30 days are kept, kept files past their period go.
		CX_Sidrena_Archive::instance()->maintain();
	}

	/**
	 * Queue a one-off public XML build (activation / first run).
	 */

	public function queue_public_xml() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::GENERATE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::GENERATE_HOOK );
		}
	}

	/**
	 * Rebuild the public file a minute after the last price, sale, or stock change.
	 *
	 * Each new change pushes the single cron event forward, so a burst of saves
	 * writes the catalog once. The daily 00:05 job is a separate hook and stays.
	 */
	public function queue_feed_after_change() {
		if ( $this->suppress_change_queue || CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return;
		}

		wp_clear_scheduled_hook( self::GENERATE_HOOK );
		wp_schedule_single_event( time() + self::CHANGE_DELAY, self::GENERATE_HOOK );
	}

	/**
	 * Queue a rebuild when a product or variation is trashed, restored, or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function queue_feed_after_product_post( $post_id ) {
		$type = get_post_type( $post_id );
		if ( ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$this->queue_feed_after_change();
	}

	/**
	 * Cron callback for the one-off build (a change, activation, a setting). The build archives what it publishes.
	 */

	public function run_queued_generate() {
		$this->regenerate_public_xml();
	}

	/**
	 * Public feed hits never build the catalog. A missing file is queued once.
	 */
	public function maybe_ensure_public_xml_on_init() {
		CX_Sidrena_Feed_Routes::instance()->maybe_ensure_public_xml_on_init();
	}

	/**
	 * One catalog rebuild at a time. The lock expires if the process dies.
	 *
	 * @return bool
	 */
	private function acquire_rebuild_lock() {
		if ( get_transient( self::REBUILD_LOCK ) ) {
			return false;
		}

		set_transient( self::REBUILD_LOCK, 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Build the public XML if the file is missing. Wp-admin only; public requests queue cron.
	 *
	 * @return array<string, mixed>|WP_Error|true True when the file already exists.
	 */

	public function maybe_ensure_public_xml() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return true; // nothing is published (CX-SID-28)
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			CX_Sidrena_Feed_Routes::instance()->schedule_public_rebuild_if_missing();
			return true;
		}

		if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			$oznaka = CX_Sidrena_Feed_Routes::instance()->requested_oznaka();
			if ( '' === $oznaka ) {
				return true;
			}

			if ( file_exists( $this->get_public_path( $this->export_format(), $oznaka ) ) ) {
				return true;
			}

			return $this->regenerate_one_outlet( $oznaka );
		}

		if ( file_exists( $this->get_public_path() ) ) {
			return true;
		}

		return $this->regenerate_public_xml();
	}

	public function get_next_daily_timestamp() {
		$next = wp_next_scheduled( self::DAILY_HOOK );
		return $next ? (int) $next : 0;
	}

	/**
	 * Whether a timestamp falls on 00:05 on the legal clock.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool
	 */

	private function timestamp_is_daily_hour( $timestamp ) {
		$dt = new DateTime( '@' . (int) $timestamp );
		$dt->setTimezone( CX_Sidrena_Archive::legal_timezone() );

		return self::DAILY_HOUR === (int) $dt->format( 'G' ) && self::DAILY_MINUTE === (int) $dt->format( 'i' );
	}

	/**
	 * Next 00:05 on the legal clock (after midnight, before the 08:00 deadline).
	 *
	 * @return int
	 */

	private function next_daily_timestamp() {
		$tz   = CX_Sidrena_Archive::legal_timezone();
		$now  = new DateTime( 'now', $tz );
		$next = new DateTime( sprintf( 'today %02d:%02d:00', self::DAILY_HOUR, self::DAILY_MINUTE ), $tz );

		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	/**
	 * Last public XML generation meta.
	 *
	 * @return array<string, mixed>
	 */

	public function get_public_meta() {
		$stored = get_option( self::PUBLIC_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the public catalog XML to uploads.
	 *
	 * @return array<string, mixed>|WP_Error
	 */

	public function regenerate_public_xml() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return $this->b2b_no_price_list();
		}

		if ( ! $this->acquire_rebuild_lock() ) {
			return new WP_Error( 'sidrena_xml_busy', __( 'The public price list is already being generated.', 'cx-sidrena' ) );
		}

		$this->suppress_change_queue = true;

		try {
			// The whole build as a visitor, whoever started it (CX-SID-28): the rows, the files and the HTML table.
			$result = CX_Sidrena_Feed_Rows::as_visitor(
				function () {
					return $this->build_public_feeds();
				}
			);
			if ( ! is_wp_error( $result ) ) {
				// Only after a complete build in the current format: until then the old file is the only price list.
				CX_Sidrena_Feed_Files::instance()->remove_other_format_live( $this->export_format() );
			}

			return $result;
		} finally {
			$this->suppress_change_queue = false;
			delete_transient( self::REBUILD_LOCK );
		}
	}

	/**
	 * The live files of the webshop (and of every outlet), under the rebuild lock.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_public_feeds() {
		if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) {
			return $this->regenerate_poslovnice_feeds();
		}

		if ( CX_Sidrena_Plugin::woocommerce_active() && ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'sidrena_xml_wc', __( 'WooCommerce is not ready to generate the price list.', 'cx-sidrena' ) );
		}

		try {
			$rows   = $this->collect_rows( false, true, null, null );
			$result = CX_Sidrena_Feed_Files::instance()->write_feed_file( $rows, '' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			update_option( self::PUBLIC_OPTION, $result + array( 'layout' => self::FEED_LAYOUT ) );

			return $result;
		} catch ( Throwable $e ) {
			return $this->feed_failure( $e );
		}
	}

	/**
	 * Rebuild every outlet feed that has products.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function regenerate_poslovnice_feeds() {
		if ( CX_Sidrena_Plugin::woocommerce_active() && ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'sidrena_xml_wc', __( 'WooCommerce is not ready to generate the price list.', 'cx-sidrena' ) );
		}

		try {
			$prev    = $this->get_public_meta();
			$stored  = ( isset( $prev['outlets'] ) && is_array( $prev['outlets'] ) ) ? $prev['outlets'] : array();
			$total   = 0;
			$latest  = 0;

			$bundle   = CX_Sidrena_Feed_Rows::instance()->collect_public_by_scope();
			$web_rows = isset( $bundle['webshop'] ) ? $bundle['webshop'] : array();
			$web      = CX_Sidrena_Feed_Files::instance()->write_feed_file( $web_rows, '' );
			if ( is_wp_error( $web ) ) {
				return $web;
			}
			$total  += (int) $web['count'];
			$latest  = max( $latest, (int) $web['generated'] );

			$scoped = isset( $bundle['outlets'] ) && is_array( $bundle['outlets'] ) ? $bundle['outlets'] : array();
			foreach ( CX_Sidrena_Poslovnice::instance()->with_products() as $outlet ) {
				$rows   = isset( $scoped[ $outlet['oznaka'] ] ) ? $scoped[ $outlet['oznaka'] ] : null;
				$result = $this->write_outlet_feed( $outlet, $stored, $rows );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$stored[ $outlet['oznaka'] ] = $result;
				$total                      += (int) $result['count'];
				$latest                      = max( $latest, (int) $result['generated'] );
			}

			$meta = array(
				'generated' => $latest > 0 ? $latest : time(),
				'count'     => $total,
				'format'    => $this->export_format(),
				'published' => $web['published'],
				'filename'  => $web['filename'],
				'pohrana'   => $web['pohrana'],
				'outlets'   => $stored,
				'layout'    => self::FEED_LAYOUT,
			);
			$meta['webshop'] = $web;
			update_option( self::PUBLIC_OPTION, $meta );

			return $meta;
		} catch ( Throwable $e ) {
			return $this->feed_failure( $e );
		}
	}

	/**
	 * Rebuild one outlet when its public URL is opened and the file is missing.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>|WP_Error|true
	 */

	private function regenerate_one_outlet( $oznaka ) {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return $this->b2b_no_price_list();
		}

		$outlet = CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka );
		if ( ! is_array( $outlet ) ) {
			return new WP_Error( 'sidrena_poslovnica_missing', __( 'The outlet has no published price list.', 'cx-sidrena' ) );
		}

		try {
			$prev   = $this->get_public_meta();
			$stored = ( isset( $prev['outlets'] ) && is_array( $prev['outlets'] ) ) ? $prev['outlets'] : array();
			$result = CX_Sidrena_Feed_Rows::as_visitor(
				function () use ( $outlet, $stored ) {
					return $this->write_outlet_feed( $outlet, $stored );
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stored[ $outlet['oznaka'] ] = $result;
			$prev['generated']           = (int) $result['generated'];
			$prev['format']              = $this->export_format();
			$prev['outlets']             = $stored;
			update_option( self::PUBLIC_OPTION, $prev );

			return $result;
		} catch ( Throwable $e ) {
			return $this->feed_failure( $e );
		}
	}

	/**
	 * A shop that sells only to businesses (B2B) publishes no price list (CX-SID-28): no build, and the live files and
	 * HTML tables go, so its addresses answer 404 (CX_Sidrena_Feed_Routes). The archive stays as CX-SID-27 keeps it.
	 *
	 * @return WP_Error
	 */
	private function b2b_no_price_list() {
		wp_clear_scheduled_hook( self::GENERATE_HOOK );
		CX_Sidrena_Feed_Files::instance()->remove_live_files();

		return new WP_Error( 'sidrena_b2b_only', self::b2b_message() );
	}

	/**
	 * What the owner reads when the shop is B2B only (Status, Settings, the build's error).
	 *
	 * @return string
	 */
	public static function b2b_message() {
		return __( 'Your shop sells only to businesses (B2B), so no public price list is made. The archive stays as it is.', 'cx-sidrena' );
	}

	/**
	 * The settings option changed: B2B on or off, or the availability column (CX-SID-28).
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public function after_settings_change( $old, $new ) {
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		$was = isset( $old['b2b_only'] ) && 'yes' === $old['b2b_only'];
		$is  = isset( $new['b2b_only'] ) && 'yes' === $new['b2b_only'];

		// Written straight to the option (not through save()): the cached settings would still say the old value.
		CX_Sidrena_Settings::instance()->flush();

		if ( $was !== $is ) {
			$this->apply_b2b_only();
			return;
		}

		$col_was = isset( $old['feed_dostupnost'] ) && 'yes' === $old['feed_dostupnost'];
		$col_is  = isset( $new['feed_dostupnost'] ) && 'yes' === $new['feed_dostupnost'];
		if ( $col_was !== $col_is ) {
			$this->queue_public_xml();
		}
	}

	/**
	 * B2B switched: on, the live price list goes now; off, a build in a few seconds brings it back.
	 */
	public function apply_b2b_only() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			$this->b2b_no_price_list();
			return;
		}

		$this->queue_public_xml();
	}

	/**
	 * Log a feed failure and return a fixed message safe to show in wp-admin.
	 *
	 * @param Throwable $exception Caught error.
	 * @return WP_Error
	 */

	private function feed_failure( $exception ) {
		CX_Sidrena_Plugin::log_exception( $exception );

		return new WP_Error( 'sidrena_xml_fatal', __( 'The public price list could not be generated.', 'cx-sidrena' ) );
	}

	/**
	 * Write one outlet feed: its archive file and live file (CX_Sidrena_Archive::publish()).
	 *
	 * @param array<string, mixed>             $outlet Outlet.
	 * @param array<string, array<string, mixed>> $stored Previous per-outlet meta.
	 * @param array<int, array<string, string>>|null $rows Prebuilt rows, or null to collect.
	 * @return array<string, mixed>|WP_Error
	 */
	private function write_outlet_feed( $outlet, $stored, $rows = null ) {
		if ( null === $rows ) {
			$rows = $this->collect_rows( false, true, isset( $outlet['products'] ) ? $outlet['products'] : array(), $outlet['oznaka'] );
		}
		return CX_Sidrena_Feed_Files::instance()->write_feed_file( $rows, $outlet['oznaka'], $outlet );
	}

	/**
	 * Last generation meta for one outlet.
	 *
	 * @param string $oznaka Object code.
	 * @return array<string, mixed>
	 */
	public function get_outlet_meta( $oznaka ) {
		return CX_Sidrena_Archive::instance()->get_outlet_meta($oznaka);
	}

	/**
	 * Serve public XML, archive index, or an archived file.
	 */
	public function maybe_serve_public_xml() {
		CX_Sidrena_Feed_Routes::instance()->maybe_serve_public_xml();
	}

	/**
	 * File list for the [sidrena-arhiva] shortcode. Name and date only.
	 *
	 * @param string $oznaka Object code, empty for every file.
	 * @return string
	 */
	public function get_archive_embed_html( $oznaka = '' ) {
		return CX_Sidrena_Archive::instance()->get_archive_embed_html( $oznaka );
	}

	/**
	 * Searchable pricelist table for the public page and the [sidrena-cjenik] shortcode.
	 *
	 * Reads the file written with the public feed. A missing file queues one rebuild.
	 *
	 * @param string                                  $oznaka Outlet code, empty for the full list.
	 * @param array<int, array<string, string>>|null $rows   Already built rows. Public calls leave this null.
	 * @return string
	 */
	public function get_cjenik_embed_html( $oznaka = '', $rows = null ) {
		return CX_Sidrena_Feed_Files::instance()->get_cjenik_embed_html($oznaka, $rows);
	}
}
