<?php
/**
 * WP-CLI: status, confirmed fill and import of bazna cijene, the sale rule and the export, Dates, history, purge.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * `wp cx-sidrena` commands.
 */
class CX_Sidrena_CLI {

	/**
	 * Fill the bazna cijena from the regular price, for every product and variation that has none, with the client's
	 * confirmation (spec §1.1).
	 *
	 * Without --confirmed-by it only counts, and says what is missing; nothing is written. With it, the confirmation
	 * text is printed and the fill runs to the end: the bazna is the regular price, the date is the product's own date
	 * or the one from Dates in Settings (never today's on an old product), and the source on each product is
	 * "WP-CLI, confirmed by <name> on <date>". A bazna that is already there is never changed. Waits until Dates in
	 * Settings are saved (after a move from MJDD they count as saved).
	 *
	 * ## OPTIONS
	 *
	 * [--confirmed-by=<name>]
	 * : Who confirmed, on behalf of the shop owner, that the regular prices are the bazna cijene.
	 *
	 * [--confirmed-on=<Y-m-d>]
	 * : The day they confirmed. Default today.
	 *
	 * [--dry-run]
	 * : Only count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena fill
	 *     wp cx-sidrena fill --confirmed-by="Ana Horvat"
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function fill( $args, $assoc_args ) {
		unset( $args );
		self::need_woo();
		$by    = trim( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'confirmed-by', '' ) );
		$on    = trim( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'confirmed-on', '' ) );
		$dry   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$ids   = CX_Sidrena_Fill::candidates();
		$saved = CX_Sidrena_Dates::dates_saved();
		WP_CLI::log( sprintf( 'Without a bazna cijena (with a regular price): %d.', count( $ids ) ) );
		if ( ! $saved ) {
			WP_CLI::log( 'Dates in Settings are not saved yet. They set each product\'s date.' );
		}
		if ( $dry || '' === $by ) {
			if ( ! $dry ) {
				WP_CLI::log( 'Nothing written. To fill, add --confirmed-by="<name of who confirmed>".' );
			}
			WP_CLI::success( sprintf( 'Would fill: %d.', $saved ? count( $ids ) : 0 ) );
			return;
		}
		if ( ! $saved ) {
			WP_CLI::error( 'Save Dates in Settings first. They set each product\'s date.' );
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to fill. Every product with a regular price has a bazna cijena.' );
			return;
		}
		WP_CLI::log( 'Confirmation: ' . CX_Sidrena_Fill::consent_text() );
		$job = CX_Sidrena_Fill::start(
			'all',
			array(
				'confirmed_by' => $by,
				'confirmed_on' => $on,
				'consent'      => CX_Sidrena_Fill::consent_text(),
				'via'          => 'cli',
			)
		);
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$r = self::run_job();
		WP_CLI::success( sprintf( 'Filled: %d. Skipped: %d.', (int) $r['updated'], (int) $r['skipped'] ) );
	}

	/**
	 * Import bazna cijene from a CSV, with the client's confirmation (the same as the Import tab).
	 *
	 * Without --confirmed-by it shows the preview (what would change, what was not found, invalid rows) and writes
	 * nothing. With it, the rows are written: only the bazna, its date, its source and the starting lowest price in 30
	 * days, never the regular or sale price or the stock. Columns: SKU (or ID), Product name, Bazna cijena, Date, Lowest
	 * price in 30 days.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The CSV.
	 *
	 * [--confirmed-by=<name>]
	 * : Who confirmed the prices, on behalf of the shop owner.
	 *
	 * [--confirmed-on=<Y-m-d>]
	 * : The day they confirmed. Default today.
	 *
	 * [--format=<format>]
	 * : Preview as table or csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena import bazne.csv
	 *     wp cx-sidrena import bazne.csv --confirmed-by="Ana Horvat" --confirmed-on=2026-11-14
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       File.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import( $args, $assoc_args ) {
		self::need_woo();
		$file = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}
		$p = CX_Sidrena_Import::read( (string) file_get_contents( $file ), basename( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_wp_error( $p ) ) {
			WP_CLI::error( $p->get_error_message() );
		}
		$c = $p['counts'];
		WP_CLI::log( sprintf( 'File %s, sha256 %s', $p['name'], $p['sha256'] ) );
		WP_CLI::log( sprintf( 'Will be updated: %d (waiting for Dates: %d). Unchanged: %d. Not found: %d. Invalid: %d.', $c['update'], CX_Sidrena_Dates::dates_saved() ? 0 : $c['no_date'], $c['unchanged'], $c['not_found'], $c['invalid'] ) );
		$items = array();
		foreach ( $p['lists']['update'] as $r ) {
			$items[] = array(
				'line'      => $r['line'],
				'sku'       => $r['sku'],
				'now'       => $r['now'] ? $r['now']['bazna'] . ' ' . $r['now']['date'] : '-',
				'new'       => $r['new'] ? $r['new']['bazna'] . ' ' . $r['new']['date'] : '-',
				'date_from' => $r['new'] ? $r['new']['date_from'] : '',
				'lowest'    => $r['lowest'] ? $r['lowest']['new'] : '',
			);
		}
		foreach ( $p['lists']['not_found'] as $r ) {
			$items[] = array( 'line' => $r['line'], 'sku' => $r['sku'], 'now' => '', 'new' => 'NOT FOUND', 'date_from' => '', 'lowest' => '' );
		}
		foreach ( $p['lists']['invalid'] as $r ) {
			$items[] = array( 'line' => $r['line'], 'sku' => $r['sku'], 'now' => '', 'new' => 'INVALID: ' . $r['message'], 'date_from' => '', 'lowest' => '' );
		}
		if ( $items ) {
			usort(
				$items,
				static function ( $a, $b ) {
					return $a['line'] - $b['line'];
				}
			);
			$format = isset( $assoc_args['format'] ) && 'csv' === $assoc_args['format'] ? 'csv' : 'table';
			WP_CLI\Utils\format_items( $format, $items, array( 'line', 'sku', 'now', 'new', 'date_from', 'lowest' ) );
		}
		$by = trim( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'confirmed-by', '' ) );
		if ( '' === $by ) {
			WP_CLI::success( 'Preview only, nothing written. To import, add --confirmed-by="<name of who confirmed>".' );
			return;
		}
		$token = CX_Sidrena_Import::store( $p );
		if ( is_wp_error( $token ) ) {
			WP_CLI::error( $token->get_error_message() );
		}
		WP_CLI::log( 'Confirmation: ' . CX_Sidrena_Import::consent_text() );
		$r = CX_Sidrena_Import::confirm(
			$token,
			array(
				'sha256'       => $p['sha256'],
				'consent'      => '1',
				'confirmed_by' => $by,
				'confirmed_on' => (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'confirmed-on', '' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$done = self::run_job();
		WP_CLI::success( sprintf( 'Imported: %d. Skipped: %d. Import %d, file fingerprint %s.', (int) $done['updated'], (int) $done['skipped'], (int) $r['import_id'], substr( $p['sha256'], 0, 8 ) ) );
	}

	/**
	 * The running fill or import: show it, or stop it (what was written stays).
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status (default) or cancel.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena job
	 *     wp cx-sidrena job cancel
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Action.
	 * @param array<string,string> $assoc_args Unused.
	 */
	public function job( $args, $assoc_args ) {
		unset( $assoc_args );
		$what = isset( $args[0] ) ? (string) $args[0] : 'status';
		$job  = CX_Sidrena_Bulk::get();
		if ( 'cancel' === $what ) {
			$r = CX_Sidrena_Bulk::cancel( 'WP-CLI' );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Stopped. Written: %d. Not written: %d.', (int) $r['updated'], isset( $r['cancelled']['left'] ) ? (int) $r['cancelled']['left'] : 0 ) );
			return;
		}
		if ( ! $job ) {
			WP_CLI::success( 'No fill or import yet.' );
			return;
		}
		WP_CLI::log(
			sprintf(
				'%s %s, confirmed by %s on %s: %d / %d, written %d, skipped %d%s.',
				$job['kind'],
				empty( $job['finished'] ) ? 'running' : ( empty( $job['cancelled'] ) ? 'finished' : 'stopped' ),
				$job['by'],
				$job['on'],
				(int) $job['pos'],
				(int) $job['total'],
				(int) $job['updated'],
				(int) $job['skipped'],
				empty( $job['finished'] ) ? sprintf( ', last step %ds ago', time() - (int) ( isset( $job['touched'] ) ? $job['touched'] : $job['started'] ) ) : ''
			)
		);
	}

