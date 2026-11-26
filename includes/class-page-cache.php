<?php
/**
 * Page cache plugins (CX-SID-141): a guest must see the price list that is published, not a page a cache kept.
 *
 * Our own pages (the price list, each place's, the archive) are never kept by a page cache, published or not: they
 * change at every build and nothing tells a cache when (Rook 4469, "B"). The pages a theme or a page builder shows our
 * shortcodes on are the shop's own: they may be cached, and after every build that published they are cleared, one by
 * one, through the public functions of the plugins the shops have (W3 Total Cache, WP Super Cache, WP-Optimize,
 * LiteSpeed Cache). A function is called only if it exists, never one that clears a whole site, and a plugin's error
 * never fails our build. A cache of the hosting without a plugin (LiteSpeed server alone, Varnish, Cloudflare) gets
 * only our Cache-Control header.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells page caches what not to keep, and clears the shortcode pages after a build.
 */
class CX_Sidrena_Page_Cache {

	/**
	 * The ids of published pages our shortcodes were shown on (in the order first shown), for pages whose content is
	 * not in post_content (page builders). Autoload no.
	 */
	const OPTION = 'cx_sidrena_cache_pages';

	/**
	 * How many such pages are remembered. A full list takes no more (never rotated): a shortcode in a footer or a
	 * widget on every page would otherwise write the option at every new page a guest opens (Rook, Argus C141-thrash).
	 */
	const MAX = 20;

	/**
	 * LiteSpeed Cache's tag of every page that shows our shortcodes.
	 */
	const TAG = 'cx_sidrena';

	/**
	 * This page is not kept by a page cache: DONOTCACHEPAGE (W3 Total Cache, WP Super Cache, WP-Optimize and LiteSpeed
	 * Cache read it), LiteSpeed's own header and its action. The Cache-Control header stays the caller's.
	 */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// A shortcode runs inside the page's output, often after the headers went out: the constant and the action
		// are enough there, and header() would only warn.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		do_action( 'litespeed_control_set_nocache', 'cx-sidrena' );
	}

	/**
	 * Our shortcode is being shown on this page. A page that says the price list is not there yet is not kept; one
	 * that shows it gets LiteSpeed's tag. Either way the page is remembered, to be cleared after the next build.
	 *
	 * @param bool $pending The shortcode says the price list is not published or is being prepared.
	 */
	public static function shortcode_shown( $pending ) {
		if ( $pending ) {
			self::no_cache();
		} else {
			do_action( 'litespeed_tag_add', self::TAG );
		}
		self::remember();
	}

	/**
	 * After a build that published: clear every page with our shortcodes in each page cache plugin there is, and
	 * LiteSpeed's tag. Never our own pages (never cached) and never a whole site.
	 */
	public static function purge() {
		foreach ( self::pages() as $id ) {
			self::call(
				function () use ( $id ) {
					if ( function_exists( 'w3tc_flush_post' ) ) {
						w3tc_flush_post( $id );
					}
				}
			);
			// wpsc_delete_post_cache() calls wpsc_delete_url_cache(), which older WP Super Cache does not have.
			self::call(
				function () use ( $id ) {
					if ( function_exists( 'wpsc_delete_post_cache' ) && function_exists( 'wpsc_delete_url_cache' ) ) {
						wpsc_delete_post_cache( $id );
					}
				}
			);
			self::call(
				function () use ( $id ) {
					if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_single_post_cache' ) ) {
						WPO_Page_Cache::delete_single_post_cache( $id );
					}
				}
			);
		}
		// LiteSpeed Cache: every page that carries the tag; queued by LiteSpeed itself when this runs in cron.
		self::call(
			function () {
				do_action( 'litespeed_purge', self::TAG );
			}
		);
	}

	/**
	 * Published pages with our shortcodes: those whose content has one, and those they were shown on (still published).
	 *
	 * @return array<int, int>
	 */
	public static function pages() {
		global $wpdb;

		$ids  = self::forget_gone();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'nav_menu_item') AND post_content LIKE %s LIMIT 500",
				'%' . $wpdb->esc_like( '[sidrena' ) . '%'
			)
		);
		foreach ( (array) $rows as $row ) {
			// [sidrena-cjenik], [sidrena_cjenik], [sidrena-arhiva], [sidrena_arhiva], with or without attributes; not
			// the price shortcode [sidrena-cijena].
			if ( preg_match( '/\[sidrena[-_](?:cjenik|arhiva)[\s\]\/]/', (string) $row->post_content ) ) {
				$ids[] = (int) $row->ID;
			}
		}

		$out = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			// A remembered page may since have become a draft, private or trashed, or be deleted: a guest no longer sees
			// it, and the plugins' own functions read get_post( $id ) without checking it (WPSC 3.0.3, WPO 4.6.1).
			if ( 'publish' === get_post_status( $id ) ) {
				$out[] = $id;
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * The remembered page ids.
	 *
	 * @return array<int, int>
	 */
	public static function remembered() {
		$ids = get_option( self::OPTION, array() );

		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * The remembered pages that are still published; the others leave the option (Rook, Argus C141-gone).
	 *
	 * @return array<int, int>
	 */
	private static function forget_gone() {
		$ids  = self::remembered();
		$keep = array();
		foreach ( $ids as $id ) {
			if ( 'publish' === get_post_status( $id ) ) {
				$keep[] = $id;
			}
		}
		if ( count( $keep ) !== count( $ids ) ) {
			update_option( self::OPTION, $keep, false );
		}

		return $keep;
	}

	/**
	 * Remember the page being shown (a published page or post shown on its own), once, while fewer than MAX are.
	 */
	private static function remember() {
		if ( is_admin() || ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id < 1 || 'publish' !== get_post_status( $id ) ) {
			return;
		}
		$ids = self::remembered();
		if ( count( $ids ) >= self::MAX || in_array( $id, $ids, true ) ) {
			return;
		}
		$ids[] = $id;
		update_option( self::OPTION, $ids, false );
	}

	/**
	 * Run one plugin's call; its error is not our build's.
	 *
	 * @param callable $call Call.
	 */
	private static function call( $call ) {
		try {
			$call();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'cx-sidrena: page cache purge: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}
}
