<?php
/**
 * Price history and the source of every bazna cijena.
 *
 * Every change of a tracked price key on a product or variation becomes a row in {prefix}cx_sidrena_history, whatever
 * wrote it: the product screen, quick or bulk edit, the REST API (an ERP), WooCommerce's CSV import, a scheduled sale,
 * WP-CLI, cron or another plugin. It is recorded at the level of WordPress's meta API, so nobody has to call us; only
 * direct SQL is not seen. Rows are only ever added. They are the shop's evidence that its bazna cijena and its lowest
 * price in 30 days are right, so deleting the plugin or `wp cx-sidrena purge` keeps them.
 *
 * Each bazna cijena also knows where it came from (product meta _cx_sidrena_izvor, our prefix, so MJDD's "delete all"
 * never touches it). A confirmed bulk write (Import, Status, `wp cx-sidrena fill`) is a row in
 * {prefix}cx_sidrena_confirmations with the exact consent text the client saw.
 *
 * Only prices are recorded. Nothing about the plugin's settings or about warnings the owner chose to ignore.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * History of prices and the source of bazna cijena.
 */
final class CX_Sidrena_History {

	const TABLE         = 'cx_sidrena_history';
	const CONFIRMATIONS = 'cx_sidrena_confirmations';

	/** Schema version the tables were last created or changed with (CX_SIDRENA_DB_VERSION). */
	const DB_VERSION_OPTION = 'cx_sidrena_db_version';

	/** Unix time from which every change has been recorded without a gap (the last activation or schema install). */
	const SINCE_OPTION = 'cx_sidrena_history_since';

	/** Failed writes to the history: count, last message, time. Shown on Status; a save never fails because of it. */
	const ERRORS_OPTION = 'cx_sidrena_history_errors';

	/** A failed schema install is retried after this transient expires, not on every request. */
	const RETRY_TRANSIENT = 'cx_sidrena_schema_retry';

	/** Source of the bazna cijena of a product or variation. */
	const IZVOR_META = '_cx_sidrena_izvor';

	/** meta_key of the single row written when a product or variation is deleted. */
	const DELETED_KEY = '_product_deleted';

	/** Keys whose every change is a row. _price is the price the customer pays: only it shows a scheduled sale. */
	const KEYS = array(
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_price',
		'_sidrena_cijena',
		'_sidrena_cijena_datum',
		'_bazna_najniza30',
		'_bazna_najniza30_izvor',
	);

	/** Amounts: '12.5' and '12.50' are the same price, so an ERP writing the same prices every night adds nothing. */
	const AMOUNT_KEYS = array( '_regular_price', '_sale_price', '_price', '_sidrena_cijena', '_bazna_najniza30' );

	/** A change of these refreshes the source of the bazna cijena in the same step. */
	const BAZNA_KEYS = array( '_sidrena_cijena', '_sidrena_cijena_datum' );

	/**
	 * Parents whose prices WooCommerce derives from their children (sync_price deletes and adds _price again on
	 * every child save). Their children are recorded, they are not.
	 */
	const PARENT_TYPES = array( 'variable', 'grouped', 'variable-subscription' );

	/** Context types set by our own code (with()), and the source each of them is recorded as. */
	const CONTEXT_SOURCES = array(
		'status' => 'status',
		'cli'    => 'cli',
		'import' => 'import',
		'new'    => 'new_product',
		// Own dates stored so that no shown date moves: at a take-over from MJDD, and before other Dates are saved.
		// They leave the source of the bazna as it was.
		'take_over' => 'take_over',
		'dates'     => 'dates',
	);

	/** Context types that never change the source of the bazna. */
	const KEEP_IZVOR = array( 'take_over', 'dates' );

	/** Kinds of confirmed bulk writes. */
	const CONFIRMATION_KINDS = array( 'import', 'status', 'cli' );

	/**
	 * Stack of contexts from with().
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $context = array();

	/**
	 * Old values read just before an update, by meta_id.
	 *
	 * @var array<int,string|null>
	 */
	private static $before = array();

	/**
	 * Rows about to be deleted, by meta_id: [post_id, value].
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	private static $gone = array();

	/**
	 * Products and variations being deleted right now (one row for the whole deletion).
	 *
	 * @var array<int,bool>
	 */
	private static $deleting = array();

	/**
	 * WooCommerce's CSV importer is processing a row.
	 *
	 * @var bool
	 */
	private static $csv = false;

	/**
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook up. Called when the main file is included, so writes made while other plugins load are recorded too.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Automatic updates (CX-SID-16) never run the activation hook, so the schema is also checked on every load.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 1 );

		add_action( 'update_post_meta', array( __CLASS__, 'before_update' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'after_update' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'after_add' ), 10, 4 );
		add_action( 'delete_post_meta', array( __CLASS__, 'before_delete' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'after_delete' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete_post' ), 10, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'after_delete_post' ), 10, 1 );

		add_action( 'woocommerce_product_import_before_process_item', array( __CLASS__, 'csv_start' ) );
		add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'csv_end' ), PHP_INT_MAX );
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Schema
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @return string
	 */
	public static function confirmations_table() {
		global $wpdb;

		return $wpdb->prefix . self::CONFIRMATIONS;
	}

