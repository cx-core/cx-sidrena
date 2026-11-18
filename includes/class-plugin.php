<?php
/**
 * Bootstrap the Sidrena cijena plugin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

require_once CX_SIDRENA_PATH . 'includes/class-settings.php';
require_once CX_SIDRENA_PATH . 'includes/class-i18n.php';
require_once CX_SIDRENA_PATH . 'includes/class-money.php';
require_once CX_SIDRENA_PATH . 'includes/class-fields.php';
require_once CX_SIDRENA_PATH . 'includes/class-catalog.php';
require_once CX_SIDRENA_PATH . 'includes/class-upit.php';
require_once CX_SIDRENA_PATH . 'includes/class-poslovnice.php';
require_once CX_SIDRENA_PATH . 'includes/class-feed-rows.php';
require_once CX_SIDRENA_PATH . 'includes/class-feed-files.php';
require_once CX_SIDRENA_PATH . 'includes/class-archive.php';
require_once CX_SIDRENA_PATH . 'includes/class-feed-routes.php';
require_once CX_SIDRENA_PATH . 'includes/class-product-fields.php';
require_once CX_SIDRENA_PATH . 'includes/class-admin-save.php';
require_once CX_SIDRENA_PATH . 'includes/class-admin.php';
require_once CX_SIDRENA_PATH . 'includes/class-export.php';
require_once CX_SIDRENA_PATH . 'includes/class-import-parse.php';
require_once CX_SIDRENA_PATH . 'includes/class-bulk-import.php';

/**
 * Main plugin controller.
 */
