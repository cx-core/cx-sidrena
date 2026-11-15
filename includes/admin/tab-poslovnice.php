<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$settings = CX_Sidrena_Settings::instance();
		$export   = CX_Sidrena_Export::instance();
		$rows     = CX_Sidrena_Poslovnice::instance()->all();
		$json     = wp_json_encode( $rows );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		$adresa = (string) $settings->get( 'file_adresa', '' );
		?>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p><?php echo esc_html__( 'The webshop is the default outlet: its type, address, label and storage number go into the official file name (NN 101/2026). Add more outlets below. Each one gets its own public XML or CSV and a 30-day archive. Assign manual items (services, shipping) on the first tab by choosing an outlet above the table. Deleting an outlet also deletes those items.', 'cx-sidrena' ); ?></p>
		</div>

		<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'poslovnice' ) ); ?>" id="sidrena-poslovnice-form">
			<?php wp_nonce_field( 'sidrena_cijena_save_poslovnice', 'sidrena_cijena_poslovnice_nonce' ); ?>
			<input type="hidden" name="sidrena_cijena_action" value="save_poslovnice" />
			<input type="hidden" name="sidrena_cijena_poslovnice_json" id="sidrena-poslovnice-json" value="<?php echo esc_attr( $json ); ?>" />

			<section class="sidrena-cijena-card">
				<div class="sidrena-cijena-card__head">
					<h2><?php echo esc_html__( 'Webshop', 'cx-sidrena' ); ?></h2>
					<p><?php echo esc_html__( 'Webshop public price list: /sidrena-cijena.xml (or .csv). All published products and manual rows without an outlet label.', 'cx-sidrena' ); ?></p>
				</div>

				<article class="sidrena-poslovnica sidrena-poslovnica--webshop">
					<div class="sidrena-poslovnica__grid">
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oblik"><?php echo esc_html__( 'Outlet type', 'cx-sidrena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_file_oblik" name="sidrena_cijena_file_oblik" value="<?php echo esc_attr( (string) $settings->get( 'file_oblik', 'webshop' ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Written exactly as it should appear, e.g. webshop or prodavaonica.', 'cx-sidrena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_adresa"><?php echo esc_html__( 'Outlet address', 'cx-sidrena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_file_adresa" name="sidrena_cijena_file_adresa" value="<?php echo esc_attr( $adresa ); ?>" placeholder="<?php echo esc_attr( $settings->default_file_address() ); ?>" />
							<span class="description"><?php echo esc_html__( 'Street and city remain in the name, e.g. Ilica 150 Zagreb.', 'cx-sidrena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oznaka"><?php echo esc_html__( 'Outlet label', 'cx-sidrena' ); ?></label>
							<input type="text" class="small-text" id="sidrena_cijena_file_oznaka" name="sidrena_cijena_file_oznaka" value="<?php echo esc_attr( (string) $settings->get( 'file_oznaka', '001' ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Internal label, e.g. WEB or 001. The hyphen is kept.', 'cx-sidrena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_pohrana"><?php echo esc_html__( 'Next storage number', 'cx-sidrena' ); ?></label>
							<input type="number" class="small-text" id="sidrena_cijena_file_pohrana" name="sidrena_cijena_file_pohrana" min="1" step="1" value="<?php echo esc_attr( (string) (int) $settings->get( 'file_pohrana', 1 ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Increases once per day. Regenerating on the same day does not increase the number. The name uses what you enter, e.g. 104.', 'cx-sidrena' ); ?></span>
						</p>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: example filename */
								__( 'Next official name: %s (Ministry example: prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45). On a Windows server the time uses a hyphen, 07-45.', 'cx-sidrena' ),
								$export->legal_filename()
							)
						);
						?>
					</p>

					<div class="sidrena-poslovnice">
						<div class="sidrena-poslovnice__head">
							<h3><?php echo esc_html__( 'Additional outlets', 'cx-sidrena' ); ?></h3>
							<button type="button" class="button button-secondary" id="sidrena-poslovnica-add">
								<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
								<?php echo esc_html__( 'Add outlet', 'cx-sidrena' ); ?>
							</button>
						</div>
						<p class="description"><?php echo esc_html__( 'Each outlet gets the URL /sidrena-cijena-LABEL.xml (or .csv) and its own archive of 30 files.', 'cx-sidrena' ); ?></p>
						<div id="sidrena-poslovnice"></div>
					</div>
				</article>
			</section>

			<?php $this->render_save_button( __( 'Save outlets', 'cx-sidrena' ) ); ?>
		</form>
		<?php
