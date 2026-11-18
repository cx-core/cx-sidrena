<?php
/**
 * The Status tab (spec §1.4): is the shop in order, at a glance, and the fixes in place (CX-SID-42): "Use regular price"
 * and "Fix all" open one confirmation dialog and write only through the model's fill (CX_Sidrena_Fill, CX-SID-22), which
 * keeps history, source and the date rule; "Keep earlier" only takes a product off the list. Both fixes wait for saved
 * Dates (spec §1.1): the fill stores each product's date, and a product's own date never changes afterwards.
 *
 * Also the take-over notice (§1.2) and the links to report a bug or suggest a feature (on Status and in the plugin's
 * row on Plugins). The plugin sends nothing by itself: every link only opens GitHub or the mail program, with the
 * versions already written in, and only when the user clicks it.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status data and screen.
 */
class CX_Sidrena_Admin_Status {

	const PER_PAGE         = 50;
	const TAKEOVER_SEEN    = 'cx_sidrena_takeover_seen';
	const ISSUES_URL       = 'https://github.com/cx-core/cx-sidrena/issues/new';
	const BUG_TEMPLATE     = 'bug_report.yml';
	const FEATURE_TEMPLATE = 'feature_request.yml';
	const ENV_FIELD        = 'environment';
	const MAIL             = 'sidrena@cx-core.com';
	const TWO_CAT_OK       = 'cx_sidrena_two_cat_ok';
	const NONCE            = 'cx_sidrena_status';

	/**
	 * What a customer sees on a product page, as measured in a browser from Status (CX-SID-92): the theme, its version,
	 * ours, when, the product and the finding per width.
	 */
	const SEEN_DISPLAY = 'cx_sidrena_seen_display';

	/** The widths a product page is measured at: a computer and a phone. */
	const DISPLAY_WIDTHS = array( 1280, 390 );

	/** A finding is checked again after this, and when the theme or a version changes. */
	const DISPLAY_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Hour by which the day's price list must be public (NN 101/2026: before 08:00).
	 */
	const DEADLINE_HOUR = 8;

