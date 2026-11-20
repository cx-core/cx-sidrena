<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$rows = CX_Sidrena_Catalog::instance()->all();
		foreach ( $rows as $i => $row ) {
			// A service that is not in the price list is marked in its row (CX-SID-130, Norman 3113 R).
			$rows[ $i ]['neobjavljeno'] = ! CX_Sidrena_Catalog::instance()->is_public_row( $row );
			// The date a published service has, for its empty date field (Norman 130 SHOULD 2).
			if ( 'usluga' === CX_Sidrena_Catalog::instance()->row_vrsta( $row ) && ! $rows[ $i ]['neobjavljeno'] ) {
				$rows[ $i ]['datum_usluge'] = CX_Sidrena_Money::customer_date( CX_Sidrena_Catalog::instance()->service_state( $row )['date'] );
			}
			// A product saved without a bazna cijena is marked in its row too (CX-SID-133, Norman 3272, Rook 3273).
			$rows[ $i ]['bez_bazne'] = CX_Sidrena_Catalog::instance()->is_goods_without_bazna( $row );
		}
		$upit_rows = CX_Sidrena_Upit::instance()->all();

		$woo        = CX_Sidrena_Plugin::woocommerce_active();
		$cjenik_url = CX_Sidrena_Export::instance()->get_cjenik_url();

		// CX-SID-142, Norman C1 (ev 3240): a list the page's script could not read. The script shows it, turns saving
		// off and never sends an empty list in its place. The link is the one of 'Tell us' on Status.
		$report_open = '<a href="' . esc_url( CX_Sidrena_Admin_Status::report_links()['bug']['url'] ) . '" target="_blank" rel="noopener noreferrer">';
		$load_errors = array(
			'catalog' => sprintf( esc_html__( 'The items did not load, so they cannot be edited or saved here right now. Your saved items and your price list have not changed. Refresh the page. If this message comes back, %1$stell us%2$s.', 'cx-sidrena' ), $report_open, '</a>' ),
			'upit'    => sprintf( esc_html__( 'The services with price by agreement did not load, so they cannot be edited or saved here right now. What you saved and your price list have not changed. Refresh the page. If this message comes back, %1$stell us%2$s.', 'cx-sidrena' ), $report_open, '</a>' ),
		);
		$items_refused = CX_Sidrena_Admin::items_refused_notice();
		?>
		<?php if ( null !== $items_refused ) : ?>
			<?php // Norman C3: at the part the page lands on, not only at the top. ?>
			<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $items_refused ); ?></p></div>
		<?php endif; ?>
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
				<input type="hidden" name="sidrena_cijena_catalog_json" id="sidrena-cijena-catalog-json" value="<?php echo CX_Sidrena_Admin::json_attr( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() in json_attr() ?>" />
				<input type="hidden" name="sidrena_cijena_upit_json" id="sidrena-cijena-upit-json" value="<?php echo CX_Sidrena_Admin::json_attr( $upit_rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() in json_attr() ?>" />
				<?php // CX-SID-142: the script sets _loaded once it has read both lists, and _cleared for a list the owner emptied. Without them the save is refused. ?>
				<input type="hidden" name="sidrena_cijena_catalog_loaded" id="sidrena-cijena-catalog-loaded" value="" />
				<input type="hidden" name="sidrena_cijena_catalog_cleared" id="sidrena-cijena-catalog-cleared" value="" />
				<input type="hidden" name="sidrena_cijena_upit_cleared" id="sidrena-cijena-upit-cleared" value="" />

				<?php // The script makes it a red notice when the list did not load; until then it is no notice at all (an empty hidden notice-error read as an error on the page). ?>
				<div class="sidrena-cijena-load-error" id="sidrena-cijena-catalog-load-error" role="alert" hidden data-cx-message="<?php echo esc_attr( $load_errors['catalog'] ); ?>"></div>

				<div class="sidrena-cijena-catalog-toolbar">
					<div class="sidrena-cijena-catalog-toolbar__start">
						<?php if ( CX_Sidrena_Settings::instance()->is_multi_poslovnica() ) : ?>
						<label class="sidrena-cijena-catalog-scope" for="sidrena-cijena-catalog-scope">
							<span><?php echo esc_html__( 'Outlet', 'cx-sidrena' ); ?></span>
							<select id="sidrena-cijena-catalog-scope">
								<option value=""><?php echo esc_html__( 'Webshop', 'cx-sidrena' ); ?></option>
								<?php foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) : ?>
									<?php $outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka']; ?>
									<option value="<?php echo esc_attr( $outlet['oznaka'] ); ?>"><?php echo CX_Sidrena_Feed_Files::instance()->text_html( $outlet_name, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as typed (CX-SID-142) ?></option>
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
								<?php // The columns every row needs first, so they are in view without scrolling (CX-SID-100); admin-catalog.js rowHtml in the same order. ?>
								<th><?php echo esc_html__( 'Name', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'URL', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Regular price', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Date of bazna cijena', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Shortcode', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Sale price', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Type', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'SKU', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Brand', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Special form', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></th>
								<th><?php echo esc_html__( 'Price per unit', 'cx-sidrena' ); ?></th>
								<?php if ( CX_Sidrena_Settings::instance()->is_cjm_enabled() ) : ?>
								<th><?php echo esc_html__( 'CJM unit', 'cx-sidrena' ); ?></th>
								<?php endif; ?>
								<th><?php echo esc_html__( 'Availability', 'cx-sidrena' ); ?></th>
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

				<div class="sidrena-cijena-upit-block" id="cx-upit">
					<?php // CX-SID-76: Kai's two sentences (1584 art. 4(2), ZZP art. 7(1)), then where they are shown (Norman). ?>
					<h3 class="sidrena-cijena-upit-head"><?php echo esc_html__( 'Services with price by agreement', 'cx-sidrena' ); ?></h3>
					<p class="description"><?php echo esc_html__( 'Only for services whose price is set case by case. A service with a known price must be in the price list with its price. They are shown only on the price list page, under the price list; the price list file does not have them.', 'cx-sidrena' ); ?></p>
					<div class="sidrena-cijena-load-error" id="sidrena-cijena-upit-load-error" role="alert" hidden data-cx-message="<?php echo esc_attr( $load_errors['upit'] ); ?>"></div>
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
									<th><?php echo esc_html__( 'Type', 'cx-sidrena' ); ?></th>
									<th><span class="screen-reader-text"><?php echo esc_html__( 'Actions', 'cx-sidrena' ); ?></span></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>

				<?php $this->render_save_button( $woo ? __( 'Save items', 'cx-sidrena' ) : __( 'Save price list', 'cx-sidrena' ) ); ?>
				<?php // Norman C2: under the button, because on a phone C1 is far above it. ?>
				<p class="description sidrena-cijena-save-off" id="sidrena-cijena-catalog-save-off" hidden><?php echo esc_html__( 'Saving is off because not everything loaded. See the message above.', 'cx-sidrena' ); ?></p>
			</form>
		</section>
		<?php
