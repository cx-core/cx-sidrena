<?php
/**
 * Lowest price in the 30 days before a sale (ZZP / EU Omnibus "prethodna cijena").
 *
 * Ported 1:1 from the bazna add-on 1.1.1 (najniza-30.php); names bazna_n30_* became cx_sidrena_n30_*, the meta keys
 * (_bazna_povijest, _bazna_akcija_od, _bazna_najniza30, _bazna_najniza30_izvor) are the same, so history moves over.
 *
 * Why our own code: WooCommerce keeps no price history, and the Omnibus plugin we tried (3.0.5) showed the sale price
 * itself as the "previous lowest" (22 → 28 → sale 20 gave 20, the right answer is 22). Rule implemented here:
 * - every change of the price a customer actually pays is recorded per product/variation (timestamp, price);
 * - when a sale starts, the lowest price applied in the 30 days before that moment is computed once and frozen
 *   (a deeper cut during the same sale keeps it: the prior price is the one before the first reduction);
 * - the struck price shown is that lowest price (normally equal to the regular price, so nothing changes visually),
 *   plus a line "Najniža cijena u 30 dana prije sniženja: X". If it is not higher than the sale price, there is no
 *   reduction to announce, so the price is shown without a strike.
 * - no history (a sale that was already running when this was installed): the regular price is used.
 * Price changes written straight into the database (ERP sync bypassing WooCommerce) are not seen.
 */

defined( 'ABSPATH' ) || exit;

const CX_SIDRENA_N30_HIST   = '_bazna_povijest';
const CX_SIDRENA_N30_START  = '_bazna_akcija_od';
const CX_SIDRENA_N30_LOWEST = '_bazna_najniza30';
const CX_SIDRENA_N30_SOURCE = '_bazna_najniza30_izvor';
const CX_SIDRENA_N30_WINDOW = 30 * DAY_IN_SECONDS;

/**
 * Lowest price in effect at any moment in [$from, $to): entries inside the window plus the one in effect at $from.
 *
 * @param array $hist List of array( ts, price ), oldest first.
 * @return string|null
 */
function cx_sidrena_n30_lowest( array $hist, $from, $to ) {
	$cands  = array();
	$before = null;
	foreach ( $hist as $e ) {
		if ( ! is_array( $e ) || count( $e ) < 2 || '' === (string) $e[1] ) {
			continue;
		}
		if ( $e[0] < $from ) {
			$before = $e[1];
		} elseif ( $e[0] < $to ) {
			$cands[] = $e[1];
		}
	}
	if ( null !== $before ) {
		$cands[] = $before;
	}
	if ( ! $cands ) {
		return null;
	}
	$min = null;
	foreach ( $cands as $c ) {
		if ( null === $min || (float) $c < (float) $min ) {
			$min = (string) $c;
		}
	}
	return $min;
}

function cx_sidrena_n30_history( $id ) {
	$h = get_post_meta( $id, CX_SIDRENA_N30_HIST, true );
	return is_array( $h ) ? $h : array();
}

/**
 * Record the price and open/close the sale. Called after every WooCommerce save of a product or variation.
 *
 * @param WC_Product $product Product.
 * @param int|null   $now     Timestamp (tests only).
 */
