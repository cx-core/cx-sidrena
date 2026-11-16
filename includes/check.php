<?php
/**
 * "wp cx-sidrena check" (the page under Tools is the Status tab now, CX-SID-23).
 *
 * Ported from the bazna add-on 1.1.1 (bazna-dodatak.php, point 3): bazna_dodatak_* became cx_sidrena_*. Lists what is
 * not in order: published simple products and variations with a price but no bazna, and items that existed on the
 * reference day while their own bazna date says it was taken later (a product saved or ordered for the first time
 * after that day gets today's price and date).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * What is not in order. Published simple products and variations only (a variable or grouped parent has no price).
 *
 * @return array{error?:string, ref?:string, missing?:array, wrong_date?:array, total?:int, feed?:string, cron?:string, version?:string, updates?:string, updates_mode?:string}
 */
function cx_sidrena_check() {
	global $wpdb;
	if ( ! class_exists( 'CX_Sidrena_Product_Meta' ) ) {
		return array( 'error' => __( 'WooCommerce is not active.', 'cx-sidrena' ) );
	}
	$dates   = CX_Sidrena_Dates::get();
	$ref_txt = $dates ? $dates['other'] : (string) CX_Sidrena_Settings::instance()->get( 'default_date' );

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

	$missing = array();
	$wrong   = array();
	$total   = 0;
	foreach ( $rows as $r ) {
		// No price = cannot be bought, so it needs no bazna either (e.g. an empty "any size" variation row).
		if ( '' === trim( (string) $r->redovna ) ) {
			continue;
		}
		$total++;
		$edit = get_edit_post_link( $r->post_parent ? $r->post_parent : $r->ID, 'raw' );
		$name = $r->post_title;
		if ( 'product_variation' === $r->post_type ) {
			$v    = wc_get_product( $r->ID );
			$name = $v ? wp_strip_all_tags( $v->get_name() ) : $name;
		}
		$item = array( 'id' => (int) $r->ID, 'name' => $name, 'redovna' => (string) $r->redovna, 'bazna' => (string) $r->bazna, 'datum' => (string) $r->datum, 'created' => $r->post_date, 'edit' => $edit );
		if ( '' === trim( (string) $r->bazna ) ) {
			$missing[] = $item;
			continue;
		}
		// Its own date is LATER than the date the rule gives it (its category's date, or the day it was added when that
		// is later; CX_Sidrena_Dates): MJDD's trap, today's date on an old product. An earlier own date (e.g. food on
		// 02.05.2025.) is correct and stays off the list; a date we cannot read stays on it.
		$own = trim( (string) $r->datum );
		if ( '' !== $own ) {
			$own_date  = cx_sidrena_check_parse_date( $own );
			$rule_date = cx_sidrena_check_parse_date( CX_Sidrena_Dates::date_for( (int) $r->ID )['rule_date'] );
			if ( null === $own_date || ( null !== $rule_date && $own_date > $rule_date ) ) {
				$wrong[] = $item;
			}
		}
	}

	$dir   = trailingslashit( CX_Sidrena_Feed_Files::instance()->get_public_dir() );
	$files = array_merge( glob( $dir . '*.xml' ) ?: array(), glob( $dir . '*.csv' ) ?: array() ); // no GLOB_BRACE: missing on musl hosts
	$files = array_filter( $files, function ( $f ) { return false === strpos( basename( $f ), 'arhiv' ); } );
	$feed  = $files ? wp_date( 'd.m.Y. H:i', max( array_map( 'filemtime', $files ) ) ) : __( 'no file', 'cx-sidrena' );

	$cron = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
		? __( 'WP-Cron is disabled in wp-config.php (a server cron job is required)', 'cx-sidrena' )
		: __( 'WP-Cron runs on site visits (recommended: a server cron job every 15 minutes)', 'cx-sidrena' );

	$upd     = CX_Sidrena_Updates::status();
	$updates = $upd['text'];
	if ( $upd['available'] ) {
		/* translators: %s: version number */
		$updates .= ' · ' . sprintf( __( 'version %s available', 'cx-sidrena' ), $upd['available'] );
	}
	if ( $upd['last_check'] ) {
		/* translators: %s: date and time */
		$updates .= ' · ' . sprintf( __( 'last checked %s', 'cx-sidrena' ), wp_date( 'd.m.Y. H:i', $upd['last_check'] ) );
	}

	return array( 'ref' => $ref_txt, 'missing' => $missing, 'wrong_date' => $wrong, 'total' => $total, 'feed' => $feed, 'cron' => $cron, 'version' => CX_SIDRENA_VERSION, 'updates' => $updates, 'updates_mode' => $upd['mode'] );
}

/**
 * A bazna date as the owner types it: d.m.Y with or without the final dot, leading zeros and spaces optional.
 *
 * @param string $text Date text.
 * @return DateTime|null Midnight of that day in the site timezone, or null when it is not a real date.
 */
