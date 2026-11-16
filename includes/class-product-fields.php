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

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_jedinica_mjere',
				'name'        => 'sidrena_jedinica_mjere',
				'label'       => __( 'Unit of measure', 'cx-sidrena' ),
				'placeholder' => 'kg',
				'desc_tip'    => true,
				'description' => __( 'Unit in the public price list, e.g. kg, l, or pcs. Empty if the product has no unit price.', 'cx-sidrena' ),
				'value'       => $meta->get_jedinica( $product_id ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => 'sidrena_cijena_jedinice_mjere',
				'name'          => 'sidrena_cijena_jedinice_mjere',
				'label'         => __( 'Unit price', 'cx-sidrena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'desc_tip'      => true,
				'description'   => __( 'Same format as the regular price. Published alongside the retail price. Empty if not applicable.', 'cx-sidrena' ),
				'value'         => $meta->get_jedinica_cijena( $product_id ),
				'data_type'     => 'price',
			)
		);

		echo '</div>';
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
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_jedinica_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_jedinica( $id ) ); ?>" placeholder="kg" />
			</p>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Unit price', 'cx-sidrena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena_jedinice_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_jedinica_cijena( $id ) ) ); ?>" />
			</p>
		</div>
		<?php
	}
}
