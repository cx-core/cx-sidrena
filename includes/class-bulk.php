<?php
/**
 * A confirmed bulk write that may outlive the request that started it: Status "Fix all", WP-CLI fill, and Import.
 *
 * One job per shop at a time. Its state is the option cx_sidrena_bulk: who confirmed, how far it got, what it wrote
 * and skipped. Rows are written in steps. WP-cron runs a step, and so does every progress read of the screen, so the
 * page may be closed and the job still ends. A lock (a row in the options table added with INSERT IGNORE, as
 * WP_Upgrader::create_lock does, on MySQL and on SQLite) lets one step run at a time, so no row is written twice
 * by two steps at once. Each kind (fill, import) also makes its own row write idempotent: a request that dropped
 * after writing a row is not a second write when the row is tried again.
 *
 * The position is saved after every row. A step that dies (time limit, memory) leaves "in_step" set: the next step
 * tries that row again once, and skips it the second time, so a job never hangs on one row.
 *
 * A job never stays half done and locked when the browser goes away (Argus F1, CX-SID-42): WP-cron (and on a shop
 * without it, any progress read or any admin page load) continues it, a lock left by a dead request is taken over
 * after STALE seconds, and the owner can stop it (cancel): what was written stays, with its history, the rest is
 * skipped and counted.
 *
 * Kinds: a class with static bulk_rows( $job ) (the rows, or WP_Error when they cannot be read), bulk_write( $job,
 * $row ) → array( result: updated|skipped, label, message ) and bulk_finish( $job ).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * The bulk job.
 */
final class CX_Sidrena_Bulk {

	const OPTION = 'cx_sidrena_bulk';

	/** Ids of a fill, kept apart from the job so that saving the position after a row stays small. */
	const ITEMS_OPTION = 'cx_sidrena_bulk_items';

	const CRON_HOOK = 'cx_sidrena_bulk_run';

	/**
	 * Seconds after which a lock left by a dead request is taken over (a step works at most CRON_BUDGET, so a live step
	 * never holds it this long). Argus F1 (CX-SID-42): a stopped import must never stay locked.
	 */
	const STALE = 300;

	/** A step from WP-cron works this long. */
	const CRON_BUDGET = 20;

	/** A step from a progress read (the screen waits for it). */
	const POLL_BUDGET = 8;

	const POLL_ROWS = 40;

	/** Skipped rows kept for the screen (the counts are always complete). */
	const MAX_SKIPPED_ROWS = 500;

	const KINDS = array(
		'fill'   => 'CX_Sidrena_Fill',
		'import' => 'CX_Sidrena_Import',
	);

