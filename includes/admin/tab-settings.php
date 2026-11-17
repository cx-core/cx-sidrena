<?php
/**
 * Settings tab markup (spec §1.8): the only place the plugin is set up. Included from CX_Sidrena_Admin.
 *
 * One form (#cx-settings-form) for every setting on this page; its fields sit in the sections below and name the form
 * with the form attribute, because Advanced also holds MJDD's outlet and additional-item editors, which are forms of
 * their own. Dates per category (CX-SID-42) are the first section.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

$settings   = CX_Sidrena_Settings::instance();
$export     = CX_Sidrena_Export::instance();
$woo        = CX_Sidrena_Plugin::woocommerce_active();
$format     = $settings->export_format();
$xml_url    = $export->get_public_url();
$xml_meta   = $export->get_public_meta();
$next_daily = $export->get_next_daily_timestamp();
$f          = 'cx-settings-form';
$open_label = 'csv' === $format ? __( 'Open CSV', 'cx-sidrena' ) : __( 'Open XML', 'cx-sidrena' );
$sample     = CX_Sidrena_Money::customer_date( '10.09.2026.' );
?>
<form method="post" id="<?php echo esc_attr( $f ); ?>" action="<?php echo esc_url( $this->get_settings_tab_url( 'settings' ) ); ?>">
	<?php wp_nonce_field( 'cx_sidrena_settings', 'cx_sidrena_settings_nonce' ); ?>
	<input type="hidden" name="sidrena_cijena_action" value="save_main_settings" />
</form>

<?php if ( ! $settings->is_b2b_only() && '' === $settings->legal_id() ) : ?>
	<?php // CX-SID-110: the Status tab's yellow line here too; without WooCommerce there is no Status tab (Norman 110-2). ?>
	<p class="cx-status__line">
		<?php echo esc_html__( 'OIB, MBO or MIBPG is missing. The official name of the price list file must include it; now it does not.', 'cx-sidrena' ); ?>
		<a href="#cx-oib-row"><?php echo esc_html__( 'Enter it', 'cx-sidrena' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( $woo && class_exists( 'CX_Sidrena_Dates' ) ) : ?>
	<?php
	// Dates per category (spec §1.3, §1.8): the model is CX-SID-21's; this screen only shows and posts them.
	$dates_rows  = CX_Sidrena_Dates::rows();
	$dates_state = CX_Sidrena_Dates::state();
	$dates_names = array();
	foreach ( $dates_rows as $dr ) {
		$dates_names[ $dr['term_id'] ] = 0 === $dr['term_id'] ? __( 'Other goods', 'cx-sidrena' ) : $dr['name'];
	}
	?>
	<section class="sidrena-cijena-card cx-settings cx-dates" id="cx-dates">
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'Dates', 'cx-sidrena' ); ?></h2>
			<p><?php echo esc_html__( 'The date of the bazna cijena for each product category. A product added later gets the day it was added.', 'cx-sidrena' ); ?></p>
		</div>
		<?php if ( 'saved' === $dates_state['state'] ) : ?>
			<?php /* translators: 1: user name, 2: date */ ?>
			<p class="cx-dates__state"><?php echo esc_html( sprintf( __( 'Saved by %1$s on %2$s', 'cx-sidrena' ), $dates_state['by'], wp_date( 'j.n.Y.', (int) $dates_state['on'] ) ) ); ?></p>
		<?php elseif ( 'taken' === $dates_state['state'] ) : ?>
			<p class="cx-dates__state"><?php echo esc_html__( 'Taken from your existing data. Used only for products without a date.', 'cx-sidrena' ); ?></p>
		<?php else : ?>
			<p class="cx-dates__state cx-dates__state--suggested"><?php echo esc_html__( 'Suggested from category names. Check them and save.', 'cx-sidrena' ); ?></p>
		<?php endif; ?>
		<table class="widefat striped cx-dates__table">
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Category', 'cx-sidrena' ); ?></th>
				<th scope="col" class="cx-dates__count"><?php echo esc_html__( 'Products', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Date', 'cx-sidrena' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $dates_rows as $dr ) : ?>
					<?php
					$is_other = 0 === $dr['term_id'];
					$follows  = ! $is_other && ! $dr['set'] && $dr['parent'] && isset( $dates_names[ $dr['parent'] ] );
					$field    = $is_other ? 'cx_dates_other' : 'cx_dates[' . $dr['term_id'] . ']';
					?>
					<tr class="cx-dates__row<?php echo $is_other ? ' cx-dates__row--other' : ''; ?>" data-term="<?php echo esc_attr( (string) $dr['term_id'] ); ?>" data-parent="<?php echo esc_attr( (string) ( $is_other ? '' : $dr['parent'] ) ); ?>" data-set="<?php echo $follows ? '0' : '1'; ?>">
						<th scope="row" style="padding-left:<?php echo esc_attr( (string) ( 10 + 24 * (int) $dr['depth'] ) ); ?>px">
							<?php echo esc_html( $dates_names[ $dr['term_id'] ] ); ?>
							<?php if ( ! $is_other && $dr['parent'] && isset( $dates_names[ $dr['parent'] ] ) ) : ?>
								<?php /* translators: %s: parent category */ ?>
								<span class="cx-dates__follows"<?php echo $follows ? '' : ' hidden'; ?>><?php echo esc_html( sprintf( __( 'same as %s', 'cx-sidrena' ), $dates_names[ $dr['parent'] ] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $dr['check'] ) ) : ?>
								<?php // A category that may hold goods of both dates, while the Dates are only suggested (CX-SID-94). ?>
								<span class="cx-dates__check"><?php echo esc_html__( 'Check: this category may hold goods with either date', 'cx-sidrena' ); ?></span>
							<?php endif; ?>
						</th>
						<td class="cx-dates__count"><?php echo esc_html( (string) $dr['count'] ); ?></td>
						<td>
							<fieldset class="cx-segment">
								<?php /* translators: %s: category */ ?>
								<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Date for %s', 'cx-sidrena' ), $dates_names[ $dr['term_id'] ] ) ); ?></legend>
								<?php foreach ( CX_Sidrena_Dates::choices() as $choice ) : ?>
									<label class="cx-segment__item"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $choice ); ?>" <?php checked( $dr['date'], $choice ); ?> /><span><?php echo esc_html( CX_Sidrena_Money::customer_date( $choice ) ); ?></span></label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php echo esc_html__( 'Applies to products that get a bazna cijena from now on. Products that already have one keep their date.', 'cx-sidrena' ); ?></p>
		<p class="description"><?php echo esc_html__( 'A product on sale on its date: for 2.5.2025. the bazna cijena is the lowest price in the 30 days before, for 10.9.2026. the regular price before the sale.', 'cx-sidrena' ); ?></p>
	</section>
