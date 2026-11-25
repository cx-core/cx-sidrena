<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$settings = CX_Sidrena_Settings::instance();
		$export   = CX_Sidrena_Export::instance();
		$archive  = CX_Sidrena_Archive::instance();
		// The storage numbers shown are the real next ones (counter row, old value, archive), read past any database
		// query cache: the counter only goes up, so the form must not show, and send back, an old one (CX-SID-119 dopuna).
		CX_Sidrena_Archive::no_db_cache();
		// The places with dodjela and "objavljeno" (a published code is read-only, D1) and without their availability,
		// which the form never sends back (CX-SID-74, Argus V3).
		$places = CX_Sidrena_Poslovnice::instance();
		$rows   = $places->form_rows();
		$loaded = array();
		// When and how many items a place's price list was imported from a file (CX-SID-181 targets(), for T72 on the
		// form, CX-SID-185). Only shown: the form never sends it back.
		$imported = null;
		if ( CX_Sidrena_Plugin::woocommerce_active() && class_exists( 'CX_Sidrena_Bulk_Import' ) && method_exists( CX_Sidrena_Bulk_Import::instance(), 'targets' ) ) {
			$imported = array();
			$targets = CX_Sidrena_Admin::targets_for_screen( CX_Sidrena_Bulk_Import::instance()->targets() );
			foreach ( isset( $targets['places'] ) && is_array( $targets['places'] ) ? $targets['places'] : array() as $target ) {
				if ( isset( $target['oznaka'] ) ) {
					$imported[ strtoupper( (string) $target['oznaka'] ) ] = isset( $target['imported'] ) ? $target['imported'] : null;
				}
			}
		}
		foreach ( $rows as $i => $row ) {
			if ( null !== $imported ) {
				$rows[ $i ]['imported'] = isset( $imported[ strtoupper( (string) $row['oznaka'] ) ] ) ? $imported[ strtoupper( (string) $row['oznaka'] ) ] : null;
			}
			$rows[ $i ]['pohrana'] = $archive->next_pohrana( $row['oznaka'], $row );
			// Manual rows of its own (services, rows by agreement): such a place has a price list with no product, so
			// "Selected" with nothing picked is not an error for it (Norman, M4 on S10). Rows by agreement alone count
			// too: they are its page, with no file (CX-SID-145, has_offer()).
			$rows[ $i ]['vlastiti'] = $places->has_offer( array_merge( $row, array( 'dodjela' => 'odabrani', 'products' => array() ) ) );
			if ( '' !== $row['id'] ) {
				$loaded[] = $row['id'];
			}
		}
		$web_next = $archive->next_pohrana( $settings->normalize_oznaka( (string) $settings->get( 'file_oznaka', '001' ) ) ?: '001' );
		// A place's name with < & " or an entity (&quot;) would break the JSON in the browser after esc_attr; with the HEX
		// flags no such character is left in it (CX-SID-142, Rook: inline until Tux's CX_Sidrena_Admin::json_attr() is
		// on this branch, then replaced by it). If it cannot be encoded, the form gets nothing it could parse and stays
		// off (admin-outlets.js), never an empty list that a save would store.
		$json = wp_json_encode( $rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) {
			$json = '';
		}
		// The ids of the places this page shows: a place the owner removes here is deleted, one added meanwhile in another
		// tab stays (CX-SID-74).
		$loaded_json = wp_json_encode( $loaded );
		if ( ! is_string( $loaded_json ) ) {
			$loaded_json = '[]';
		}
		$woo = CX_Sidrena_Plugin::woocommerce_active();

		?>
		<?php // A refused save (cx_outlet_error): the form puts back what was sent and says why here (CX-SID-131). ?>
		<div id="sidrena-poslovnice-refused" class="cx-field__msg sidrena-poslovnice__refused" role="alert" hidden></div>
		<?php // The places could not be read (CX-SID-142): the form stays off and says so (Norman C1). ?>
		<div id="sidrena-poslovnice-unreadable" class="cx-field__msg sidrena-poslovnice__refused" role="alert" hidden>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: opening link to the bug report, 2: closing link */
					esc_html__( 'The outlets did not load, so they cannot be edited or saved here right now. Your saved outlets and their price lists have not changed. Refresh the page. If this message comes back, %1$stell us%2$s.', 'cx-sidrena' ),
					'<a href="' . esc_url( CX_Sidrena_Admin_Status::report_links()['bug']['url'] ) . '" target="_blank" rel="noopener noreferrer">',
					'</a>'
				),
				array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
			);
			?>
		</div>
		<div class="sidrena-cijena-callout">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the heading of the section with the manual items ("Additional items" or "Products") */
						__( 'The Pravilnik (NN 132/2026-1584) requires a price list for each outlet. The webshop is the first outlet. If you also sell elsewhere, for example in a shop, add that outlet below. Each outlet has its own public price list at a permanent address and its own archive: a price list stays public for 15 days after a newer one replaces it, as the Pravilnik requires. Assign manual items (services, shipping) to an outlet with the choice above the table in %s. Removing an outlet also deletes its manual items, and the archive of its price lists stays.', 'cx-sidrena' ),
						$woo ? __( 'Additional items', 'cx-sidrena' ) : __( 'Products', 'cx-sidrena' )
					)
				);
				?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'poslovnice' ) ); ?>" id="sidrena-poslovnice-form">
			<?php wp_nonce_field( 'sidrena_cijena_save_poslovnice', 'sidrena_cijena_poslovnice_nonce' ); ?>
			<input type="hidden" name="sidrena_cijena_action" value="save_poslovnice" />
			<input type="hidden" name="sidrena_cijena_poslovnice_json" id="sidrena-poslovnice-json" value="<?php echo esc_attr( $json ); ?>" />
			<input type="hidden" name="sidrena_cijena_poslovnice_loaded" id="sidrena-poslovnice-loaded" value="<?php echo esc_attr( $loaded_json ); ?>" />
			<?php // Set by the form's script only when it read the places and sends them (Tux, CX-SID-142): empty = not confirmed. ?>
			<input type="hidden" name="sidrena_cijena_poslovnice_ok" id="sidrena-poslovnice-ok" value="" />
			<input type="hidden" name="sidrena_cijena_poslovnice_cleared" id="sidrena-poslovnice-cleared" value="" />

			<?php if ( array() !== $rows ) : ?>
				<?php // CX-SID-113 (Norman, SPEC-UVOZ §6, T73, T74, T76): only with a place; _shown says this page has the box. ?>
				<section class="sidrena-cijena-card sidrena-isti-cjenik" id="cx-isti-cjenik">
					<input type="hidden" name="sidrena_cijena_isti_cjenik_shown" value="1" />
					<p>
						<label for="sidrena_cijena_isti_cjenik">
							<input type="checkbox" id="sidrena_cijena_isti_cjenik" name="sidrena_cijena_isti_cjenik" value="yes"<?php checked( $settings->is_isti_cjenik() ); ?> />
							<?php echo esc_html__( 'The same price list for all places of sale', 'cx-sidrena' ); ?>
						</label>
					</p>
					<p class="description"><?php echo esc_html__( 'Turn this on only if every place of sale has the same prices as the webshop. Then one price list is published for all of them, with ZA_SVA_PRODAJNA_MJESTA in its name. The plugin does not check the prices in your shops.', 'cx-sidrena' ); ?></p>
					<p class="description"><?php echo esc_html__( "While this is on, every place of sale publishes the webshop's whole price list, whatever products were selected for it.", 'cx-sidrena' ); ?></p>
				</section>
			<?php endif; ?>

			<section class="sidrena-cijena-card">
				<div class="sidrena-cijena-card__head">
					<h2><?php echo esc_html__( 'Webshop', 'cx-sidrena' ); ?></h2>
					<p><?php echo esc_html__( 'The webshop\'s price list is at the permanent address /sidrena-cijena.xml (or .csv). It has all published products and the manual items that are not assigned to another outlet.', 'cx-sidrena' ); ?></p>
				</div>

				<article class="sidrena-poslovnica sidrena-poslovnica--webshop">
					<div class="sidrena-poslovnica__grid">
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oblik"><?php echo esc_html__( 'Outlet type', 'cx-sidrena' ); ?></label>
							<input type="text" class="regular-text" id="sidrena_cijena_file_oblik" name="sidrena_cijena_file_oblik" value="<?php echo esc_attr( (string) $settings->get( 'file_oblik', 'webshop' ) ); ?>" />
							<span class="description"><?php echo esc_html__( 'Goes into the file name exactly as written, e.g. webshop.', 'cx-sidrena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_oznaka"><?php echo esc_html__( 'Outlet label', 'cx-sidrena' ); ?></label>
							<input type="text" class="small-text" id="sidrena_cijena_file_oznaka" name="sidrena_cijena_file_oznaka" value="<?php echo esc_attr( (string) $settings->get( 'file_oznaka', '001' ) ); ?>" />
							<span class="cx-field__msg js-msg" id="sidrena_cijena_file_oznaka-msg" hidden></span>
							<span class="description"><?php echo esc_html__( 'E.g. 001: letters without diacritics, digits and hyphens. Goes into the file name.', 'cx-sidrena' ); ?></span>
						</p>
						<p class="sidrena-poslovnica__field">
							<label for="sidrena_cijena_file_pohrana"><?php echo esc_html__( 'Next storage number', 'cx-sidrena' ); ?></label>
							<input type="number" class="small-text" id="sidrena_cijena_file_pohrana" name="sidrena_cijena_file_pohrana" min="<?php echo esc_attr( (string) (int) $web_next ); ?>" step="1" value="<?php echo esc_attr( (string) (int) $web_next ); ?>" />
							<span class="description"><?php echo esc_html__( 'Goes up by one with each new price list file. You can raise it, but not lower it.', 'cx-sidrena' ); ?></span>
						</p>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the name of the next price list file */
								__( 'Next official name: %s', 'cx-sidrena' ),
								// One price list for all places (CX-SID-113): its name, with the webshop's number.
								$settings->is_isti_cjenik() ? $export->legal_filename( '', 0, 0, CX_Sidrena_Archive::ALL_PLACES ) : $export->legal_filename()
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
						<p class="description"><?php echo esc_html__( 'Each outlet\'s price list is at the permanent address /sidrena-cijena-LABEL.xml (or .csv).', 'cx-sidrena' ); ?></p>
						<div id="sidrena-poslovnice"></div>
					</div>
				</article>
			</section>

			<?php $this->render_save_button( __( 'Save outlets', 'cx-sidrena' ) ); ?>
			<p id="sidrena-poslovnice-saveoff" class="description" hidden><?php echo esc_html__( 'Saving is off because not everything loaded. See the message above.', 'cx-sidrena' ); ?></p>
		</form>
		<?php