function cx_sidrena_check_parse_date( $text ) {
	$text = rtrim( preg_replace( '/\s+/u', '', (string) $text ), '.' );
	if ( ! preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m ) || ! checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
		return null;
	}
	$date = DateTime::createFromFormat( '!Y-n-j', $m[3] . '-' . (int) $m[2] . '-' . (int) $m[1], wp_timezone() );
	return $date ? $date : null;
}

// The page under Tools is gone: its content is the Status tab (CX-SID-23, spec §1.0); its old address redirects there
// (CX_Sidrena_Admin::redirect_old_check_page). cx_sidrena_check() stays for WP-CLI.

/**
 * The former check page under Tools (kept for any caller; not registered).
 */
function cx_sidrena_check_page() {
	$c = cx_sidrena_check();
	echo '<div class="wrap"><h1>' . esc_html__( 'Bazna cijena: check', 'cx-sidrena' ) . '</h1>';
	if ( isset( $c['error'] ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $c['error'] ) . '</p></div></div>';
		return;
	}
	$ok = ! $c['missing'] && ! $c['wrong_date'];
	printf(
		'<div class="notice %s"><p><strong>%s</strong></p></div>',
		$ok ? 'notice-success' : 'notice-warning',
		esc_html( $ok ? __( 'Everything is in order: every product has a bazna cijena.', 'cx-sidrena' ) : __( 'Some products need attention (list below).', 'cx-sidrena' ) )
	);
	echo '<table class="widefat striped" style="max-width:720px"><tbody>';
	foreach ( array(
		array( __( 'Products and variations with a price', 'cx-sidrena' ), $c['total'] ),
		array( __( 'Without bazna cijena', 'cx-sidrena' ), count( $c['missing'] ) ),
		/* translators: %s: reference date */
		array( sprintf( __( 'Existed on %s, but the bazna cijena has a later date', 'cx-sidrena' ), $c['ref'] ), count( $c['wrong_date'] ) ),
		array( __( 'Public price list last generated', 'cx-sidrena' ), $c['feed'] ),
		array( __( 'CX Sidrena version', 'cx-sidrena' ), $c['version'] ),
		array( __( 'Updates', 'cx-sidrena' ), $c['updates'] ),
		array( __( 'Scheduled tasks', 'cx-sidrena' ), $c['cron'] ),
	) as $row ) {
		echo '<tr><th style="width:55%">' . esc_html( $row[0] ) . '</th><td>' . esc_html( (string) $row[1] ) . '</td></tr>';
	}
	echo '</tbody></table>';
	foreach ( array(
		'missing'    => __( 'Without bazna cijena', 'cx-sidrena' ),
		'wrong_date' => __( 'Existed on the reference day, bazna cijena with a later date', 'cx-sidrena' ),
	) as $key => $title ) {
		if ( ! $c[ $key ] ) {
			continue;
		}
		echo '<h2>' . esc_html( $title ) . ' (' . count( $c[ $key ] ) . ')</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>'
			. esc_html__( 'Product', 'cx-sidrena' ) . '</th><th>' . esc_html__( 'Regular', 'cx-sidrena' ) . '</th><th>'
			. esc_html__( 'Bazna', 'cx-sidrena' ) . '</th><th>' . esc_html__( 'Bazna cijena date', 'cx-sidrena' ) . '</th><th>'
			. esc_html__( 'Added', 'cx-sidrena' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $c[ $key ], 0, 200 ) as $i ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( $i['edit'] ),
				esc_html( $i['name'] ),
				esc_html( $i['redovna'] ),
				esc_html( $i['bazna'] ),
				esc_html( $i['datum'] ),
				esc_html( substr( $i['created'], 0, 10 ) )
			);
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// wp cx-sidrena check  →  the same check over SSH; exit code 1 when something is not in order.
	WP_CLI::add_command(
		'cx-sidrena check',
		function () {
			$c = cx_sidrena_check();
			if ( isset( $c['error'] ) ) {
				WP_CLI::error( $c['error'] );
			}
			WP_CLI::log( sprintf( 'ukupno %d | bez bazne %d | kasniji datum %d | cjenik %s | CX Sidrena %s | %s', $c['total'], count( $c['missing'] ), count( $c['wrong_date'] ), $c['feed'], $c['version'], $c['cron'] ) );
			WP_CLI::log( 'azuriranje: ' . $c['updates_mode'] . ' | ' . $c['updates'] );
			foreach ( array_merge( $c['missing'], $c['wrong_date'] ) as $i ) {
				WP_CLI::log( sprintf( '  #%d %s | redovna %s | bazna %s | datum %s', $i['id'], $i['name'], $i['redovna'], $i['bazna'], $i['datum'] ) );
			}
			if ( $c['missing'] || $c['wrong_date'] ) {
				WP_CLI::halt( 1 );
			}
		}
	);
}
