<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$rows = CX_Sidrena_Catalog::instance()->all();
		$json = wp_json_encode( $rows );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		$upit_rows = CX_Sidrena_Upit::instance()->all();
		$upit_json = wp_json_encode( $upit_rows );
		if ( ! is_string( $upit_json ) ) {
			$upit_json = '[]';
		}

		$woo        = CX_Sidrena_Plugin::woocommerce_active();
		$cjenik_url = CX_Sidrena_Export::instance()->get_cjenik_url();
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					$woo
						? __( 'Services and products that are not in WooCommerce (e.g. made to order, or a shipping class). Name, regular price and bazna cijena are required. The type sets the columns of the public price list. Leave the date empty for the default reference day, or enter the day the item was first listed. If you have several outlets, choose the webshop or an outlet above the table - the rows go only into that price list. Availability is a dropdown: in stock or out of stock. The shortcode [sidrena-cijena id="dX"] shows the prices of that row on a page. For a single amount add prikaz="redovna", prikaz="akcijska" or prikaz="sidrena".', 'cx-sidrena' )
						: __( 'Each row becomes an item of the public price list (XML/CSV and HTML). Name, regular price and bazna cijena are required. The type sets the columns: product or service. Leave the date empty for the default reference day, or enter the day the item was first listed. If you have several outlets, choose the webshop or an outlet above the table. Availability is a dropdown: in stock or out of stock. Empty rows are not saved. The shortcode [sidrena-cijena id="x"] shows the prices of this row on any page. For a single amount add prikaz="redovna", prikaz="akcijska" or prikaz="sidrena". Insert the whole table with the shortcode [sidrena-cjenik].', 'cx-sidrena' )
				);
				?>
			</p>
		</div>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html( $woo ? __( 'Additional items', 'cx-sidrena' ) : __( 'Products', 'cx-sidrena' ) ); ?></h2>
				<p>
					<?php
					if ( $woo ) {
						echo esc_html(
							sprintf(
								/* translators: %d row count */
								__( 'Currently %d items. They are appended to the public price list.', 'cx-sidrena' ),
								count( $rows )
							)
						);
					} else {
						echo esc_html(
							sprintf(
								/* translators: %d product count */
								__( 'Currently %d products. Public page:', 'cx-sidrena' ),
								count( $rows )
							)
						);
					}
					echo ' ';
					if ( $woo ) {
						echo esc_html__( 'HTML:', 'cx-sidrena' );
						echo ' ';
					}
					?>
					<a href="<?php echo esc_url( $cjenik_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cjenik_url ); ?></a>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'proizvodi' ) ); ?>" id="sidrena-cijena-catalog-form">
				<?php wp_nonce_field( 'sidrena_cijena_save_catalog', 'sidrena_cijena_catalog_nonce' ); ?>
				<input type="hidden" name="sidrena_cijena_action" value="save_catalog" />
				<input type="hidden" name="sidrena_cijena_catalog_json" id="sidrena-cijena-catalog-json" value="<?php echo esc_attr( $json ); ?>" />
				<input type="hidden" name="sidrena_cijena_upit_json" id="sidrena-cijena-upit-json" value="<?php echo esc_attr( $upit_json ); ?>" />

				<div class="sidrena-cijena-catalog-toolbar">
					<div class="sidrena-cijena-catalog-toolbar__start">
						<?php if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) : ?>
						<label class="sidrena-cijena-catalog-scope" for="sidrena-cijena-catalog-scope">
							<span><?php echo esc_html__( 'Outlet', 'cx-sidrena' ); ?></span>
							<select id="sidrena-cijena-catalog-scope">
								<option value=""><?php echo esc_html__( 'Webshop', 'cx-sidrena' ); ?></option>
								<?php foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) : ?>
									<?php $outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka']; ?>
									<option value="<?php echo esc_attr( $outlet['oznaka'] ); ?>"><?php echo esc_html( $outlet_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php endif; ?>
						<button type="button" class="button button-secondary" id="sidrena-cijena-catalog-add">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php echo esc_html( $woo ? __( 'Add item', 'cx-sidrena' ) : __( 'Add product', 'cx-sidrena' ) ); ?>
						</button>
					</div>
					<p class="description sidrena-cijena-catalog-count" id="sidrena-cijena-catalog-count"></p>
				</div>

				<div class="sidrena-cijena-catalog-wrap">
					<table class="sidrena-cijena-catalog" id="sidrena-cijena-catalog">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Name', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Type', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'SKU', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Brand', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'URL', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Regular price', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Sale price', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Special form', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Price per unit', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Date of bazna cijena', 'cx-sidrena' ); ?></th>
								<?php if ( CX_Sidrena_Settings::instance()->is_cjm_enabled() ) : ?>
								<th><?php echo esc_html__( 'CJM unit', 'cx-sidrena' ); ?></th>
								<?php endif; ?>
								<th><?php echo esc_html__( 'Availability', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Shortcode', 'cx-sidrena' ); ?></th>
								<th><span class="screen-reader-text"><?php echo esc_html__( 'Actions', 'cx-sidrena' ); ?></span></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>

				<nav class="sidrena-cijena-catalog-pager" id="sidrena-cijena-catalog-pager" hidden>
					<button type="button" class="button" id="sidrena-cijena-catalog-prev"><?php echo esc_html__( 'Previous', 'cx-sidrena' ); ?></button>
					<span id="sidrena-cijena-catalog-page"></span>
					<button type="button" class="button" id="sidrena-cijena-catalog-next"><?php echo esc_html__( 'Next', 'cx-sidrena' ); ?></button>
				</nav>

				<div class="sidrena-cijena-upit-block">
					<h3 class="sidrena-cijena-upit-head"><?php echo esc_html__( 'Price on request', 'cx-sidrena' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'These rows are printed at the bottom of the HTML price list.', 'cx-sidrena' ); ?></p>
					<p class="sidrena-cijena-actions">
						<button type="button" class="button button-secondary" id="sidrena-cijena-upit-add">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
							<?php echo esc_html__( 'Add item', 'cx-sidrena' ); ?>
						</button>
					</p>
					<div class="sidrena-cijena-catalog-wrap">
						<table class="sidrena-cijena-catalog sidrena-cijena-upit" id="sidrena-cijena-upit">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Name', 'cx-sidrena' ); ?></th>
									<th><?php echo esc_html__( 'Criteria', 'cx-sidrena' ); ?></th>
									<th><span class="screen-reader-text"><?php echo esc_html__( 'Actions', 'cx-sidrena' ); ?></span></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>

				<?php $this->render_save_button( $woo ? __( 'Save items', 'cx-sidrena' ) : __( 'Save price list', 'cx-sidrena' ) ); ?>
			</form>
		</section>
		<?php