<?php endif; ?>

<section class="sidrena-cijena-card cx-settings" id="cx-label">
	<div class="sidrena-cijena-card__head">
		<h2><label for="sidrena_cijena_prefix"><?php echo esc_html__( 'Label shown to customers', 'cx-sidrena' ); ?></label></h2>
	</div>
	<p>
		<input type="text" form="<?php echo esc_attr( $f ); ?>" class="regular-text sidrena-cijena-prefix-input" id="sidrena_cijena_prefix" name="sidrena_cijena_prefix" value="<?php echo esc_attr( $settings->get( 'prefix' ) ); ?>" />
	</p>
	<p class="description"><?php echo esc_html__( 'Words only. The date is added per product.', 'cx-sidrena' ); ?></p>
	<div class="cx-settings__preview" aria-label="<?php echo esc_attr__( 'Preview:', 'cx-sidrena' ); ?>">
		<span class="cx-settings__preview-head"><?php echo esc_html__( 'Preview:', 'cx-sidrena' ); ?></span>
		<span class="cx-settings__preview-price">17,90 €</span>
		<span class="cx-settings__preview-line" id="sidrena-cijena-preview"><?php echo esc_html( $settings->format_prefix_with_date( (string) $settings->get( 'prefix' ), '10.09.2026.' ) . $settings->preview_separator() . '19,90 €' ); ?></span>
	</div>
	<?php $this->prefix_advice(); ?>
	<?php
	// Colour of the label line and the lowest-price caption (CX-SID-41): the shop's text colour, or the owner's own.
	$line_color = $settings->line_color();
	?>
	<fieldset class="cx-settings__color">
		<legend><?php echo esc_html__( 'Colour', 'cx-sidrena' ); ?></legend>
		<label class="cx-settings__color-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="cx_line_color_mode" value="theme" <?php checked( '', $line_color ); ?> /> <span><?php echo esc_html__( "Shop's text colour", 'cx-sidrena' ); ?></span></label>
		<label class="cx-settings__color-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="cx_line_color_mode" value="own" <?php checked( '' !== $line_color ); ?> /> <span><?php echo esc_html__( 'Own colour', 'cx-sidrena' ); ?></span>
			<input type="color" form="<?php echo esc_attr( $f ); ?>" name="cx_line_color" id="cx_line_color" value="<?php echo esc_attr( '' !== $line_color ? $line_color : '#666666' ); ?>" aria-label="<?php echo esc_attr__( 'Own colour', 'cx-sidrena' ); ?>" /></label>
		<p class="cx-settings__color-warn" id="cx-line-color-warn" hidden><?php echo esc_html__( 'Hard to read on a white background.', 'cx-sidrena' ); ?></p>
		<?php // The preview shows only the bazna line; the colour is also the lowest-price caption's (Norman, CX-SID-41). ?>
		<p class="description"><?php echo esc_html__( 'Also applies to the lowest-price note on sale products.', 'cx-sidrena' ); ?></p>
	</fieldset>
