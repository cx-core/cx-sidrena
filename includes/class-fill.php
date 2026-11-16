<?php
/**
 * Fill the bazna cijena from the regular price, confirmed: Status "Use regular price" / "Fix all", and WP-CLI fill.
 *
 * Only products and variations without a bazna (the "No bazna cijena" rows of Status), never one that has a bazna.
 * The date is the product's own date if it has one, otherwise the rule's (CX-SID-21), never today's on an old
 * product. Nothing is written until the Dates are saved (spec §1.1). Every write goes through
 * CX_Sidrena_History::set_bazna with the confirmation's id, so each change carries who confirmed it.
 *
 * Up to AT_ONCE products are written in the request; more run as the shop's background job (CX_Sidrena_Bulk).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Confirmed fill.
 */
final class CX_Sidrena_Fill {

	/** Up to this many, start() writes at once; more run in the background (spec §1.1: > 300). */
	const AT_ONCE = 300;

	/** Seconds start() spends writing before the rest continues in the background. */
	const START_BUDGET = 25;

	/** Who may start one, and the source recorded on each product. */
	const VIA = array( 'status', 'cli' );

	/**
	 * The text the person confirms with (spec §1.1), kept with the confirmation as it was shown. [Kai] temporary
	 * text, at most 200 characters. The same string as the Status dialog (CX-SID-42), so one translation serves both.
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'I confirm, on behalf of the shop owner, that each product\'s regular price was its price on its bazna cijena date.', 'cx-sidrena' );
	}

	/**
	 * Published simple products and variations (of a published parent) with a regular price and no bazna: the
	 * "No bazna cijena" rows of Status, in the same query shape, so "Fix all (N)" and the fill agree.
	 *
	 * @param int[]|string $ids Ids, or 'all'.
	 * @return int[]
	 */
	public static function candidates( $ids = 'all' ) {
		global $wpdb;
		$skip_types = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug IN ('variable','grouped')";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->posts} par ON par.ID = p.post_parent
				JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price' AND TRIM( rp.meta_value ) <> ''
				LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s
				WHERE p.post_status = 'publish'
				AND ( ( p.post_type = 'product' AND p.ID NOT IN ( $skip_types ) )
					OR ( p.post_type = 'product_variation' AND par.post_status = 'publish' ) )
				AND ( pr.meta_value IS NULL OR TRIM( pr.meta_value ) = '' )
				ORDER BY p.ID",
				CX_SIDRENA_META_PRICE
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = array_map( 'intval', (array) $rows );
		if ( 'all' !== $ids ) {
			$rows = array_values( array_intersect( $rows, array_map( 'intval', (array) $ids ) ) );
		}

		return $rows;
	}

	/**
	 * The status part of candidates(), for one product: published, and a variation's product published too. The step
	 * checks it again, so a product unpublished or moved to the trash while the fill runs is skipped.
	 *
	 * @param int $id Product or variation.
	 * @return bool
	 */
	private static function published( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( 'product_variation' === $post->post_type ) {
			return 'publish' === get_post_status( (int) $post->post_parent );
		}

		return 'product' === $post->post_type;
	}

	/**
	 * Check a confirmation: who, when, and the text.
	 *
	 * @param array<string,mixed> $c confirmed_by, confirmed_on (Y-m-d, default today), consent.
	 * @return array{by:string,on:string,consent:string}|WP_Error
	 */
	public static function confirmation( array $c ) {
		$by = isset( $c['confirmed_by'] ) ? trim( sanitize_text_field( (string) $c['confirmed_by'] ) ) : '';
		if ( '' === $by || ( function_exists( 'mb_strlen' ) ? mb_strlen( $by ) : strlen( $by ) ) > 100 ) {
			return new WP_Error( 'no_name', __( 'Enter the name of who confirmed.', 'cx-sidrena' ) );
		}
		$on = isset( $c['confirmed_on'] ) && '' !== trim( (string) $c['confirmed_on'] ) ? trim( (string) $c['confirmed_on'] ) : wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $on, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || $on > wp_date( 'Y-m-d' ) ) {
			return new WP_Error( 'bad_date', __( 'The date of the confirmation must be a real date, not later than today.', 'cx-sidrena' ) );
		}
		$consent = isset( $c['consent'] ) ? trim( (string) $c['consent'] ) : '';
		if ( '' === $consent ) {
			return new WP_Error( 'no_consent', __( 'Tick the confirmation.', 'cx-sidrena' ) );
		}

