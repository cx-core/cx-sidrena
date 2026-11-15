<?php
/**
 * Moving a shop over from MJDD Sidrena i bazna cijena (and the bazna add-on) to CX Sidrena.
 *
 * Rule: product meta is shared and read in place (same keys); everything MJDD's uninstall or deactivation deletes
 * (its options, its uploads folder, its cron) is COPIED under our names, so removing MJDD later touches nothing of
 * ours, and going back to MJDD still finds its own data untouched. MJDD and the add-on never run next to us: they
 * are switched off when we take over, and switched off again (with a notice) if someone turns them back on.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Take-over from MJDD 1.26.0 and the bazna add-on 1.1.1.
 */
final class CX_Sidrena_Migration {

	/** Marker of the last take-over: time, fingerprints of MJDD's options, counts. */
	const DONE_OPTION = 'cx_sidrena_migrated';

	/** Messages for the owner, shown once in the admin. */
	const NOTICE_OPTION = 'cx_sidrena_notice';

	/** Whether the "lowest price in 30 days" line is shown (yes|no). The history is recorded either way. */
	const N30_DISPLAY_OPTION = 'cx_sidrena_n30_display';

	/** Main file names of the plugins we replace; the folder name may differ per shop. */
	const MJDD_MAIN  = 'sidrena-cijena.php';
	const ADDON_MAIN = 'bazna-dodatak.php';

	/** Cron hooks of MJDD and the add-on. MJDD's deactivation clears its own, but only when it runs its hook. */
	const FOREIGN_CRON = array( 'sidrena_cijena_daily_xml', 'sidrena_cijena_generate_xml', 'sidrena_cijena_snapshot_batch', 'bazna_n30_seed' );

	/** MJDD's uploads folder (uploads/sidrena-cijena); ours is CX_Sidrena_Feed_Files::get_public_dir(). */
	const MJDD_DIR = 'sidrena-cijena';

