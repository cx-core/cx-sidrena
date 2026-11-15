<?php
/**
 * Settings tab markup. Included from CX_Sidrena_Admin.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

		$export->maybe_ensure_public_xml();

		$settings     = CX_Sidrena_Settings::instance();
		$format       = $settings->export_format();
		$open_label   = 'csv' === $format ? __( 'Open CSV', 'cx-sidrena' ) : __( 'Open XML', 'cx-sidrena' );
		$xml_url      = $export->get_public_url();
		$archive_url  = $export->get_archive_url();
		$xml_meta     = $export->get_public_meta();
		$next_daily   = $export->get_next_daily_timestamp();
		?>
		<section class="sidrena-cijena-card sidrena-cijena-feeds">
			<div class="sidrena-cijena-card__head">
				<h2><?php echo esc_html__( 'Public price list', 'cx-sidrena' ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$settings->is_multi_poslovnica()
							? __( 'The webshop has its own public price list (XML or CSV) and a 30-day archive. Each outlet has a separate file: the Woo products you add to it, plus manual rows (services, shipping) with its label. A daily cron after 00:00 regenerates the webshop and the outlets. The outlet type, address, label and storage number are entered on the Outlets tab.', 'cx-sidrena' )
							: __( 'The price list of published products and variations is public at a fixed address (XML or CSV). A daily cron after 00:00 regenerates the file and stores it in a 30-day archive under the official name, so it is available before 08:00. The outlet type, address, label and storage number of the webshop are entered on the Outlets tab. Barcode (GTIN/UPC/EAN/ISBN) and availability (dostupno/nedostupno, from stock) are always exported with the prices.', 'cx-sidrena' )
					);
					?>
				</p>
			</div>

			<div class="sidrena-cijena-feeds__grid">
			<?php
			$this->render_url_box(
				$settings->is_multi_poslovnica() ? __( 'Webshop', 'cx-sidrena' ) : __( 'Address', 'cx-sidrena' ),
				$xml_url,
				$xml_url,
				$open_label,
				'button button-primary',
				'dashicons-external',
				'sidrena-cijena-public-url'
			);

			$cjenik_url = $export->get_cjenik_url();
			$this->render_url_box(
				__( 'HTML price list', 'cx-sidrena' ),
				$cjenik_url,
				$cjenik_url,
				__( 'Open price list', 'cx-sidrena' ),
				'button button-secondary',
				'dashicons-external',
				'sidrena-cijena-cjenik-url'
			);

			$this->render_url_box(
				__( 'Archive', 'cx-sidrena' ),
				$archive_url,
				$archive_url,
				__( 'Open archive', 'cx-sidrena' ),
				'button button-secondary',
				'dashicons-archive',
				'sidrena-cijena-archive-url'
			);

			if ( $settings->is_multi_poslovnica() ) {
				foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
					$outlet_url  = $export->get_public_url( '', $outlet['oznaka'] );
					$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
					$this->render_url_box( $outlet_name, $outlet_url, $outlet_url, $open_label );
				}
			}

			$this->render_archive_shortcode_docs();
			$this->render_cjenik_shortcode_docs( $settings );
			?>
			</div>

			<?php if ( ! $settings->is_multi_poslovnica() && ! empty( $xml_meta['generated'] ) ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: datetime, 2: product count */
							__( 'Last generation: %1$s (%2$d products/variations).', 'cx-sidrena' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $xml_meta['generated'] ),
							isset( $xml_meta['count'] ) ? (int) $xml_meta['count'] : 0
						)
					);
					?>
				</p>
			<?php elseif ( ! $settings->is_multi_poslovnica() ) : ?>
				<p class="description"><?php echo esc_html__( 'The file has not been generated yet. Click the button or open the public URL—the price list will then be created.', 'cx-sidrena' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $this->get_settings_tab_url( 'javni-xml' ) ); ?>">
				<?php wp_nonce_field( 'sidrena_cijena_save_xml', 'sidrena_cijena_xml_nonce' ); ?>
				<p>
					<label><?php echo esc_html__( 'Export format', 'cx-sidrena' ); ?></label>
				</p>
				<p class="sidrena-cijena-field">
					<fieldset class="sidrena-cijena-choices">
						<label class="sidrena-cijena-choice">
							<input type="radio" name="sidrena_cijena_public_format" value="xml" <?php checked( $format, 'xml' ); ?> />
							<span>XML</span>
						</label>
						<label class="sidrena-cijena-choice">
							<input type="radio" name="sidrena_cijena_public_format" value="csv" <?php checked( $format, 'csv' ); ?> />
							<span>CSV</span>
						</label>
					</fieldset>
				</p>
				<p class="description"><?php echo esc_html__( 'Changes only the current public link and new generations. Files in the archive remain in the format they were created in.', 'cx-sidrena' ); ?></p>

				<p class="description">
					<?php
					echo esc_html__( 'The outlet type, address, label and storage number for the webshop and additional outlets are entered on the Outlets tab.', 'cx-sidrena' );
					echo ' ';
					?>
					<a href="<?php echo esc_url( $this->get_settings_tab_url( 'poslovnice' ) ); ?>"><?php echo esc_html__( 'Open the Outlets tab.', 'cx-sidrena' ); ?></a>
				</p>

				<p>
					<?php
					$this->render_toggle(
						'cx_sidrena_daily_xml',
						'cx_sidrena_daily_xml',
						'yes' === $settings->get( 'daily_xml' ),
						__( 'Regenerate public price list daily after 00:00', 'cx-sidrena' )
					);
					?>
				</p>
				<?php if ( 'yes' === $settings->get( 'daily_xml' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) : ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html__( 'Daily generation at 00:05 depends on WordPress cron. It runs only on the next request that loads PHP, so on a low-traffic store or behind full-page cache the feed may be delayed after 08:00. In wp-config.php set DISABLE_WP_CRON to true and from system cron call wp-cron.php every 5 to 15 minutes.', 'cx-sidrena' ); ?></p>
					</div>
				<?php endif; ?>
				<?php
				$generated_at = ! empty( $xml_meta['generated'] ) ? (int) $xml_meta['generated'] : 0;
				$feed_stale   = 'yes' === $settings->get( 'daily_xml' ) && ( $generated_at < 1 || ( time() - $generated_at ) > ( DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS ) );
				?>
				<?php if ( $feed_stale ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							if ( $generated_at > 0 ) {
								echo esc_html(
									sprintf(
										/* translators: %s: datetime */
										__( 'Last successful generation (%s) is more than 26 hours old. The public price list may not be ready before 08:00.', 'cx-sidrena' ),
										wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at )
									)
								);
							} else {
								echo esc_html__( 'The public price list has not been generated yet. Until the file exists, the HTML page and shortcode show that the price list is being prepared.', 'cx-sidrena' );
							}
							?>
						</p>
					</div>
				<?php elseif ( $generated_at > 0 ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: datetime */
								__( 'Last successful generation: %s.', 'cx-sidrena' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $generated_at )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					echo esc_html__( 'Enabled by default. After 00:05 (WordPress timezone) the public price list is regenerated so it is available before the legal deadline of 08:00, and stored in the archive under the official name (max 30 files).', 'cx-sidrena' );
					echo ' ';
					if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
						echo esc_html__( 'DISABLE_WP_CRON is enabled, so system cron at 00:05 calls wp-cron.php.', 'cx-sidrena' );
					} else {
						echo esc_html__( 'WordPress cron runs that job only on the next PHP request.', 'cx-sidrena' );
					}
					?>
				</p>
				<?php if ( $next_daily ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: datetime */
								__( 'Next automatic generation: %s.', 'cx-sidrena' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_daily )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p class="sidrena-cijena-actions">
					<button type="submit" class="button button-secondary" name="sidrena_cijena_action" value="save_xml_settings">
						<?php echo $this->save_icon_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
						<?php echo esc_html__( 'Save', 'cx-sidrena' ); ?>
					</button>
					<button type="submit" class="button button-primary" name="sidrena_cijena_action" value="regenerate_public_xml">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo esc_html__( 'Generate public price list', 'cx-sidrena' ); ?>
					</button>
				</p>
			</form>
		</section>
		<?php