	/**
	 * The sale rule on the reference day: a file for `import` with a suggested bazna cijena for every product that is
	 * on sale now and has none (02.05.2025. = the lowest price in the 30 days before the sale, 10.09.2026. and a
	 * product added later = the regular price). A product that has a bazna is never in it. Writes nothing to products.
	 * Needs Dates in Settings saved.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<file>]
	 * : Write the CSV to this file. Without it, the CSV goes to the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena sale-rule --out=akcije.csv
	 *
	 * @subcommand sale-rule
	 * @when after_wp_load
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args --out.
	 */
	public function sale_rule( $args, $assoc_args ) {
		unset( $args );
		self::need_woo();
		$rows = CX_Sidrena_Sale_Rule::rows();
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		$none = count( array_filter( $rows, static function ( $r ) { return '' === $r['rule']; } ) );
		self::write_out( CX_Sidrena_Sale_Rule::csv( $rows ), $assoc_args );
		WP_CLI::success( sprintf( 'On sale without a bazna cijena: %d (no rule for the date: %d). Nothing written to products.', count( $rows ), $none ) );
	}

	/**
	 * Export the bazna cijene (SKU, ID, name, bazna, date, lowest price in 30 days), in the shape `import` reads back.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<file>]
	 * : Write the CSV to this file. Without it, the CSV goes to the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena export-bazna --out=bazna.csv
	 *
	 * @subcommand export-bazna
	 * @when after_wp_load
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args --out.
	 */
	public function export_bazna( $args, $assoc_args ) {
		unset( $args );
		self::need_woo();
		$rows = CX_Sidrena_Import::export_rows();
		self::write_out( CX_Sidrena_Import::export_csv( $rows ), $assoc_args );
		WP_CLI::success( sprintf( 'Rows: %d.', count( $rows ) ) );
	}