final class CX_Sidrena_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin after plugins_loaded.
	 */
	public static function init() {
		self::load_textdomain();

		require_once CX_SIDRENA_PATH . 'includes/class-frontend.php';

		if ( self::woocommerce_active() ) {
			require_once CX_SIDRENA_PATH . 'includes/class-product-meta.php';
			require_once CX_SIDRENA_PATH . 'includes/class-activator.php';
			require_once CX_SIDRENA_PATH . 'includes/class-compat.php';
			require_once CX_SIDRENA_PATH . 'includes/class-import-export.php';
			// From the bazna add-on 1.1.1: lowest price in 30 days before a sale, and the check page.
			require_once CX_SIDRENA_PATH . 'includes/lowest-30.php';
			require_once CX_SIDRENA_PATH . 'includes/check.php';
			require_once CX_SIDRENA_PATH . 'includes/class-cart.php';
		}

		self::instance();
	}

	/**
	 * Register our translations (languages/cx-sidrena-<locale>.mo).
	 *
	 * Also called on its own wherever a text can be translated in a request where init() never ran: activation
	 * includes this plugin after plugins_loaded, so without this the take-over notice would be English on a Croatian
	 * shop. Calling it twice is harmless.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'cx-sidrena', false, dirname( plugin_basename( CX_SIDRENA_FILE ) ) . '/languages' );
	}

	/**
	 * Run a build of something customers see (the HTML price list) in the shop's language.
	 *
	 * The price list is written to a file once and then served to every customer, and the build can be started by an
	 * admin whose own profile language is English, by cron or by WP-CLI. Its language must be the shop's, never the
	 * language of whoever started it.
	 *
	 * switch_to_locale() alone is not enough (CX-SID-121): it does nothing when the request is already in the shop's
	 * language, and the build runs as a visitor (CX_Sidrena_Feed_Rows::as_visitor()), whose language is the shop's. Our
	 * texts then stayed in whatever language they were first used in during this request: an English admin's Settings
	 * page had loaded them in English, so the stored table was English (S5, 17.11.). In that case our text domain is
	 * loaded again in the shop's language for the build, and afterwards it loads again in the request's own language.
	 *
	 * @param callable $build Work that produces customer-facing text.
	 * @return mixed What $build returns.
	 */
	public static function in_shop_locale( $build ) {
		$locale   = get_locale();
		$switched = switch_to_locale( $locale );
		$own      = $switched ? null : self::load_textdomain_in( $locale );

		try {
			return $build();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			if ( null !== $own ) {
				self::release_textdomain( $own );
			}
		}
	}

	/**
	 * Our text domain in $locale, whatever language it was loaded in before. With no translation for $locale, the
	 * source (English) texts, never the request's language.
	 *
	 * @param string $locale Locale.
	 * @return string The translation controller's locale before (WordPress 6.5+), '' before 6.5.
	 */
	private static function load_textdomain_in( $locale ) {
		global $l10n, $wp_textdomain_registry;

		// load_textdomain() moves the translation controller to $locale: put back afterwards (release_textdomain()).
		$before = class_exists( 'WP_Translation_Controller' ) ? WP_Translation_Controller::get_instance()->get_locale() : '';

		self::load_textdomain(); // registers our languages/ folder, also in a request where init() did not run
		unload_textdomain( 'cx-sidrena', true );

		// The file WordPress itself would pick for $locale (language packs and Loco first, then our languages/), as in
		// _load_textdomain_just_in_time(), but for the shop's locale instead of this request's.
		$path   = $wp_textdomain_registry instanceof WP_Textdomain_Registry ? $wp_textdomain_registry->get( 'cx-sidrena', $locale ) : false;
		$loaded = $path && load_textdomain( 'cx-sidrena', $path . 'cx-sidrena-' . $locale . '.mo', $locale );
		if ( ! $loaded ) {
			// Nothing for $locale: the source texts. Without this, the first text would load the request's language.
			$l10n['cx-sidrena'] = new NOOP_Translations();
		}

		return $before;
	}

	/**
	 * After the build: our text domain loads again on its next use, in the request's language, and the translation
	 * controller is back on the locale it had (so the admin's other texts are in his language again).
	 *
	 * @param string $before What load_textdomain_in() returned.
	 */
	private static function release_textdomain( $before ) {
		unload_textdomain( 'cx-sidrena', true );
		if ( '' !== $before && class_exists( 'WP_Translation_Controller' ) ) {
			WP_Translation_Controller::get_instance()->set_locale( $before );
		}
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Capability that can manage plugin settings.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		return self::woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Current user may manage the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::manage_capability() );
	}

	/**
	 * Write an exception to the WooCommerce log, or to the PHP error log.
	 *
	 * @param Throwable $exception Caught error. The message is not shown to visitors.
	 */
	public static function log_exception( $exception ) {
		if ( ! $exception instanceof Throwable ) {
			return;
		}

		$message = 'Sidrena cijena: ' . $exception->getMessage();
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'sidrena-cijena' ) );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		CX_Sidrena_Settings::instance();
		CX_Sidrena_I18n::instance();
		CX_Sidrena_Catalog::instance();
		CX_Sidrena_Admin::instance();
		CX_Sidrena_Export::instance();
		CX_Sidrena_Archive::register();
		CX_Sidrena_Bulk_Import::instance();
		CX_Sidrena_Frontend::instance();

		if ( ! self::woocommerce_active() ) {
			return;
		}

		CX_Sidrena_Product_Meta::instance();
		CX_Sidrena_Compat::instance();
		CX_Sidrena_Import_Export::instance();
		// Cart, mini-cart and checkout blocks: one price per line, never the regular price struck (CX-SID-14).
		CX_Sidrena_Cart::instance();
		CX_Sidrena_Import::register();
		CX_Sidrena_Sale_Rule::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once CX_SIDRENA_PATH . 'includes/class-cli.php';
			WP_CLI::add_command( 'cx-sidrena', 'CX_Sidrena_CLI' );
		}
	}

	/**
	 * Activation: take over MJDD's settings and files if it was used here, otherwise defaults. Sidrena prices stay
	 * empty until someone fills them. MJDD and the add-on are switched off right after WordPress has saved the
	 * plugin list (CX_Sidrena_Migration::on_activated).
	 */
	public static function activate() {
		CX_Sidrena_History::activate();

		$took_over = CX_Sidrena_Migration::copy_from_mjdd();

		// Dates per category: taken from the shop's data after a move from MJDD, else suggested (runs once).
		CX_Sidrena_Dates::maybe_setup();

		CX_Sidrena_Settings::add_defaults();

		// After a take-over MJDD's own "fill empty fields" state came along; asking again would be new to the owner.
		if ( self::woocommerce_active() && ! $took_over ) {
			require_once CX_SIDRENA_PATH . 'includes/class-activator.php';
			CX_Sidrena_Activator::queue_fill_prompt();
		}

		CX_Sidrena_Export::instance()->register_rewrite();
		CX_Sidrena_Export::instance()->sync_daily_schedule();
		CX_Sidrena_Export::instance()->queue_public_xml();
		flush_rewrite_rules();
		update_option( CX_Sidrena_Export::REWRITE_OPTION, CX_Sidrena_Export::rewrite_fingerprint(), false );
	}

	/**
	 * Deactivation: clear scheduled jobs only. Product meta stays.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cx_sidrena_snapshot_batch' );
		wp_clear_scheduled_hook( CX_Sidrena_Dates::SETUP_HOOK ); // an unfinished take-over goes on after reactivation
		wp_clear_scheduled_hook( CX_Sidrena_Bulk::CRON_HOOK ); // a running job continues after reactivation (keep_going)
		wp_clear_scheduled_hook( CX_Sidrena_Export::DAILY_HOOK );
		wp_clear_scheduled_hook( CX_Sidrena_Export::GENERATE_HOOK );
		wp_unschedule_hook( 'cx_sidrena_n30_seed' );
		flush_rewrite_rules();
	}

	/**
	 * Delete our data: options, the live price lists, transients, cron (wp cx-sidrena purge).
	 *
	 * Never product meta: bazna, date, price history and the lowest price are the evidence for an inspection, and
	 * MJDD reads the same keys, so going back to it keeps every price. For the same reason the price history tables
	 * and the source of every bazna (CX_Sidrena_History) stay; deleting them would need its own command. And never the
	 * archive of published price lists (arhiva/, cuvano-*): the shop must keep them (CX-SID-27), a reinstall goes on
	 * with them and their storage numbers.
	 */
	public static function purge_all_data() {
		global $wpdb;

		delete_option( 'cx_sidrena_settings' );
		delete_option( 'cx_sidrena_snapshot_pending' );
		delete_option( 'cx_sidrena_snapshot_page' );
		delete_option( 'cx_sidrena_snapshot_offset' );
		delete_option( 'cx_sidrena_snapshot_child' );
		delete_option( 'cx_sidrena_fill_prompt' );
		delete_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION );
		delete_option( CX_Sidrena_Settings::FOLLOWUP_NOTICE_OPTION );
		delete_option( 'cx_sidrena_public_xml' );
		delete_option( 'cx_sidrena_rewrite_ver' );
		delete_option( CX_Sidrena_Catalog::OPTION_KEY );
		delete_option( CX_Sidrena_Upit::OPTION_KEY );
		delete_option( CX_Sidrena_Poslovnice::OPTION_KEY );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'cx_sidrena_' ) . '%',
				$wpdb->esc_like( '_transient_cx_sidrena_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cx_sidrena_' ) . '%'
			)
		);
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		self::delete_live_files();

		wp_clear_scheduled_hook( 'cx_sidrena_snapshot_batch' );
		wp_clear_scheduled_hook( CX_Sidrena_Export::DAILY_HOOK );
		wp_clear_scheduled_hook( CX_Sidrena_Export::GENERATE_HOOK );
		wp_clear_scheduled_hook( CX_Sidrena_Dates::SETUP_HOOK );
		wp_clear_scheduled_hook( CX_Sidrena_Bulk::CRON_HOOK );
		wp_unschedule_hook( 'cx_sidrena_n30_seed' );
	}

	/**
	 * The live price lists, their HTML tables, temporary files of a write that was cut, and MJDD's old import folder.
	 * The archive folders stay. Also called by uninstall.php.
	 */
	public static function delete_live_files() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return;
		}

		$base  = trailingslashit( $uploads['basedir'] ) . 'cx-sidrena';
		$found = array_merge(
			glob( $base . '/sidrena-cijena*' ) ?: array(),
			glob( $base . '/.sidrena-cijena*.tmp-*' ) ?: array(),
			glob( $base . '/*/.*.tmp-*' ) ?: array()
		);
		foreach ( $found as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::delete_directory( $base . '/imports' );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::delete_directory( $path );
			} elseif ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
