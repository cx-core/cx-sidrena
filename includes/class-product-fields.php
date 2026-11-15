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

		echo '<div class="sidrena-cijena-price-row">';

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_cijena',
				'name'        => 'sidrena_cijena',
				'label'       => __( 'Sidrena cijena', 'cx-sidrena' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'desc_tip'    => true,
				'description' => __( 'Regular retail price on the reference day (2 May 2025 for FMCG, 10 September 2026 for everything else). Enter it in the same format as the regular price (with or without VAT, per WooCommerce tax settings). Do not enter the sale price.', 'cx-sidrena' ),
				'value'       => $meta->get_price( $product_id ),
				'data_type'   => 'price',
				'wrapper_class' => 'sidrena-cijena-price-field',
			)
		);

		if ( $cjm_on ) {
			$this->render_cjm_controls(
				$product_id,
				'sidrena_cijena_cjm',
				'sidrena_cijena_cjm_suffix',
				$meta->is_cjm_enabled( $product_id ),
				$meta->get_cjm_suffix( $product_id )
			);
		}

		echo '</div>';

		woocommerce_wp_text_input(
			array(
				'id'          => 'sidrena_cijena_datum',
				'name'        => 'sidrena_cijena_datum',
				'label'       => __( 'Reference date', 'cx-sidrena' ),
				'placeholder' => CX_Sidrena_Settings::instance()->get( 'default_date' ),
				'desc_tip'    => true,
				'description' => __( 'Leave empty for the default date from settings. For food, beverages, cosmetics, and household: 02.05.2025.', 'cx-sidrena' ),
				'value'       => $meta->get_date( $product_id ),
			)
		);

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
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Sidrena cijena', 'cx-sidrena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_price( $id ) ) ); ?>" />
			</p>
			<?php if ( $cjm_on ) : ?>
				<p class="form-row form-row-last sidrena-cijena-cjm">
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
				<label><?php echo esc_html__( 'Reference date', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_cijena_datum[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_date( $id ) ); ?>" placeholder="<?php echo esc_attr( CX_Sidrena_Settings::instance()->get( 'default_date' ) ); ?>" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Name of special form of sale', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_naziv_posebnog_oblika[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_oblik( $id ) ); ?>" placeholder="akcija" />
			</p>
			<p class="form-row form-row-first">
				<label><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></label>
				<input type="text" class="short" name="sidrena_jedinica_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( $meta->get_jedinica( $id ) ); ?>" placeholder="kg" />
			</p>
			<p class="form-row form-row-last">
				<label><?php echo esc_html__( 'Unit price', 'cx-sidrena' ) . ' (' . esc_html( get_woocommerce_currency_symbol() ) . ')'; ?></label>
				<input type="text" class="short wc_input_price" name="sidrena_cijena_jedinice_mjere[<?php echo esc_attr( (string) $loop ); ?>]" value="<?php echo esc_attr( wc_format_localized_price( $meta->get_jedinica_cijena( $id ) ) ); ?>" />
			</p>
		</div>
		<?php
	}
}