function cx_sidrena_n30_track( $product, $now = null ) {
	if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
		return;
	}
	$id    = $product->get_id();
	$price = (string) $product->get_price( 'edit' );
	if ( ! $id || '' === $price ) {
		return;
	}
	$now     = $now ? (int) $now : time();
	$hist    = cx_sidrena_n30_history( $id );
	$on_sale = $product->is_on_sale( 'edit' );
	$started = get_post_meta( $id, CX_SIDRENA_N30_START, true );

	if ( $on_sale && '' === (string) $started ) {
		// History BEFORE the sale price is appended, so the sale price itself never counts; everything already in it
		// was applied, including an earlier change in this same second (hence $now + 1).
		// A scheduled sale is applied by WooCommerce's scheduler, possibly late: the 30 days count from the planned
		// start date (if it lies within the last 30 days), and the window runs up to now, so nothing applied in between
		// is skipped. Wider window = lower or equal prior price, never a bigger claimed reduction.
		$from   = $product->get_date_on_sale_from( 'edit' );
		$anchor = ( $from && $from->getTimestamp() <= $now && $from->getTimestamp() > $now - CX_SIDRENA_N30_WINDOW ) ? $from->getTimestamp() : $now;
		$low    = cx_sidrena_n30_lowest( $hist, $anchor - CX_SIDRENA_N30_WINDOW, $now + 1 );
		$src = 'povijest';
		if ( null === $low ) {
			$low = (string) $product->get_regular_price( 'edit' );
			$src = 'redovna';
		}
		update_post_meta( $id, CX_SIDRENA_N30_START, $now );
		update_post_meta( $id, CX_SIDRENA_N30_LOWEST, $low );
		update_post_meta( $id, CX_SIDRENA_N30_SOURCE, $src );
	} elseif ( ! $on_sale && '' !== (string) $started ) {
		delete_post_meta( $id, CX_SIDRENA_N30_START );
		delete_post_meta( $id, CX_SIDRENA_N30_LOWEST );
		delete_post_meta( $id, CX_SIDRENA_N30_SOURCE );
	}

	$last = $hist ? end( $hist ) : null;
	if ( ! $last || abs( (float) $last[1] - (float) $price ) > 0.0001 ) {
		$hist[] = array( $now, $price );
		// Keep 40 days plus the entry in effect at the start of them; older entries can never matter again.
		$cut  = $now - 40 * DAY_IN_SECONDS;
		$keep = array();
		$prev = null;
		foreach ( $hist as $e ) {
			if ( $e[0] < $cut ) {
				$prev = $e;
			} else {
				$keep[] = $e;
			}
		}
		if ( $prev ) {
			array_unshift( $keep, $prev );
		}
		update_post_meta( $id, CX_SIDRENA_N30_HIST, $keep );
	}
}

add_action( 'woocommerce_after_product_object_save', 'cx_sidrena_n30_track', 20 );
add_action( 'woocommerce_after_product_variation_object_save', 'cx_sidrena_n30_track', 20 );

/**
 * Prior price for a product on sale: frozen value, or (sale started without a save we saw) computed now.
 *
 * @return string|null Raw price as entered, or null when the product is not on sale.
 */
function cx_sidrena_n30_prior( $product ) {
	if ( ! $product->is_on_sale() ) {
		return null;
	}
	$low = (string) get_post_meta( $product->get_id(), CX_SIDRENA_N30_LOWEST, true );
	if ( '' !== $low ) {
		return $low;
	}
	$from = $product->get_date_on_sale_from();
	$at   = $from ? $from->getTimestamp() : time();
	$low  = cx_sidrena_n30_lowest( cx_sidrena_n30_history( $product->get_id() ), $at - CX_SIDRENA_N30_WINDOW, $at );
	return null !== $low ? $low : (string) $product->get_regular_price();
}

function cx_sidrena_n30_line( $product, $raw ) {
	return '<span class="bazna-najniza30" style="display:block;font-size:.85em">' . esc_html__( 'Lowest price in the 30 days before the discount:', 'cx-sidrena' ) . ' '
		. wc_price( wc_get_price_to_display( $product, array( 'price' => $raw ) ) ) . '</span>';
}

/**
 * Struck price = prior price, plus the line. $regular_raw/$sale_raw: raw prices of the item on sale.
 */
function cx_sidrena_n30_html( $product, $html, $prior, $regular_raw, $sale_raw, $line = null ) {
	$prior_d = wc_get_price_to_display( $product, array( 'price' => $prior ) );
	$sale_d  = wc_get_price_to_display( $product, array( 'price' => $sale_raw ) );
	if ( (float) $prior <= (float) $sale_raw ) {
		$html = wc_price( $sale_d ) . $product->get_price_suffix();
	} elseif ( abs( (float) $prior - (float) $regular_raw ) > 0.0001 ) {
		$html = wc_format_sale_price( $prior_d, $sale_d ) . $product->get_price_suffix();
	}
	return $html . ( null === $line ? cx_sidrena_n30_line( $product, $prior ) : $line );
}

/**
 * Whether the line (and the struck prior price) is shown. Per shop, option cx_sidrena_n30_display; the history is
 * recorded either way, so turning it on later has 30 days of data. Missing = shown (a fresh install).
 *
 * @return bool
 */
function cx_sidrena_n30_display_enabled() {
	return 'no' !== get_option( 'cx_sidrena_n30_display', 'yes' );
}