</section>

<?php if ( $woo && class_exists( 'CX_Sidrena_Badge' ) ) : ?>
	<?php
	$badge_mode    = CX_Sidrena_Badge::mode();
	$badge_default = CX_Sidrena_Badge::default_mode();
	$badge_choices = array(
		'percent' => array( __( 'Show percentage (calculated from the lowest price in 30 days)', 'cx-sidrena' ), '−12 %' ),
		'akcija'  => array( __( 'Show "Akcija" only', 'cx-sidrena' ), 'Akcija' ),
		'hide'    => array( __( 'Hide', 'cx-sidrena' ), '' ),
	);
	?>
	<section class="sidrena-cijena-card cx-settings" id="cx-badge">
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'Discount badge', 'cx-sidrena' ); ?></h2>
			<p><?php echo esc_html__( 'The badge on products on sale. Example: 7,90 € before, 6,90 € now.', 'cx-sidrena' ); ?></p>
		</div>
		<fieldset class="cx-settings__badges">
			<?php foreach ( $badge_choices as $value => $choice ) : ?>
				<label class="cx-settings__badge-choice">
					<input type="radio" form="<?php echo esc_attr( $f ); ?>" name="cx_discount_badge" value="<?php echo esc_attr( $value ); ?>" <?php checked( $badge_mode, $value ); ?> />
					<span><?php echo esc_html( $choice[0] ); ?></span>
					<?php if ( $badge_default === $value ) : ?>
						<span class="description"><?php echo esc_html__( '(default for your theme)', 'cx-sidrena' ); ?></span>
					<?php endif; ?>
					<span class="cx-settings__badge-preview" aria-hidden="true"><?php echo '' !== $choice[1] ? '<span class="cx-badge">' . esc_html( $choice[1] ) . '</span>' : '<span class="cx-badge cx-badge--none">' . esc_html__( 'no badge', 'cx-sidrena' ) . '</span>'; ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php echo esc_html__( 'A sale whose price is not lower than the lowest price in the last 30 days never gets a badge.', 'cx-sidrena' ); ?></p>
	</section>
<?php endif; ?>