		return array(
			'by'      => $by,
			'on'      => $on,
			'consent' => $consent,
		);
	}

	/**
	 * Start a fill.
	 *
	 * @param int[]|string        $ids Ids, or 'all'.
	 * @param array<string,mixed> $c   confirmed_by, confirmed_on (Y-m-d), consent (the exact text the person saw),
	 *                                 via (status|cli, default status).
	 * @return array{job:string,total:int,processed:int,finished:bool,result?:array}|WP_Error
	 *         dates_not_saved, no_name, bad_date, no_consent, running, nothing, or a database error.
	 */
	public static function start( $ids, array $c ) {
		if ( ! CX_Sidrena_Dates::dates_saved() ) {
			return self::dates_not_saved();
		}
		$ok = self::confirmation( $c );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( CX_Sidrena_Bulk::running() ) {
			return new WP_Error( 'running', __( 'Another fill or import is running. Wait for it to finish.', 'cx-sidrena' ) );
		}
		$list = self::candidates( $ids );
		if ( ! $list ) {
			return new WP_Error( 'nothing', __( 'Nothing to fill: these products already have a bazna cijena.', 'cx-sidrena' ) );
		}
		$via = isset( $c['via'] ) && in_array( $c['via'], self::VIA, true ) ? $c['via'] : 'status';
		$job = CX_Sidrena_Bulk::start(
			array(
				'kind'  => 'fill',
				'via'   => $via,
				'by'    => $ok['by'],
				'on'    => $ok['on'],
				'total' => count( $list ),
			),
			$list,
			// Under the start lock, with nothing running: a second "Fix all" at the same moment never writes its own
			// confirmation (it is refused with 'running').
			static function () use ( $via, $ok, $list ) {
				$conf = CX_Sidrena_History::start_confirmation(
					array(
						'kind'         => $via,
						'confirmed_by' => $ok['by'],
						'confirmed_on' => $ok['on'],
						'consent'      => $ok['consent'],
						'total'        => count( $list ),
					)
				);

				return is_wp_error( $conf ) ? $conf : array( 'conf' => (int) $conf );
			}
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( count( $list ) <= self::AT_ONCE && 'cli' !== $via ) {
			$t0 = microtime( true );
			while ( empty( $job['finished'] ) && microtime( true ) - $t0 < self::START_BUDGET ) {
				$next = CX_Sidrena_Bulk::step( self::START_BUDGET - ( microtime( true ) - $t0 ) );
				if ( ! $next || (int) $next['pos'] === (int) $job['pos'] ) {
					break; // another request holds the step
				}
				$job = $next;
			}
		}

		return CX_Sidrena_Bulk::shape( $job );
	}

	/**
	 * @return WP_Error
	 */
	public static function dates_not_saved() {
		return new WP_Error( 'dates_not_saved', __( 'Save Dates in Settings first. They set each product\'s date.', 'cx-sidrena' ) );
	}

	/**
	 * Progress of a fill. Reading it also writes the next rows (the background job does not wait for WP-cron).
	 * A finished fill keeps answering until the next job starts, so the bar can show its end.
	 *
	 * @param string $job Job id.
	 * @return array|null
	 */
	public static function progress( $job ) {
		$j = CX_Sidrena_Bulk::get();
		if ( ! $j || 'fill' !== $j['kind'] || ! hash_equals( (string) $j['id'], (string) $job ) ) {
			return null;
		}
		if ( empty( $j['finished'] ) ) {
			$j = CX_Sidrena_Bulk::step( CX_Sidrena_Bulk::POLL_BUDGET, CX_Sidrena_Bulk::POLL_ROWS );
		}

		return $j ? CX_Sidrena_Bulk::shape( $j ) : null;
	}

	/**
	 * The running fill of this shop, or null.
	 *
	 * @return array|null
	 */
	public static function current() {
		$j = CX_Sidrena_Bulk::running();

		return $j && 'fill' === $j['kind'] ? CX_Sidrena_Bulk::shape( $j ) : null;
	}

	// ----------------------------------------------------------------------------------------------------------------
	// CX_Sidrena_Bulk kind
	// ----------------------------------------------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $job Job.
	 * @return int[]
	 */
	public static function bulk_rows( array $job ) {
		unset( $job );

		return CX_Sidrena_Bulk::items();
	}

	/**
	 * @param int $id Product or variation.
	 * @return string
	 */
	public static function bulk_label( $id ) {
		$sku = (string) get_post_meta( (int) $id, '_sku', true );

		return '' !== $sku ? $sku : '#' . (int) $id;
	}

	/**
	 * One product: the regular price becomes its bazna, with its own date or the rule's.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int                 $id  Product or variation.
	 * @return array{result:string,label:string,message:string,already?:bool}
	 */
	public static function bulk_write( array $job, $id ) {
		$id    = (int) $id;
		$label = self::bulk_label( $id );
		$skip  = static function ( $message ) use ( $label ) {
			return array( 'result' => 'skipped', 'label' => $label, 'message' => $message );
		};
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return $skip( __( 'Product not found.', 'cx-sidrena' ) );
		}
		if ( '' !== trim( CX_Sidrena_Product_Meta::instance()->get_price( $id ) ) ) {
			$izvor = CX_Sidrena_History::izvor( $id );
			if ( (int) $izvor['ref'] === (int) $job['conf'] && $izvor['type'] === $job['via'] ) {
				// Written by this fill in a request that died before it saved its position.
				return array( 'result' => 'updated', 'label' => $label, 'message' => '', 'already' => true );
			}
			return $skip( __( 'It already has a bazna cijena.', 'cx-sidrena' ) );
		}
		if ( ! self::published( $id ) ) {
			// Unpublished or moved to the trash after the fill started (a fill over 300 runs in the background).
			return $skip( __( 'The product is no longer published (a draft, private or in the trash).', 'cx-sidrena' ) );
		}
		$regular = trim( (string) $product->get_regular_price( 'edit' ) );
		if ( '' === $regular ) {
			return $skip( __( 'It has no regular price.', 'cx-sidrena' ) );
		}
		$d   = CX_Sidrena_Dates::date_for( $id );
		$own = 'own' === $d['from'] || '' !== trim( (string) get_post_meta( $id, CX_SIDRENA_META_DATE, true ) );
		$res = CX_Sidrena_History::set_bazna(
			$id,
			$regular,
			$own ? null : $d['rule_date'],
			array(
				'type'      => $job['via'],
				'by'        => $job['by'],
				'on'        => $job['on'],
				'ref'       => (int) $job['conf'],
				'date_from' => $own ? $d['from'] : $d['rule_from'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return $skip( $res->get_error_message() );
		}

		return array( 'result' => 'updated', 'label' => $label, 'message' => '' );
	}

	/**
	 * @param array<string,mixed> $job Job.
	 */
	public static function bulk_finish( array $job ) {
		CX_Sidrena_History::finish_confirmation(
			(int) $job['conf'],
			array(
				'updated' => (int) $job['updated'],
				'skipped' => (int) $job['skipped'],
			)
		);
	}
}