	/**
	 * The archive of published price lists: the public files (the last 30 days) and the kept ones.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list (default) or get.
	 * ---
	 * default: list
	 * options:
	 *   - list
	 *   - get
	 * ---
	 *
	 * [<name>]
	 * : For get: the file's name, as list shows it.
	 *
	 * [--kept]
	 * : list: the kept files (older than the public 30 days) instead of the public ones.
	 *
	 * [--out=<file>]
	 * : get: write the file there. Without it, the file goes to the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena archive
	 *     wp cx-sidrena archive list --kept
	 *     wp cx-sidrena archive get "webshop_Ilica 1 Zagreb_001_12_01.10.2026_07:45.xml" --out=cjenik.xml
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Action, name.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function archive( $args, $assoc_args ) {
		$arch   = CX_Sidrena_Archive::instance();
		$action = isset( $args[0] ) ? $args[0] : 'list';
		if ( 'get' === $action ) {
			$name = isset( $args[1] ) ? (string) $args[1] : '';
			$body = $arch->read_kept( $name );
			if ( is_wp_error( $body ) ) {
				$clean = $arch->sanitize_archive_name( $name );
				$path  = '' !== $clean ? trailingslashit( $arch->get_archive_dir() ) . $clean : '';
				$body  = '' !== $path && is_file( $path ) ? file_get_contents( $path ) : $body; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			if ( ! is_string( $body ) ) {
				WP_CLI::error( 'No archive file of that name.' );
			}
			$out = isset( $assoc_args['out'] ) ? (string) $assoc_args['out'] : '';
			if ( '' === $out ) {
				WP_CLI::log( $body );
				return;
			}
			if ( false === file_put_contents( $out, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				WP_CLI::error( 'Could not write ' . $out );
			}
			WP_CLI::success( sprintf( '%s (%d bytes, sha256 %s)', $out, strlen( $body ), hash( 'sha256', $body ) ) );
			return;
		}

		$kept  = ! empty( $assoc_args['kept'] );
		$files = $kept ? $arch->kept_files() : $arch->public_files();
		$rows  = array();
		foreach ( $files as $file ) {
			$rows[] = array(
				'name'  => $file['name'],
				'time'  => $file['stamp'] ? CX_Sidrena_Archive::legal_date( 'd.m.Y. H:i', $file['stamp'] ) : '',
				'bytes' => (int) $file['bytes'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'time', 'bytes' ) );
		WP_CLI::log( sprintf( '%s: %d. Kept for %s.', $kept ? 'Kept' : 'Public (last 30 days)', count( $rows ), 0 === CX_Sidrena_Archive::keep_days() ? 'ever' : CX_Sidrena_Archive::keep_days() . ' days' ) );
	}

	/**
	 * A CSV to --out, or to the output.
	 *
	 * @param string               $csv        The file.
	 * @param array<string,string> $assoc_args --out.
	 */
	private static function write_out( $csv, $assoc_args ) {
		$out = isset( $assoc_args['out'] ) ? (string) $assoc_args['out'] : '';
		if ( '' === $out ) {
			WP_CLI::log( $csv );
			return;
		}
		if ( false === file_put_contents( $out, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error( sprintf( 'Cannot write %s.', $out ) );
		}
		WP_CLI::log( sprintf( 'Written to %s.', $out ) );
	}

	/**
	 * Run the started job to its end here, with a line every step.
	 *
	 * @return array<string,mixed> The job.
	 */
	private static function run_job() {
		$job = CX_Sidrena_Bulk::running();
		while ( $job && empty( $job['finished'] ) ) {
			$before = (int) $job['pos'];
			$job    = CX_Sidrena_Bulk::step( CX_Sidrena_Bulk::CRON_BUDGET );
			if ( ! $job ) {
				break;
			}
			WP_CLI::log( sprintf( '%d / %d', (int) $job['pos'], (int) $job['total'] ) );
			if ( empty( $job['finished'] ) && (int) $job['pos'] === $before ) {
				sleep( 2 ); // another request holds the step (WP-cron)
			}
		}

		return $job ? $job : array( 'updated' => 0, 'skipped' => 0 );
	}

	/**
	 * Stop unless WooCommerce is active.
	 */
	private static function need_woo() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}
	}

	/**
	 * Show how many products/variations already have a sidrena cijena.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena status
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args Unused.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$stats = CX_Sidrena_Activator::catalog_stats();
		WP_CLI::log(
			sprintf(
				'eligible=%d (products/variations with a regular price)',
				$stats['eligible']
			)
		);
		WP_CLI::log( sprintf( 'filled=%d', $stats['filled'] ) );
		WP_CLI::log( sprintf( 'missing=%d', $stats['missing'] ) );
		WP_CLI::success(
			sprintf(
				'%d of %d eligible items have a sidrena cijena.',
				$stats['filled'],
				$stats['eligible']
			)
		);
	}

	/**
	 * Dates of bazna cijena per product category, and their state (suggested, taken from MJDD's data, saved).
	 *
	 * A category without its own date follows its parent; products in no category are Other goods. Read only: the
	 * Dates are saved in Settings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or csv. Default table.
	 *
	 * [<id>]
	 * : A product or variation: show its date and where it comes from instead.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena dates
	 *     wp cx-sidrena dates 123
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Optional product ID.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function dates( $args, $assoc_args ) {
		CX_Sidrena_Dates::maybe_setup();
		if ( ! empty( $args[0] ) ) {
			$d = CX_Sidrena_Dates::date_for( (int) $args[0] );
			WP_CLI::log( sprintf( 'date=%s from=%s rule=%s (%s) two_categories=%s', $d['date'], $d['from'], $d['rule_date'], $d['rule_from'], $d['two_categories'] ? 'yes' : 'no' ) );
			return;
		}
		$s = CX_Sidrena_Dates::state();
		WP_CLI::log( sprintf( 'state=%s%s', $s['state'], $s['on'] ? sprintf( ' (%s, %s)', $s['by'], wp_date( 'Y-m-d H:i', $s['on'] ) ) : '' ) );
		$items = array();
		foreach ( CX_Sidrena_Dates::rows() as $r ) {
			$items[] = array(
				'category' => str_repeat( '  ', $r['depth'] ) . ( 0 === $r['term_id'] ? 'Other goods' : $r['name'] ),
				'id'       => $r['term_id'],
				'products' => $r['count'],
				'date'     => $r['date'],
				'set'      => $r['set'] ? 'yes' : 'follows ' . ( 'other' === $r['inherited_from'] ? 'Other goods' : $r['inherited_from'] ),
			);
		}
		$format = isset( $assoc_args['format'] ) && 'csv' === $assoc_args['format'] ? 'csv' : 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'category', 'id', 'products', 'date', 'set' ) );
	}

	/**
	 * Price history of a product or variation: every change of its prices, with time, source and user.
	 *
	 * A product's own rows and those of its variations. Old and new value empty = there was none / it was deleted.
	 *
	 * ## OPTIONS
	 *
	 * <id-or-sku>
	 * : Product or variation ID, or SKU.
	 *
	 * [--format=<format>]
	 * : table or csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena history 123
	 *     wp cx-sidrena history MAJ-B --format=csv
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       ID or SKU.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function history( $args, $assoc_args ) {
		$key = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		$id  = ctype_digit( $key ) ? (int) $key : 0;
		if ( $id < 1 || ! in_array( get_post_type( $id ), array( 'product', 'product_variation' ), true ) ) {
			$id = '' !== $key && function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $key ) : 0;
		}
		if ( $id < 1 ) {
			WP_CLI::error( sprintf( 'No product or variation with ID or SKU "%s".', $key ) );
		}

		$izvor = CX_Sidrena_History::izvor( $id );
		WP_CLI::log( sprintf( 'Bazna cijena source: %s', 'none' === $izvor['type'] ? '-' : CX_Sidrena_History::izvor_label( $izvor ) ) );

		$items = array();
		foreach ( CX_Sidrena_History::rows( $id ) as $row ) {
			$user    = (int) $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : false;
			$items[] = array(
				'changed'    => wp_date( 'Y-m-d H:i:s', (int) $row['changed_at'] ),
				'product_id' => (int) $row['product_id'],
				'key'        => $row['meta_key'],
				'old'        => null === $row['old_value'] ? '' : $row['old_value'],
				'new'        => null === $row['new_value'] ? '' : $row['new_value'],
				'source'     => $row['source'],
				'user'       => $user ? $user->user_login : '',
				'ref'        => null === $row['ref'] ? '' : (string) $row['ref'],
				'note'       => $row['note'],
			);
		}
		$format = isset( $assoc_args['format'] ) && 'csv' === $assoc_args['format'] ? 'csv' : 'table';
		if ( ! $items ) {
			WP_CLI::log( 'No changes recorded.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}

	/**
	 * Delete all CX Sidrena data and deactivate the plugin.
	 *
	 * Deletes our settings, the manual pricelist, outlets, the live public price list, and our scheduled jobs.
	 * Product meta is never deleted: bazna, date, price history and the lowest price stay on every product (they are
	 * the evidence for an inspection, and MJDD reads the same fields). Neither are the price history tables, the
	 * source of every bazna and the archive of published price lists (the shop must keep it). This replaces the old
	 * admin button.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cx-sidrena purge
	 *
	 * @when after_wp_load
	 * @param array<int,string>    $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function purge( $args, $assoc_args ) {
		unset( $args );

		WP_CLI::confirm( 'Delete all CX Sidrena settings and the live public pricelist, and deactivate the plugin? Bazna prices on products and the archive of published price lists stay.', $assoc_args );

		CX_Sidrena_Plugin::purge_all_data();

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( CX_SIDRENA_FILE ) );

		WP_CLI::success( 'CX Sidrena data deleted and the plugin deactivated. Product meta was not touched.' );
	}
}
