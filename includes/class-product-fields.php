<?php
/**
 * WooCommerce product and variation editor fields.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product editor UI for sidrena fields.
 */
class CX_Sidrena_Product_Fields {

	/**
	 * The owner's mark that a WooCommerce product is a service (CX-SID-138): 'yes' on the product, or no row. A variable
	 * product carries it on the parent and its sizes read it there, so a size is never a service on its own. It decides
	 * only which file of the price list a row goes into (NN 132/2026-1584 art. 3(3), 6(3)); a virtual product is not a
	 * service by that alone (Bishop 3071, Rook 3075: the mark is the owner's).
	 */
	const META_SERVICE = '_cx_sidrena_usluga';

	/**
	 * Posted next to the box by the product form. A form without it (another screen, a plugin's own product form) leaves
	 * the mark alone instead of reading the absent box as unchecked.
	 */
	const SERVICE_FORM = 'cx_sidrena_usluga_form';

	/**
	 * The product types a service can be (the box's show_if_ classes): a marked External or Grouped product is goods.
	 */
	const SERVICE_TYPES = array( 'simple', 'variable' );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

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
	 * Simple / external product fields under sale price.
	 */
	public function render_simple_fields() {
		$product_id = get_the_ID();
		$meta       = CX_Sidrena_Product_Meta::instance();
		$cjm_on     = ( 'yes' === CX_Sidrena_Settings::instance()->get( 'enable_cjm' ) );

		echo '<div class="options_group sidrena-cijena-admin-fields">';

		// Bazna cijena: read-only line + Change (spec §1.6); no free field that could change it by accident.
		$this->render_bazna( (int) $product_id, null );

		if ( $cjm_on ) {
			echo '<div class="sidrena-cijena-price-row">';
			$this->render_cjm_controls(
				$product_id,
				'sidrena_cijena_cjm',
				'sidrena_cijena_cjm_suffix',
				$meta->is_cjm_enabled( $product_id ),
				$meta->get_cjm_suffix( $product_id )
			);
			echo '</div>';
		}

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_naziv_posebnog_oblika',
				'name'        => 'sidrena_naziv_posebnog_oblika',
				'label'       => __( 'Name of special form of sale', 'cx-sidrena' ),
				'placeholder' => 'akcija',
				'desc_tip'    => true,
				'description' => __( 'While the sale price is active, the public price list writes “akcija”. Enter another name here (e.g. rasprodaja, sezonsko sniženje) if needed. An empty field means “akcija”. Without an active sale price nothing is published.', 'cx-sidrena' ),
				'value'       => $meta->get_oblik( $product_id ),
			)
		);

		// Net quantity, the unit price worked out from it (CX-SID-38, Norman SPEC §2.1): before MJDD's hand-typed pair.
		$this->render_neto( (int) $product_id, null );

		// MJDD's hand-typed unit of measure and unit price (SPEC §2.5): as before without a net quantity; with one they are
		// not used, so they are hidden when empty and read-only when they hold a value. A disabled field posts nothing,
		// and the save keeps what it does not get (CX_Sidrena_Fields::posted_value()).
		$old = self::old_pair( (int) $product_id );
		if ( 'hidden' !== $old ) {
			$off = 'read-only' === $old ? array( 'disabled' => 'disabled' ) : array();
			woocommerce_wp_text_input(
				array(
					'id'                => 'sidrena_jedinica_mjere',
					'name'              => 'sidrena_jedinica_mjere',
					'label'             => __( 'Unit of measure', 'cx-sidrena' ),
					'placeholder'       => 'kg',
					'desc_tip'          => true,
					'description'       => __( 'Unit in the public price list, e.g. kg, l, or pcs. Empty if the product has no unit price.', 'cx-sidrena' ),
					'value'             => $meta->get_jedinica( $product_id ),
					'custom_attributes' => $off,
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => 'sidrena_cijena_jedinice_mjere',
					'name'              => 'sidrena_cijena_jedinice_mjere',
					'label'             => __( 'Unit price', 'cx-sidrena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
					'desc_tip'          => true,
					'description'       => __( 'Same format as the regular price. Published alongside the retail price. Empty if not applicable.', 'cx-sidrena' ),
					'value'             => $meta->get_jedinica_cijena( $product_id ),
					'data_type'         => 'price',
					'custom_attributes' => $off,
				)
			);

			if ( 'read-only' === $old ) {
				echo '<p class="form-field cx-neto-old"><span class="description">' . esc_html__( 'Not used while Net quantity is filled in. Kept, and used again if you clear Net quantity.', 'cx-sidrena' ) . '</span></p>';
			}
		}

		echo '</div>';
	}

	/**
	 * How MJDD's hand-typed unit of measure and unit price show (Norman SPEC §2.5), by what is stored: "editable" without
	 * a net quantity the unit price can use (the price list then uses them); "hidden" with one and both empty;
	 * "read-only" with one and a value in either.
	 *
	 * @param int $id Product or variation.
	 * @return string editable|hidden|read-only
	 */
	private static function old_pair( $id ) {
		$meta = CX_Sidrena_Product_Meta::instance();
		if ( null === $meta->readable_neto( $id ) ) {
			return 'editable';
		}

		return ( '' === trim( $meta->get_jedinica( $id ) ) && '' === trim( $meta->get_jedinica_cijena( $id ) ) ) ? 'hidden' : 'read-only';
	}

	/**
	 * Net quantity of one product or size (CX-SID-38, Norman SPEC §2.2-2.3): a number and its unit; under them a line on
	 * which goods need it, a disclosure with the goods and the exceptions of the law (NN 105/2026 art. 8), and the unit
	 * price it gives, from the same unit_price() as the product page and the price list. Saved by
	 * CX_Sidrena_Fields::save_neto().
	 *
	 * @param int      $id   Product or variation.
	 * @param int|null $loop Variation loop index; null for a simple product.
	 */
	public function render_neto( $id, $loop ) {
		$meta   = CX_Sidrena_Product_Meta::instance();
		$stored = $meta->get_neto( $id );
		$unit   = $meta->normalize_neto_unit( $stored['jm'] );
		$value  = '' !== trim( $stored['neto'] ) ? wc_format_localized_decimal( trim( $stored['neto'] ) ) : '';
		$name   = null === $loop ? 'cx_neto' : 'cx_neto_var[' . (int) $loop . ']';
		$dom    = 'cx-neto-' . ( null === $loop ? 'p' : 'v' . (int) $loop );
		$row    = null === $loop ? 'form-field' : 'form-row form-row-full';
		$status = $this->neto_status( $id );
		$units  = array(
			''   => '—',
			'g'  => 'g',
			'kg' => 'kg',
			'ml' => 'ml',
			'l'  => 'l',
		);
		$goods  = array(
			__( "A price per kg or l is required for food and animal feed, detergents, paints and varnishes (not artists' paints), oils and fluids for engines and motor vehicles, distilled water, and products for the care, washing and cleaning of face, body, hair, teeth and mouth.", 'cx-sidrena' ),
			__( 'For food in a liquid, enter the drained weight. For glazed food, enter the weight without the glaze.', 'cx-sidrena' ),
			__( 'Leave it empty for gift packs and sets, pet treats, cakes, alcoholic drinks up to 100 ml, frozen desserts up to 500 ml, and fruit and vegetables sold by the piece or by the bunch.', 'cx-sidrena' ),
			__( 'Under 50 g or 50 ml, or when the price per kg or l equals the product\'s price, the product page does not show it. The price list still has it.', 'cx-sidrena' ),
		);
		?>
		<div class="cx-neto" id="<?php echo esc_attr( $dom ); ?>" data-stale="<?php echo esc_attr__( 'The new unit price shows after you save.', 'cx-sidrena' ); ?>">
			<p class="<?php echo esc_attr( $row ); ?> cx-neto__field">
				<label for="<?php echo esc_attr( $dom . '-q' ); ?>"><?php echo esc_html__( 'Net quantity', 'cx-sidrena' ); ?></label>
				<input type="text" inputmode="decimal" class="cx-neto__q" id="<?php echo esc_attr( $dom . '-q' ); ?>" name="<?php echo esc_attr( $name . '[q]' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="200" autocomplete="off" />
				<select class="cx-neto__u" name="<?php echo esc_attr( $name . '[u]' ); ?>" aria-label="<?php echo esc_attr__( 'Unit of the net quantity', 'cx-sidrena' ); ?>">
					<?php foreach ( $units as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $unit, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<div class="cx-neto__help">
				<p class="description cx-neto__desc"><?php echo esc_html__( 'For goods that must show a price per kg or l. Leave it empty for anything else.', 'cx-sidrena' ); ?></p>
				<details class="cx-neto__more">
					<summary><?php echo esc_html__( 'Which goods, and when to leave it empty', 'cx-sidrena' ); ?></summary>
					<?php foreach ( $goods as $text ) : ?>
						<p><?php echo esc_html( $text ); ?></p>
					<?php endforeach; ?>
				</details>
				<?php if ( '' !== $status ) : ?>
					<p class="description cx-neto-status"><?php echo wp_kses_post( $status ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * What the owner gets from the saved net quantity (Norman SPEC §2.3), from the same unit_price() as the page and the
	 * price list: shown, only in the list (under 50 g or 50 ml, or equal to the price), or not yet (no price). On a sale,
	 * a sentence that it comes from the sale price (the price the list and the page use: is_on_sale() with a sale price).
	 * Empty without a quantity the unit price can use.
	 *
	 * @param int $id Product or variation.
	 * @return string HTML.
	 */
	private function neto_status( $id ) {
		$meta    = CX_Sidrena_Product_Meta::instance();
		$product = wc_get_product( $id );
		if ( ! $product || null === $meta->readable_neto( $id ) ) {
			return '';
		}

		$unit = $meta->unit_price( $product );
		if ( null === $unit ) {
			return esc_html__( 'The unit price is worked out once the product has a price.', 'cx-sidrena' ) . $this->pack_sentence( $id, $product );
		}

		$amount = $meta->unit_price_html( $unit );
		if ( $unit['page'] ) {
			/* translators: %s: the unit price as the page shows it, e.g. 19,95 €/l. */
			$text = sprintf( esc_html__( 'Unit price: %s. Shown on the product page and in the price list.', 'cx-sidrena' ), $amount );
		} elseif ( (float) $unit['neto'] < 0.05 ) {
			/* translators: %s: the unit price as the page would show it, e.g. 49,75 €/l. */
			$text = sprintf( esc_html__( 'Unit price: %s, in the price list only. The product page does not show it under 50 g or 50 ml.', 'cx-sidrena' ), $amount );
		} else {
			/* translators: %s: the unit price, e.g. 2,49 €/kg. */
			$text = sprintf( esc_html__( "Unit price: %s, in the price list only. It equals the product's price, so the product page does not show it.", 'cx-sidrena' ), $amount );
		}

		if ( $product->is_on_sale() && '' !== (string) $product->get_sale_price() ) {
			$text .= ' ' . esc_html__( 'During the sale it is worked out from the sale price.', 'cx-sidrena' );
		}

		return $text . $this->pack_sentence( $id, $product );
	}

	/**
	 * The last sentence of the status line when the name has "N+M gratis" (CX-SID-190, Norman SPEC bd7c1cc1): which
	 * words of the name did it and the sum one piece × pieces = the pack, in the unit the owner entered (no conversion,
	 * so 2 × 500 g = 1000 g reads straight), with no-break spaces before the units. A pack typed in as one piece shows
	 * itself ("2 × 100 ml" where the label says 50 ml). Empty without the form.
	 *
	 * @param int        $id      Product or size.
	 * @param WC_Product $product The same.
	 * @return string HTML, with a space before it.
	 */
	private function pack_sentence( $id, $product ) {
		$meta   = CX_Sidrena_Product_Meta::instance();
		$pack   = $meta->pack_from_name( $product );
		$stored = $meta->readable_neto( $id );
		if ( null === $pack || null === $stored ) {
			return '';
		}

		// The total exactly, from the canonical quantity (at most 4 decimals) in ten-thousandths.
		$parts = explode( '.', $stored['neto'] );
		$whole = (int) $parts[0] * 10000 + (int) str_pad( isset( $parts[1] ) ? $parts[1] : '', 4, '0' );
		$sum   = $whole * $pack['pieces'];
		$total = rtrim( rtrim( intdiv( $sum, 10000 ) . '.' . str_pad( (string) ( $sum % 10000 ), 4, '0', STR_PAD_LEFT ), '0' ), '.' );
		$shown = static function ( $quantity ) use ( $stored ) {
			return wc_format_localized_decimal( $quantity ) . "\xc2\xa0" . $stored['jm'];
		};

		return ' <span class="cx-neto-pack">' . sprintf(
			/* translators: %1$s: the "N+M gratis" text as it is in the name, e.g. 1+1 Gratis. %2$s: number of pieces, e.g. 2. %3$s: net quantity of one piece, e.g. 50 ml. %4$s: the whole pack, e.g. 100 ml. */
			esc_html__( 'The name has “%1$s”, so it is worked out for the whole pack: %2$s × %3$s = %4$s. Enter the net quantity of one piece, as on its label.', 'cx-sidrena' ),
			esc_html( $pack['form'] ),
			(int) $pack['pieces'],
			esc_html( $shown( $stored['neto'] ) ),
			esc_html( $shown( $total ) )
		) . '</span>';
	}

	/**
	 * The bazna cijena of one product or variation, read-only, with Change (spec §1.6, CX-SID-24).
	 *
	 * Change opens three fields whose inputs stay disabled until then, so an unopened line posts nothing and a save can
	 * never change the bazna by accident (MJDD's free field, and the empty field that wiped a new variation's bazna).
	 * Saved by CX_Sidrena_Fields::save_change() through CX_Sidrena_History::set_bazna() (history and source: CX-SID-20).
	 *
	 * @param int      $id   Product or variation.
	 * @param int|null $loop Variation loop index; null for a simple product.
	 */
	public function render_bazna( $id, $loop ) {
		$meta    = CX_Sidrena_Product_Meta::instance();
		$product = wc_get_product( $id );
		$amount  = $meta->get_price( $id );
		$rule    = CX_Sidrena_Dates::date_for( $id );
		$name    = null === $loop ? 'cx_bazna' : 'cx_bazna_var[' . (int) $loop . ']';
		$dom     = 'cx-bazna-' . ( null === $loop ? 'p' : 'v' . (int) $loop );
		$own     = trim( $meta->get_date( $id ) );

		if ( '' !== trim( $amount ) ) {
			$from  = isset( $rule['from'] ) ? (string) $rule['from'] : '';
			$where = 'added' === $from ? __( '(day added)', 'cx-sidrena' ) : ( in_array( $from, array( 'category', 'other' ), true ) ? __( '(from category)', 'cx-sidrena' ) : '' );
			$parts = array(
				wc_price( (float) $amount ),
				esc_html( self::short_date( (string) $rule['date'] ) ) . ( '' !== $where ? ' <small>' . esc_html( $where ) . '</small>' : '' ),
				esc_html( CX_Sidrena_History::izvor_label( CX_Sidrena_History::izvor( $id ) ) ),
			);
			$text  = implode( ' · ', array_filter( $parts ) );
			$class = 'cx-bazna';
		} elseif ( $product && CX_Sidrena_Dates::fills_on_save( $product ) ) {
			$text  = esc_html__( 'No bazna cijena yet. It will be set from the regular price when you save.', 'cx-sidrena' );
			$class = 'cx-bazna cx-bazna--missing';
		} elseif ( $product && '' !== (string) $product->get_regular_price() ) {
			// An existing product the rule will not fill (MJDD's trap: today's date on an old product): Status lists it.
			$text  = esc_html__( 'No bazna cijena. It is listed on Status under Needs attention.', 'cx-sidrena' )
				. ' <a href="' . esc_url( CX_Sidrena_Admin_Status::url() . '#cx-attention' ) . '">' . esc_html__( 'Open Status', 'cx-sidrena' ) . '</a>';
			$class = 'cx-bazna cx-bazna--missing';
		} else {
			$text  = esc_html__( 'No bazna cijena.', 'cx-sidrena' );
			$class = 'cx-bazna cx-bazna--missing';
		}

		$prefill  = '' !== trim( $amount ) ? $amount : ( $product ? (string) $product->get_regular_price() : '' );
		$row_open = null === $loop ? '<p class="form-field %s">' : '<p class="form-row form-row-full %s">';
		?>
		<div class="<?php echo esc_attr( $class ); ?>" id="<?php echo esc_attr( $dom ); ?>">
			<?php printf( $row_open, 'cx-bazna__line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<label><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></label>
				<span class="cx-bazna__text"><?php echo wp_kses_post( $text ); ?></span>
				<button type="button" class="button-link cx-bazna__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $dom . '-edit' ); ?>"><?php echo esc_html__( 'Change', 'cx-sidrena' ); ?></button>
			</p>
			<div class="cx-bazna__edit" id="<?php echo esc_attr( $dom . '-edit' ); ?>" hidden>
				<input type="hidden" name="<?php echo esc_attr( $name . '[open]' ); ?>" value="1" disabled />
				<?php printf( $row_open, 'cx-bazna__field' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label for="<?php echo esc_attr( $dom . '-amount' ); ?>"><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
					<input type="text" class="short wc_input_price" id="<?php echo esc_attr( $dom . '-amount' ); ?>" name="<?php echo esc_attr( $name . '[amount]' ); ?>" value="<?php echo esc_attr( wc_format_localized_price( $prefill ) ); ?>" disabled />
				</p>
				<?php printf( $row_open, 'cx-bazna__field' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label for="<?php echo esc_attr( $dom . '-date' ); ?>"><?php echo esc_html__( 'Date', 'cx-sidrena' ); ?></label>
					<input type="text" class="short cx-bazna__date" id="<?php echo esc_attr( $dom . '-date' ); ?>" name="<?php echo esc_attr( $name . '[date]' ); ?>" value="<?php echo esc_attr( '' !== $own ? self::short_date( $own ) : '' ); ?>" placeholder="<?php echo esc_attr( self::short_date( (string) $rule['date'] ) ); ?>" autocomplete="off" disabled />
					<?php /* translators: %s: the date by the rule, e.g. 10.9.2026. */ ?>
					<span class="description"><?php echo esc_html( sprintf( __( 'Empty: the date by the rule (%s).', 'cx-sidrena' ), self::short_date( (string) $rule['date'] ) ) ); ?></span>
				</p>
				<?php printf( $row_open, 'cx-bazna__field' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<label for="<?php echo esc_attr( $dom . '-reason' ); ?>"><?php echo esc_html__( 'Reason', 'cx-sidrena' ); ?></label>
					<input type="text" class="short" id="<?php echo esc_attr( $dom . '-reason' ); ?>" name="<?php echo esc_attr( $name . '[reason]' ); ?>" value="" maxlength="200" disabled />
					<span class="description"><?php echo esc_html__( 'Optional. Kept in the price history.', 'cx-sidrena' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * "10.09.2026." as the owner reads it: "10.9.2026.".
	 *
	 * @param string $date Stored date.
	 * @return string
	 */
	public static function short_date( $date ) {
		$d = DateTime::createFromFormat( '!d.m.Y', rtrim( trim( (string) $date ), '.' ) );

		return $d ? $d->format( 'j.n.Y.' ) : trim( (string) $date );
	}

	/**
	 * CJM toggle + suffix field next to a sidrena price input.
	 *
	 * @param int    $product_id Product ID (unused, kept for callers).
	 * @param string $toggle_name Checkbox name.
	 * @param string $suffix_name Suffix input name.
	 * @param bool   $enabled     Current toggle.
	 * @param string $suffix      Current suffix.
	 */
	public function render_cjm_controls( $product_id, $toggle_name, $suffix_name, $enabled, $suffix ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		?>
		<p class="form-field sidrena-cijena-cjm">
			<label for="<?php echo esc_attr( $toggle_name ); ?>"><?php echo esc_html__( 'CJM', 'cx-sidrena' ); ?></label>
			<?php
			CX_Sidrena_Admin::instance()->render_toggle(
				$toggle_name,
				$toggle_name,
				$enabled,
				__( 'Unit price', 'cx-sidrena' ),
				'sidrena-cijena-cjm-toggle'
			);
			?>
			<span class="sidrena-cijena-cjm-suffix" <?php echo $enabled ? '' : 'hidden'; ?>>
				<input type="text" name="<?php echo esc_attr( $suffix_name ); ?>" value="<?php echo esc_attr( $suffix ); ?>" placeholder="<?php echo esc_attr__( 'per kg/l', 'cx-sidrena' ); ?>" />
				<span class="description"><?php echo esc_html__( 'Appended to the prefix: Price per kg/l', 'cx-sidrena' ); ?></span>
			</span>
		</p>
		<?php
	}

	/**
	 * Variation fields under variation sale price.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$meta   = CX_Sidrena_Product_Meta::instance();
		$id     = $variation->ID;
		$cjm_on = ( 'yes' === CX_Sidrena_Settings::instance()->get( 'enable_cjm' ) );
		$cjm    = $meta->is_cjm_enabled( $id );
		?>
		<div class="sidrena-cijena-variation-fields">
			<?php $this->render_bazna( (int) $id, (int) $loop ); ?>
			<?php if ( $cjm_on ) : ?>
				<p class="form-row form-row-full sidrena-cijena-cjm">
					<label><?php echo esc_html__( 'CJM', 'cx-sidrena' ); ?></label>
					<?php
					CX_Sidrena_Admin::instance()->render_toggle(
						'sidrena_cijena_cjm[' . (string) $loop . ']',
						'sidrena_cijena_cjm_' . (string) $loop,
						$cjm,
						__( 'Unit of measure', 'cx-sidrena' ),
						'sidrena-cijena-cjm-toggle'
					);
					?>
				</p>
				<p class="form-row form-row-full sidrena-cijena-cjm-suffix" <?php echo $cjm ? '' : 'hidden'; ?>>
					<label><?php echo esc_html__( 'Prefix CJM suffix', 'cx-sidrena' ); ?></label>
					<input type="text" class="short" name="sidrena_cijena_cjm_suffix[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_cjm_suffix( $id ) ); ?>" placeholder="<?php echo esc_attr__( 'per kg/l', 'cx-sidrena' ); ?>" />
				</p>
			<?php endif; ?>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Name of special form of sale', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_naziv_posebnog_oblika[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_oblik( $id ) ); ?>" placeholder="akcija" />
			</p>
			<?php
			// The size's net quantity (CX-SID-38, Norman SPEC §2.1), then MJDD's pair as on a simple product (§2.5).
			$this->render_neto( (int) $id, (int) $loop );
			$old = self::old_pair( (int) $id );
			$off = 'read-only' === $old ? ' disabled' : '';
			?>
			<?php if ( 'hidden' !== $old ) : ?>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_jedinica_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_jedinica( $id ) ); ?>" placeholder="kg"<?php echo $off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Unit price', 'cx-sidrena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena_jedinice_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_jedinica_cijena( $id ) ) ); ?>"<?php echo $off; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</p>
				<?php if ( '' !== $off ) : ?>
			<p class="form-row form-row-full description cx-neto-old"><?php echo esc_html__( 'Not used while Net quantity is filled in. Kept, and used again if you clear Net quantity.', 'cx-sidrena' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether a product or a size of one is a service (CX-SID-138): the mark of the product, of a size's parent, and only
	 * on a simple or variable product, the types the box is shown for. A marked product changed to External or Grouped is
	 * goods while it is that type; its mark stays stored, so changing it back makes it a service again (Argus, 57a54a9).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public static function is_service( $product ) {
		$size = $product->is_type( 'variation' );
		$id   = $size ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( $id <= 0 || 'yes' !== get_post_meta( $id, self::META_SERVICE, true ) ) {
			return false;
		}
		$type = $size ? WC_Product_Factory::get_product_type( $id ) : $product->get_type();

		return in_array( $type, self::SERVICE_TYPES, true );
	}

	/**
	 * The box "Service" next to Virtual and Downloadable (product_type_options), for a simple and a variable product.
	 * WooCommerce shows it checked when the product has the meta '_' . key = 'yes' and posts it as its id.
	 *
	 * @param array<string, array<string, string>> $options WooCommerce's product type options.
	 * @return array<string, array<string, string>>
	 */
	public function service_type_option( $options ) {
		$options = is_array( $options ) ? $options : array();

		$options[ ltrim( self::META_SERVICE, '_' ) ] = array(
			'id'            => self::META_SERVICE,
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => __( 'Service', 'cx-sidrena' ),
			'description'   => __( 'Tick this if the product is a service (e.g. fitting, repair). It then goes into the services price list, not the goods price list.', 'cx-sidrena' ),
			'default'       => 'no',
		);

		return $options;
	}

	/**
	 * The product form's marker that the box was on it (SERVICE_FORM); in the General panel, which every type's form has.
	 */
	public function render_service_marker() {
		echo '<input type="hidden" name="' . esc_attr( self::SERVICE_FORM ) . '" value="1" />';
	}

	/**
	 * Store the box, only from the product form that showed it: WooCommerce calls this from its product save after its
	 * nonce and edit_post checks, then saves the product (one build queued, as for any product save). Quick and bulk
	 * edit, REST, imports and the sizes' own save never come here, so they keep the mark. Only 'yes' or no row.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_service_mark( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product nonce and edit_post.
		// Only a simple or variable product has the box; another type's form still posts it hidden (WooCommerce's script
		// unticks only Virtual and Downloadable), and an unticked box would not be posted: either way, leave the mark.
		if ( empty( $_POST[ self::SERVICE_FORM ] ) || ! is_a( $product, 'WC_Product' ) || ! $product->is_type( self::SERVICE_TYPES ) ) {
			return;
		}

		if ( isset( $_POST[ self::META_SERVICE ] ) ) {
			$product->update_meta_data( self::META_SERVICE, 'yes' );
		} else {
			$product->delete_meta_data( self::META_SERVICE );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