<section class="sidrena-cijena-card cx-settings" id="cx-public">
	<div class="sidrena-cijena-card__head">
		<h2><?php echo esc_html__( 'Public price list', 'cx-sidrena' ); ?></h2>
	</div>
	<div class="sidrena-cijena-feeds__grid">
		<?php
		// CX-SID-110: a shop with goods and services publishes two files; then each has its box (Norman).
		if ( CX_Sidrena_Feed_Routes::instance()->scope_has_both( '' ) ) {
			$this->render_url_box( __( 'Price list file: goods', 'cx-sidrena' ), $xml_url, $xml_url, $open_label, 'button button-secondary', 'dashicons-external', 'sidrena-cijena-public-url' );
			$usluge_url = CX_Sidrena_Feed_Files::instance()->get_public_url( '', '', 'usluga' );
			$this->render_url_box( __( 'Price list file: services', 'cx-sidrena' ), $usluge_url, $usluge_url, $open_label, 'button button-secondary', 'dashicons-external', 'sidrena-cijena-public-url-usluge' );
		} else {
			$this->render_url_box( __( 'Price list file', 'cx-sidrena' ), $xml_url, $xml_url, $open_label, 'button button-secondary', 'dashicons-external', 'sidrena-cijena-public-url' );
		}
		$cjenik_url = $export->get_cjenik_url();
		$this->render_url_box( __( 'HTML price list', 'cx-sidrena' ), $cjenik_url, $cjenik_url, __( 'Open price list', 'cx-sidrena' ), 'button button-secondary', 'dashicons-external', 'sidrena-cijena-cjenik-url' );
		$archive_url = $export->get_archive_url();
		$this->render_url_box( __( 'Archive', 'cx-sidrena' ), $archive_url, $archive_url, __( 'Open archive', 'cx-sidrena' ), 'button button-secondary', 'dashicons-archive', 'sidrena-cijena-archive-url' );
		?>
	</div>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php echo esc_html__( 'Export format', 'cx-sidrena' ); ?></th>
			<td>
				<fieldset class="sidrena-cijena-choices">
					<label class="sidrena-cijena-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_public_format" value="xml" <?php checked( $format, 'xml' ); ?> /> <span>XML</span></label>
					<label class="sidrena-cijena-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_public_format" value="csv" <?php checked( $format, 'csv' ); ?> /> <span>CSV</span></label>
				</fieldset>
				<p class="description"><?php echo esc_html__( 'Changes only the current public link and new generations. Files in the archive remain in the format they were created in.', 'cx-sidrena' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="sidrena_cijena_file_adresa"><?php echo esc_html__( 'Shop address', 'cx-sidrena' ); ?></label></th>
			<td>
				<input type="text" form="<?php echo esc_attr( $f ); ?>" class="regular-text" id="sidrena_cijena_file_adresa" name="sidrena_cijena_file_adresa" value="<?php echo esc_attr( (string) $settings->get( 'file_adresa', '' ) ); ?>" placeholder="<?php echo esc_attr( $settings->default_file_address() ); ?>" />
				<p class="description"><?php echo esc_html__( 'Street and city, for the official name of the price list file. Empty: the store address from WooCommerce settings is used, or the web address.', 'cx-sidrena' ); ?></p>
			</td>
		</tr>
		<tr id="cx-oib-row">
			<th scope="row"><label for="sidrena_cijena_file_oib"><?php echo esc_html__( 'OIB, MBO or MIBPG', 'cx-sidrena' ); ?></label></th>
			<td>
				<?php
				// After a refused save the field shows what was typed, marked, so one wrong digit is fixed there; the stored
				// value is unchanged until a valid one is saved (Norman, CX-SID-110). The save lands on this row, and the
				// reason (refused, or saved with a warning) stands under the field, tied to it for screen readers (110-1).
				$oib_refused = isset( $_GET['cx_oib'] ) && 0 === strpos( sanitize_key( wp_unslash( $_GET['cx_oib'] ) ), 'refused' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$oib_value   = $oib_refused ? self::oib_typed() : (string) $settings->get( 'file_oib', '' );
				$oib_notice  = self::oib_notice();
				?>
				<input type="text" form="<?php echo esc_attr( $f ); ?>" class="regular-text<?php echo $oib_refused ? ' cx-field--invalid' : ''; ?>" id="sidrena_cijena_file_oib" name="sidrena_cijena_file_oib" inputmode="numeric" autocomplete="off" value="<?php echo esc_attr( $oib_value ); ?>"<?php echo $oib_refused ? ' aria-invalid="true"' : ''; ?><?php echo null !== $oib_notice ? ' aria-describedby="cx-oib-msg"' : ''; ?> />
				<?php if ( null !== $oib_notice ) : ?>
					<p class="cx-field__msg cx-field__msg--<?php echo esc_attr( $oib_notice[0] ); ?>" id="cx-oib-msg"><?php echo esc_html( $oib_notice[1] ); ?></p>
					<?php
					// "Save and generate now" with that save: what the build did, here too, since the page landed here (110-C).
					$xml_notice = self::xml_notice();
					?>
					<?php if ( null !== $xml_notice ) : ?>
						<p class="cx-field__msg cx-field__msg--<?php echo esc_attr( $xml_notice[0] ); ?>" id="cx-oib-xml-msg"><?php echo esc_html( $xml_notice[1] ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<p class="description"><?php echo esc_html__( 'Company (d.o.o., j.d.o.o.): OIB, 11 digits. Craft: MBO. Family farm: MIBPG. For the official name of the price list file. Empty: the name is without it.', 'cx-sidrena' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Link on the shop', 'cx-sidrena' ); ?></th>
			<td>
				<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" name="cx_cjenik_link" value="yes" <?php checked( 'no' !== $settings->get( 'cjenik_link' ) ); ?> /> <?php echo esc_html__( 'Show a "Cjenik" link at the bottom of every page', 'cx-sidrena' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Inspectors and price comparison services need to find your price list.', 'cx-sidrena' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Availability', 'cx-sidrena' ); ?></th>
			<td>
				<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" name="cx_feed_dostupnost" value="yes" <?php checked( $settings->feed_has_dostupnost() ); ?> /> <?php echo esc_html__( 'Show in stock / out of stock in the price list', 'cx-sidrena' ); ?></label>
				<p class="description"><?php echo esc_html__( 'Off by default. Only the earlier Decision on prices, which no longer applies, asked for this column.', 'cx-sidrena' ); ?></p>
			</td>
		</tr>
		<?php
		// CX-SID-27: how long a price list file older than the public 30 days is kept, and those files by month.
		$keep_days  = CX_Sidrena_Archive::keep_days();
		$keep_opts  = array(
			30   => __( '30 days (minimum)', 'cx-sidrena' ),
			365  => __( '1 year', 'cx-sidrena' ),
			730  => __( '2 years', 'cx-sidrena' ),
			1095 => __( '3 years', 'cx-sidrena' ),
			1461 => __( '4 years (recommended)', 'cx-sidrena' ),
			1826 => __( '5 years', 'cx-sidrena' ),
			3652 => __( '10 years', 'cx-sidrena' ),
			0    => __( 'Forever', 'cx-sidrena' ),
		);
		if ( ! isset( $keep_opts[ $keep_days ] ) ) {
			/* translators: %d: number of days */
			$keep_opts = array( $keep_days => sprintf( __( '%d days', 'cx-sidrena' ), $keep_days ) ) + $keep_opts;
		}
		$kept_months = CX_Sidrena_Archive::instance()->kept_months();
		$kept_month  = isset( $_GET['cx_kept_month'] ) ? sanitize_text_field( wp_unslash( $_GET['cx_kept_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a view filter
		if ( ! isset( $kept_months[ $kept_month ] ) ) {
			$kept_month = (string) key( $kept_months ); // newest month first
		}
		$kept_all   = '' !== $kept_month ? $kept_months[ $kept_month ] : array();
		$kept_shown = array_slice( $kept_all, 0, CX_Sidrena_Archive::KEPT_LIST_MAX );
		?>
		<tr id="cx-kept">
			<th scope="row"><label for="cx_archive_keep_days"><?php echo esc_html__( 'Keep older price lists', 'cx-sidrena' ); ?></label></th>
			<td>
				<select form="<?php echo esc_attr( $f ); ?>" id="cx_archive_keep_days" name="cx_archive_keep_days">
					<?php foreach ( $keep_opts as $days => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $keep_days, $days ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html__( 'Price lists older than 30 days are no longer public. They stay stored on your server, out of public reach, for as long as you choose here. The default is 4 years, the limitation period for a misdemeanour. If you shorten it, older price lists are deleted for good the next morning.', 'cx-sidrena' ); ?></p>
				<h3 class="cx-kept__title"><?php echo esc_html__( 'Saved older price lists', 'cx-sidrena' ); ?></h3>
				<?php if ( array() === $kept_months ) : ?>
					<p class="cx-kept__empty"><?php echo esc_html__( 'No older price lists yet. Price lists older than 30 days will appear here.', 'cx-sidrena' ); ?></p>
				<?php else : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#cx-kept" class="cx-kept__month">
						<input type="hidden" name="page" value="sidrena-cijena" />
						<input type="hidden" name="tab" value="settings" />
						<label for="cx_kept_month"><?php echo esc_html__( 'Month', 'cx-sidrena' ); ?></label>
						<select id="cx_kept_month" name="cx_kept_month" onchange="this.form.submit()">
							<?php foreach ( $kept_months as $ym => $in_month ) : ?>
								<option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $kept_month, $ym ); ?>><?php echo esc_html( $in_month[0]['stamp'] ? CX_Sidrena_Archive::legal_date( 'm.Y.', $in_month[0]['stamp'] ) : $ym ); ?></option>
							<?php endforeach; ?>
						</select>
					</form>
					<table class="widefat striped cx-kept__table">
						<thead><tr>
							<th scope="col"><?php echo esc_html__( 'Date', 'cx-sidrena' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'File', 'cx-sidrena' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Size', 'cx-sidrena' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php echo esc_html__( 'Download', 'cx-sidrena' ); ?></span></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $kept_shown as $kept ) : ?>
							<tr>
								<td><?php echo esc_html( $kept['stamp'] ? CX_Sidrena_Archive::legal_date( 'd.m.Y. H:i', $kept['stamp'] ) : '' ); ?></td>
								<td><?php echo esc_html( $kept['name'] ); ?></td>
								<td><?php echo esc_html( size_format( (int) $kept['bytes'], 1 ) ); ?></td>
								<td><a href="<?php echo esc_url( CX_Sidrena_Archive::kept_url( $kept['name'] ) ); ?>"><?php echo esc_html__( 'Download', 'cx-sidrena' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $kept_all ) > count( $kept_shown ) ) : ?>
						<?php /* translators: 1: number shown, 2: number of files in the month */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Showing the newest %1$d of %2$d in this month.', 'cx-sidrena' ), count( $kept_shown ), count( $kept_all ) ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>
</section>

<p class="submit cx-settings__submit">
	<button type="submit" form="<?php echo esc_attr( $f ); ?>" class="button button-primary"><?php echo esc_html__( 'Save', 'cx-sidrena' ); ?></button>
</p>

<?php if ( ! $woo ) : ?>
	<?php // Without WooCommerce the products and their import are the whole price list: here, not under Advanced (CX-SID-100). ?>
	<div id="cx-items" class="cx-settings__sub">
		<h2><?php echo esc_html__( 'Products', 'cx-sidrena' ); ?></h2>
		<?php $this->render_tab_proizvodi(); ?>
	</div>

	<div id="cx-outlets-import" class="cx-settings__sub">
		<h2><?php echo esc_html__( 'Import/Export', 'cx-sidrena' ); ?></h2>
		<?php $this->render_tab_uvoz_izvoz(); ?>
	</div>
<?php endif; ?>

<details class="cx-settings__advanced" id="cx-advanced">
	<summary><?php echo esc_html__( 'Show advanced settings', 'cx-sidrena' ); ?></summary>

	<section class="sidrena-cijena-card cx-settings" id="cx-options">
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'Display and price list', 'cx-sidrena' ); ?></h2>
		</div>
		<?php if ( $woo && class_exists( 'CX_Sidrena_Compat' ) && CX_Sidrena_Compat::lowest_price_plugin_active() ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s detected 30-day lowest price plugin name */
						__( '%s was detected. This plugin works with it: one bazna cijena row, between the current price and the “Lowest price in the last 30 days” row.', 'cx-sidrena' ),
						CX_Sidrena_Compat::lowest_price_plugin_label()
					)
				);
				?>
			</p>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<?php if ( $woo ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Automatic display of bazna cijena', 'cx-sidrena' ); ?></th>
				<td>
					<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_auto_display" value="yes" <?php checked( $settings->is_auto_display_enabled() ); ?> /> <?php echo esc_html__( 'Show below the price', 'cx-sidrena' ); ?></label>
					<p class="description"><?php echo esc_html__( 'The plugin automatically shows the bazna cijena below the current price. If this is off, use the shortcode [sidrena-cijena] or [sidrena-cijena id="x"] to show it outside product loops. For a single amount add prikaz="redovna", prikaz="akcijska" or prikaz="sidrena".', 'cx-sidrena' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Date format', 'cx-sidrena' ); ?></th>
				<td>
					<fieldset class="sidrena-cijena-choices">
						<label class="sidrena-cijena-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_date_style" value="plain" <?php checked( $settings->get( 'date_style' ), 'plain' ); ?> /> <span><?php echo esc_html__( 'Prefix date', 'cx-sidrena' ); ?></span></label>
						<label class="sidrena-cijena-choice"><input type="radio" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_date_style" value="parens" <?php checked( $settings->get( 'date_style' ), 'parens' ); ?> /> <span><?php echo esc_html__( 'Prefix (date)', 'cx-sidrena' ); ?></span></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="sidrena_cijena_default_date"><?php echo esc_html__( 'Default reference date', 'cx-sidrena' ); ?></label></th>
				<td>
					<input type="text" form="<?php echo esc_attr( $f ); ?>" class="regular-text" id="sidrena_cijena_default_date" name="sidrena_cijena_default_date" value="<?php echo esc_attr( $settings->get( 'default_date' ) ); ?>" placeholder="10.09.2026." />
					<p class="description"><?php echo esc_html__( 'For products without their own date. For FMCG (food, beverages, cosmetics…) enter 02.05.2025.', 'cx-sidrena' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Tooltip', 'cx-sidrena' ); ?></th>
				<td>
					<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" id="sidrena_cijena_enable_tooltip" name="sidrena_cijena_enable_tooltip" value="yes" <?php checked( $settings->is_tooltip_enabled() ); ?> /> <?php echo esc_html__( 'Show tooltip on storefront', 'cx-sidrena' ); ?></label>
					<p class="description"><?php echo esc_html__( 'Shown on mouse hover over the bazna cijena.', 'cx-sidrena' ); ?></p>
					<textarea form="<?php echo esc_attr( $f ); ?>" class="large-text" rows="4" id="sidrena_cijena_tooltip" name="sidrena_cijena_tooltip" aria-label="<?php echo esc_attr__( 'Tooltip text', 'cx-sidrena' ); ?>"><?php echo esc_textarea( $settings->get( 'tooltip' ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Unit price (CJM)', 'cx-sidrena' ); ?></th>
				<td>
					<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" name="sidrena_cijena_enable_cjm" value="yes" <?php checked( $settings->is_cjm_enabled() ); ?> /> <?php echo esc_html__( 'Enable unit of measure next to the bazna cijena', 'cx-sidrena' ); ?></label>
					<p class="description"><?php echo esc_html__( 'When this is on, the unit price (CJM) appears next to the bazna cijena: on the Woo product and variation, and on every row of the Products or Additional items tab. Turn it on for the row and enter a suffix (e.g. "za kg/l"), and the shop shows "Cijena za kg/l (10.09.2026.)". The same unit of measure goes into the public XML/CSV and the HTML table.', 'cx-sidrena' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo $woo ? 'sidrena_cijena_brand_taxonomy' : 'sidrena_cijena_brand_manual'; ?>"><?php echo esc_html__( 'Brand', 'cx-sidrena' ); ?></label></th>
				<td>
					<?php if ( $woo ) : ?>
						<select form="<?php echo esc_attr( $f ); ?>" id="sidrena_cijena_brand_taxonomy" name="sidrena_cijena_brand_taxonomy">
							<option value=""><?php echo esc_html__( 'No taxonomy', 'cx-sidrena' ); ?></option>
							<?php foreach ( $settings->brand_taxonomy_choices() as $tax => $tax_label ) : ?>
								<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( (string) $settings->get( 'brand_taxonomy' ), $tax ); ?>><?php echo esc_html( $tax_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html__( "For WooCommerce products, the brand in the price list is the name of the term from the selected taxonomy (e.g. product_brand or pa_marka). A variation without a term takes the parent's brand.", 'cx-sidrena' ); ?></p>
					<?php endif; ?>
					<label for="sidrena_cijena_brand_manual"><?php echo esc_html__( 'Manual brand', 'cx-sidrena' ); ?></label>
					<input type="text" form="<?php echo esc_attr( $f ); ?>" class="regular-text" id="sidrena_cijena_brand_manual" name="sidrena_cijena_brand_manual" value="<?php echo esc_attr( (string) $settings->get( 'brand_manual' ) ); ?>" />
					<p class="description"><?php echo esc_html__( 'Used when the product has no term in the taxonomy, and when the Brand field on a manual row is empty. Manual rows (Products or Additional items) have their own field.', 'cx-sidrena' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Daily price list', 'cx-sidrena' ); ?></th>
				<td>
					<label><input type="checkbox" form="<?php echo esc_attr( $f ); ?>" name="cx_sidrena_daily_xml" value="yes" <?php checked( 'yes' === $settings->get( 'daily_xml' ) ); ?> /> <?php echo esc_html__( 'Make a new price list every morning', 'cx-sidrena' ); ?></label>
					<p class="description">
						<?php
						echo esc_html__( 'On by default. After 00:05 Croatian time the morning price list is made, before the legal deadline of 08:00, and stored in the archive under its official name.', 'cx-sidrena' );
						echo ' ';
						if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
							echo esc_html__( 'DISABLE_WP_CRON is enabled, so system cron at 00:05 calls wp-cron.php.', 'cx-sidrena' );
						} else {
							echo esc_html__( 'WordPress cron runs that job only on the next PHP request.', 'cx-sidrena' );
						}
						?>
					</p>
					<?php if ( ! empty( $xml_meta['generated'] ) ) : ?>
						<?php /* translators: %s: datetime */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Last successful generation: %s.', 'cx-sidrena' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $xml_meta['generated'] ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( $next_daily ) : ?>
						<?php /* translators: %s: datetime */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Next automatic generation: %s.', 'cx-sidrena' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_daily ) ) ); ?></p>
					<?php endif; ?>
					<p><button type="submit" form="<?php echo esc_attr( $f ); ?>" class="button" name="cx_regenerate" value="1"><?php echo esc_html__( 'Save and generate the price list now', 'cx-sidrena' ); ?></button></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Shortcodes', 'cx-sidrena' ); ?></th>
				<td class="sidrena-cijena-feeds__grid">
					<?php
					$this->render_archive_shortcode_docs();
					$this->render_cjenik_shortcode_docs( $settings );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'CX Sidrena version', 'cx-sidrena' ); ?></th>
				<td>
					<?php echo esc_html( CX_SIDRENA_VERSION ); ?>
					<?php if ( class_exists( 'CX_Sidrena_Updates' ) ) : ?>
						<?php
						$upd     = CX_Sidrena_Updates::status();
						$updates = $upd['text'];
						if ( $upd['available'] ) {
							/* translators: %s: version number */
							$updates .= ' · ' . sprintf( __( 'version %s available', 'cx-sidrena' ), $upd['available'] );
						}
						if ( $upd['last_check'] ) {
							/* translators: %s: date and time */
							$updates .= ' · ' . sprintf( __( 'last checked %s', 'cx-sidrena' ), wp_date( 'd.m.Y. H:i', $upd['last_check'] ) );
						}
						?>
						<p class="description"><?php echo esc_html__( 'Updates', 'cx-sidrena' ); ?>: <?php echo esc_html( $updates ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Scheduled tasks', 'cx-sidrena' ); ?></th>
				<td>
					<?php
					echo esc_html(
						( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
							? __( 'WP-Cron is disabled in wp-config.php (a server cron job is required)', 'cx-sidrena' )
							: __( 'WP-Cron runs on site visits (recommended: a server cron job every 15 minutes)', 'cx-sidrena' )
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row">WP-CLI</th>
				<td>
					<code>wp cx-sidrena status</code><br />
					<code>wp cx-sidrena check</code><br />
					<code>wp cx-sidrena fill --dry-run</code><br />
					<code>wp cx-sidrena fill</code>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" form="<?php echo esc_attr( $f ); ?>" class="button button-primary"><?php echo esc_html__( 'Save', 'cx-sidrena' ); ?></button>
		</p>
	</section>

	<?php if ( $woo ) : ?>
	<div id="cx-items" class="cx-settings__sub">
		<h2><?php echo esc_html__( 'Additional items', 'cx-sidrena' ); ?></h2>
		<?php $this->render_tab_proizvodi(); ?>
	</div>
	<?php endif; ?>

	<div id="cx-outlets" class="cx-settings__sub">
		<h2><?php echo esc_html__( 'Outlets', 'cx-sidrena' ); ?></h2>
		<?php $this->render_tab_poslovnice(); ?>
	</div>

	<?php if ( $woo ) : ?>
	<div id="cx-outlets-import" class="cx-settings__sub">
		<?php // With WooCommerce the section holds only the export: the bulk import is not there (CX-SID-43). ?>
		<h2><?php echo esc_html__( 'Export', 'cx-sidrena' ); ?></h2>
		<?php $this->render_tab_uvoz_izvoz(); ?>
	</div>
	<?php endif; ?>

	<p class="cx-settings__keep">
		<?php echo esc_html__( 'Deleting the plugin keeps your bazna cijene and the price list archive.', 'cx-sidrena' ); ?>
		<?php if ( $woo ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( $this->get_settings_tab_url( 'settings', array( 'cx_export_bazna' => '1' ) ), 'cx_sidrena_export_bazna' ) ); ?>"><?php echo esc_html__( 'Export bazna cijene', 'cx-sidrena' ); ?></a>
		<?php endif; ?>
	</p>
</details>