add_filter(
	'woocommerce_get_price_html',
	function ( $html, $product ) {
		if ( ! cx_sidrena_n30_display_enabled() ) {
			return $html;
		}
		if ( ! $product instanceof WC_Product || '' === (string) $product->get_price() ) {
			return $html;
		}
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $product->is_on_sale() ) {
				return $html;
			}
			$priors = array();
			$one    = null;
			foreach ( $product->get_visible_children() as $cid ) {
				$v = wc_get_product( $cid );
				if ( $v && $v->is_on_sale() && $v->is_purchasable() ) {
					$p        = cx_sidrena_n30_prior( $v );
					$priors[] = (float) $p;
					$one      = array( $p, (string) $v->get_regular_price(), (string) $v->get_sale_price() );
				}
			}
			if ( ! $priors ) {
				return $html;
			}
			$vp = $product->get_variation_prices( true );
			$single = $vp['price'] && min( $vp['price'] ) === max( $vp['price'] ) && min( $vp['regular_price'] ) === max( $vp['regular_price'] );
			$lo   = wc_price( wc_get_price_to_display( $product, array( 'price' => min( $priors ) ) ) );
			$hi   = wc_price( wc_get_price_to_display( $product, array( 'price' => max( $priors ) ) ) );
			$text = min( $priors ) === max( $priors ) ? $lo : $lo . ' – ' . $hi;
			$line = '<span class="bazna-najniza30" style="display:block;font-size:.85em">' . esc_html__( 'Lowest price in the 30 days before the discount:', 'cx-sidrena' ) . ' ' . $text . '</span>';
			if ( $single ) {
				// One price for every size: WooCommerce strikes the regular price. Strike the LOWEST prior of the sizes
				// instead (never announces a bigger reduction than any size really has); none if that is not higher.
				return cx_sidrena_n30_html( $product, $html, (string) min( $priors ), $one[1], $one[2], $line );
			}
			return $html . $line;
		}
		$prior = cx_sidrena_n30_prior( $product );
		if ( null === $prior || '' === $prior ) {
			return $html;
		}
		// Sale price, not get_price(): between a scheduled start date and WooCommerce's cron run _price is still the old one.
		return cx_sidrena_n30_html( $product, $html, $prior, (string) $product->get_regular_price(), (string) $product->get_sale_price() );
	},
	50,
	2
);

/**
 * Start the history for every existing product and variation (on activation, and once after an update from 1.0.0).
 * Batches of 200 through WP-cron, so a large shop does not time out.
 */
function cx_sidrena_n30_seed_batch( $page = 1 ) {
	$ids = get_posts(
		array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'paged'          => (int) $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	foreach ( $ids as $id ) {
		if ( ! cx_sidrena_n30_history( $id ) ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				cx_sidrena_n30_track( $p );
			}
		}
	}
	if ( count( $ids ) === 200 ) {
		wp_schedule_single_event( time() + 5, 'cx_sidrena_n30_seed', array( (int) $page + 1 ) );
	} else {
		update_option( 'cx_sidrena_n30_seeded', time(), false );
	}
	return count( $ids );
}
add_action( 'cx_sidrena_n30_seed', 'cx_sidrena_n30_seed_batch' );

add_action(
	'init',
	function () {
		// 'running' until the last batch; a batch that dies leaves it so (every later save still starts that item's history).
		if ( ! get_option( 'cx_sidrena_n30_seeded' ) && function_exists( 'wc_get_product' ) ) {
			update_option( 'cx_sidrena_n30_seeded', 'running', false );
			wp_schedule_single_event( time() + 5, 'cx_sidrena_n30_seed', array( 1 ) );
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// wp cx-sidrena n30 on|off|status  →  show or hide the line until the settings screen has a switch.
	WP_CLI::add_command(
		'cx-sidrena n30',
		function ( $args ) {
			$what = isset( $args[0] ) ? $args[0] : 'status';
			if ( 'on' === $what || 'off' === $what ) {
				update_option( 'cx_sidrena_n30_display', 'on' === $what ? 'yes' : 'no', true );
			} elseif ( 'status' !== $what ) {
				WP_CLI::error( 'Use: wp cx-sidrena n30 on|off|status' );
			}
			WP_CLI::success( 'Lowest price in 30 days: ' . ( cx_sidrena_n30_display_enabled() ? 'shown' : 'hidden' ) . ' (history is always recorded).' );
		}
	);
}