	/**
	 * plugins_loaded: create or upgrade the tables when the code is newer than the database.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) >= CX_SIDRENA_DB_VERSION ) {
			return;
		}
		if ( get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or upgrade both tables (dbDelta). Safe to run again; never drops anything.
	 *
	 * @return bool Whether both tables exist afterwards.
	 */
	public static function install() {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$collate = $wpdb->get_charset_collate();
		$history = self::table();
		$confirm = self::confirmations_table();

		// dbDelta wants one field per line and two spaces after PRIMARY KEY.
		$sql = array(
			"CREATE TABLE {$history} (
id bigint(20) unsigned NOT NULL auto_increment,
product_id bigint(20) unsigned NOT NULL,
parent_id bigint(20) unsigned NOT NULL default 0,
meta_key varchar(64) NOT NULL default '',
old_value text NULL,
new_value text NULL,
changed_at bigint(20) unsigned NOT NULL,
source varchar(20) NOT NULL default '',
user_id bigint(20) unsigned NOT NULL default 0,
ref bigint(20) unsigned NULL,
note varchar(255) NOT NULL default '',
PRIMARY KEY  (id),
KEY product_changed (product_id,changed_at),
KEY changed_at (changed_at),
KEY ref (ref)
) {$collate};",
			"CREATE TABLE {$confirm} (
id bigint(20) unsigned NOT NULL auto_increment,
kind varchar(20) NOT NULL default '',
started_at bigint(20) unsigned NOT NULL,
finished_at bigint(20) unsigned NULL,
user_id bigint(20) unsigned NOT NULL default 0,
confirmed_by varchar(100) NOT NULL default '',
confirmed_on varchar(10) NOT NULL default '',
consent text NOT NULL,
file_name varchar(255) NOT NULL default '',
file_sha256 varchar(64) NOT NULL default '',
total int(10) unsigned NOT NULL default 0,
updated int(10) unsigned NOT NULL default 0,
unchanged int(10) unsigned NOT NULL default 0,
not_found int(10) unsigned NOT NULL default 0,
invalid int(10) unsigned NOT NULL default 0,
skipped int(10) unsigned NOT NULL default 0,
PRIMARY KEY  (id),
KEY started_at (started_at)
) {$collate};",
		);

		$suppress = $wpdb->suppress_errors( true );
		dbDelta( $sql );
		$ok = self::table_exists( $history ) && self::table_exists( $confirm );
		$wpdb->suppress_errors( $suppress );

		if ( ! $ok ) {
			self::fail( 'schema: ' . $wpdb->last_error );
			set_transient( self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
			return false;
		}

		update_option( self::DB_VERSION_OPTION, CX_SIDRENA_DB_VERSION, true );
		if ( ! get_option( self::SINCE_OPTION ) ) {
			update_option( self::SINCE_OPTION, time(), false );
		}

		return true;
	}

	/**
	 * Activation: the schema, and recording starts again now. While the plugin was off nothing was recorded, so the
	 * history is complete only from this moment; the rows from before stay.
	 */
	public static function activate() {
		self::install();
		update_option( self::SINCE_OPTION, time(), false );
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Context: who is writing, from our own code
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Run $fn with a context, so every change it makes is recorded with it and the source of the bazna says so.
	 *
	 * @param array<string,mixed> $context {
	 *     @type string $type      status|cli|import|new. Without it the source is detected (admin, REST…) and the
	 *                             bazna's source becomes "changed".
	 *     @type string $by        Name of who confirmed (status, cli, import) or changed it.
	 *     @type int    $ref       Confirmation id (start_confirmation()).
	 *     @type string $reason    Why it was changed (the product screen's Reason).
	 *     @type string $date_from own|category|added|other: where the date came from (the date rule, CX-SID-21).
	 *                             Without it: own when the product's own date was set, empty when it was cleared.
	 * }
	 * @param callable            $fn Work to do.
	 * @return mixed What $fn returns.
	 */
	public static function with( array $context, $fn ) {
		self::$context[] = $context;
		try {
			return call_user_func( $fn );
		} finally {
			array_pop( self::$context );
		}
	}

	/**
	 * @return array<string,mixed> The innermost context, or an empty one.
	 */
	private static function context() {
		$top = end( self::$context );

		return is_array( $top ) ? $top : array();
	}

	/**
	 * WooCommerce's CSV importer starts a row.
	 */
	public static function csv_start() {
		self::$csv = true;
	}

	/**
	 * WooCommerce's CSV importer saved a row.
	 */
	public static function csv_end() {
		self::$csv = false;
	}

	/**
	 * Where the change in progress comes from, and a note (the reason, or which plugin wrote when nothing else says).
	 *
	 * @return array{source:string,note:string,context:array<string,mixed>}
	 */
	private static function source() {
		$context = self::context();
		$note    = isset( $context['reason'] ) ? (string) $context['reason'] : '';
		$type    = isset( $context['type'] ) ? (string) $context['type'] : '';

		if ( array_key_exists( $type, self::CONTEXT_SOURCES ) ) {
			$source = self::CONTEXT_SOURCES[ $type ];
		} elseif ( doing_action( 'woocommerce_scheduled_sales' ) || doing_action( 'wc_product_start_scheduled_sale' ) || doing_action( 'wc_product_end_scheduled_sale' ) ) {
			$source = 'sale_schedule'; // WooCommerce 7.9: the daily cron; 10.5+: one Action Scheduler action per sale.
		} elseif ( self::$csv ) {
			$source = 'wc_csv';
		} elseif ( doing_action( 'woocommerce_product_bulk_and_quick_edit' ) ) {
			$source = empty( $_REQUEST['woocommerce_quick_edit'] ) ? 'bulk_edit' : 'quick_edit'; // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$source = 'rest';
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			$source = 'cli';
		} elseif ( wp_doing_cron() ) {
			$source = 'cron';
		} elseif ( is_admin() ) {
			$source = 'admin';
		} else {
			$source = 'other';
		}

		if ( '' === $note && ( 'cron' === $source || 'other' === $source ) ) {
			$note = self::caller();
		}

		return array(
			'source'  => $source,
			'note'    => $note,
			'context' => $context,
		);
	}

	/**
	 * The plugin or theme that made the change: the first file on the call stack outside WordPress, WooCommerce and us.
	 *
	 * @return string e.g. "plugin:my-erp-sync", or empty.
	 */
	private static function caller() {
		$dirs = array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => wp_normalize_path( get_theme_root() ) . '/',
		);
		$skip = array( dirname( plugin_basename( CX_SIDRENA_FILE ) ), 'woocommerce' );

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			foreach ( $dirs as $kind => $dir ) {
				if ( 0 !== strpos( $file, $dir ) ) {
					continue;
				}
				$slug = strtok( substr( $file, strlen( $dir ) ), '/' );
				if ( false !== $slug && ! in_array( $slug, $skip, true ) ) {
					return $kind . ':' . $slug;
				}
			}
		}

		return '';
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Meta API hooks
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function tracked( $key ) {
		static $keys = null;
		if ( null === $keys ) {
			$keys = array_flip( self::KEYS );
		}

		return is_string( $key ) && isset( $keys[ $key ] );
	}

	/**
	 * update_post_meta (before the write): keep the value being replaced.
	 *
	 * @param int    $meta_id   Meta row.
	 * @param int    $object_id Post.
	 * @param string $meta_key  Key.
	 */
	public static function before_update( $meta_id, $object_id, $meta_key ) {
		if ( ! self::tracked( $meta_key ) || ! self::is_product( $object_id ) ) {
			return;
		}
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$old      = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppress );

		self::$before[ (int) $meta_id ] = $old;
	}

	/**
	 * updated_post_meta.
	 *
	 * @param int    $meta_id   Meta row.
	 * @param int    $object_id Post.
	 * @param string $meta_key  Key.
	 * @param mixed  $value     New value.
	 */
	public static function after_update( $meta_id, $object_id, $meta_key, $value ) {
		if ( ! self::tracked( $meta_key ) ) {
			return;
		}
		$meta_id = (int) $meta_id;
		$old     = array_key_exists( $meta_id, self::$before ) ? self::$before[ $meta_id ] : null;
		unset( self::$before[ $meta_id ] );
		self::record( $object_id, $meta_key, $old, $value );
	}

	/**
	 * added_post_meta.
	 *
	 * @param int    $meta_id   Meta row.
	 * @param int    $object_id Post.
	 * @param string $meta_key  Key.
	 * @param mixed  $value     Value.
	 */
	public static function after_add( $meta_id, $object_id, $meta_key, $value ) {
		if ( self::tracked( $meta_key ) ) {
			self::record( $object_id, $meta_key, null, $value );
		}
	}

	/**
	 * delete_post_meta (before): keep the rows about to go. With delete_all the object id is 0, so the post of each
	 * row is read too.
	 *
	 * @param array<int,string> $meta_ids  Meta rows.
	 * @param int               $object_id Post, or 0.
	 * @param string            $meta_key  Key.
	 */
	public static function before_delete( $meta_ids, $object_id, $meta_key ) {
		if ( ! self::tracked( $meta_key ) || ! $meta_ids ) {
			return;
		}
		global $wpdb;

		$ids      = implode( ',', array_map( 'intval', (array) $meta_ids ) );
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id IN ({$ids})" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		foreach ( (array) $rows as $row ) {
			self::$gone[ (int) $row->meta_id ] = array( (int) $row->post_id, $row->meta_value );
		}
	}

	/**
	 * deleted_post_meta.
	 *
	 * @param array<int,string> $meta_ids  Meta rows.
	 * @param int               $object_id Post, or 0.
	 * @param string            $meta_key  Key.
	 */
	public static function after_delete( $meta_ids, $object_id, $meta_key ) {
		if ( ! self::tracked( $meta_key ) ) {
			return;
		}
		foreach ( (array) $meta_ids as $meta_id ) {
			$meta_id = (int) $meta_id;
			if ( isset( self::$gone[ $meta_id ] ) ) {
				list( $post_id, $old ) = self::$gone[ $meta_id ];
				unset( self::$gone[ $meta_id ] );
				self::record( $post_id, $meta_key, $old, null );
			}
		}
	}

	/**
	 * A product or variation is being deleted for good: one row with its last prices instead of one per key.
	 *
	 * @param int $post_id Post.
	 */
	public static function before_delete_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! self::is_product( $post_id ) ) {
			return;
		}
		// Also for a parent: WordPress removes its product type before its meta, so it would look like a simple product.
		$parent                     = self::is_parent( $post_id );
		self::$deleting[ $post_id ] = true;
		if ( $parent ) {
			return;
		}

		try {
			global $wpdb;
			$in       = "'" . implode( "','", array_map( 'esc_sql', self::KEYS ) ) . "'";
			$suppress = $wpdb->suppress_errors( true );
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ({$in}) ORDER BY meta_id", $post_id ) ); // phpcs:ignore WordPress.DB
			$wpdb->suppress_errors( $suppress );

			$last = array();
			foreach ( (array) $rows as $row ) {
				if ( null !== self::value( $row->meta_value ) ) {
					$last[ $row->meta_key ] = $row->meta_value;
				}
			}
			$src = self::source();
			self::insert_rows(
				array(
					self::row( $post_id, self::DELETED_KEY, $last ? wp_json_encode( $last ) : null, null, $src ),
				)
			);
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	/**
	 * @param int $post_id Post.
	 */
	public static function after_delete_post( $post_id ) {
		unset( self::$deleting[ (int) $post_id ] );
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Recording
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * One change of one key. Never throws and never stops the save it is part of.
	 *
	 * @param int         $post_id Product or variation.
	 * @param string      $key     Tracked key.
	 * @param mixed       $old     Value before (null: there was none).
	 * @param mixed       $new     Value after (null: deleted).
	 */
	private static function record( $post_id, $key, $old, $new ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 || isset( self::$deleting[ $post_id ] ) ) {
			return;
		}

		try {
			$type = get_post_type( $post_id );
			if ( 'product' !== $type && 'product_variation' !== $type ) {
				return;
			}
			if ( 'product' === $type && self::is_parent( $post_id ) ) {
				return;
			}
			$old = self::value( $old );
			$new = self::value( $new );
			if ( self::same( $key, $old, $new ) ) {
				return;
			}

			$src = self::source();
			self::insert_rows( array( self::row( $post_id, $key, $old, $new, $src ) ) );

			if ( in_array( $key, self::BAZNA_KEYS, true ) ) {
				self::refresh_izvor( $post_id, $key, $src );
			}
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	/**
	 * Rows written by our own direct SQL (the bulk fill), which the meta API hooks do not see.
	 *
	 * @param string                    $key     Tracked key.
	 * @param array<int,array{0:mixed,1:mixed}> $changes Post id => [old, new].
	 */
	public static function record_direct( $key, array $changes ) {
		if ( ! self::tracked( $key ) || ! $changes ) {
			return;
		}
		try {
			$src  = self::source();
			$rows = array();
			foreach ( $changes as $post_id => $pair ) {
				$old = self::value( $pair[0] );
				$new = self::value( $pair[1] );
				if ( ! self::same( $key, $old, $new ) ) {
					$rows[] = self::row( (int) $post_id, $key, $old, $new, $src );
				}
			}
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				self::insert_rows( $chunk );
			}
			if ( in_array( $key, self::BAZNA_KEYS, true ) ) {
				foreach ( array_keys( $changes ) as $post_id ) {
					self::refresh_izvor( (int) $post_id, $key, $src );
				}
			}
		} catch ( Throwable $e ) {
			self::fail( $e->getMessage() );
		}
	}

	/**
	 * @param int                 $post_id Product or variation.
	 * @param string              $key     Key.
	 * @param string|null         $old     Value before.
	 * @param string|null         $new     Value after.
	 * @param array<string,mixed> $src     From source().
	 * @return array<string,mixed>
	 */
	private static function row( $post_id, $key, $old, $new, $src ) {
		$context = $src['context'];

		return array(
			'product_id' => $post_id,
			'parent_id'  => 'product_variation' === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : 0,
			'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'old_value'  => $old,
			'new_value'  => $new,
			'changed_at' => time(),
			'source'     => $src['source'],
			'user_id'    => get_current_user_id(),
			'ref'        => ! empty( $context['ref'] ) ? (int) $context['ref'] : null,
			'note'       => function_exists( 'mb_substr' ) ? mb_substr( $src['note'], 0, 255 ) : substr( $src['note'], 0, 255 ),
		);
	}

	/**
	 * One INSERT for all rows. A NULL stays NULL ($wpdb->prepare would turn it into '').
	 *
	 * @param array<int,array<string,mixed>> $rows Rows from row().
	 */
	private static function insert_rows( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}

		$values = array();
		foreach ( $rows as $r ) {
			$values[] = '(' . implode(
				',',
				array(
					$wpdb->prepare( '%d,%d,%s', $r['product_id'], $r['parent_id'], $r['meta_key'] ),
					null === $r['old_value'] ? 'NULL' : $wpdb->prepare( '%s', $r['old_value'] ),
					null === $r['new_value'] ? 'NULL' : $wpdb->prepare( '%s', $r['new_value'] ),
					$wpdb->prepare( '%d,%s,%d', $r['changed_at'], $r['source'], $r['user_id'] ),
					null === $r['ref'] ? 'NULL' : $wpdb->prepare( '%d', $r['ref'] ),
					$wpdb->prepare( '%s', $r['note'] ),
				)
			) . ')';
		}

		$table    = self::table();
		$suppress = $wpdb->suppress_errors( true );
		$done     = $wpdb->query( "INSERT INTO {$table} (product_id,parent_id,meta_key,old_value,new_value,changed_at,source,user_id,ref,note) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		if ( false === $done ) {
			self::fail( 'insert: ' . $wpdb->last_error );
		}
	}

	/**
	 * A write to the history failed. Counted for Status; the save it belongs to goes on.
	 *
	 * @param string $message What failed.
	 */
	private static function fail( $message ) {
		$errors = get_option( self::ERRORS_OPTION, array() );
		$errors = is_array( $errors ) ? $errors : array();
		update_option(
			self::ERRORS_OPTION,
			array(
				'count' => ( isset( $errors['count'] ) ? (int) $errors['count'] : 0 ) + 1,
				'last'  => substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $message ) ) ), 0, 300 ),
				'at'    => time(),
			),
			false
		);
		if ( class_exists( 'CX_Sidrena_Plugin' ) ) {
			CX_Sidrena_Plugin::log_exception( new RuntimeException( 'history: ' . $message ) );
		}
	}

	/**
	 * Failed writes so far (for Status).
	 *
	 * @return array{count:int,last:string,at:int}
	 */
	public static function errors() {
		$errors = get_option( self::ERRORS_OPTION, array() );
		$errors = is_array( $errors ) ? $errors : array();

		return array(
			'count' => isset( $errors['count'] ) ? (int) $errors['count'] : 0,
			'last'  => isset( $errors['last'] ) ? (string) $errors['last'] : '',
			'at'    => isset( $errors['at'] ) ? (int) $errors['at'] : 0,
		);
	}

	/**
	 * A meta value as stored: a string, or null for none (null, '' and blanks are all "no value").
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function value( $value ) {
		if ( null === $value || false === $value ) {
			return null;
		}
		if ( ! is_scalar( $value ) ) {
			$value = maybe_serialize( $value );
		}
		$value = (string) $value;

		return '' === trim( $value ) ? null : $value;
	}

	/**
	 * @param string      $key Key.
	 * @param string|null $a   Value.
	 * @param string|null $b   Value.
	 * @return bool Whether nothing really changed.
	 */
	private static function same( $key, $a, $b ) {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		$a = trim( $a );
		$b = trim( $b );
		if ( $a === $b ) {
			return true;
		}
		if ( in_array( $key, self::AMOUNT_KEYS, true ) && self::is_amount( $a ) && self::is_amount( $b ) ) {
			return self::canonical_amount( $a ) === self::canonical_amount( $b );
		}

		return false;
	}

	/**
	 * @param string $v Value.
	 * @return bool
	 */
	private static function is_amount( $v ) {
		return 1 === preg_match( '/^-?\d+(\.\d+)?$/', $v );
	}

	/**
	 * "012.50" → "12.5", "12.0" → "12".
	 *
	 * @param string $v Amount.
	 * @return string
	 */
	private static function canonical_amount( $v ) {
		$neg = '-' === $v[0];
		$v   = ltrim( $v, '-' );
		if ( false !== strpos( $v, '.' ) ) {
			$v = rtrim( rtrim( $v, '0' ), '.' );
		}
		$v = ltrim( $v, '0' );
		if ( '' === $v || '.' === $v[0] ) {
			$v = '0' . $v;
		}

		return ( $neg && '0' !== $v ? '-' : '' ) . $v;
	}

	/**
	 * @param int $post_id Post.
	 * @return bool A product or a variation.
	 */
	private static function is_product( $post_id ) {
		$type = get_post_type( (int) $post_id );

		return 'product' === $type || 'product_variation' === $type;
	}

	/**
	 * A variable or grouped product (its own prices are derived from its children).
	 *
	 * @param int $post_id Product.
	 * @return bool
	 */
	private static function is_parent( $post_id ) {
		$terms = get_the_terms( (int) $post_id, 'product_type' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( in_array( $term->slug, self::PARENT_TYPES, true ) ) {
				return true;
			}
		}

		return false;
	}

	// ----------------------------------------------------------------------------------------------------------------
	// Source of the bazna cijena
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * After the bazna or its date changed: where the bazna now comes from.
	 *
	 * @param int                 $post_id Product or variation.
	 * @param string              $key     The key that changed.
	 * @param array<string,mixed> $src     From source().
	 */
	private static function refresh_izvor( $post_id, $key, $src ) {
		if ( isset( $src['context']['type'] ) && in_array( $src['context']['type'], self::KEEP_IZVOR, true ) ) {
			return;
		}
		$price = get_post_meta( $post_id, CX_SIDRENA_META_PRICE, true );
		if ( '' === trim( (string) $price ) ) {
			delete_post_meta( $post_id, self::IZVOR_META );
			return;
		}

		$context = $src['context'];
		$prev    = get_post_meta( $post_id, self::IZVOR_META, true );
		$prev    = is_array( $prev ) ? $prev : array();
		$type    = isset( $context['type'] ) && array_key_exists( (string) $context['type'], self::CONTEXT_SOURCES ) ? (string) $context['type'] : 'changed';
		$user    = wp_get_current_user();

		if ( isset( $context['date_from'] ) ) {
			$date_from = (string) $context['date_from']; // the date rule (CX-SID-21) says where its date came from
		} elseif ( CX_SIDRENA_META_DATE === $key ) {
			// Someone set this product's own date, or cleared it (A9): then the date is the rule's again, never "own".
			$date_from = '' !== trim( (string) get_post_meta( $post_id, CX_SIDRENA_META_DATE, true ) ) ? 'own'
				: ( class_exists( 'CX_Sidrena_Dates' ) ? CX_Sidrena_Dates::date_for( $post_id )['rule_from'] : '' );
		} else {
			$date_from = isset( $prev['date_from'] ) ? (string) $prev['date_from'] : '';
		}

		update_post_meta(
			$post_id,
			self::IZVOR_META,
			array(
				'type'      => $type,
				'at'        => time(),
				'by'        => isset( $context['by'] ) && '' !== (string) $context['by'] ? (string) $context['by'] : ( $user->exists() ? (string) $user->display_name : '' ),
				'user'      => (int) $user->ID,
				'login'     => $user->exists() ? (string) $user->user_login : '',
				'ref'       => ! empty( $context['ref'] ) ? (int) $context['ref'] : 0,
				'reason'    => isset( $context['reason'] ) ? (string) $context['reason'] : '',
				'via'       => (string) $src['source'],
				'date_from' => $date_from,
				// The day the client confirmed (Y-m-d), for a confirmed fill or import: the label names that day.
				'on'        => isset( $context['on'] ) ? (string) $context['on'] : '',
			)
		);
	}

	/**
	 * Where the bazna cijena of a product or variation comes from.
	 *
	 * Bazna set before this version has no recorded source (v0.1.0 recorded none). It shows as taken over from MJDD
	 * when the shop came from MJDD and the product existed before that; otherwise as set before history started.
	 *
	 * @param int $post_id Product or variation.
	 * @return array{type:string,at:int,by:string,user:int,login:string,ref:int,reason:string,via:string,date_from:string,on:string}
	 *               type: status|cli|import|new|changed|mjdd|before, or none (no bazna).
	 */
	public static function izvor( $post_id ) {
		$post_id = (int) $post_id;
		$empty   = array(
			'type'      => 'none',
			'at'        => 0,
			'by'        => '',
			'user'      => 0,
			'login'     => '',
			'ref'       => 0,
			'reason'    => '',
			'via'       => '',
			'date_from' => '',
			'on'        => '',
		);
		if ( '' === trim( (string) get_post_meta( $post_id, CX_SIDRENA_META_PRICE, true ) ) ) {
			return $empty;
		}
		$stored = get_post_meta( $post_id, self::IZVOR_META, true );
		if ( is_array( $stored ) && ! empty( $stored['type'] ) ) {
			return array_merge( $empty, array_intersect_key( $stored, $empty ) );
		}

		$since   = self::mjdd_since();
		$created = (int) get_post_time( 'U', true, $post_id );
		$empty['type'] = ( $since > 0 && $created > 0 && $created < $since ) ? 'mjdd' : 'before';

		return $empty;
	}

	/**
	 * When this shop was taken over from MJDD (0: never). Also read by CX_Sidrena_Dates (take over or suggest).
	 *
	 * @return int
	 */
	public static function mjdd_since() {
		$done = get_option( 'cx_sidrena_migrated', array() );
		if ( ! is_array( $done ) ) {
			return 0;
		}
		if ( ! empty( $done['mjdd_at'] ) ) {
			return (int) $done['mjdd_at'];
		}
		// A marker written by 0.1.0 has no mjdd_at. MJDD's options it took are in fp; a fresh install has none.
		if ( empty( $done['fresh'] ) && ! empty( $done['fp'] ) && ! empty( $done['first'] ) ) {
			return (int) $done['first'];
		}

		return 0;
	}

	/**
	 * The source as the owner reads it (spec §1.6), e.g. "Import, confirmed by Ana Horvat on 14.11.2026.".
	 *
	 * @param array<string,mixed> $izvor From izvor().
	 * @return string Translated, or empty when there is no bazna.
	 */
	public static function izvor_label( array $izvor ) {
		$type = isset( $izvor['type'] ) ? (string) $izvor['type'] : 'none';
		$by   = isset( $izvor['by'] ) ? (string) $izvor['by'] : '';
		$on   = ! empty( $izvor['at'] ) ? wp_date( 'j.n.Y.', (int) $izvor['at'] ) : '';
		// A confirmed write names the day of the confirmation, not the day the rows were written.
		if ( in_array( $type, self::CONFIRMATION_KINDS, true ) && ! empty( $izvor['on'] ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $izvor['on'], $m ) ) {
			$on = sprintf( '%d.%d.%d.', (int) $m[3], (int) $m[2], (int) $m[1] );
		}

		switch ( $type ) {
			case 'import':
				/* translators: 1: name of who confirmed, 2: date */
				return sprintf( __( 'Import, confirmed by %1$s on %2$s', 'cx-sidrena' ), $by, $on );
			case 'status':
				/* translators: 1: name of who confirmed, 2: date */
				return sprintf( __( 'Status, confirmed by %1$s on %2$s', 'cx-sidrena' ), $by, $on );
			case 'cli':
				/* translators: 1: name of who confirmed, 2: date */
				return sprintf( __( 'WP-CLI, confirmed by %1$s on %2$s', 'cx-sidrena' ), $by, $on );
			case 'new':
				/* translators: %s: date */
				return sprintf( __( 'New product, first price on %s', 'cx-sidrena' ), $on );
			case 'changed':
				return self::changed_label( $izvor, $on );
			case 'mjdd':
				return __( 'Taken over from MJDD', 'cx-sidrena' );
			case 'before':
				return __( 'Set before history started', 'cx-sidrena' );
		}

		return '';
	}

	/**
	 * "Changed by …": a person in the admin, or the way it came when there was no person at the screen.
	 *
	 * @param array<string,mixed> $izvor From izvor().
	 * @param string              $on    Date.
	 * @return string
	 */
	private static function changed_label( array $izvor, $on ) {
		$via   = isset( $izvor['via'] ) ? (string) $izvor['via'] : '';
		$login = isset( $izvor['login'] ) ? (string) $izvor['login'] : '';
		$by    = isset( $izvor['by'] ) && '' !== (string) $izvor['by'] ? (string) $izvor['by'] : $login;

		if ( 'rest' === $via ) {
			/* translators: 1: user name, 2: date */
			return sprintf( __( 'Changed through the REST API (user %1$s) on %2$s', 'cx-sidrena' ), $login, $on );
		}
		if ( 'wc_csv' === $via ) {
			/* translators: 1: user name, 2: date */
			return sprintf( __( 'Changed by a WooCommerce CSV import (user %1$s) on %2$s', 'cx-sidrena' ), $login, $on );
		}
		if ( '' === $by ) {
			/* translators: %s: date */
			return 'cli' === $via ? sprintf( __( 'Changed through WP-CLI on %s', 'cx-sidrena' ), $on ) : sprintf( __( 'Changed automatically on %s', 'cx-sidrena' ), $on );
		}

		/* translators: 1: user name, 2: date */
		return sprintf( __( 'Changed by %1$s on %2$s', 'cx-sidrena' ), $by, $on );
	}

	// ----------------------------------------------------------------------------------------------------------------
	// API for the screens, the import and WP-CLI
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * Set the bazna cijena (and its date) of one product or variation the way the model wants: history and source.
	 *
	 * @param int                 $post_id Product or variation.
	 * @param string|float        $price   Amount (> 0), in the same tax basis as the regular price.
	 * @param string|null         $date    d.m.Y. (with or without leading zeros and the last dot) sets the product's own
	 *                                     date; '' clears it, so the date is the rule's again (A9); null keeps it.
	 * @param array<string,mixed> $context As in with().
	 * @return true|WP_Error
	 */
	public static function set_bazna( $post_id, $price, $date, array $context = array() ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post_id ) : null;
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'cx-sidrena' ) );
		}
		if ( $product->is_type( self::PARENT_TYPES ) ) {
			return new WP_Error( 'variable_parent', __( 'Set the bazna cijena on each variation.', 'cx-sidrena' ) );
		}
		$meta   = CX_Sidrena_Product_Meta::instance();
		$amount = $meta->normalize_price( $price );
		if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return new WP_Error( 'bad_price', __( 'The bazna cijena must be an amount above zero.', 'cx-sidrena' ) );
		}
		$stored_date = null;
		if ( null !== $date && '' !== trim( (string) $date ) ) {
			$stored_date = self::parse_date( (string) $date );
			if ( '' === $stored_date ) {
				return new WP_Error( 'bad_date', __( 'The date must be a real date, e.g. 10.9.2026.', 'cx-sidrena' ) );
			}
		}

		$id = $product->get_id();
		self::with(
			$context,
			static function () use ( $meta, $id, $amount, $stored_date, $date ) {
				$meta->set_price( $id, $amount );
				if ( null !== $stored_date ) {
					$meta->set_date( $id, $stored_date );
				} elseif ( null !== $date ) {
					$meta->set_date( $id, '' ); // deletes the own date
				}
			}
		);

		return true;
	}

	/**
	 * A date as the plugin stores it ("10.09.2026."), or empty when it is not a real date.
	 *
	 * @param string $date d.m.Y, d.m.Y. (leading zeros optional).
	 * @return string
	 */
	public static function parse_date( $date ) {
		if ( ! preg_match( '/^\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})\.?\s*$/', $date, $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return '';
		}

		return sprintf( '%02d.%02d.%04d.', (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	/**
	 * Start a confirmed bulk write. Its id goes into with( [ 'ref' => id ] ) for every change it makes.
	 *
	 * @param array<string,mixed> $c {
	 *     @type string $kind         import|status|cli.
	 *     @type string $confirmed_by Name, required, at most 100 characters.
	 *     @type string $confirmed_on Y-m-d, required.
	 *     @type string $consent      The exact text the client saw, required.
	 *     @type string $file_name    Import only.
	 *     @type string $file_sha256  Import only.
	 *     @type int    $total        Rows to write.
	 * }
	 * @return int|WP_Error
	 */
	public static function start_confirmation( array $c ) {
		global $wpdb;

		$kind = isset( $c['kind'] ) ? (string) $c['kind'] : '';
		$by   = isset( $c['confirmed_by'] ) ? trim( (string) $c['confirmed_by'] ) : '';
		$on   = isset( $c['confirmed_on'] ) ? (string) $c['confirmed_on'] : '';
		$text = isset( $c['consent'] ) ? trim( (string) $c['consent'] ) : '';
		if ( ! in_array( $kind, self::CONFIRMATION_KINDS, true ) ) {
			return new WP_Error( 'bad_kind', 'Unknown confirmation kind.' );
		}
		if ( '' === $by || ( function_exists( 'mb_strlen' ) ? mb_strlen( $by ) : strlen( $by ) ) > 100 ) {
			return new WP_Error( 'no_name', __( 'Enter the name of who confirmed.', 'cx-sidrena' ) );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $on, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return new WP_Error( 'bad_date', __( 'The date must be a real date, e.g. 10.9.2026.', 'cx-sidrena' ) );
		}
		if ( '' === $text ) {
			return new WP_Error( 'no_consent', __( 'The confirmation text is missing.', 'cx-sidrena' ) );
		}

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::confirmations_table(),
			array(
				'kind'         => $kind,
				'started_at'   => time(),
				'user_id'      => get_current_user_id(),
				'confirmed_by' => $by,
				'confirmed_on' => $on,
				'consent'      => $text,
				'file_name'    => isset( $c['file_name'] ) ? (string) $c['file_name'] : '',
				'file_sha256'  => isset( $c['file_sha256'] ) ? (string) $c['file_sha256'] : '',
				'total'        => isset( $c['total'] ) ? absint( $c['total'] ) : 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		$wpdb->suppress_errors( $suppress );

		if ( ! $ok ) {
			self::fail( 'confirmation: ' . $wpdb->last_error );
			return new WP_Error( 'db', __( 'The confirmation could not be saved, so nothing was written.', 'cx-sidrena' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * The confirmed bulk write has ended.
	 *
	 * @param int               $id     From start_confirmation().
	 * @param array<string,int> $counts updated, unchanged, not_found, invalid, skipped.
	 * @return bool
	 */
	public static function finish_confirmation( $id, array $counts ) {
		global $wpdb;

		$data = array( 'finished_at' => time() );
		foreach ( array( 'updated', 'unchanged', 'not_found', 'invalid', 'skipped' ) as $k ) {
			if ( isset( $counts[ $k ] ) ) {
				$data[ $k ] = absint( $counts[ $k ] );
			}
		}
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->update( self::confirmations_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			self::fail( 'confirmation: ' . $wpdb->last_error );
		}

		return false !== $ok;
	}

	/**
	 * Confirmed bulk writes, newest first.
	 *
	 * @param int    $page     From 1.
	 * @param int    $per_page Rows per page.
	 * @param string $kind     import|status|cli, or '' for all.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function confirmations( $page = 1, $per_page = 20, $kind = '' ) {
		global $wpdb;

		$table    = self::confirmations_table();
		$per_page = max( 1, (int) $per_page );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$where    = in_array( $kind, self::CONFIRMATION_KINDS, true ) ? $wpdb->prepare( ' WHERE kind = %s', $kind ) : '';
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * History rows of a product (and of its variations), oldest first.
	 *
	 * @param int $post_id Product or variation.
	 * @param int $limit   At most this many of the newest rows (0: all).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( $post_id, $limit = 0 ) {
		global $wpdb;

		$table    = self::table();
		$post_id  = (int) $post_id;
		$sql      = $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d OR parent_id = %d ORDER BY id DESC", $post_id, $post_id ); // phpcs:ignore WordPress.DB
		$sql     .= $limit > 0 ? $wpdb->prepare( ' LIMIT %d', (int) $limit ) : '';
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $suppress );

		return is_array( $rows ) ? array_reverse( $rows ) : array();
	}
}
