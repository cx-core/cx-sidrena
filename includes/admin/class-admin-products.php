<?php
/**
 * The bazna cijena in WooCommerce's product list: a column and a filter (spec §1.7, CX-SID-24).
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Column "Bazna cijena" and the filter "Bazna cijena: all / needs attention".
 */
class CX_Sidrena_Admin_Products {

	const COLUMN = 'cx_bazna';
	const FILTER = 'cx_bazna_filter';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Products of the current list page whose children's meta and posts are already loaded.
	 *
	 * @var bool
	 */
	private $primed = false;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_filter' ) );
	}

	/**
	 * The column, right after the price (MJDD's "Sidrena cijena" column is replaced).
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		unset( $columns['sidrena_cijena'] );
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'price' === $key ) {
				$out[ self::COLUMN ] = __( 'Bazna cijena', 'cx-sidrena' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Bazna cijena', 'cx-sidrena' );
		}

		return $out;
	}

	/**
	 * Amount with the date under it; a variable product: the range, the date when all are the same.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product.
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$this->prime();
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '&ndash;';
			return;
		}

		$items = $product->is_type( array( 'variable', 'variable-subscription' ) ) ? $product->get_children() : array( $product->get_id() );
		if ( $product->is_type( 'grouped' ) ) {
			echo '&ndash;'; // a grouped product has no price of its own; its products have their own rows
			return;
		}

		$meta    = CX_Sidrena_Product_Meta::instance();
		$amounts = array();
		$dates   = array();
		$without = 0;
		foreach ( $items as $id ) {
			$item = $id === $product->get_id() ? $product : wc_get_product( $id );
			if ( ! $item || '' === (string) $item->get_regular_price() ) {
				continue; // no price: cannot be bought, needs no bazna (as Status)
			}
			$shown = $meta->get_price_to_display( $item );
			if ( null === $shown ) {
				$without++;
				continue;
			}
			$amounts[] = (float) $shown;
			$rule      = CX_Sidrena_Dates::date_for( $item->get_id() );
			$dates[ CX_Sidrena_Product_Fields::short_date( (string) $rule['date'] ) ] = true;
		}

		if ( ! $amounts ) {
			if ( $without ) {
				echo '<span class="cx-bazna-col--missing" title="' . esc_attr__( 'No bazna cijena', 'cx-sidrena' ) . '">&mdash;</span>';
			} else {
				echo '&ndash;';
			}
			return;
		}

		$min = min( $amounts );
		$max = max( $amounts );
		echo wp_kses_post( $min === $max ? wc_price( $min ) : wc_price( $min ) . ' &ndash; ' . wc_price( $max ) );
		$date = 1 === count( $dates ) ? (string) key( $dates ) : __( 'several dates', 'cx-sidrena' );
		echo '<br><small class="cx-bazna-col__date">' . esc_html( $date ) . '</small>';
		if ( $without ) {
			/* translators: %d: number of variations without a bazna cijena */
			echo '<br><small class="cx-bazna-col--missing">' . esc_html( sprintf( __( 'Without bazna: %d', 'cx-sidrena' ), $without ) ) . '</small>';
		}
	}

	/**
	 * Load the page's variations (posts and meta) in a few queries instead of one per variation.
	 */
	private function prime() {
		if ( $this->primed ) {
			return;
		}
		$this->primed = true;
		global $wp_query, $wpdb;
		$ids = $wp_query && ! empty( $wp_query->posts ) ? array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ) : array();
		if ( ! $ids ) {
			return;
		}
		$in       = implode( ',', $ids );
		$children = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent IN ( {$in} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$children = array_map( 'intval', (array) $children );
		if ( $children ) {
			_prime_post_caches( $children, false, true );
		}
		update_meta_cache( 'post', array_merge( $ids, $children ) );
	}

	/**
	 * "Bazna cijena: all / needs attention" above the list.
	 *
	 * @param string $post_type Post type.
	 */
	public function render_filter( $post_type, $which = 'top' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'product' !== $post_type ) {
			return;
		}
		$current = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( self::FILTER ); ?>"><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER ); ?>" id="<?php echo esc_attr( self::FILTER ); ?>">
			<option value=""><?php echo esc_html__( 'Bazna cijena: all', 'cx-sidrena' ); ?></option>
			<option value="attention" <?php selected( 'attention', $current ); ?>><?php echo esc_html__( 'Bazna cijena: needs attention', 'cx-sidrena' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Needs attention = the products Status lists (a variation shows as its product). None: an empty list, never all.
	 *
	 * @param WP_Query $query Query.
	 */
	public function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		$current = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'attention' !== $current ) {
			return;
		}
		$ids = CX_Sidrena_Admin_Status::instance()->attention_product_ids();
		// The filter narrows the list, it never widens it: WooCommerce's admin search (and other filters) already put the
		// products they found in post__in, so keep only those in both (Argus N1: search + filter showed every product
		// that needs attention). A search with no hits sets array( 0 ): still a limit, so the list stays empty.
		$before = $query->get( 'post__in' );
		if ( is_array( $before ) && array() !== $before ) {
			$ids = array_values( array_intersect( array_map( 'intval', $ids ), array_map( 'intval', $before ) ) );
		}
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}
}