	/**
	 * Hook up. Called from the main file, so it also runs in the request that activates us.
	 */
	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'on_load' ), 0 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated' ), 10, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'show_notice' ) );
	}

	/**
	 * Every request, before MJDD (plugins_loaded 10) and before our own boot (also 10).
	 *
	 * - No take-over marker yet (we were activated silently, e.g. by code or an updater, so no activation hook ran):
	 *   take over now, before our boot reads settings and maybe writes a feed with defaults. On a fresh install this
	 *   only records that there was nothing to take over.
	 * - MJDD or the add-on is active next to us (silent activation of either): keep them from running in this
	 *   request, so a page never shows two bazna or two "lowest" lines, and switch them off once WordPress is loaded.
	 */
	public static function on_load() {
		if ( false === get_option( self::DONE_OPTION, false ) ) {
			self::copy_from_mjdd();
		}

		$conflicts = self::active_conflicts();
		if ( ! $conflicts ) {
			return;
		}

		remove_action( 'plugins_loaded', array( 'Sidrena_Cijena_Plugin', 'init' ) );
		foreach ( $conflicts as $file ) {
			if ( self::ADDON_MAIN === basename( $file ) ) {
				self::remove_callbacks_from_dir( WP_PLUGIN_DIR . '/' . dirname( $file ) );
			}
		}
		add_action( 'wp_loaded', array( __CLASS__, 'resolve_refused' ) );
	}

	/**
	 * After WordPress wrote active_plugins (activated_plugin fires after update_option; the activation hook fires
	 * before it, so switching MJDD off there could be undone by the write on older WordPress versions).
	 *
	 * @param string $plugin Basename of the plugin that was just activated.
	 */
	public static function on_activated( $plugin ) {
		// The activating request includes us after plugins_loaded, so our init() (which loads translations) never ran.
		// Today WordPress loads them anyway, as a side effect of reading our header (activate_plugin ->
		// validate_plugin_requirements -> get_plugin_data). The notice below must be in the shop's language, so we do
		// not rely on a side effect.
		CX_Sidrena_Plugin::load_textdomain();

		$ours = plugin_basename( CX_SIDRENA_FILE );
		if ( $plugin === $ours ) {
			self::resolve( 'took_over' );
			return;
		}
		if ( self::is_conflict( $plugin ) && in_array( $ours, (array) get_option( 'active_plugins', array() ), true ) ) {
			self::resolve( 'refused' );
		}
	}

	/**
	 * wp_loaded callback for the silent case.
	 */
	public static function resolve_refused() {
		self::resolve( 'refused' );
	}

	/**
	 * Switch MJDD and the add-on off, drop their cron, refresh the rewrite rules, leave a notice.
	 *
	 * @param string $why took_over|refused.
	 */
	private static function resolve( $why ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$off = self::active_conflicts();
		if ( $off ) {
			// Silent: MJDD's own deactivation hook would flush rewrite rules with its code half-loaded; we do both
			// of its jobs ourselves just below.
			deactivate_plugins( $off, true );
		}
		foreach ( self::FOREIGN_CRON as $hook ) {
			wp_unschedule_hook( $hook ); // all events of the hook, whatever their args (bazna_n30_seed has a page arg)
		}

		// The public addresses keep working right away, before anyone opens the admin (M4): our routes use the same
		// patterns and query vars as MJDD's.
		CX_Sidrena_Export::instance()->register_rewrite();
		flush_rewrite_rules();
		update_option( CX_Sidrena_Export::REWRITE_OPTION, CX_Sidrena_Export::rewrite_fingerprint(), false );

		$names = array();
		foreach ( $off as $file ) {
			$names[] = self::plugin_name( $file );
		}

		if ( 'took_over' === $why ) {
			$done = get_option( self::DONE_OPTION, array() );
			if ( ! empty( $done['options'] ) || ! empty( $done['files'] ) || $names ) {
				$msg = sprintf(
					/* translators: 1: number of settings, 2: number of files, 3: plugin names or "nothing" */
					__( 'CX Sidrena has taken over the settings (%1$d) and the price list and archive files (%2$d) from the MJDD Sidrena i bazna cijena plugin. Bazna cijena values, dates and price history on products stay as they are. Switched off: %3$s.', 'cx-sidrena' ),
					isset( $done['options'] ) ? (int) $done['options'] : 0,
					isset( $done['files'] ) ? (int) $done['files'] : 0,
					$names ? implode( ', ', $names ) : __( 'nothing', 'cx-sidrena' )
				);
				self::add_notice( $msg, 'info' );
			}
		} elseif ( $names ) {
			self::add_notice(
				sprintf(
					/* translators: %s: plugin names */
					__( 'Switched off: %s. CX Sidrena does the same, and two such plugins switched on together would show prices twice.', 'cx-sidrena' ),
					implode( ', ', $names )
				),
				'warning'
			);
		}
	}

	/**
	 * Copy MJDD's options and files under our names. Safe to run again (going back to MJDD and over once more):
	 * an option is copied again only if MJDD changed it since the last take-over, a file only if we lack it, and a
	 * storage counter never goes backwards.
	 *
	 * An option we never took from MJDD is copied only while we have none of our own. So MJDD activated next to a
	 * fresh install (it writes its default settings) never replaces the owner's settings, with or without the marker.
	 * The marker is written on a fresh install too ("nothing to take over").
	 *
	 * @return bool Whether there was MJDD data to take over.
	 */
	public static function copy_from_mjdd() {
		global $wpdb;

		$done  = get_option( self::DONE_OPTION, array() );
		$done  = is_array( $done ) ? $done : array();
		$fp    = isset( $done['fp'] ) && is_array( $done['fp'] ) ? $done['fp'] : array();
		$first = array() === $done;

		// Every MJDD option, not only the 13 its uninstall knows, plus the add-on's seed marker.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
				$wpdb->esc_like( 'sidrena_cijena_' ) . '%',
				'bazna_n30_seeded'
			)
		);
		if ( ! $rows && ! self::mjdd_dir_present() ) {
			if ( $first ) {
				// A fresh install. Record it, and the lowest-price line shown (the default for a fresh install), so
				// that neither changes when MJDD is switched on here later.
				update_option(
					self::DONE_OPTION,
					array(
						'at'      => time(),
						'first'   => time(),
						'fresh'   => true,
						'options' => 0,
						'files'   => 0,
						'fp'      => array(),
					),
					true
				);
				if ( false === get_option( self::N30_DISPLAY_OPTION, false ) ) {
					update_option( self::N30_DISPLAY_OPTION, 'yes', true );
				}
			}
			return false;
		}

		$copied = 0;
		foreach ( (array) $rows as $row ) {
			$name = (string) $row->option_name;
			$hash = md5( (string) $row->option_value );
			$ours = 'bazna_n30_seeded' === $name ? 'cx_sidrena_n30_seeded' : 'cx_sidrena_' . substr( $name, strlen( 'sidrena_cijena_' ) );
			if ( isset( $fp[ $name ] ) ) {
				if ( $fp[ $name ] === $hash ) {
					continue; // unchanged by MJDD since we last took it: ours is the newer one.
				}
				// Changed since we took it: the owner went back to MJDD and changed it there. Take the newer one.
			} elseif ( false !== get_option( $ours, false ) ) {
				continue; // never taken from MJDD and we have our own (e.g. MJDD's defaults after a fresh install).
			}
			$fp[ $name ] = $hash;

			if ( 'bazna_n30_seeded' === $name ) {
				// "running" = the add-on's seeding was cut off; ours starts again (it only fills empty histories).
				if ( 'running' !== $row->option_value ) {
					update_option( 'cx_sidrena_n30_seeded', maybe_unserialize( $row->option_value ), false );
				}
				$copied++;
				continue;
			}

			$value = maybe_unserialize( $row->option_value );
			if ( CX_Sidrena_Settings::OPTION_KEY === $ours ) {
				$value = self::keep_counter( $value, get_option( $ours, array() ) );
			} elseif ( CX_Sidrena_Poslovnice::OPTION_KEY === $ours ) {
				$value = self::keep_outlet_counters( $value, get_option( $ours, array() ) );
			}
			update_option( $ours, $value, self::autoload_flag( (string) $row->autoload ) );
			$copied++;
		}

		$files = self::copy_files();

		// T3/K2: the "lowest price in 30 days" line keeps showing where it showed before (the add-on was on) and
		// stays hidden where it did not. Only set once, so a later take-over never overrides the owner's choice.
		if ( false === get_option( self::N30_DISPLAY_OPTION, false ) ) {
			update_option( self::N30_DISPLAY_OPTION, self::addon_active() ? 'yes' : 'no', true );
		}

		update_option(
			self::DONE_OPTION,
			array(
				'at'      => time(),
				'first'   => $first ? time() : ( isset( $done['first'] ) ? (int) $done['first'] : time() ),
				'options' => $copied,
				'files'   => $files,
				'fp'      => $fp,
			),
			true
		);

		return true;
	}

	/**
	 * Copy uploads/sidrena-cijena into our folder. Live files (feed, HTML table) are taken as they are now, because
	 * at take-over time MJDD's are the current ones; archived files only when we do not have that file yet; staged
	 * import jobs are temporary and stay behind. File times are kept, the archive list uses them.
	 *
	 * @return int Files copied.
	 */
	private static function copy_files() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return 0;
		}
		$from = trailingslashit( $uploads['basedir'] ) . self::MJDD_DIR;
		$to   = CX_Sidrena_Feed_Files::instance()->get_public_dir();
		if ( ! is_dir( $from ) || '' === $to || ! wp_mkdir_p( $to ) ) {
			return 0;
		}

		$items = scandir( $from );
		if ( ! is_array( $items ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || 'imports' === $item ) {
				continue;
			}
			$src = $from . '/' . $item;
			$dst = $to . '/' . $item;
			if ( is_file( $src ) ) {
				$count += self::copy_one( $src, $dst, true );
			} elseif ( is_dir( $src ) && wp_mkdir_p( $dst ) ) {
				$subs = scandir( $src );
				foreach ( is_array( $subs ) ? $subs : array() as $sub ) {
					if ( '.' !== $sub && '..' !== $sub && is_file( $src . '/' . $sub ) ) {
						$count += self::copy_one( $src . '/' . $sub, $dst . '/' . $sub, false );
					}
				}
			}
		}

		return $count;
	}

	/**
	 * @param string $src       Source file.
	 * @param string $dst       Destination file.
	 * @param bool   $overwrite Replace an existing file.
	 * @return int 1 when copied.
	 */
	private static function copy_one( $src, $dst, $overwrite ) {
		if ( ! $overwrite && file_exists( $dst ) ) {
			return 0;
		}
		if ( ! copy( $src, $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 0;
		}
		$mtime = filemtime( $src );
		if ( $mtime ) {
			touch( $dst, $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return 1;
	}

	/**
	 * MJDD's settings, with the storage counter (file_pohrana) never lower than ours.
	 *
	 * @param mixed $mjdd MJDD's settings.
	 * @param mixed $ours Our current settings.
	 * @return mixed
	 */
	private static function keep_counter( $mjdd, $ours ) {
		if ( is_array( $mjdd ) && is_array( $ours ) && isset( $ours['file_pohrana'] ) ) {
			$mjdd['file_pohrana'] = max( absint( $ours['file_pohrana'] ), isset( $mjdd['file_pohrana'] ) ? absint( $mjdd['file_pohrana'] ) : 0 );
		}

		return $mjdd;
	}

	/**
	 * MJDD's outlets, with each outlet's storage counter never lower than ours.
	 *
	 * @param mixed $mjdd MJDD's outlet rows.
	 * @param mixed $ours Our outlet rows.
	 * @return mixed
	 */
	private static function keep_outlet_counters( $mjdd, $ours ) {
		if ( ! is_array( $mjdd ) || ! is_array( $ours ) ) {
			return $mjdd;
		}
		$have = array();
		foreach ( $ours as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['pohrana'] ) ) {
				$have[ (string) $row['id'] ] = absint( $row['pohrana'] );
			}
		}
		foreach ( $mjdd as $i => $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && isset( $have[ (string) $row['id'] ] ) ) {
				$mjdd[ $i ]['pohrana'] = max( $have[ (string) $row['id'] ], isset( $row['pohrana'] ) ? absint( $row['pohrana'] ) : 0 );
			}
		}

		return $mjdd;
	}

	/**
	 * @param string $autoload Value of wp_options.autoload (yes/no before WP 6.6, on/off/auto… since).
	 * @return bool|null
	 */
	private static function autoload_flag( $autoload ) {
		if ( in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ) ) {
			return true;
		}
		if ( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * MJDD's settings or folder exist (it is installed, or was deactivated without being deleted).
	 *
	 * @return bool
	 */
	private static function mjdd_data_present() {
		return false !== get_option( 'sidrena_cijena_settings', false ) || self::mjdd_dir_present();
	}

	/**
	 * @return bool
	 */
	private static function mjdd_dir_present() {
		$uploads = wp_upload_dir( null, false );

		return empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( trailingslashit( $uploads['basedir'] ) . self::MJDD_DIR );
	}

	/**
	 * Active MJDD and add-on basenames.
	 *
	 * @return array<int,string>
	 */
	public static function active_conflicts() {
		$out = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( self::is_conflict( (string) $file ) ) {
				$out[] = (string) $file;
			}
		}

		return $out;
	}

	/**
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	private static function is_conflict( $file ) {
		return in_array( basename( $file ), array( self::MJDD_MAIN, self::ADDON_MAIN ), true );
	}

	/**
	 * @return bool
	 */
	private static function addon_active() {
		foreach ( self::active_conflicts() as $file ) {
			if ( self::ADDON_MAIN === basename( $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove every hook callback defined in a PHP file directly in $dir (the add-on hooks in at include time). Its
	 * update-checker library lives in a subfolder and is left alone: it only checks the add-on's own updates.
	 *
	 * @param string $dir Plugin folder.
	 */
	private static function remove_callbacks_from_dir( $dir ) {
		global $wp_filter;

		$dir = wp_normalize_path( untrailingslashit( (string) realpath( $dir ) ) );
		if ( '' === $dir ) {
			return;
		}
		foreach ( array_keys( (array) $wp_filter ) as $hook ) {
			if ( ! $wp_filter[ $hook ] instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$file = self::callback_file( $cb['function'] );
					$real = '' !== $file ? realpath( $file ) : false;
					if ( '' !== $file && dirname( wp_normalize_path( $real ? $real : $file ) ) === $dir ) {
						remove_filter( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * @param mixed $fn Hook callback.
	 * @return string File it is defined in, or empty.
	 */
	private static function callback_file( $fn ) {
		try {
			if ( $fn instanceof Closure || ( is_string( $fn ) && function_exists( $fn ) ) ) {
				$ref = new ReflectionFunction( $fn );
			} elseif ( is_array( $fn ) && 2 === count( $fn ) && method_exists( $fn[0], $fn[1] ) ) {
				$ref = new ReflectionMethod( $fn[0], $fn[1] );
			} else {
				return '';
			}
		} catch ( ReflectionException $e ) {
			return '';
		}
		$file = $ref->getFileName();

		return is_string( $file ) ? $file : '';
	}

	/**
	 * @param string $file Plugin basename.
	 * @return string Its name from the header, or the file name.
	 */
	private static function plugin_name( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( function_exists( 'get_plugin_data' ) && is_file( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return (string) $data['Name'];
			}
		}

		return $file;
	}

	/**
	 * @param string $text Message.
	 * @param string $type info|warning.
	 */
	private static function add_notice( $text, $type ) {
		$all   = get_option( self::NOTICE_OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$all[] = array(
			'text' => $text,
			'type' => $type,
		);
		update_option( self::NOTICE_OPTION, $all, false );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			if ( 'warning' === $type ) {
				WP_CLI::warning( $text );
			} else {
				WP_CLI::log( $text );
			}
		}
	}

	/**
	 * Show the stored messages once to someone who manages the shop.
	 */
	public static function show_notice() {
		if ( ! class_exists( 'CX_Sidrena_Plugin' ) || ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}
		$all = get_option( self::NOTICE_OPTION, array() );
		if ( ! is_array( $all ) || ! $all ) {
			return;
		}
		delete_option( self::NOTICE_OPTION );
		foreach ( $all as $n ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'warning' === ( isset( $n['type'] ) ? $n['type'] : '' ) ? 'warning' : 'info',
				esc_html( isset( $n['text'] ) ? (string) $n['text'] : '' )
			);
		}
	}
}