	/**
	 * Hook up.
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_going' ) );
	}

	/**
	 * A running job always has its next step scheduled: after a restore, a cleared cron, or a step that died before it
	 * could schedule one, the next admin page load puts it back.
	 */
	public static function keep_going() {
		if ( self::running() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	// ----------------------------------------------------------------------------------------------------------------
	// The job
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * The job as stored now (read from the database, never from a cache another request may have left behind).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB
		$job = null === $raw ? null : maybe_unserialize( $raw );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * The job that is still running, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function running() {
		$job = self::get();

		return $job && empty( $job['finished'] ) ? $job : null;
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	private static function save( array $job ) {
		$job['touched'] = time();
		wp_cache_delete( self::OPTION, 'options' );
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Start a job. No product is written here; the caller runs a first step if it wants one now.
	 *
	 * $prepare does what belongs to this job alone (the confirmation row, an import's rows): it runs only in the request
	 * that holds the start lock and found nothing running. A second confirmation made at the same moment is refused
	 * with 'running' before it touches anything (Argus, CX-SID-22: it used to write its own confirmation and delete the
	 * rows the first one was about to import).
	 *
	 * @param array<string,mixed> $job     kind, via, by, on, user, total, and what the kind needs (file, counts).
	 * @param int[]|null          $items   A fill's ids.
	 * @param callable|null       $prepare Returns fields for the job (conf) or a WP_Error, which is returned as is.
	 * @return array<string,mixed>|WP_Error The job, 'running', or $prepare's error.
	 */
	public static function start( array $job, $items = null, $prepare = null ) {
		if ( ! self::lock( 'bulk_start', 30 ) ) {
			return self::running_error();
		}
		try {
			if ( self::running() ) {
				return self::running_error();
			}
			if ( null !== $prepare ) {
				$more = call_user_func( $prepare );
				if ( is_wp_error( $more ) ) {
					return $more;
				}
				$job = array_merge( $job, (array) $more );
			}
			$job = array_merge(
				array(
					'v'            => 1,
					'id'           => strtolower( wp_generate_password( 32, false, false ) ),
					'kind'         => 'fill',
					'via'          => 'status',
					'conf'         => 0,
					'by'           => '',
					'on'           => '',
					'user'         => get_current_user_id(),
					'total'        => 0,
					'pos'          => 0,
					'updated'      => 0,
					'skipped'      => 0,
					'skipped_rows' => array(),
					'in_step'      => false,
					'crash_pos'    => -1,
					'crashes'      => 0,
					'started'      => time(),
					'finished'     => false,
					'finished_at'  => 0,
				),
				$job
			);
			wp_cache_delete( self::ITEMS_OPTION, 'options' );
			if ( null !== $items ) {
				update_option( self::ITEMS_OPTION, array_values( array_map( 'intval', $items ) ), false );
			} else {
				delete_option( self::ITEMS_OPTION );
			}
			self::save( $job );
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time(), self::CRON_HOOK );
			}

			return $job;
		} finally {
			self::unlock( 'bulk_start' );
		}
	}

	/**
	 * @return WP_Error
	 */
	private static function running_error() {
		return new WP_Error( 'running', __( 'Another fill or import is running. Wait for it to finish.', 'cx-sidrena' ) );
	}

	/**
	 * A fill's ids.
	 *
	 * @return int[]
	 */
	public static function items() {
		wp_cache_delete( self::ITEMS_OPTION, 'options' );
		$items = get_option( self::ITEMS_OPTION, array() );

		return is_array( $items ) ? array_map( 'intval', $items ) : array();
	}

	/**
	 * Write the next rows of the running job. Does nothing while another step runs.
	 *
	 * @param float $budget   Seconds to work (a row that has started always ends).
	 * @param int   $max_rows At most this many rows.
	 * @return array<string,mixed>|null The job after the step.
	 */
	public static function step( $budget, $max_rows = PHP_INT_MAX ) {
		// Products are written through WooCommerce: while it is off the job waits, nothing is lost.
		if ( ! CX_Sidrena_Plugin::woocommerce_active() || ! self::running() || ! self::lock( 'bulk_step', self::STALE ) ) {
			return self::get();
		}
		$who = get_current_user_id();
		try {
			$job = self::running();
			if ( ! $job ) {
				return self::get();
			}
			$class = isset( self::KINDS[ $job['kind'] ] ) ? self::KINDS[ $job['kind'] ] : '';
			if ( '' === $class || ! class_exists( $class ) ) {
				return $job;
			}
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( (int) ceil( $budget ) + 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
			}
			ignore_user_abort( true );
			// The person who confirmed is the user of every change: also when WP-cron writes it, and when someone else
			// follows the job on the screen (Argus O1, CX-SID-42). Put back at the end of the step.
			if ( ! empty( $job['user'] ) && (int) $job['user'] !== $who ) {
				wp_set_current_user( (int) $job['user'] );
			}

			$rows = call_user_func( array( $class, 'bulk_rows' ), $job );
			if ( ! empty( $job['in_step'] ) ) {
				// The step before died on this row.
				if ( (int) $job['crash_pos'] === (int) $job['pos'] ) {
					++$job['crashes'];
				} else {
					$job['crash_pos'] = (int) $job['pos'];
					$job['crashes']   = 1;
				}
				if ( $job['crashes'] >= 2 && $job['pos'] < $job['total'] ) {
					$label = ! is_wp_error( $rows ) && isset( $rows[ $job['pos'] ] ) ? self::label( $class, $rows[ $job['pos'] ] ) : '';
					self::skip( $job, $label, __( 'Stopped twice on this row.', 'cx-sidrena' ) );
					++$job['pos'];
					$job['crashes'] = 0;
				}
			}
			$job['in_step'] = true;
			self::save( $job );

			$wrote = false;
			if ( is_wp_error( $rows ) ) {
				// The rows cannot be read any more (the import file is gone): the rest is skipped, nothing half written.
				while ( $job['pos'] < $job['total'] ) {
					self::skip( $job, '', $rows->get_error_message() );
					++$job['pos'];
				}
			} else {
				$t0 = microtime( true );
				$n  = 0;
				while ( $job['pos'] < $job['total'] && $n < $max_rows && ( 0 === $n || microtime( true ) - $t0 < $budget ) ) {
					$row = isset( $rows[ $job['pos'] ] ) ? $rows[ $job['pos'] ] : null;
					$r   = null === $row ? array( 'result' => 'skipped', 'label' => '', 'message' => '' ) : call_user_func( array( $class, 'bulk_write' ), $job, $row );
					if ( 'updated' === $r['result'] ) {
						++$job['updated'];
						$wrote = $wrote || empty( $r['already'] );
					} else {
						self::skip( $job, (string) $r['label'], (string) $r['message'] );
					}
					++$job['pos'];
					++$n;
					self::save( $job );
				}
			}

			if ( $job['pos'] >= $job['total'] ) {
				$job['finished']    = true;
				$job['finished_at'] = time();
				call_user_func( array( $class, 'bulk_finish' ), $job );
			}
			$job['in_step'] = false;
			self::save( $job );
			if ( $wrote && class_exists( 'CX_Sidrena_Export' ) ) {
				// The price list follows within a minute; the first one is made as soon as the first bazna exists.
				CX_Sidrena_Export::instance()->queue_feed_after_change();
			}
			if ( empty( $job['finished'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time(), self::CRON_HOOK );
			}

			return $job;
		} finally {
			if ( get_current_user_id() !== $who ) {
				wp_set_current_user( $who );
			}
			self::unlock( 'bulk_step' );
		}
	}

	/**
	 * @param string $class Kind.
	 * @param mixed  $row   Row.
	 * @return string
	 */
	private static function label( $class, $row ) {
		return method_exists( $class, 'bulk_label' ) ? (string) call_user_func( array( $class, 'bulk_label' ), $row ) : '';
	}

	/**
	 * @param array<string,mixed> $job     Job.
	 * @param string              $label   SKU or name.
	 * @param string              $message Why.
	 */
	private static function skip( array &$job, $label, $message ) {
		++$job['skipped'];
		if ( count( $job['skipped_rows'] ) < self::MAX_SKIPPED_ROWS ) {
			$job['skipped_rows'][] = array(
				'sku'     => (string) $label,
				'message' => (string) $message,
			);
		}
	}

	/**
	 * Stop the running job: what was written stays (each row with its history), the rest is skipped and counted, and the
	 * kind closes its confirmation as for a job that ended. Waits for a step in progress (up to STALE).
	 *
	 * @param string $by Who stopped it (a user login, or "WP-CLI").
	 * @return array<string,mixed>|WP_Error The job after it was stopped, or not_running / busy.
	 */
	public static function cancel( $by = '' ) {
		if ( ! self::running() ) {
			return new WP_Error( 'not_running', __( 'Nothing is running.', 'cx-sidrena' ) );
		}
		if ( ! self::lock( 'bulk_step', self::STALE ) ) {
			return new WP_Error( 'busy', __( 'A step is being written right now. Try again in a minute.', 'cx-sidrena' ) );
		}
		try {
			$job = self::running();
			if ( ! $job ) {
				return self::get();
			}
			$left = (int) $job['total'] - (int) $job['pos'];
			$job['skipped']     += max( 0, $left );
			$job['pos']          = (int) $job['total'];
			$job['cancelled']    = array(
				'at'   => time(),
				'by'   => (string) $by,
				'left' => max( 0, $left ),
			);
			$job['finished']     = true;
			$job['finished_at']  = time();
			$job['in_step']      = false;
			$class               = isset( self::KINDS[ $job['kind'] ] ) ? self::KINDS[ $job['kind'] ] : '';
			if ( '' !== $class && class_exists( $class ) ) {
				call_user_func( array( $class, 'bulk_finish' ), $job );
			}
			self::save( $job );
			wp_clear_scheduled_hook( self::CRON_HOOK );

			return $job;
		} finally {
			self::unlock( 'bulk_step' );
		}
	}

	/**
	 * WP-cron: a step, and the next one is scheduled until the job ends.
	 */
	public static function run_cron() {
		self::step( self::CRON_BUDGET );
	}

	/**
	 * The job as the screens read it.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array{job:string,total:int,processed:int,finished:bool,result?:array}
	 */
	public static function shape( array $job ) {
		$out = array(
			'job'       => (string) $job['id'],
			'total'     => (int) $job['total'],
			'processed' => (int) $job['pos'],
			'finished'  => ! empty( $job['finished'] ),
		);
		if ( $out['finished'] ) {
			$out['result'] = array(
				'updated'      => (int) $job['updated'],
				'skipped'      => (int) $job['skipped'],
				'skipped_rows' => array_values( (array) $job['skipped_rows'] ),
			);
			if ( ! empty( $job['cancelled'] ) ) {
				$out['result']['cancelled'] = (int) $job['cancelled']['left']; // rows not written because it was stopped
			}
		}

		return $out;
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Lock
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Take a lock: a row in the options table (INSERT IGNORE: one request wins). A lock older than $stale seconds was
	 * left by a request that died, and is taken over (only one request can, the update checks the old value).
	 *
	 * @param string $name  Lock.
	 * @param int    $stale Seconds.
	 * @return bool
	 */
	private static function lock( $name, $stale ) {
		global $wpdb;
		$opt = 'cx_sidrena_' . $name . '.lock';
		for ( $try = 0; $try < 2; $try++ ) {
			$suppress = $wpdb->suppress_errors( true );
			$ins      = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $opt, (string) time() ) ); // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( $suppress );
			if ( $ins ) {
				return true;
			}
			$at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) ); // phpcs:ignore WordPress.DB
			if ( null === $at ) {
				continue; // released in between: try again
			}
			if ( (int) $at > time() - $stale ) {
				return false;
			}
			$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $opt, (string) $at ) ); // phpcs:ignore WordPress.DB

			return 1 === (int) $took;
		}

		return false;
	}

	/**
	 * @param string $name Lock.
	 */
	private static function unlock( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'cx_sidrena_' . $name . '.lock' ) ); // phpcs:ignore WordPress.DB
	}
}