	/**
	 * A queued first build overdue by more than this means WP-cron does not run (CX-SID-68): a server cron every 15
	 * minutes, as Settings recommends, plus a margin.
	 */
	const FIRST_BUILD_LATE = 20 * MINUTE_IN_SECONDS;

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * One scan per request.
	 *
	 * @var array|null
	 */
	private $scan = null;

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
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'wp_ajax_cx_sidrena_status_fill', array( $this, 'ajax_fill' ) );
		add_action( 'wp_ajax_cx_sidrena_status_progress', array( $this, 'ajax_progress' ) );
		add_action( 'wp_ajax_cx_sidrena_two_cat_ok', array( $this, 'ajax_two_cat_ok' ) );
		add_action( 'wp_ajax_cx_sidrena_display_seen', array( $this, 'ajax_display_seen' ) );
	}

	/**
	 * Status URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'sidrena-cijena', 'tab' => 'status' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * "Update now" (red banner) and closing the take-over notice.
	 */
	public function handle_actions() {
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( isset( $_POST['cx_sidrena_status_action'] ) && 'update_now' === $_POST['cx_sidrena_status_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'cx_sidrena_update_now', 'cx_sidrena_status_nonce' );
			// Only the price list: never the settings (the old "Generate" button also saved the daily switch).
			$result = CX_Sidrena_Export::instance()->regenerate_public_xml();
			if ( ! is_wp_error( $result ) ) {
				CX_Sidrena_Export::instance()->archive_public_xml();
			}
			// b2b: not a failure, a B2B shop publishes no price list (CX-SID-28); busy: another build was running and
			// this one comes after it (CX-SID-119).
			$flag = CX_Sidrena_Export::result_flag( $result );
			if ( in_array( $flag, array( '0', 'fail' ), true ) ) {
				CX_Sidrena_Export::instance()->retry_soon();
			}
			wp_safe_redirect( self::url( array( 'cx_updated' => $flag ) ) );
			exit;
		}

		if ( isset( $_GET['cx_sidrena_takeover_seen'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'cx_sidrena_takeover_seen' );
			$done = get_option( 'cx_sidrena_migrated', array() );
			update_option( self::TAKEOVER_SEEN, is_array( $done ) && isset( $done['at'] ) ? (int) $done['at'] : 1, false );
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Published simple products and variations with a price: what has a bazna, what does not, and whose bazna date is
	 * later than expected. One query, as the add-on's check (cx_sidrena_check), without loading products.
	 *
	 * @return array{total:int, with:int, missing:int[], two_cat:int[], late:int[], not_lower:int[], on_sale:int, rows:array<int,object>}
	 */
	public function scan() {
		if ( null !== $this->scan ) {
			return $this->scan;
		}

		global $wpdb;
		$ref_txt = (string) CX_Sidrena_Settings::instance()->get( 'default_date' );
		$ref     = DateTime::createFromFormat( '!d.m.Y', rtrim( trim( $ref_txt ), '.' ), wp_timezone() );
		$ref_end = $ref ? ( clone $ref )->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) : '1970-01-01 00:00:00';

		$skip_types = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug IN ('variable','grouped')";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_parent, p.post_date, p.post_title,
					pr.meta_value AS bazna, dt.meta_value AS datum, rp.meta_value AS redovna
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} dt ON dt.post_id = p.ID AND dt.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
				WHERE p.post_status = 'publish'
				AND ( ( p.post_type = 'product' AND p.ID NOT IN ( $skip_types ) )
					OR ( p.post_type = 'product_variation' AND par.post_status = 'publish' ) )
				ORDER BY p.ID",
				CX_SIDRENA_META_PRICE,
				CX_SIDRENA_META_DATE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array( 'total' => 0, 'with' => 0, 'missing' => array(), 'late' => array(), 'not_lower' => array(), 'on_sale' => 0, 'rows' => array() );
		// With CX-SID-21's dates, "later than expected" is judged against each product's own rule date, exactly as
		// wp cx-sidrena check does (includes/check.php): its category's date, or the day it was added when later.
		$by_rule = class_exists( 'CX_Sidrena_Dates' ) && method_exists( 'CX_Sidrena_Dates', 'date_for' );
		$dated   = array();
		foreach ( (array) $rows as $r ) {
			if ( '' !== trim( (string) $r->redovna ) && '' !== trim( (string) $r->bazna ) && '' !== trim( (string) $r->datum ) ) {
				$dated[] = $r;
			}
		}
		if ( $by_rule && $dated ) {
			// The rule reads each product's post, categories and meta, and a variation its parent's categories: load
			// them for the whole list at once, not one query per product (CX-SID-21 reads categories with get_the_terms,
			// which takes them from this cache; Tux's test D-list-no-queries).
			$ids = array_map( 'intval', wp_list_pluck( $dated, 'ID' ) );
			$all = array_values( array_unique( array_merge( $ids, array_map( 'intval', array_filter( wp_list_pluck( $dated, 'post_parent' ) ) ) ) ) );
			_prime_post_caches( $all, false, false );
			update_object_term_cache( $all, array( 'product', 'product_variation' ) );
			update_meta_cache( 'post', $ids );
		}
		foreach ( (array) $rows as $r ) {
			// No price = cannot be bought, so it needs no bazna either (cx_sidrena_check does the same).
			if ( '' === trim( (string) $r->redovna ) ) {
				continue;
			}
			$id                  = (int) $r->ID;
			$out['rows'][ $id ]  = $r;
			$out['total']++;
			if ( '' === trim( (string) $r->bazna ) ) {
				$out['missing'][] = $id;
				continue;
			}
			$out['with']++;
			// Its own date is later than the date it should have (MJDD's trap: today's date on an old product). An
			// earlier own date is right (CX-SID-13), and a date that cannot be read is on the list.
			$own = trim( (string) $r->datum );
			if ( '' !== $own && $by_rule && function_exists( 'cx_sidrena_check_parse_date' ) ) {
				$own_date  = cx_sidrena_check_parse_date( $own );
				$rule_date = cx_sidrena_check_parse_date( (string) CX_Sidrena_Dates::date_for( $id )['rule_date'] );
				if ( null === $own_date || ( null !== $rule_date && $own_date > $rule_date ) ) {
					$out['late'][] = $id;
				}
			} elseif ( '' !== $own && $r->post_date < $ref_end && function_exists( 'cx_sidrena_check_parse_date' ) ) {
				// Without CX-SID-21: existed on the shop's single reference day, but the bazna was taken later.
				$own_date = cx_sidrena_check_parse_date( $own );
				if ( null === $own_date || $own_date > $ref ) {
					$out['late'][] = $id;
				}
			}
		}

		// On sale: WooCommerce's cached list; only those few are loaded, to tell a real reduction from one that is not.
		if ( function_exists( 'wc_get_product_ids_on_sale' ) && class_exists( 'CX_Sidrena_Frontend' ) ) {
			foreach ( wc_get_product_ids_on_sale() as $sid ) {
				$sid = (int) $sid;
				if ( ! isset( $out['rows'][ $sid ] ) ) {
					continue; // a variable parent, a draft, or an item without a price
				}
				$product = wc_get_product( $sid );
				$state   = $product ? CX_Sidrena_Frontend::instance()->sale_state( $product ) : null;
				if ( null !== $state && 'not_lower' === $state['kind'] ) {
					$out['not_lower'][] = $sid;
				} elseif ( $product && $product->is_on_sale() ) {
					$out['on_sale']++;
				}
			}
		}

		// Two categories with different dates (CX-SID-21's rule takes the earlier one), minus those the owner kept.
		$out['two_cat'] = array();
		if ( class_exists( 'CX_Sidrena_Dates' ) && method_exists( 'CX_Sidrena_Dates', 'two_categories' ) ) {
			$two            = CX_Sidrena_Dates::two_categories( 1, PHP_INT_MAX );
			$out['two_cat'] = array_values( array_diff( array_map( 'intval', $two['ids'] ), self::two_cat_kept() ) );
		}

		$this->scan = $out;

		return $out;
	}

	/**
	 * Products whose earlier date the owner kept ("Keep earlier"): only off the list, nothing written to them.
	 *
	 * @return int[]
	 */
	public static function two_cat_kept() {
		$ids = get_option( self::TWO_CAT_OK, array() );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Whether "Use regular price" and "Fix all" may run: the fill is there and Dates were saved (spec §1.1; after a
	 * move from MJDD they count as saved).
	 *
	 * @return bool
	 */
	public function fill_ready() {
		return class_exists( 'CX_Sidrena_Fill' ) && class_exists( 'CX_Sidrena_Dates' ) && CX_Sidrena_Dates::dates_saved();
	}

	/**
	 * The fill running now (a big "Fix all" in the background), or null.
	 *
	 * @return array|null
	 */
	public function running_fill() {
		return class_exists( 'CX_Sidrena_Fill' ) ? CX_Sidrena_Fill::current() : null;
	}

	/**
	 * Nonce and capability for the Status fixes, or a JSON error.
	 */
	private function ajax_guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'code' => 'forbidden', 'message' => __( 'You are not allowed to do this.', 'cx-sidrena' ) ), 403 );
		}
	}

	/**
	 * A fill job as the screen shows it: the model's progress, plus the sentence when it is done.
	 *
	 * @param array $job From CX_Sidrena_Fill.
	 * @return array
	 */
	private function job_out( array $job ) {
		if ( ! empty( $job['finished'] ) ) {
			$n = isset( $job['result']['updated'] ) ? (int) $job['result']['updated'] : 0;
			/* translators: %d: number of products and variations */
			$job['message'] = sprintf( __( 'Bazna cijena set from the regular price. Products and variations: %d.', 'cx-sidrena' ), $n );
		}

		return $job;
	}

	/**
	 * "Use regular price" (ids) and "Fix all" (all): the dialog's name and confirmation go to the model's fill.
	 */
	public function ajax_fill() {
		$this->ajax_guard();
		if ( ! $this->fill_ready() ) {
			wp_send_json_error( array( 'code' => 'dates_not_saved', 'message' => __( 'Save Dates in Settings first. They set each product\'s date.', 'cx-sidrena' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ajax_guard()
		$all  = isset( $_POST['all'] ) && '1' === $_POST['all'];
		$ids  = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$name = isset( $_POST['confirmed_by'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirmed_by'] ) ) ) : '';
		$ok   = isset( $_POST['consent'] ) && '1' === $_POST['consent'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $name ) {
			wp_send_json_error( array( 'code' => 'no_name', 'message' => __( 'Enter the name of who confirmed.', 'cx-sidrena' ) ) );
		}
		if ( ! $ok ) {
			wp_send_json_error( array( 'code' => 'no_consent', 'message' => __( 'Tick the confirmation.', 'cx-sidrena' ) ) );
		}
		if ( ! $all && ! $ids ) {
			wp_send_json_error( array( 'code' => 'nothing', 'message' => __( 'Nothing to fill: these products already have a bazna cijena.', 'cx-sidrena' ) ) );
		}
		$job = CX_Sidrena_Fill::start(
			$all ? 'all' : $ids,
			array(
				'confirmed_by' => $name,
				'confirmed_on' => wp_date( 'Y-m-d' ),
				'consent'      => CX_Sidrena_Fill::consent_text(), // what the dialog showed: the model's own text
			)
		);
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'code' => $job->get_error_code(), 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( $this->job_out( $job ) );
	}

	/**
	 * Progress of a big "Fix all" (the bar polls it; it also picks the job up after the page was closed).
	 */
	public function ajax_progress() {
		$this->ajax_guard();
		$id  = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job = class_exists( 'CX_Sidrena_Fill' ) ? CX_Sidrena_Fill::progress( $id ) : null;
		if ( ! $job ) {
			wp_send_json_error( array( 'code' => 'not_found', 'message' => __( 'This fill is not running any more. Reload the page.', 'cx-sidrena' ) ) );
		}
		wp_send_json_success( $this->job_out( $job ) );
	}

	/**
	 * "Keep earlier": the product leaves "Two categories, two dates". Nothing is written to the product.
	 */
	public function ajax_two_cat_ok() {
		$this->ajax_guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $id && 'product' === get_post_type( $id ) ) {
			$kept = self::two_cat_kept();
			if ( ! in_array( $id, $kept, true ) ) {
				$kept[] = $id;
				update_option( self::TWO_CAT_OK, $kept, false );
			}
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Every row of "Needs attention", in the order of the spec's table.
	 *
	 * @return array<int, array{id:int, problem:string}>
	 */
	public function problems() {
		$s    = $this->scan();
		$list = array();
		foreach ( array( 'missing', 'two_cat', 'late', 'not_lower' ) as $kind ) {
			foreach ( $s[ $kind ] as $id ) {
				$list[] = array( 'id' => $id, 'problem' => $kind );
			}
		}

		return $list;
	}

	/**
	 * Products (a variation as its product) that need attention: the product list's filter (CX-SID-24).
	 *
	 * @return int[]
	 */
	public function attention_product_ids() {
		$s   = $this->scan();
		$ids = array();
		foreach ( $this->problems() as $p ) {
			$r      = isset( $s['rows'][ $p['id'] ] ) ? $s['rows'][ $p['id'] ] : null;
			$parent = $r && 'product_variation' === $r->post_type ? (int) $r->post_parent : (int) $p['id'];
			$ids[ $parent ] = true;
		}

		return array_keys( $ids );
	}

	/**
	 * Products and variations that need attention (one product with two problems counts once).
	 *
	 * @return int
	 */
	public function attention_count() {
		$ids = array();
		foreach ( $this->problems() as $p ) {
			$ids[ $p['id'] ] = true;
		}

		return count( $ids );
	}

	/**
	 * Problem text.
	 *
	 * @param string $kind missing | two_cat | late | not_lower.
	 * @return string
	 */
	public static function problem_text( $kind ) {
		switch ( $kind ) {
			case 'missing':
				return __( 'No bazna cijena', 'cx-sidrena' );
			case 'two_cat':
				return __( 'Two categories, two dates: using the earlier one.', 'cx-sidrena' );
			case 'late':
				return __( 'Bazna cijena date is later than expected', 'cx-sidrena' );
			default:
				return __( 'Sale price is not lower than the lowest price in the last 30 days. Shown as a regular price.', 'cx-sidrena' );
		}
	}

	/**
	 * When the price list was last generated: the export's record, else the newest live file (XML or CSV, not the
	 * archive), or 0.
	 *
	 * @return int Unix time.
	 */
	public function feed_time() {
		$meta = CX_Sidrena_Export::instance()->get_public_meta();
		if ( is_array( $meta ) && ! empty( $meta['generated'] ) ) {
			return (int) $meta['generated'];
		}

		$dir = CX_Sidrena_Feed_Files::instance()->get_public_dir();
		if ( '' === $dir ) {
			return 0;
		}
		$dir   = trailingslashit( $dir );
		$files = array_merge( glob( $dir . '*.xml' ) ?: array(), glob( $dir . '*.csv' ) ?: array() ); // no GLOB_BRACE: missing on musl hosts
		$files = array_filter( $files, function ( $f ) { return false === strpos( basename( $f ), 'arhiv' ); } );

		return $files ? (int) max( array_map( 'filemtime', $files ) ) : 0;
	}

	/**
	 * Red: the day's price list is not public by the deadline. Only once there is a bazna (before it there is no price
	 * list, and that is not a failure), and only when the daily run is on.
	 *
	 * @param DateTimeImmutable|null $now Now (tests); null = the real time.
	 * @return string|null Reason, or null when not red.
	 */
	public function red_reason( $now = null ) {
		$s = $this->scan();
		// A B2B shop publishes no price list (CX-SID-28): nothing is late.
		if ( $s['with'] < 1 || 'yes' !== CX_Sidrena_Settings::instance()->get( 'daily_xml' ) || CX_Sidrena_Settings::instance()->is_b2b_only() ) {
			return null;
		}

		// The morning build runs at 00:05 on the legal clock (CX_Sidrena_Export::next_daily_timestamp), and the 08:00
		// deadline is the law's: both on that clock, not the site's. On a site left on UTC the build runs at 23:05 UTC
		// the evening before, which is earlier than "today 00:05 UTC", so the banner was red every morning.
		$tz   = CX_Sidrena_Archive::legal_timezone();
		$now  = $now instanceof DateTimeImmutable ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		$run  = $now->setTime( CX_Sidrena_Export::DAILY_HOUR, CX_Sidrena_Export::DAILY_MINUTE );
		$due  = $run->setTime( self::DEADLINE_HOUR, 0 );
		$feed = $this->feed_time();
		if ( $now < $due || $feed >= $run->getTimestamp() ) {
			return null;
		}
		// The first price list is on its way (CX-SID-68, Argus S1): before 68 the activation published at once, so a shop
		// with a bazna and no price list did not exist; now the first bazna queues the first build in 60 s, and saying
		// the scheduled tasks did not run would be untrue.
		if ( $this->first_build_time( $now->getTimestamp() ) > 0 ) {
			return null;
		}

		return __( 'Scheduled tasks did not run.', 'cx-sidrena' );
	}

	/**
	 * When the first price list will be built, while a shop that has never published waits for it: now if the build
	 * runs, else when it is queued (now, if it is already due). 0 when the shop has published, a build failed (its
	 * retries are the red's case, as before), nothing is queued, or the queued one is overdue by more than
	 * FIRST_BUILD_LATE (WP-cron does not run: the red is true).
	 *
	 * @param int|null $now Unix time (tests); null = the real time.
	 * @return int Unix time, or 0.
	 */
	public function first_build_time( $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$export = CX_Sidrena_Export::instance();
		if ( $export->ever_published() || (int) get_option( CX_Sidrena_Export::RETRY_OPTION, 0 ) > 0 ) {
			return 0;
		}
		if ( $export->rebuild_running() ) {
			return $now;
		}
		$queued = (int) wp_next_scheduled( CX_Sidrena_Export::GENERATE_HOOK );
		if ( $queued < 1 || $queued < $now - self::FIRST_BUILD_LATE ) {
			return 0;
		}

		return max( $queued, $now );
	}

	/**
	 * Both the shop address for the file name and WooCommerce's store address are empty: the file is named after the
	 * web address (class-archive.php). A yellow line, not red: the price list is still published (Rook, card note).
	 *
	 * @return bool
	 */
	public function address_missing() {
		if ( '' !== trim( (string) CX_Sidrena_Settings::instance()->get( 'file_adresa', '' ) ) ) {
			return false;
		}
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_postcode', 'woocommerce_store_city' ) as $key ) {
			if ( '' !== trim( (string) get_option( $key, '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A theme that draws its own sale badge, which we cannot check (spec §2.5); not when the owner already hid it.
	 *
	 * @return bool
	 */
	public function own_badge_theme() {
		// As seen on the shop's pages: in the admin WooCommerce's template hooks are not there (CX_Sidrena_Badge).
		return class_exists( 'CX_Sidrena_Badge' ) && 'own' === CX_Sidrena_Badge::seen_theme_mode() && 'hide' !== CX_Sidrena_Badge::mode();
	}

	/**
	 * The take-over record, when the blue notice is to be shown (a take-over, not a fresh install, not closed yet).
	 *
	 * @return array|null
	 */
	public function takeover() {
		$done = get_option( 'cx_sidrena_migrated', array() );
		if ( ! is_array( $done ) || ! empty( $done['fresh'] ) || ( empty( $done['options'] ) && empty( $done['files'] ) && empty( $done['off'] ) ) ) {
			return null;
		}
		if ( (int) get_option( self::TAKEOVER_SEEN, 0 ) === (int) ( isset( $done['at'] ) ? $done['at'] : 1 ) ) {
			return null;
		}

		return $done;
	}

	/**
	 * Versions for a bug report. No shop address, no names, nothing personal (card note).
	 *
	 * @param string $sep Between the versions: a line break for e-mail; ", " in a GitHub link, because esc_url()
	 *                    removes %0A from every URL except mailto: (the versions would run together).
	 * @return string
	 */
	public static function environment( $sep = "\n" ) {
		$theme = wp_get_theme();
		$lines = array(
			'CX Sidrena ' . CX_SIDRENA_VERSION,
			'WordPress ' . get_bloginfo( 'version' ),
			'WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '-' ),
			'PHP ' . PHP_VERSION,
			'Theme ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
		);
		if ( $theme->parent() ) {
			$lines[] = 'Parent theme ' . $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' );
		}

		return implode( $sep, $lines );
	}

	/**
	 * The three links: a GitHub issue form (bug, feature) with the versions filled in, and an e-mail.
	 *
	 * @return array<string, array{url:string, label:string}>
	 */
	public static function report_links() {
		$env  = self::environment( ', ' );
		$mail = self::environment();

		return array(
			'bug'     => array(
				'url'   => self::ISSUES_URL . '?template=' . self::BUG_TEMPLATE . '&' . self::ENV_FIELD . '=' . rawurlencode( $env ),
				'label' => __( 'Report a bug', 'cx-sidrena' ),
			),
			'feature' => array(
				'url'   => self::ISSUES_URL . '?template=' . self::FEATURE_TEMPLATE . '&' . self::ENV_FIELD . '=' . rawurlencode( $env ),
				'label' => __( 'Suggest a feature', 'cx-sidrena' ),
			),
			'mail'    => array(
				'url'   => 'mailto:' . self::MAIL . '?subject=' . rawurlencode( 'CX Sidrena' ) . '&body=' . rawurlencode( "\n\n" . $mail ),
				'label' => __( 'Email us', 'cx-sidrena' ),
			),
		);
	}

	/**
	 * Links in the plugin's row on Plugins.
	 *
	 * @param string[] $links Row links.
	 * @param string   $file  Plugin file.
	 * @return string[]
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( CX_SIDRENA_FILE ) !== $file ) {
			return $links;
		}

		foreach ( self::report_links() as $link ) {
			$links[] = '<a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link['label'] ) . '</a>';
		}

		return $links;
	}

	/**
	 * Edit link and name of a product or variation in the list.
	 *
	 * @param int $id Product or variation id.
	 * @return array{name:string, edit:string}
	 */
	private function item( $id ) {
		$s   = $this->scan();
		$r   = isset( $s['rows'][ $id ] ) ? $s['rows'][ $id ] : null;
		$pid = $r && (int) $r->post_parent ? (int) $r->post_parent : $id;
		$name = $r ? (string) $r->post_title : ( '' !== (string) get_post_field( 'post_title', $id ) ? (string) get_post_field( 'post_title', $id ) : '#' . $id );
		if ( $r && 'product_variation' === $r->post_type ) {
			$v    = wc_get_product( $id );
			$name = $v ? wp_strip_all_tags( $v->get_name() ) : $name;
		}

		return array( 'name' => $name, 'edit' => (string) get_edit_post_link( $pid, 'raw' ) );
	}

	/**
	 * The screen.
	 */
	public function render() {
		$status  = $this;
		$display = $this->display_state();
		if ( $display ) {
			$this->enqueue_display_check( $display );
		}
		include CX_SIDRENA_PATH . 'includes/admin/tab-status.php';
	}

	// ----------------------------------------------------------------------------------------------------------------
	// What a customer sees (CX-SID-92)
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * The product whose page is measured: a published simple product with a bazna, at best one on sale with the lowest
	 * price struck (so the struck amount is measured too); else a variable product whose page shows our line before a
	 * size is chosen. One whose sizes have different labels or dates shows none there (each size shows its own once
	 * chosen), so measuring it would read as a theme hiding our price; with no other, there is nothing to measure.
	 *
	 * @return array{id:int,url:string,sale:bool}|null Null when no product has a bazna.
	 */
	public function display_target() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$pick = null;
		$args = array(
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'has_password'   => false,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => CX_SIDRENA_META_PRICE, 'value' => '', 'compare' => '!=' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		);
		$ids  = get_posts( array_merge( $args, array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple' ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$front = class_exists( 'CX_Sidrena_Frontend' ) ? CX_Sidrena_Frontend::instance() : null;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_visible() || '' === (string) $product->get_price() ) {
				continue;
			}
			$state = $front ? $front->sale_state( $product ) : null;
			if ( $state && 'sale' === $state['kind'] ) {
				return array( 'id' => (int) $id, 'url' => (string) get_permalink( $id ), 'sale' => true );
			}
			if ( null === $pick ) {
				$pick = array( 'id' => (int) $id, 'url' => (string) get_permalink( $id ), 'sale' => false );
			}
		}
		if ( null === $pick && $front ) {
			$tried = array();
			foreach ( get_posts( array_merge( $args, array( 'post_type' => 'product_variation' ) ) ) as $vid ) {
				$parent = wp_get_post_parent_id( $vid );
				if ( ! $parent || isset( $tried[ $parent ] ) ) {
					continue;
				}
				$tried[ $parent ] = true;
				$p                = wc_get_product( $parent );
				if ( $p && 'publish' === get_post_status( $parent ) && $p->is_visible() && '' === (string) get_post_field( 'post_password', $parent )
					&& '' !== $front->get_html_for_product( $p ) ) {
					$pick = array( 'id' => (int) $parent, 'url' => (string) get_permalink( $parent ), 'sale' => false );
					break;
				}
			}
		}

		return $pick;
	}

	/**
	 * The theme and the versions a finding is for: another theme, or a new version of the theme or of ours, is a new
	 * check (a child theme's edits do not change its version: "Check again" is for that).
	 *
	 * @return array{theme:string,template:string,theme_version:string,plugin:string}
	 */
	private function display_context() {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'theme'         => (string) get_stylesheet(),
			'template'      => (string) get_template(),
			'theme_version' => (string) $theme->get( 'Version' ) . ( $parent ? '/' . (string) $parent->get( 'Version' ) : '' ),
			'plugin'        => (string) CX_SIDRENA_VERSION,
		);
	}

	/**
	 * Whether a finding says a customer misses something: on some width, no bazna line with its amount in sight (while
	 * the line is added automatically), or the caption in sight without the struck amount it is about.
	 *
	 * @param array $widths Finding per width.
	 * @param bool  $line   Whether the line is expected on the product page (automatic display on).
	 * @return array<int,int> The widths where something is missed.
	 */
	public static function display_missed( array $widths, $line ) {
		$out = array();
		foreach ( self::DISPLAY_WIDTHS as $w ) {
			$r = isset( $widths[ $w ] ) && is_array( $widths[ $w ] ) ? $widths[ $w ] : null;
			if ( ! $r ) {
				continue;
			}
			if ( ( $line && empty( $r['line'] ) ) || ( ! empty( $r['caption'] ) && false === $r['struck'] ) ) {
				$out[] = $w;
			}
		}

		return $out;
	}

	/**
	 * Whether a finding says a customer sees our line, with its amount, on every width (and misses nothing): only then
	 * does Status say so. With the automatic display off and no line on the page, nothing is missed, but nothing is
	 * claimed either.
	 *
	 * @param array          $widths Finding per width.
	 * @param array<int,int> $missed display_missed().
	 * @return bool
	 */
	public static function display_seen( array $widths, array $missed ) {
		if ( $missed ) {
			return false;
		}
		foreach ( self::DISPLAY_WIDTHS as $w ) {
			if ( empty( $widths[ $w ] ) || ! is_array( $widths[ $w ] ) || true !== ( $widths[ $w ]['line'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * What Status shows about the product page, or null when there is nothing to measure (no product has a bazna).
	 *
	 * @return array{target:array,due:bool,state:string,missed:array<int,int>,seen:bool,product:int,url:string}|null
	 *         state: none (not measured yet), checked or unchecked (the page could not be measured here); seen: the
	 *         finding says a customer sees our line (display_seen()).
	 */
	public function display_state() {
		$target = $this->display_target();
		if ( ! $target ) {
			return null;
		}
		$ctx  = $this->display_context();
		$seen = get_option( self::SEEN_DISPLAY, null );
		$same = is_array( $seen ) && isset( $seen['theme'], $seen['template'] ) && $seen['theme'] === $ctx['theme'] && $seen['template'] === $ctx['template'];
		$due  = ! $same || ! isset( $seen['theme_version'], $seen['plugin'], $seen['at'] ) || $seen['theme_version'] !== $ctx['theme_version']
			|| $seen['plugin'] !== $ctx['plugin'] || time() - (int) $seen['at'] > self::DISPLAY_TTL;
		$state  = $same && isset( $seen['state'] ) ? (string) $seen['state'] : 'none';
		$missed = $same && 'checked' === $state && isset( $seen['missed'] ) && is_array( $seen['missed'] ) ? array_map( 'intval', $seen['missed'] ) : array();
		$pid    = $same && ! empty( $seen['product'] ) ? (int) $seen['product'] : (int) $target['id'];
		$widths = $same && 'checked' === $state && isset( $seen['widths'] ) && is_array( $seen['widths'] ) ? $seen['widths'] : array();

		return array(
			'target'  => $target,
			'due'     => $due,
			'state'   => $state,
			'missed'  => $missed,
			'seen'    => 'checked' === $state && self::display_seen( $widths, $missed ),
			'product' => $pid,
			'url'     => (string) get_permalink( $pid ),
		);
	}

	/**
	 * The words for where a customer misses it.
	 *
	 * @param array<int,int> $missed Widths.
	 * @return string
	 */
	public static function display_where( array $missed ) {
		if ( array( 390 ) === array_values( $missed ) ) {
			return __( 'Only on a phone.', 'cx-sidrena' );
		}
		if ( array( 1280 ) === array_values( $missed ) ) {
			return __( 'Only on a computer.', 'cx-sidrena' );
		}

		return '';
	}

	/**
	 * The script that measures the page, on Status only.
	 *
	 * @param array $display display_state().
	 */
	private function enqueue_display_check( array $display ) {
		wp_enqueue_script( 'cx-sidrena-display-check', CX_SIDRENA_URL . 'assets/js/admin-display-check.js', array(), CX_SIDRENA_VERSION, true );
		wp_localize_script(
			'cx-sidrena-display-check',
			'cxDisplayCheck',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'url'     => $display['target']['url'],
				'product' => (int) $display['target']['id'],
				'sale'    => (bool) $display['target']['sale'],
				'due'     => (bool) $display['due'],
				'widths'  => self::DISPLAY_WIDTHS,
				't'       => array(
					'phone'    => __( 'Only on a phone.', 'cx-sidrena' ),
					'computer' => __( 'Only on a computer.', 'cx-sidrena' ),
				),
			)
		);
	}

	/**
	 * The script's finding: stored with the theme and versions it is for, then what Status shows. Only the measured
	 * booleans are taken; the product is the one Status chose.
	 */
	public function ajax_display_seen() {
		$this->ajax_guard();
		$target = $this->display_target();
		if ( ! $target ) {
			wp_send_json_error( array( 'code' => 'nothing', 'message' => __( 'No product has a bazna cijena yet.', 'cx-sidrena' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ajax_guard()
		$state  = isset( $_POST['state'] ) && 'checked' === $_POST['state'] ? 'checked' : 'unchecked';
		$raw    = isset( $_POST['widths'] ) ? json_decode( wp_unslash( (string) $_POST['widths'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded, then only booleans are kept
		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$widths = array();
		$bool   = static function ( $r, $k ) {
			return ! empty( $r[ $k ] ) && true === $r[ $k ];
		};
		if ( 'checked' === $state ) {
			foreach ( self::DISPLAY_WIDTHS as $w ) {
				$r = is_array( $raw ) && isset( $raw[ $w ] ) && is_array( $raw[ $w ] ) ? $raw[ $w ] : null;
				if ( ! $r ) {
					$state  = 'unchecked';
					$reason = 'incomplete';
					break;
				}
				$widths[ $w ] = array(
					'found'   => $bool( $r, 'found' ),
					'line'    => $bool( $r, 'line' ),
					'caption' => $bool( $r, 'caption' ),
					'struck'  => array_key_exists( 'struck', $r ) && is_bool( $r['struck'] ) ? $r['struck'] : null,
				);
			}
		}
		$line   = CX_Sidrena_Settings::instance()->is_auto_display_enabled();
		$missed = 'checked' === $state ? self::display_missed( $widths, $line ) : array();
		update_option(
			self::SEEN_DISPLAY,
			array_merge(
				$this->display_context(),
				array(
					'at'      => time(),
					'product' => (int) $target['id'],
					'state'   => $state,
					'reason'  => 'checked' === $state ? '' : $reason,
					'widths'  => 'checked' === $state ? $widths : array(),
					'line'    => $line,
					'missed'  => $missed,
				)
			),
			false
		);
		wp_send_json_success(
			array(
				'state'  => $state,
				'missed' => $missed,
				'seen'   => 'checked' === $state && self::display_seen( $widths, $missed ),
				'where'  => self::display_where( $missed ),
				'url'    => (string) get_permalink( $target['id'] ),
			)
		);
	}

	/**
	 * Rows of one page of "Needs attention", with names (only these are loaded).
	 *
	 * @param int $page Page from 1.
	 * @return array{rows: array<int, array{id:int, problem:string, name:string, edit:string}>, pages:int, page:int}
	 */
	public function page_of_problems( $page ) {
		$all   = $this->problems();
		$pages = max( 1, (int) ceil( count( $all ) / self::PER_PAGE ) );
		$page  = min( max( 1, (int) $page ), $pages );
		$rows  = array();
		foreach ( array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $p ) {
			$rows[] = array_merge( $p, $this->item( $p['id'] ) );
		}

		return array( 'rows' => $rows, 'pages' => $pages, 'page' => $page );
	}
}
