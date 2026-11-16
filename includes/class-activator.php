<?php
/**
 * Catalog counts for `wp cx-sidrena status`. MJDD's old fill (regular price into empty bazna, in batches from the
 * settings page or WP-cron, by SQL from WP-CLI) was here: it wrote with no confirmation and could stamp today's date,
 * so it is gone (CX-SID-22, decision 1606 c). The fill is CX_Sidrena_Fill, with a confirmation.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog counts.
 */
class CX_Sidrena_Activator {

	const FILL_PROMPT_OPTION = 'cx_sidrena_fill_prompt';

	/**
	 * Ask an admin to fill empty sidrena fields. Activation does not write prices.
	 */
	public static function queue_fill_prompt() {
		update_option( self::FILL_PROMPT_OPTION, 'yes', false );
	}

	/**
	 * Product types whose parent row must not receive a sidrena price.
	 *
	 * @return array<int,string>
	 */
	public static function skip_parent_types() {
		return array( 'variable', 'grouped', 'variable-subscription' );
	}

	/**
	 * Catalog counts for CLI/status: eligible rows, already filled, still empty.
	 *
	 * Eligible = product or variation with a non-empty regular price, excluding
	 * variable / grouped / variable-subscription parents.
	 *
	 * @return array{eligible:int,filled:int,missing:int}
	 */
	public static function catalog_stats() {
		global $wpdb;

		$sql = self::eligible_from_sql( 'stats' );
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'eligible' => isset( $row['eligible'] ) ? (int) $row['eligible'] : 0,
			'filled'   => isset( $row['filled'] ) ? (int) $row['filled'] : 0,
			'missing'  => isset( $row['missing'] ) ? (int) $row['missing'] : 0,
		);
	}

	/**
	 * SQL fragment: join product_type to posts as `pt`.
	 *
	 * @return string
	 */
	private static function product_type_join_sql() {
		global $wpdb;

		return "LEFT JOIN (
			SELECT tr.object_id, t.slug
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt
				ON tt.term_taxonomy_id = tr.term_taxonomy_id
				AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		) pt ON pt.object_id = p.ID";
	}

	/**
	 * Quoted IN() list of parent types that must not be filled.
	 *
	 * @return string
	 */
	private static function skip_types_sql() {
		$quoted = array();
		foreach ( self::skip_parent_types() as $slug ) {
			$quoted[] = "'" . esc_sql( $slug ) . "'";
		}

		return implode( ',', $quoted );
	}

	/**
	 * Shared FROM/WHERE for catalog stats.
	 *
	 * @param string $mode stats.
	 * @return string
	 */
	private static function eligible_from_sql( $mode = 'stats' ) {
		global $wpdb;

		unset( $mode );

		$price_key = CX_SIDRENA_META_PRICE;
		$skip_in   = self::skip_types_sql();
		$type_join = self::product_type_join_sql();

		return $wpdb->prepare(
			"SELECT
				COUNT(*) AS eligible,
				SUM(has_sidrena) AS filled,
				SUM(1 - has_sidrena) AS missing
			FROM (
				SELECT p.ID,
					CASE WHEN MAX(CASE WHEN sc.meta_value IS NOT NULL AND sc.meta_value <> '' THEN 1 ELSE 0 END) = 1 THEN 1 ELSE 0 END AS has_sidrena
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} rp
					ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND rp.meta_value <> ''
				LEFT JOIN {$wpdb->postmeta} sc
					ON sc.post_id = p.ID AND sc.meta_key = %s
				{$type_join}
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status IN ('publish', 'private', 'draft', 'pending', 'inherit')
				AND (pt.slug IS NULL OR pt.slug NOT IN ({$skip_in}))
				GROUP BY p.ID
			) sidrena_rows",
			$price_key
		);
	}
}
