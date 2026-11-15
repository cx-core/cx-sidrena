<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$woo = CX_Sidrena_Plugin::woocommerce_active();
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					$woo
						? __( 'The bazna cijena is shown next to the current price (in the Government decisions: dodatna or sidrena cijena), clearly and separately from the price the customer pays. The default label is Bazna cijena. The plugin can show it automatically below the price, or only through the shortcode [sidrena-cijena].', 'cx-sidrena' )
						: __( 'The bazna cijena is shown next to the current price (in the Government decisions: dodatna or sidrena cijena). The default label is Bazna cijena. Enter items on the Products tab; the public HTML page is /sidrena-cjenik, and the XML/CSV feed stays at /sidrena-cijena.xml or .csv.', 'cx-sidrena' )
				);
				?>
			</p>
		</div>
		<?php if ( $woo && class_exists( 'CX_Sidrena_Compat' ) && CX_Sidrena_Compat::lowest_price_plugin_active() ) : ?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s detected 30-day lowest price plugin name */
						__( '%s was detected. This plugin works with it: one sidrena cijena row, between the current price and the “Lowest price in the last 30 days” row.', 'cx-sidrena' ),
						CX_Sidrena_Compat::lowest_price_plugin_label()
					)
				);
				?>
			</p>
		</div>
		<?php endif; ?>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Storefront display', 'cx-sidrena' ); ?></h2>
				<p><?php echo esc_html__( 'Text, date, and how it is highlighted next to the product price.', 'cx-sidrena' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'postavke' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_save_settings', 'sidrena_cijena_nonce' ); ?>
				<input type="hidden" name="sidrena_cijena_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<?php if ( $woo ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatic display of sidrena cijena', 'cx-sidrena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_auto_display',
								'sidrena_cijena_auto_display',
								'yes' === $settings->get( 'auto_display' ),
								__( 'Automatic display of sidrena cijena', 'cx-sidrena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'The plugin automatically shows the sidrena cijena below the current price. If this is off, use the shortcode [sidrena-cijena] or [sidrena-cijena id="x"] to show it outside product loops. For a single amount add prikaz="redovna", prikaz="akcijska" or prikaz="sidrena".', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Automatic fill for new products', 'cx-sidrena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_auto_fill',
								'sidrena_cijena_auto_fill',
								'yes' === $settings->get( 'auto_fill' ),
								__( 'Fill an empty sidrena cijena and date on save', 'cx-sidrena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'For products and variations added after the plugin was activated. If the fields are empty, the current regular price and today\'s date are entered (WordPress timezone). A sidrena cijena and date that are already entered are not overwritten.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="sidrena_cijena_prefix"><?php echo esc_html__( 'Prefix', 'cx-sidrena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text sidrena-cijena-prefix-input" id="sidrena_cijena_prefix" name="sidrena_cijena_prefix" value="<?php echo esc_attr( $settings->get( 'prefix' ) ); ?>" />
							<p class="description">
								<?php echo esc_html__( 'Words only, no date. Preview:', 'cx-sidrena' ); ?>
								<code id="sidrena-cijena-preview"><?php echo esc_html( $settings->get_preview_label() . $settings->preview_separator() . '19,99 €' ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Date format', 'cx-sidrena' ); ?></th>
						<td>
							<fieldset class="sidrena-cijena-choices">
								<label class="sidrena-cijena-choice">
									<input type="radio" name="sidrena_cijena_date_style" value="parens" <?php checked( $settings->get( 'date_style' ), 'parens' ); ?> />
									<span><?php echo esc_html__( 'Prefix (date)', 'cx-sidrena' ); ?></span>
								</label>
								<label class="sidrena-cijena-choice">
									<input type="radio" name="sidrena_cijena_date_style" value="plain" <?php checked( $settings->get( 'date_style' ), 'plain' ); ?> />
									<span><?php echo esc_html__( 'Prefix date', 'cx-sidrena' ); ?></span>
								</label>
							</fieldset>
							<p class="description"><?php echo esc_html__( 'Examples: Bazna cijena (10.09.2026.) or Bazna cijena 10.09.2026. If the prefix ends with “na”, you get Bazna cijena na 10.09.2026. You can also enter Sidrena cijena. An empty prefix with “Prefix date” shows only the date and the amount.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sidrena_cijena_default_date"><?php echo esc_html__( 'Default reference date', 'cx-sidrena' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="sidrena_cijena_default_date" name="sidrena_cijena_default_date" value="<?php echo esc_attr( $settings->get( 'default_date' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used in the selected format on the storefront. For FMCG (food, beverages, cosmetics…) enter 02.05.2025 on the product.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tooltip', 'cx-sidrena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_enable_tooltip',
								'sidrena_cijena_enable_tooltip',
								'yes' === $settings->get( 'enable_tooltip' ),
								__( 'Show tooltip on storefront', 'cx-sidrena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'On mouse hover over the sidrena cijena.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr class="sidrena-cijena-tooltip-fields">
						<th scope="row"><label for="sidrena_cijena_tooltip"><?php echo esc_html__( 'Tooltip text', 'cx-sidrena' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="5" id="sidrena_cijena_tooltip" name="sidrena_cijena_tooltip"><?php echo esc_textarea( $settings->get( 'tooltip' ) ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Shown on mouse hover over the sidrena cijena.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Unit price (CJM)', 'cx-sidrena' ); ?></th>
						<td>
							<?php
							$this->render_toggle(
								'sidrena_cijena_enable_cjm',
								'sidrena_cijena_enable_cjm',
								$settings->is_cjm_enabled(),
								__( 'Enable unit of measure next to the sidrena cijena', 'cx-sidrena' )
							);
							?>
							<p class="description"><?php echo esc_html__( 'When this is on, the unit price (CJM) appears next to the sidrena cijena: on the Woo product and variation, and on every row of the Products or Additional items tab. Turn it on for the row and enter a suffix (e.g. "za kg/l"), and the shop shows "Cijena za kg/l (10.09.2026.)". The same unit of measure goes into the public XML/CSV and the HTML table.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo $woo ? 'sidrena_cijena_brand_taxonomy' : 'sidrena_cijena_brand_manual'; ?>"><?php echo esc_html__( 'Brand', 'cx-sidrena' ); ?></label></th>
						<td>
							<?php if ( $woo ) : ?>
								<select id="sidrena_cijena_brand_taxonomy" name="sidrena_cijena_brand_taxonomy">
									<option value=""><?php echo esc_html__( 'No taxonomy', 'cx-sidrena' ); ?></option>
									<?php foreach ( $settings->brand_taxonomy_choices() as $tax_slug => $tax_label ) : ?>
										<option value="<?php echo esc_attr( $tax_slug ); ?>" <?php selected( (string) $settings->get( 'brand_taxonomy', '' ), $tax_slug ); ?>><?php echo esc_html( $tax_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'For WooCommerce products, the brand in the price list is the name of the term from the selected taxonomy (e.g. product_brand or pa_marka). A variation without a term takes the parent\'s brand.', 'cx-sidrena' ); ?></p>
							<?php endif; ?>
							<p>
								<label for="sidrena_cijena_brand_manual"><?php echo esc_html__( 'Manual brand', 'cx-sidrena' ); ?></label>
							</p>
							<input type="text" class="regular-text" id="sidrena_cijena_brand_manual" name="sidrena_cijena_brand_manual" value="<?php echo esc_attr( (string) $settings->get( 'brand_manual', '' ) ); ?>" />
							<p class="description"><?php echo esc_html__( 'Used when the product has no term in the taxonomy, and when the Brand field on a manual row is empty. Manual rows (Products or Additional items) have their own field.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<?php if ( $woo ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Public price list (XML/CSV)', 'cx-sidrena' ); ?></th>
						<td>
							<p><?php echo esc_html__( 'Availability (dostupno/nedostupno) and barcode are always exported. On the webshop, availability follows the WooCommerce stock status. On other outlets, availability is the one imported for that outlet, not the webshop stock. The barcode is read from the GTIN, UPC, EAN or ISBN field. If the product has no barcode, the field stays empty.', 'cx-sidrena' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php $this->render_save_button( __( 'Save settings', 'cx-sidrena' ) ); ?>
			</form>
		</section>

		<?php if ( $woo ) : ?>
		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Filling empty fields', 'cx-sidrena' ); ?></h2>
				<p><?php echo esc_html__( 'Only empty fields are filled with the current regular price. A sidrena cijena that is already entered is never overwritten. New products can be filled automatically on save if this is on in the settings.', 'cx-sidrena' ); ?></p>
			</div>
			<p class="sidrena-cijena-actions">
				<button type="button" class="button button-secondary sidrena-cijena-snapshot-btn" id="sidrena-cijena-run-snapshot">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php echo esc_html__( 'Fill empty fields from regular price', 'cx-sidrena' ); ?>
				</button>
			</p>
			<p class="description">
				<?php echo esc_html__( 'For products that were in the assortment on the default reference day. The date stays the default from settings (e.g. 10.09.2026.).', 'cx-sidrena' ); ?>
			</p>
			<p class="sidrena-cijena-actions">
				<button type="button" class="button button-secondary sidrena-cijena-snapshot-btn" id="sidrena-cijena-run-snapshot-today">
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<?php echo esc_html__( 'Fill empty sidrena cijena fields with today\'s date', 'cx-sidrena' ); ?>
				</button>
			</p>
			<p class="description">
				<?php echo esc_html__( 'For products put on sale after the reference day. Enters the regular price and today\'s date (WordPress timezone). An existing date on the product is not overwritten.', 'cx-sidrena' ); ?>
			</p>
			<div class="sidrena-cijena-progress" id="sidrena-snapshot-progress" hidden>
				<div class="sidrena-cijena-progress__track">
					<div class="sidrena-cijena-progress__bar" id="sidrena-snapshot-bar"></div>
				</div>
			</div>
			<p class="sidrena-cijena-snapshot-status" aria-live="polite"></p>
			<p class="description">
				<?php echo esc_html__( 'Filling runs in short steps with a progress bar. If the server stops the request, the plugin continues on its own. A sidrena cijena that is already entered is not overwritten, so you can press the button again for the remaining items. You can also enter a single price and date by hand on the product, below the sale price.', 'cx-sidrena' ); ?>
			</p>
			<p class="description">
				<?php echo esc_html__( 'For large catalogs use WP-CLI (much faster than this button): wp cx-sidrena fill --dry-run then wp cx-sidrena fill. New products: wp cx-sidrena fill --today', 'cx-sidrena' ); ?>
			</p>
		</section>
		<?php endif; ?>
		<?php
