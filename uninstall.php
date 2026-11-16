<?php
/**
 * Uninstall Sidrena cijena.
 *
 * Product meta is kept on purpose: it is the legal snapshot of the
 * reference-date price and must survive a mistaken uninstall. So are the
 * price history tables ({prefix}cx_sidrena_history, {prefix}cx_sidrena_confirmations)
 * and the source of every bazna (_cx_sidrena_izvor): they are the shop's evidence. And so is the archive of
 * published price lists (uploads/cx-sidrena/arhiva and cuvano-*).
 *
 * @package CX_Sidrena
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cx_sidrena_settings' );
delete_option( 'cx_sidrena_snapshot_pending' );
delete_option( 'cx_sidrena_snapshot_page' );
delete_option( 'cx_sidrena_snapshot_offset' );
delete_option( 'cx_sidrena_snapshot_child' );
delete_option( 'cx_sidrena_fill_prompt' );
delete_option( 'cx_sidrena_prefix_choice' );
delete_option( 'cx_sidrena_followup_13_11' );
delete_option( 'cx_sidrena_public_xml' );
delete_option( 'cx_sidrena_rewrite_ver' );
delete_option( 'cx_sidrena_poslovnice' );
delete_option( 'cx_sidrena_catalog' );
delete_option( 'cx_sidrena_upit' );

// The live price lists, their HTML tables, temporary files of a write that was cut, and the old import folder. The
// archive of published price lists (arhiva/, cuvano-*) stays: the shop must keep it after the plugin is gone, and a
// reinstall goes on with it and its storage numbers (CX-SID-27). Same as CX_Sidrena_Plugin::delete_live_files().
$sidrena_cijena_uploads = wp_upload_dir( null, false );
if ( empty( $sidrena_cijena_uploads['error'] ) && ! empty( $sidrena_cijena_uploads['basedir'] ) ) {
	$sidrena_cijena_base = trailingslashit( $sidrena_cijena_uploads['basedir'] ) . 'cx-sidrena';
	$sidrena_cijena_live = array_merge(
		glob( $sidrena_cijena_base . '/sidrena-cijena*' ) ?: array(),
		glob( $sidrena_cijena_base . '/.sidrena-cijena*.tmp-*' ) ?: array(),
		glob( $sidrena_cijena_base . '/*/.*.tmp-*' ) ?: array(),
		glob( $sidrena_cijena_base . '/imports/*' ) ?: array()
	);
	foreach ( $sidrena_cijena_live as $sidrena_cijena_file ) {
		if ( is_file( $sidrena_cijena_file ) ) {
			wp_delete_file( $sidrena_cijena_file );
		}
	}
	if ( is_dir( $sidrena_cijena_base . '/imports' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $sidrena_cijena_base . '/imports' );
	}
}

wp_clear_scheduled_hook( 'cx_sidrena_snapshot_batch' );
wp_clear_scheduled_hook( 'cx_sidrena_daily_xml' );
wp_clear_scheduled_hook( 'cx_sidrena_generate_xml' );
wp_clear_scheduled_hook( 'cx_sidrena_dates_setup' );
wp_clear_scheduled_hook( 'cx_sidrena_bulk_run' );
wp_unschedule_hook( 'cx_sidrena_n30_seed' );

// Everything else under our prefix: take-over marker, notices, lowest-30 options, transients. Product meta is never
// touched (see above), and neither is anything of MJDD's: going back to MJDD finds its own data as it left it.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'cx_sidrena_' ) . '%',
		$wpdb->esc_like( '_transient_cx_sidrena_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cx_sidrena_' ) . '%'
	)
);
wp_cache_delete( 'alloptions', 'options' );
