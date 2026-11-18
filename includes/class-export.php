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
	const REBUILD_LOCK     = 'cx_sidrena_rebuild_lock'; // the lock of 0.2.2 and before (a transient); still respected
	const REBUILD_COOLDOWN = 'cx_sidrena_rebuild_cooldown';
	const CHANGE_DELAY     = 60;
	// The rebuild lock (CX-SID-119): a row in the options table, "<time>:<token>". One request wins the insert; a lock
	// older than REBUILD_STALE was left by a build that died, and exactly one request takes it over.
	const REBUILD_LOCK_ROW = 'cx_sidrena_rebuild.lock';
	const REBUILD_STALE    = 900; // 15 minutes, as the transient's expiry was
	const BUSY_DELAY       = 60;  // a build that found another one running comes again after this many seconds
	const RETRY_OPTION     = 'cx_sidrena_rebuild_fails'; // failed builds in a row; deleted by a build that succeeds
	const RETRY_DELAYS     = array( 300, 900, 3600 );    // after the 1st, 2nd, and every later failure
	// The first price list waits for the first bazna (CX-SID-68): set by a build that published nothing for that reason,
	// deleted by a build that published. An options row, not a transient: it must outlive the request (a transient
	// lives only in one request's memory on a shop with W3TC's object-cache.php and its cache off).
	const AWAIT_BAZNA      = 'cx_sidrena_await_bazna';
	// The columns of the public files (CX_Sidrena_Feed_Files::COLUMNS_*). Every build stores it in the public meta; a
	// shop whose meta has another one (an update) gets one rebuild on the next visit, not at 00:05 (CX-SID-109).
	const FEED_LAYOUT      = '88'; // 88: poseban oblik as the page shows it (CX-SID-88) and the table in the shop's language (CX-SID-121), rebuilt right after the update to 0.2.3

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
	 * The value of the rebuild lock row while this request holds it, else ''.
	 *
	 * @var string
	 */
	private $lock_value = '';

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

		// A build already due within a minute is enough (a change's); a retry an hour away is not (CX-SID-119).
		$this->queue_build( 5, self::CHANGE_DELAY );
	}

	/**
	 * One queued build in $delay seconds, unless one is already due within $enough seconds. A later one (a retry of a
	 * failed build) is replaced by this sooner one; if that fails too, it queues its own retry.
	 *
	 * @param int $delay  Seconds from now.
	 * @param int $enough An existing build due within this many seconds is kept.
	 */
	private function queue_build( $delay, $enough ) {
		$next = wp_next_scheduled( self::GENERATE_HOOK );
		if ( $next && (int) $next <= time() + (int) $enough ) {
			return;
		}

		wp_clear_scheduled_hook( self::GENERATE_HOOK );
		wp_schedule_single_event( time() + (int) $delay, self::GENERATE_HOOK );
	}

	/**
	 * A build failed when the owner asked for it (Settings, Status): the next attempt within 5 minutes, also when the
	 * retries are already an hour apart, so the message "in a few minutes" is true (CX-SID-119).
	 */
	public function retry_soon() {
		if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return;
		}

		$this->queue_build( self::RETRY_DELAYS[0], self::RETRY_DELAYS[0] );
	}

	/**
	 * What the owner is told about a build's result (Settings ?sidrena_xml=, Status ?cx_updated=):
	 * '1' built, 'b2b' nothing to publish, 'nobazna' the first price list waits for the first bazna (CX-SID-68), 'busy'
	 * another build was running (this one comes after it), '2' no outlet has products, '0' a file or folder could not be
	 * written (the uploads folder), 'fail' any other failure.
	 *
	 * @param array<string, mixed>|WP_Error $result The build's result.
	 * @return string
	 */
	public static function result_flag( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return '1';
		}

		$code  = $result->get_error_code();
		$flags = array(
			'sidrena_b2b_only'          => 'b2b',
			'sidrena_no_bazna'          => 'nobazna',
			'sidrena_xml_busy'          => 'busy',
			'sidrena_poslovnice_empty'  => '2',
			'sidrena_xml_uploads'       => '0',
			'sidrena_xml_mkdir'         => '0',
			'sidrena_xml_write'         => '0',
			'sidrena_cjenik_write'      => '0',
			'sidrena_xml_archive_mkdir' => '0',
			'sidrena_xml_archive_copy'  => '0',
		);

		return isset( $flags[ $code ] ) ? $flags[ $code ] : 'fail';
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
	 * One catalog rebuild at a time (CX-SID-119). The old lock was a transient, read and then written, so two requests
	 * could both take it. Now a row in the options table: INSERT IGNORE lets one request win. A lock older than
	 * REBUILD_STALE was left by a build that died; the UPDATE checks the old value, so only one request takes it over.
	 * While a build of 0.2.2 may still run (the update's window), its transient counts as busy too.
	 *
	 * @return bool
	 */
	private function acquire_rebuild_lock() {
		global $wpdb;

		// A build: none of its own queries from a database query cache (the lock, the counter; CX-SID-119 dopuna).
		CX_Sidrena_Archive::no_db_cache();
		if ( get_transient( self::REBUILD_LOCK ) ) {
			return false;
		}

		$mine = time() . ':' . wp_generate_password( 20, false );
		for ( $try = 0; $try < 2; $try++ ) {
			$suppress = $wpdb->suppress_errors( true );
			$ins      = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", self::REBUILD_LOCK_ROW, $mine ) ); // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( $suppress );
			if ( $ins ) {
				$this->lock_value = $mine;
				return true;
			}

			$held = $this->rebuild_lock_value();
			if ( null === $held ) {
				continue; // released in between: try again
			}
			if ( (int) $held > time() - self::REBUILD_STALE ) {
				return false;
			}

			$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $mine, self::REBUILD_LOCK_ROW, $held ) ); // phpcs:ignore WordPress.DB
			if ( 1 === (int) $took ) {
				$this->lock_value = $mine;
				return true;
			}

			return false; // another request took it over first
		}

		return false;
	}

	/**
	 * Release the lock, only if it is still ours: a build that ran past REBUILD_STALE and was taken over must not
	 * remove the lock of the build that took over.
	 */
	private function release_rebuild_lock() {
		global $wpdb;

		if ( '' === $this->lock_value ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::REBUILD_LOCK_ROW, $this->lock_value ) ); // phpcs:ignore WordPress.DB
		$this->lock_value = '';
	}

	/**
	 * @return string|null The lock row's value, or null when nobody holds it.
	 */
	private function rebuild_lock_value() {
		global $wpdb;

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::REBUILD_LOCK_ROW ) ); // phpcs:ignore WordPress.DB

		return null === $held ? null : (string) $held;
	}

	/**
	 * Whether a rebuild is running now (a lock younger than REBUILD_STALE, or the transient of 0.2.2).
	 *
	 * @return bool
	 */
	public function rebuild_running() {
		// Reached on a public visit only when a rebuild may be queued (a missing file, an older layout), never on an
		// ordinary page view (CX_Sidrena_Feed_Routes::schedule_public_rebuild(); Rook, 2925).
		CX_Sidrena_Archive::no_db_cache();
		if ( get_transient( self::REBUILD_LOCK ) ) {
			return true;
		}

		$held = $this->rebuild_lock_value();

		return null !== $held && (int) $held > time() - self::REBUILD_STALE;
	}

	/**
	 * Another build holds the lock: this one is not dropped but comes again after BUSY_DELAY (CX-SID-119). Read at
	 * its start, so what was saved meanwhile (the OIB in the file name) is in it.
	 *
	 * @return WP_Error
	 */
	private function busy() {
		$this->queue_build( self::BUSY_DELAY, self::BUSY_DELAY );

		return new WP_Error( 'sidrena_xml_busy', __( 'The public price list is already being generated.', 'cx-sidrena' ) );
	}

	/**
	 * After a build: a failure queues its retry in 5, then 15 minutes, then every hour until one succeeds; a success
	 * clears the count (CX-SID-119). Not a failure: busy (it is queued), B2B (nothing to publish), no outlet products,
	 * no bazna yet (CX-SID-68: the first bazna queues the build, as every product change does).
	 *
	 * @param array<string, mixed>|WP_Error $result The build's result.
	 */
	private function after_build( $result ) {
		if ( ! is_wp_error( $result ) ) {
			delete_option( self::RETRY_OPTION );
			return;
		}

		if ( in_array( $result->get_error_code(), array( 'sidrena_b2b_only', 'sidrena_no_bazna', 'sidrena_poslovnice_empty', 'sidrena_xml_busy' ), true ) ) {
			return;
		}

		$fails = (int) get_option( self::RETRY_OPTION, 0 ) + 1;
		update_option( self::RETRY_OPTION, $fails, false );
		$delay = self::RETRY_DELAYS[ min( $fails, count( self::RETRY_DELAYS ) ) - 1 ];
		$this->queue_build( $delay, $delay );
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
			return $this->busy();
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
		} catch ( Throwable $e ) {
			$result = $this->feed_failure( $e );
		} finally {
			$this->suppress_change_queue = false;
			$this->release_rebuild_lock();
		}

		$this->after_build( $result );

		return $result;
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
			$rows    = $this->collect_rows( false, true, null, null );
			$waiting = $this->wait_for_first_bazna( array( $rows ) );
			if ( null !== $waiting ) {
				return $waiting;
			}

			$result = CX_Sidrena_Feed_Files::instance()->write_feed_file( $rows, '' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			update_option( self::PUBLIC_OPTION, $result + array( 'layout' => self::FEED_LAYOUT ) );
			delete_option( self::AWAIT_BAZNA );

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
			$scoped   = isset( $bundle['outlets'] ) && is_array( $bundle['outlets'] ) ? $bundle['outlets'] : array();
			// A bazna in any one outlet publishes them all (CX-SID-68): the webshop and every outlet are one price list.
			$waiting = $this->wait_for_first_bazna( array_merge( array( $web_rows ), array_values( $scoped ) ) );
			if ( null !== $waiting ) {
				return $waiting;
			}

			$web = CX_Sidrena_Feed_Files::instance()->write_feed_file( $web_rows, '' );
			if ( is_wp_error( $web ) ) {
				return $web;
			}
			$total  += (int) $web['count'];
			$latest  = max( $latest, (int) $web['generated'] );

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
			delete_option( self::AWAIT_BAZNA );

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

		// Under the same lock as the whole build (CX-SID-119): both write the public meta and the storage numbers.
		if ( ! $this->acquire_rebuild_lock() ) {
			return $this->busy();
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
		} finally {
			$this->release_rebuild_lock();
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
	 * The first price list waits for the first bazna (CX-SID-68, SPEC §2.8). A price list without a bazna is not
	 * compliant (art. 3(2) of the Pravilnik o cjeniku) and, once published, must be kept and shown for 15 days, so a shop
	 * that has never published publishes nothing until one row of its public price list has a bazna: no file, no HTML
	 * table, no archive file. However it got there (the product, Import, Fix all, WP-CLI, MJDD's data): every way in is
	 * the owner's. A shop that has published goes on as before, also without a bazna: its price list must exist and be
	 * current (art. 3(1)); Status is red then. Nothing is filled in here and nothing is deleted.
	 *
	 * @param array<int, array<int, array<string, string>>> $row_sets The public rows, one set per scope.
	 * @return WP_Error|null The refusal, or null to publish.
	 */
	private function wait_for_first_bazna( $row_sets ) {
		foreach ( $row_sets as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( '' !== trim( isset( $row['sidrena_cijena'] ) ? (string) $row['sidrena_cijena'] : '' ) ) {
					return null;
				}
			}
		}

		if ( $this->ever_published() ) {
			return null;
		}

		// Public visits stop queueing builds (CX_Sidrena_Feed_Routes::schedule_public_rebuild()); the ways a bazna gets
		// in queue one as before, and so does 00:05.
		update_option( self::AWAIT_BAZNA, time(), false );

		return new WP_Error( 'sidrena_no_bazna', self::no_bazna_message() );
	}

	/**
	 * Whether this shop has ever published a price list: a build's meta, a live file, or a file in the archive (also
	 * one taken over from MJDD).
	 *
	 * @return bool
	 */
	public function ever_published() {
		$meta = $this->get_public_meta();
		if ( ! empty( $meta['generated'] ) ) {
			return true;
		}

		foreach ( array( 'xml', 'csv' ) as $ext ) {
			if ( file_exists( $this->get_public_path( $ext ) ) ) {
				return true;
			}
		}

		return array() !== $this->get_archive_files();
	}

	/**
	 * Whether the last build published nothing because no row had a bazna yet (CX-SID-68).
	 *
	 * @return bool
	 */
	public function awaiting_bazna() {
		return false !== get_option( self::AWAIT_BAZNA, false );
	}

	/**
	 * What the owner reads while the first price list waits for the first bazna (Kai's sentence, CX-SID-68): Status
	 * and "Save and generate now".
	 *
	 * @return string
	 */
	public static function no_bazna_message() {
		return __( 'The price list has not been published yet because no bazna cijena has been entered. The obligation to publish applies from 17.11.2026.', 'cx-sidrena' );
	}

	/**
	 * What a visitor reads at the price list's addresses before the first price list (SPEC §2.8).
	 *
	 * @return string
	 */
	public static function not_published_message() {
		return __( 'The price list has not been published yet.', 'cx-sidrena' );
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
