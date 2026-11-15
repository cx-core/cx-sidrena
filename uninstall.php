<?php
/**
 * Uninstall Sidrena cijena.
 *
 * Product meta is kept on purpose: it is the legal snapshot of the
 * reference-date price and must survive a mistaken uninstall.
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

$sidrena_cijena_uploads = wp_upload_dir( null, false );
if ( empty( $sidrena_cijena_uploads['error'] ) && ! empty( $sidrena_cijena_uploads['basedir'] ) ) {
	$sidrena_cijena_base = trailingslashit( $sidrena_cijena_uploads['basedir'] ) . 'cx-sidrena';
	$sidrena_cijena_live = glob( $sidrena_cijena_base . '/sidrena-cijena*' );
	if ( is_array( $sidrena_cijena_live ) ) {
		foreach ( $sidrena_cijena_live as $sidrena_cijena_file ) {
			if ( is_file( $sidrena_cijena_file ) ) {
				wp_delete_file( $sidrena_cijena_file );
			}
		}
	}

	$sidrena_cijena_dirs = array( $sidrena_cijena_base . '/arhiva', $sidrena_cijena_base . '/imports' );
	foreach ( $sidrena_cijena_dirs as $sidrena_cijena_dir ) {
		if ( ! is_dir( $sidrena_cijena_dir ) ) {
			continue;
		}

		$sidrena_cijena_staged = glob( $sidrena_cijena_dir . '/*' );
		if ( is_array( $sidrena_cijena_staged ) ) {
			foreach ( $sidrena_cijena_staged as $sidrena_cijena_staged_file ) {
				if ( is_file( $sidrena_cijena_staged_file ) ) {
					wp_delete_file( $sidrena_cijena_staged_file );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $sidrena_cijena_dir );
	}

	if ( is_dir( $sidrena_cijena_base ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $sidrena_cijena_base );
	}
}

wp_clear_scheduled_hook( 'cx_sidrena_snapshot_batch' );
wp_clear_scheduled_hook( 'cx_sidrena_daily_xml' );
wp_clear_scheduled_hook( 'cx_sidrena_generate_xml' );
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
