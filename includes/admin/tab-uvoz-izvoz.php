<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		// With WooCommerce the bazna cijena is imported only through Import (CX-SID-22), with the client's confirmation;
		// MJDD's bulk import wrote it, its date and the stock without one (CX-SID-43). Without WooCommerce it is the only
		// way to load the price list's own rows, so there it stays, with its CSV template.
		$bulk_import = ! CX_Sidrena_Plugin::woocommerce_active();
		if ( $bulk_import ) :
			?>
		<section class="sidrena-cijena-card sidrena-cijena-import" id="sidrena-cijena-bulk-import">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Bulk import', 'cx-sidrena' ); ?></h2>
				<p><?php echo esc_html( CX_Sidrena_Plugin::woocommerce_active() ? __( 'Upload CSV or XML. Rows are matched first by SKU; if SKU does not find a product, then by the existing ID column. Import to the webshop updates the Woo product, including stock. Import to another outlet writes availability only for that outlet. If neither SKU nor ID matches an existing product, the row becomes a manual item for that outlet.', 'cx-sidrena' ) : __( 'Upload a CSV or XML file. Rows are matched first by SKU in the selected outlet, then by ID. Other rows are added to that price list. Name, regular price and bazna cijena are required for new rows. Empty fields do not delete existing values.', 'cx-sidrena' ) ); ?></p>
			</div>

			<div class="sidrena-cijena-drop" id="sidrena-import-drop">
				<input type="file" id="sidrena-import-file" accept=".csv,.xml,.txt,text/csv,text/xml,application/xml" />
				<label for="sidrena-import-file" class="sidrena-cijena-drop__label">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<strong><?php echo esc_html__( 'Select CSV or XML', 'cx-sidrena' ); ?></strong>
					<em><?php echo esc_html__( 'or drop the file here', 'cx-sidrena' ); ?></em>
				</label>
				<p class="sidrena-cijena-import-filemeta" id="sidrena-import-filemeta" hidden></p>
				<p class="sidrena-cijena-actions">
					<button type="button" class="button button-primary" id="sidrena-import-analyze">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php echo esc_html__( 'Upload and map fields', 'cx-sidrena' ); ?>
					</button>
				</p>
			</div>

			<div class="sidrena-cijena-import-map" id="sidrena-import-map" hidden>
				<div class="sidrena-cijena-mapgrid">
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'SKU', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-sku"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'ID', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-id"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Name', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-name"></select>
					</label>
					<label class="sidrena-cijena-mapfield sidrena-import-standalone-only" hidden>
						<span><?php echo esc_html__( 'URL', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-url"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Regular price', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-regular"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Sale price', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-sale"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-sidrena"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Bazna cijena date', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-datum"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Type', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-vrsta"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Outlet', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-poslovnica"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Unit of measure', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-jedinica"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Price per unit', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-jedinica-cijena"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Brand', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-marka"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Special form', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-oblik"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'CJM', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-cjm"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'CJM suffix', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-cjm-suffix"></select>
					</label>
					<label class="sidrena-cijena-mapfield">
						<span><?php echo esc_html__( 'Availability', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-map-dostupnost"></select>
					</label>
				</div>

				<div class="sidrena-cijena-preview-wrap">
					<p class="sidrena-cijena-urlbox__label"><?php echo esc_html__( 'Preview', 'cx-sidrena' ); ?></p>
					<div class="sidrena-cijena-preview" id="sidrena-import-preview"></div>
				</div>

				<p class="sidrena-cijena-actions">
					<label class="sidrena-cijena-catalog-scope" for="sidrena-import-poslovnica">
						<span><?php echo esc_html__( 'Import to outlet', 'cx-sidrena' ); ?></span>
						<select id="sidrena-import-poslovnica" required>
							<option value=""><?php echo esc_html__( 'Select outlet', 'cx-sidrena' ); ?></option>
							<option value="webshop"><?php echo esc_html__( 'Webshop', 'cx-sidrena' ); ?></option>
							<?php foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) : ?>
								<?php $outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka']; ?>
								<option value="<?php echo esc_attr( $outlet['oznaka'] ); ?>"><?php echo esc_html( $outlet_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button button-primary" id="sidrena-import-run">
						<span class="dashicons dashicons-database-import" aria-hidden="true"></span>
						<?php echo esc_html__( 'Start import', 'cx-sidrena' ); ?>
					</button>
				</p>
			</div>

			<div class="sidrena-cijena-progress" id="sidrena-import-progress" hidden>
				<div class="sidrena-cijena-progress__track">
					<div class="sidrena-cijena-progress__bar" id="sidrena-import-bar"></div>
				</div>
				<p class="sidrena-cijena-progress__text" id="sidrena-import-status"></p>
			</div>

			<div class="sidrena-cijena-import-result" id="sidrena-import-result" hidden></div>
		</section>
			<?php
		endif;
		?>

		<section class="sidrena-cijena-card">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Price list export', 'cx-sidrena' ); ?></h2>
				<p><?php echo esc_html__( 'The price list export is the same public CSV or XML: type, date, retail price, brand, whether the item is in a special form of sale and its name, unit of measure, unit price, bazna cijena, barcode, and availability when it is turned on in Settings. Only what a visitor can buy is included, with a retail price. Variations are separate rows, and additional items go at the end. The export without bazna cijena stays an internal list (name, SKU, regular, sale and bazna cijena).', 'cx-sidrena' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'uvoz-izvoz' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_export', 'sidrena_cijena_export_nonce' ); ?>
				<p class="sidrena-cijena-field">
					<label for="sidrena_cijena_export_format"><?php echo esc_html__( 'Format', 'cx-sidrena' ); ?></label>
					<select id="sidrena_cijena_export_format" name="sidrena_cijena_export_format">
						<option value="csv">CSV</option>
						<option value="xml">XML</option>
					</select>
				</p>
				<p class="sidrena-cijena-actions">
					<button type="submit" class="button button-primary" name="sidrena_cijena_action" value="export_cjenik">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php echo esc_html__( 'Export price list', 'cx-sidrena' ); ?>
					</button>
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="export_missing">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php echo esc_html__( 'Export products without bazna cijena', 'cx-sidrena' ); ?>
					</button>
					<?php if ( $bulk_import ) : ?>
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="export_template">
						<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
						<?php echo esc_html__( 'Download CSV template', 'cx-sidrena' ); ?>
					</button>
					<?php endif; ?>
				</p>
				<?php if ( $bulk_import ) : ?>
				<p class="description"><?php echo esc_html__( 'The template has a header, one sample product, and one sample service (type, date, outlet).', 'cx-sidrena' ); ?></p>
				<?php endif; ?>
			</form>
		</section>
		<?php
