<?php
/**
 * The sale rule on the reference day (T1, CX-SID-22 B): a file for the bazna import, with a suggested bazna cijena for
 * every product that is on sale now and has none.
 *
 * It cannot be worked out from the data alone (no price history before the plugin, sales without a start date), so it
 * is a tool, not an automatic fill: the owner reads the file, corrects it if needed and imports it with a confirmation
 * (CX_Sidrena_Import). A product that already has a bazna is never in the file.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggested bazna cijene for products on sale.
 */
final class CX_Sidrena_Sale_Rule {

	/** admin-post action and its nonce. */
	const ACTION = 'cx_sidrena_import_sale_rule';

	/**
	 * What the bazna of a product on sale is, by the date of its bazna (spec §1, Kai): on 02.05.2025. the lowest price
	 * in the 30 days before the sale, on 10.09.2026. the regular price before it. A product added after its category's
	 * date takes the regular price. Any other date has no rule and gets no suggestion. Filter `cx_sidrena_sale_rule`.
	 */
	const RULES = array(
		'02.05.2025.' => 'lowest_30',
		'10.09.2026.' => 'regular',
	);

	/**
	 * Hook up (WooCommerce active).
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Link that downloads the file (for the Import tab).
	 *
	 * @return string
	 */
	public static function url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * The rule for one product.
	 *
	 * @param string $date Date of its bazna ("02.05.2025.").
	 * @param string $from own|category|added|other (CX_Sidrena_Dates::date_for).
	 * @param int    $id   Product or variation.
	 * @return string lowest_30, regular, or '' (no rule for that date).
	 */
	public static function rule( $date, $from, $id ) {
		$rule = 'added' === $from ? 'regular' : ( isset( self::RULES[ $date ] ) ? self::RULES[ $date ] : '' );
		/**
		 * The rule for a product on sale without a bazna.
		 *
		 * @param string $rule lowest_30, regular or ''.
		 * @param string $date Date of its bazna.
		 * @param string $from Where the date comes from.
		 * @param int    $id   Product or variation.
		 */
		$rule = (string) apply_filters( 'cx_sidrena_sale_rule', $rule, $date, $from, (int) $id );

		return in_array( $rule, array( 'lowest_30', 'regular' ), true ) ? $rule : '';
	}

	/**
	 * Products and variations on sale now without a bazna cijena, each with its suggestion.
	 *
	 * @return array<int,array{id:int,sku:string,name:string,date:string,from:string,rule:string,bazna:string,regular:string,sale:string}>|WP_Error
	 *         bazna: the suggestion as stored ("19.90"), '' when there is no rule. WP_Error dates_not_saved.
	 */
	public static function rows() {
		if ( ! CX_Sidrena_Dates::dates_saved() ) {
			return new WP_Error( 'dates_not_saved', __( 'Save Dates in Settings first. They set each product\'s date.', 'cx-sidrena' ) );
		}
		$ids = CX_Sidrena_Fill::candidates();
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_on_sale() ) {
				continue;
			}
			// Never offer a product that has a bazna (the candidates have none; checked here again because it is the rule).
			if ( '' !== trim( (string) get_post_meta( $id, CX_SIDRENA_META_PRICE, true ) ) ) {
				continue;
			}
			$d     = CX_Sidrena_Dates::date_for( $id );
			$rule  = self::rule( $d['date'], $d['from'], $id );
			$bazna = '';
			if ( 'regular' === $rule ) {
				$bazna = (string) $p->get_regular_price( 'edit' );
			} elseif ( 'lowest_30' === $rule && function_exists( 'cx_sidrena_n30_prior' ) ) {
				$bazna = (string) cx_sidrena_n30_prior( $p );
			}
			$post  = get_post( $id );
			$out[] = array(
				'id'      => (int) $id,
				'sku'     => (string) $p->get_sku( 'edit' ),
				'name'    => CX_Sidrena_Import::export_name(
					(object) array(
						'post_type'    => $post->post_type,
						'post_title'   => $post->post_title,
						'post_excerpt' => $post->post_excerpt,
						'parent_title' => $post->post_parent ? (string) get_post_field( 'post_title', $post->post_parent, 'raw' ) : '',
					)
				),
				'date'    => $d['date'],
				'from'    => $d['from'],
				'rule'    => $rule,
				'bazna'   => $bazna,
				'regular' => (string) $p->get_regular_price( 'edit' ),
				'sale'    => (string) $p->get_sale_price( 'edit' ),
			);
		}

		return $out;
	}

	/**
	 * The file: the import's columns (Date and Lowest price empty) plus three for reading, which the import ignores.
	 *
	 * @param array<int,array> $rows rows().
	 * @return string CSV with a BOM, separator ; and a decimal comma (a Croatian spreadsheet opens it as numbers).
	 */
	public static function csv( array $rows ) {
		$f   = CX_Sidrena_Feed_Files::instance();
		$out = "\xEF\xBB\xBF" . $f->csv_line( array( 'SKU', 'ID', 'Product name', 'Bazna cijena', 'Date', 'Lowest price in 30 days', 'Rule', 'Regular price', 'Sale price' ) );
		foreach ( $rows as $r ) {
			$out .= $f->csv_line( array( $r['sku'], (string) $r['id'], $r['name'], self::comma( $r['bazna'] ), '', '', self::rule_label( $r ), self::comma( $r['regular'] ), self::comma( $r['sale'] ) ) );
		}

		return $out;
	}

	/**
	 * What the Rule column says.
	 *
	 * @param array $r A row.
	 * @return string
	 */
	public static function rule_label( array $r ) {
		// CX-SID-162: a suggestion comes from today's sale and prices, the regulation asks for the reference day's. The date
		// ends the sentence with its own dot (02.05.2025.), so the text has none after it.
		$check = sprintf(
			/* translators: %s: date of the bazna cijena */
			__( 'Suggested from today\'s sale and prices: in your own records, check the sale and price on %s', 'cx-sidrena' ),
			$r['date']
		);
		if ( 'lowest_30' === $r['rule'] ) {
			/* translators: %s: date of the bazna cijena */
			return sprintf( __( '%s: the lowest price in the 30 days before the sale', 'cx-sidrena' ), $r['date'] ) . '. ' . $check;
		}
		if ( 'regular' === $r['rule'] ) {
			/* translators: %s: date of the bazna cijena */
			return sprintf( __( '%s: the regular price before the sale', 'cx-sidrena' ), $r['date'] ) . '. ' . $check;
		}
		/* translators: %s: date of the bazna cijena */
		return sprintf( __( '%s: no rule for this date, enter the bazna cijena', 'cx-sidrena' ), $r['date'] );
	}

	/**
	 * An amount as stored, with a decimal comma.
	 *
	 * @param string $v "19.90".
	 * @return string "19,90", or ''.
	 */
	private static function comma( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v || ! is_numeric( $v ) ) {
			return $v;
		}

		return str_replace( '.', ',', wc_format_decimal( $v, wc_get_price_decimals() ) );
	}

	/**
	 * admin-post: download the file.
	 */
	public static function download() {
		check_admin_referer( self::ACTION );
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cx-sidrena' ), '', array( 'response' => 403 ) );
		}
		$rows = self::rows();
		if ( is_wp_error( $rows ) ) {
			wp_die( esc_html( $rows->get_error_message() ), '', array( 'response' => 409, 'back_link' => true ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bazna-cijene-akcije-' . wp_date( 'Y-m-d' ) . '.csv"' );
		echo self::csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, fields neutralized by csv_line().
		exit;
	}
}
