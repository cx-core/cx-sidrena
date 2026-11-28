<?php
/**
 * Status tab markup (spec §1.4, sketch/status.png). Included from CX_Sidrena_Admin_Status::render().
 *
 * @package CX_Sidrena
 * @var CX_Sidrena_Admin_Status $status
 */

defined( 'ABSPATH' ) || exit;

$scan      = $status->scan();
$attention = $status->attention_count();
$red       = $status->red_reason();
$feed      = $status->feed_time();
$export    = CX_Sidrena_Export::instance();
$settings_url = add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'settings' ), admin_url( 'admin.php' ) );
$page_no   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$list      = $status->page_of_problems( $page_no );
$takeover  = $status->takeover();
$ready     = $status->fill_ready();
$running   = $status->running_fill();
$n_missing = count( $scan['missing'] );
$me        = wp_get_current_user();
// The lowest price in 30 days turned off for this shop, and no other plugin drawing it (CX-SID-63): customers on sale
// see no lowest price, so the tile does not say they do, and a line says why.
$n30_off   = CX_Sidrena_Plugin::woocommerce_active() && function_exists( 'cx_sidrena_n30_display_enabled' ) && ! cx_sidrena_n30_display_enabled()
	&& ! ( class_exists( 'CX_Sidrena_Compat' ) && CX_Sidrena_Compat::lowest_price_plugin_active() );
$fmt_time  = function ( $ts ) {
	return CX_Sidrena_Archive::legal_when( $ts ); // the legal clock, as the file name and the public page (CX-SID-59)
};
?>
<div class="cx-status-wrap">
<div class="notice inline cx-fill-notice" id="cx-fill-notice" role="status" hidden><p></p></div>
<div class="cx-status" id="cx-status">

	<?php if ( isset( $_GET['cx_updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<?php if ( '1' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( __( 'The public price list has been generated.', 'cx-sidrena' ) . CX_Sidrena_Admin::fallback_text() ); ?></p></div>
		<?php elseif ( 'b2b' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( CX_Sidrena_Export::b2b_message() ); ?></p></div>
		<?php elseif ( 'nobazna' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php // The first price list waits for the first bazna (CX-SID-68). Not a failure: nothing is retried. ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( CX_Sidrena_Export::no_bazna_message() ); ?></p></div>
		<?php elseif ( 'nooib' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php // No OIB or MBO (CX-SID-159). Not a failure: nothing is retried; the red block below links to the field. ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( CX_Sidrena_Export::no_legal_id_message() ); ?></p></div>
		<?php elseif ( 'busy' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php // Another build was running; one more follows it (CX-SID-119). Not a failure. ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html__( 'The price list is being generated right now. As soon as that is done, it will be generated once more.', 'cx-sidrena' ); ?></p></div>
		<?php elseif ( '0' === $_GET['cx_updated'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( __( 'The public price list could not be generated. Check uploads folder permissions.', 'cx-sidrena' ) . ' ' . CX_Sidrena_Admin::retry_text() ); ?></p></div>
		<?php else : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( __( 'The public price list was not generated.', 'cx-sidrena' ) . ' ' . CX_Sidrena_Admin::retry_text() ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $takeover ) : ?>
		<div class="cx-status__takeover notice notice-info inline">
			<p><strong><?php echo esc_html__( 'CX Sidrena has taken over from Sidrena i bazna cijena (MJDD).', 'cx-sidrena' ); ?></strong></p>
			<ul>
				<?php /* translators: %d: number of products and variations */ ?>
				<li><?php echo esc_html( sprintf( __( 'Products and variations that keep their bazna cijena and date: %d.', 'cx-sidrena' ), $scan['with'] ) ); ?></li>
				<?php /* translators: 1: number of settings, 2: number of price list files */ ?>
				<li><?php echo esc_html( sprintf( __( 'Settings copied: %1$d. Price list and archive files copied: %2$d.', 'cx-sidrena' ), isset( $takeover['options'] ) ? (int) $takeover['options'] : 0, isset( $takeover['files'] ) ? (int) $takeover['files'] : 0 ) ); ?></li>
				<?php if ( ! empty( $takeover['off'] ) && is_array( $takeover['off'] ) ) : ?>
					<?php /* translators: %s: plugin names */ ?>
					<li><?php echo esc_html( sprintf( __( 'Deactivated: %s.', 'cx-sidrena' ), implode( ', ', array_map( 'strval', $takeover['off'] ) ) ) ); ?></li>
				<?php endif; ?>
				<li><?php echo esc_html__( 'Public price list stays at the same address.', 'cx-sidrena' ); ?></li>
				<?php /* translators: %s: the label, e.g. "Bazna cijena na <date>:" */ ?>
				<li><?php echo esc_html( sprintf( __( 'Customers now see the CX Sidrena label "%s", without a tooltip.', 'cx-sidrena' ), CX_Sidrena_Settings::instance()->format_prefix_with_date( (string) CX_Sidrena_Settings::instance()->get( 'prefix' ), (string) CX_Sidrena_Settings::instance()->get( 'default_date' ) ) . ':' ) ); ?></li>
			</ul>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'cx_sidrena_takeover_seen', '1', CX_Sidrena_Admin_Status::url() ), 'cx_sidrena_takeover_seen' ) ); ?>"><?php echo esc_html__( 'Close', 'cx-sidrena' ); ?></a></p>
		</div>
	<?php endif; ?>

	<?php if ( $export->waiting_for_legal_id() ) : ?>
		<?php
		// CX-SID-159: without an OIB or MBO nothing is published (NN 132/2026-1584 art. 6(3)); what was published stays.
		// Red, above the rest. To the field's row, not the section: on a phone the field is three link boxes further down
		// (Norman 110-3).
		?>
		<div class="cx-status__banner cx-status__banner--red" id="cx-status-no-oib" role="status">
			<p class="cx-status__headline"><?php echo esc_html__( 'The price list is not published: the OIB, MBO or MIBPG is missing.', 'cx-sidrena' ); ?></p>
			<p>
				<?php echo esc_html__( 'The Pravilnik requires it in the name of every price list file. Until you enter it, no new price list is made and price changes are not published.', 'cx-sidrena' ); ?>
				<?php if ( $export->ever_published() ) : ?>
					<?php echo esc_html__( 'The last published price list stays public.', 'cx-sidrena' ); ?>
				<?php endif; ?>
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $settings_url . '#cx-oib-row' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a></p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $red ) : ?>
		<div class="cx-status__banner cx-status__banner--red" role="status">
			<p class="cx-status__headline"><?php echo esc_html__( 'The public price list was not updated today.', 'cx-sidrena' ); ?></p>
			<p><?php echo esc_html( $red ); ?></p>
			<form method="post" action="<?php echo esc_url( CX_Sidrena_Admin_Status::url() ); ?>">
				<?php wp_nonce_field( 'cx_sidrena_update_now', 'cx_sidrena_status_nonce' ); ?>
				<button type="submit" class="button button-primary" name="cx_sidrena_status_action" value="update_now"><?php echo esc_html__( 'Update now', 'cx-sidrena' ); ?></button>
			</form>
		</div>
	<?php elseif ( $scan['with'] < 1 ) : ?>
		<?php
		// No bazna: red (CX-SID-68). Never published: the first price list waits for the first bazna, while the
		// obligation applies (Kai's sentence). Published: the price list goes on without one. One red block only: the
		// red above is null without a bazna (red_reason()). A B2B shop publishes no price list: yellow, as before.
		$b2b = CX_Sidrena_Settings::instance()->is_b2b_only();
		if ( $b2b ) {
			$no_bazna_head = __( 'No product has a bazna cijena yet.', 'cx-sidrena' );
		} elseif ( $feed ) {
			$no_bazna_head = __( 'No product has a bazna cijena. The price list is published without it.', 'cx-sidrena' );
		} else {
			$no_bazna_head = CX_Sidrena_Export::no_bazna_message();
		}
		?>
		<div class="cx-status__banner cx-status__banner--<?php echo $b2b ? 'yellow' : 'red'; ?>" role="status">
			<p class="cx-status__headline"><?php echo esc_html( $no_bazna_head ); ?></p>
			<ol>
				<?php $dates_state = class_exists( 'CX_Sidrena_Dates' ) ? CX_Sidrena_Dates::state() : array( 'state' => '' ); ?>
				<?php if ( 'saved' === $dates_state['state'] ) : ?>
					<?php /* translators: 1: user name, 2: date */ ?>
					<li class="cx-status__step-done"><?php echo esc_html( sprintf( __( 'Dates saved by %1$s on %2$s', 'cx-sidrena' ), $dates_state['by'], wp_date( 'j.n.Y.', (int) $dates_state['on'] ) ) ); ?></li>
				<?php elseif ( 'taken' === $dates_state['state'] ) : ?>
					<li class="cx-status__step-done"><?php echo esc_html__( 'Dates taken from your existing data.', 'cx-sidrena' ); ?></li>
				<?php else : ?>
					<li><a href="<?php echo esc_url( $settings_url . '#cx-dates' ); ?>"><?php echo esc_html__( 'Check and save Dates in Settings.', 'cx-sidrena' ); ?></a></li>
				<?php endif; ?>
				<li><?php echo esc_html__( 'Fill in the shop address in Settings, if empty.', 'cx-sidrena' ); ?></li>
				<li><?php echo esc_html__( 'Fill bazna cijena: Import (prices changed since the reference date), Fix all below, or wp cx-sidrena fill.', 'cx-sidrena' ); ?></li>
			</ol>
		</div>
	<?php elseif ( $attention > 0 ) : ?>
		<div class="cx-status__banner cx-status__banner--yellow" role="status">
			<?php /* translators: %d: number of products and variations */ ?>
			<p class="cx-status__headline"><?php echo esc_html( sprintf( __( 'Products that need attention: %d.', 'cx-sidrena' ), $attention ) ); ?>
				<a class="button" href="#cx-attention"><?php echo esc_html__( 'Show', 'cx-sidrena' ); ?></a></p>
		</div>
	<?php elseif ( ! $export->waiting_for_legal_id() ) : ?>
		<?php // Without the OIB or MBO nothing is published (CX-SID-159): not "all set", the red block above says why. ?>
		<div class="cx-status__banner cx-status__banner--green" id="cx-status-all-set" role="status"<?php echo ( $display && $display['missed'] ) ? ' hidden' : ''; ?>>
			<p class="cx-status__headline">
				<?php echo esc_html__( 'All set. Every product has a bazna cijena.', 'cx-sidrena' ); ?>
				<?php if ( $feed ) : ?>
					<?php /* translators: %s: "today at 07:12" or "on 14.11.2026. at 07:12" */ ?>
					<?php echo esc_html( sprintf( __( 'Price list updated %s.', 'cx-sidrena' ), $fmt_time( $feed ) ) ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $display ) : ?>
		<?php // What a customer sees on a product page, measured in a browser (CX-SID-92; assets/js/admin-display-check.js). ?>
		<div class="cx-status__display" id="cx-display" data-state="<?php echo esc_attr( $display['state'] ); ?>">
			<div class="cx-status__banner cx-status__banner--yellow" id="cx-display-bad" role="status"<?php echo $display['missed'] ? '' : ' hidden'; ?>>
				<p class="cx-status__headline"><?php echo esc_html__( 'On the product page customers do not see the bazna cijena (or the line with the lowest price in 30 days): the theme hides it or draws its own price.', 'cx-sidrena' ); ?></p>
				<p>
					<span class="cx-display__where"><?php echo esc_html( CX_Sidrena_Admin_Status::display_where( $display['missed'] ) ); ?></span>
					<a class="cx-display__product" href="<?php echo esc_url( $display['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View the product', 'cx-sidrena' ); ?></a>
					· <button type="button" class="button-link cx-display__again"><?php echo esc_html__( 'Check again', 'cx-sidrena' ); ?></button>
				</p>
			</div>
			<p class="cx-status__line" id="cx-display-unchecked"<?php echo 'unchecked' === $display['state'] ? '' : ' hidden'; ?>>
				<?php echo esc_html__( 'What customers see on a product page was not checked: the page could not be opened here.', 'cx-sidrena' ); ?>
				<button type="button" class="button-link cx-display__again"><?php echo esc_html__( 'Check again', 'cx-sidrena' ); ?></button>
			</p>
			<p class="cx-status__line" id="cx-display-ok"<?php echo $display['seen'] ? '' : ' hidden'; ?>>
				<?php echo esc_html__( 'Checked: customers see the bazna cijena on a product page.', 'cx-sidrena' ); ?>
				<button type="button" class="button-link cx-display__again"><?php echo esc_html__( 'Check again', 'cx-sidrena' ); ?></button>
			</p>
			<p class="cx-status__line" id="cx-display-checking" hidden><?php echo esc_html__( 'Checking what customers see on a product page…', 'cx-sidrena' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( CX_Sidrena_Settings::instance()->is_b2b_only() ) : ?>
		<p class="cx-status__line"><?php echo esc_html( CX_Sidrena_Export::b2b_message() ); ?></p>
	<?php endif; ?>

	<?php if ( $status->address_missing() ) : ?>
		<p class="cx-status__line">
			<?php echo esc_html__( 'Shop address is missing. The price list file is named after the web address.', 'cx-sidrena' ); ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-public' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
		</p>
	<?php endif; ?>

	<?php
	// CX-SID-130 (Kai B, Norman 3113 S): a service that is offered but not in the price list is the owner's omission
	// (1584 art. 3(1)), said one line per service, or one line for more than three.
	$unpub = CX_Sidrena_Catalog::instance()->unpublished_services();
	$lines = array();
	if ( count( $unpub ) > 3 ) {
		/* translators: %d: number of services */
		$lines[] = sprintf( __( 'Services not in the price list because they have no date or bazna cijena: %d.', 'cx-sidrena' ), count( $unpub ) );
	} else {
		foreach ( $unpub as $one ) {
			if ( 'no_bazna' === $one['reason'] ) {
				/* translators: 1: service name, 2: date */
				$lines[] = sprintf( __( 'Service %1$s is not in the price list because it has no bazna cijena. Enter its price on %2$s as the bazna cijena.', 'cx-sidrena' ), $one['naziv'], rtrim( CX_Sidrena_Money::customer_date( $one['date'] ), '.' ) ); // the sentence's dot ends the date (Argus 3209)
			} elseif ( 'no_date' === $one['reason'] ) {
				/* translators: %s: service name */
				$lines[] = sprintf( __( 'Service %s is not in the price list because its bazna cijena has no date. Enter the day the bazna cijena is from.', 'cx-sidrena' ), $one['naziv'] );
			} else {
				/* translators: %s: service name */
				$lines[] = sprintf( __( 'Service %s is not in the price list because it has no date and no bazna cijena. Enter the day you started offering it (10.9.2026. for any day before) and its price on that day.', 'cx-sidrena' ), $one['naziv'] );
			}
		}
	}
	?>
	<?php foreach ( $lines as $line ) : ?>
		<p class="cx-status__line">
			<?php // A service's name is as typed (CX-SID-142): escaped whole, also an '&amp;' the owner typed. ?>
			<?php echo CX_Sidrena_Feed_Files::instance()->text_html( $line, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars() in text_html() ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-items' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
		</p>
	<?php endforeach; ?>

	<?php $upit_products = CX_Sidrena_Upit::instance()->count_products(); ?>
	<?php if ( $upit_products > 0 ) : ?>
		<?php // CX-SID-76: products shown "by agreement" have no displayed price (ZZP art. 7(1)); a warning, never a block. ?>
		<p class="cx-status__line">
			<?php /* translators: %d: number of rows marked as a product */ ?>
			<?php echo esc_html( sprintf( __( 'Products without a displayed price, in Services with price by agreement: %d.', 'cx-sidrena' ), $upit_products ) ); ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-upit' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( CX_Sidrena_Settings::instance()->is_isti_cjenik() ) : ?>
		<?php // CX-SID-113: one price list for all places of sale; the places' own lines do not apply. Norman's T86: what is on and what it means, a state, not a task (blue edge). ?>
		<p class="cx-status__line cx-status__line--info" id="cx-isti-status">
			<?php echo esc_html__( 'The same price list for all places of sale is on: the webshop and every place of sale publish one price list.', 'cx-sidrena' ); ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-isti-cjenik' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
		</p>
	<?php endif; ?>
	<?php foreach ( CX_Sidrena_Settings::instance()->is_isti_cjenik() ? array() : CX_Sidrena_Poslovnice::instance()->problems() as $place ) : ?>
		<?php
		// CX-SID-74: what a place of sale lacks for its price list (texts Norman 3178, 3182; Kai 3191). A code shared with
		// the webshop or another place is red: their price lists share one archive and one storage number.
		$red  = in_array( $place['type'], array( 'same_code_webshop', 'same_code_pair' ), true );
		$tell = 'same_code_pair' === $place['type'] && $place['locked']; // nothing the owner can change here: tell us
		?>
		<p class="cx-status__line<?php echo $red ? ' cx-status__line--red' : ''; ?>">
			<?php
			switch ( $place['type'] ) {
				case 'no_address':
					/* translators: %s: name of the place of sale */
					$text = sprintf( __( "Outlet %s has no address, so that part of its price list's file name is empty. Enter the address.", 'cx-sidrena' ), $place['naziv'] );
					break;
				case 'no_kind':
					/* translators: %s: name of the place of sale */
					$text = sprintf( __( "Outlet %s has no type, so that part of its price list's file name is empty. Enter the type, e.g. prodavaonica.", 'cx-sidrena' ), $place['naziv'] );
					break;
				case 'no_both':
					/* translators: %s: name of the place of sale */
					$text = sprintf( __( "Outlet %s has no address and no type, so those parts of its price list's file name are empty. Enter both.", 'cx-sidrena' ), $place['naziv'] );
					break;
				case 'same_code_webshop':
					$text = $place['locked']
						/* translators: 1: name of the place of sale, 2: its code */
						? sprintf( __( "Outlet %1\$s has the label %2\$s, which the webshop also has, so their price lists share one archive and one storage number. %1\$s has already published a price list, so its label no longer changes: give the webshop another label in Outlets. The address of the webshop's price list stays the same.", 'cx-sidrena' ), $place['naziv'], $place['oznaka'] )
						/* translators: 1: name of the place of sale, 2: its code */
						: sprintf( __( 'Outlet %1$s has the label %2$s, which the webshop also has, so their price lists share one archive and one storage number. %1$s has not published a price list yet: give it another label in Outlets. The webshop keeps %2$s.', 'cx-sidrena' ), $place['naziv'], $place['oznaka'] );
					break;
				case 'same_code_pair':
					$text = $place['locked']
						/* translators: 1, 2: names of the two places of sale, 3, 4: their codes */
						? sprintf( __( 'Outlets %1$s and %2$s have the labels %3$s and %4$s, which differ only in letter case, so their price lists share one archive and one storage number. Both have already published price lists, so their labels no longer change here. Tell us and we will correct it.', 'cx-sidrena' ), $place['naziv'], $place['naziv2'], $place['oznaka'], $place['oznaka2'] )
						/* translators: 1, 2: names of the two places of sale (2 = the one that has not published), 3, 4: their codes */
						: sprintf( __( 'Outlets %1$s and %2$s have the labels %3$s and %4$s, which differ only in letter case, so their price lists share one archive and one storage number. %2$s has not published a price list yet: give it another label in Outlets.', 'cx-sidrena' ), $place['naziv'], $place['naziv2'], $place['oznaka'], $place['oznaka2'] );
					break;
				default:
					/* translators: %s: name of the place of sale */
					$text = sprintf( __( 'Place of sale %s has no products, so it has no price list.', 'cx-sidrena' ), $place['naziv'] );
			}
			echo CX_Sidrena_Feed_Files::instance()->text_html( $text, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a place's name as typed (CX-SID-142)
			?>
			<?php if ( $tell ) : ?>
				<a href="<?php echo esc_url( CX_Sidrena_Admin_Status::report_links()['bug']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Tell us', 'cx-sidrena' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $settings_url . '#cx-outlets' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
			<?php endif; ?>
		</p>
	<?php endforeach; ?>

	<?php if ( $n30_off ) : ?>
		<p class="cx-status__line cx-status__line--n30">
			<?php echo esc_html__( 'Customers do not see the lowest price in 30 days on products on sale: showing it is turned off.', 'cx-sidrena' ); ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-n30' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( $status->own_badge_theme() ) : ?>
		<p class="cx-status__line">
			<?php echo esc_html__( 'Your theme shows its own discount percentage. CX Sidrena cannot check how it is calculated.', 'cx-sidrena' ); ?>
			<a href="<?php echo esc_url( $settings_url . '#cx-badge' ); ?>"><?php echo esc_html__( 'Hide it', 'cx-sidrena' ); ?></a>
			· <a href="<?php echo esc_url( CX_Sidrena_Admin_Status::report_links()['bug']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Tell us', 'cx-sidrena' ); ?></a>
		</p>
	<?php endif; ?>

	<div class="cx-status__tiles">
		<a class="cx-status__tile" href="#cx-attention">
			<?php /* translators: 1: products with bazna, 2: all products and variations with a price */ ?>
			<span class="cx-status__num"><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'cx-sidrena' ), $scan['with'], $scan['total'] ) ); ?></span>
			<span class="cx-status__desc"><?php echo esc_html__( 'Products with bazna cijena', 'cx-sidrena' ); ?></span>
		</a>
		<a class="cx-status__tile" href="#cx-attention">
			<span class="cx-status__num"><?php echo esc_html( (string) $attention ); ?></span>
			<span class="cx-status__desc"><?php echo esc_html__( 'Needs attention', 'cx-sidrena' ); ?></span>
		</a>
		<div class="cx-status__tile">
			<span class="cx-status__num"><?php echo esc_html( (string) $scan['on_sale'] ); ?></span>
			<span class="cx-status__desc"><?php echo $n30_off ? esc_html__( 'On sale now', 'cx-sidrena' ) : esc_html__( 'On sale now, with the lowest price in 30 days', 'cx-sidrena' ); ?></span>
		</div>
		<div class="cx-status__tile">
			<?php if ( $scan['with'] < 1 && ! $feed ) : ?>
				<?php // Not published: it waits for the first bazna (CX-SID-68). Published without one: its time, below. ?>
				<span class="cx-status__num">—</span>
				<span class="cx-status__desc"><?php echo esc_html__( 'Price list', 'cx-sidrena' ); ?>: <?php echo esc_html__( 'not published. No product has a bazna cijena.', 'cx-sidrena' ); ?></span>
			<?php elseif ( ! $feed ) : ?>
				<?php
				// Products have a bazna, no price list yet (Norman, CX-SID-42): the next daily run, or the first build when
				// it comes sooner (the first bazna queues it in 60 s, CX-SID-68, Argus S1).
				$next  = $export->get_next_daily_timestamp();
				$first = $status->first_build_time();
				if ( $first > 0 && ( ! $next || $first < $next ) ) {
					$next = $first;
				}
				// Without the OIB or MBO that build is refused (CX-SID-159): no time is promised.
				if ( $export->waiting_for_legal_id() ) {
					$next = 0;
				}
				?>
				<span class="cx-status__num">—</span>
				<span class="cx-status__desc">
					<?php echo esc_html__( 'Price list', 'cx-sidrena' ); ?>: <?php echo esc_html__( 'Not created yet.', 'cx-sidrena' ); ?>
					<?php if ( $next ) : ?>
						<?php /* translators: %s: date and time of the next scheduled run */ ?>
						<?php echo esc_html( sprintf( __( 'Next: %s.', 'cx-sidrena' ), CX_Sidrena_Archive::legal_date( 'j.n.Y. H:i', $next ) ) ); ?>
					<?php endif; ?>
				</span>
			<?php else : ?>
				<span class="cx-status__num"><?php echo esc_html( $fmt_time( $feed ) ); ?></span>
				<span class="cx-status__desc">
					<?php echo esc_html__( 'Price list', 'cx-sidrena' ); ?>
					<?php $next = $export->waiting_for_legal_id() ? 0 : $export->get_next_daily_timestamp(); // CX-SID-159: refused, no time promised ?>
					<?php if ( $next ) : ?>
						<?php /* translators: %s: date and time of the next scheduled run */ ?>
						· <?php echo esc_html( sprintf( __( 'next: %s', 'cx-sidrena' ), CX_Sidrena_Archive::legal_date( 'j.n.Y. H:i', $next ) ) ); ?>
					<?php endif; ?>
					· <a href="<?php echo esc_url( $export->get_public_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( strtoupper( $export->export_format() ) ); ?></a>
					· <a href="<?php echo esc_url( $export->get_cjenik_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Page', 'cx-sidrena' ); ?></a>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<h2 id="cx-attention"><?php echo esc_html__( 'Needs attention', 'cx-sidrena' ); ?></h2>

	<div class="cx-fill-progress" id="cx-fill-progress" data-job="<?php echo esc_attr( $running ? (string) $running['job'] : '' ); ?>"<?php echo $running ? '' : ' hidden'; ?>>
		<p><?php echo esc_html__( 'Setting bazna cijena from the regular price…', 'cx-sidrena' ); ?>
			<span class="cx-fill-progress__count"><?php echo $running ? esc_html( (int) $running['processed'] . ' / ' . (int) $running['total'] ) : ''; ?></span></p>
		<progress max="<?php echo esc_attr( (string) ( $running ? max( 1, (int) $running['total'] ) : 1 ) ); ?>" value="<?php echo esc_attr( (string) ( $running ? (int) $running['processed'] : 0 ) ); ?>"></progress>
		<p class="description"><?php echo esc_html__( 'You may close this page. The work continues, and this bar shows it again when you come back.', 'cx-sidrena' ); ?></p>
	</div>

	<?php if ( $n_missing > 0 && ! $running ) : ?>
		<p class="cx-status__fixall">
			<button type="button" class="button button-primary cx-fix-all" data-count="<?php echo esc_attr( (string) $n_missing ); ?>"<?php disabled( ! $ready ); ?>>
				<?php /* translators: %d: products and variations without a bazna cijena */ ?>
				<?php echo esc_html( sprintf( __( 'Fix all (%d)', 'cx-sidrena' ), $n_missing ) ); ?>
			</button>
		</p>
		<?php if ( ! $ready ) : ?>
			<p class="cx-status__line cx-status__wait">
				<?php echo esc_html__( 'Save Dates in Settings first. They set each product\'s date.', 'cx-sidrena' ); ?>
				<a href="<?php echo esc_url( $settings_url . '#cx-dates' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $list['rows'] ) : ?>
		<p><?php echo esc_html__( 'Nothing needs attention.', 'cx-sidrena' ); ?></p>
	<?php else : ?>
		<table class="widefat striped cx-status__table">
			<thead><tr>
				<th><?php echo esc_html__( 'Product', 'cx-sidrena' ); ?></th>
				<th><?php echo esc_html__( 'Problem', 'cx-sidrena' ); ?></th>
				<th><?php echo esc_html__( 'Fix', 'cx-sidrena' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $list['rows'] as $row ) : ?>
					<tr data-id="<?php echo esc_attr( (string) $row['id'] ); ?>" data-problem="<?php echo esc_attr( $row['problem'] ); ?>">
						<td><a href="<?php echo esc_url( $row['edit'] ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td><?php echo esc_html( CX_Sidrena_Admin_Status::problem_text( $row['problem'] ) ); ?></td>
						<td>
							<?php if ( 'missing' === $row['problem'] ) : ?>
								<?php
								// The dialog names what will be written (Norman, CX-SID-42): the product and its regular price.
								$fix_p     = wc_get_product( $row['id'] );
								$fix_reg   = $fix_p ? (string) $fix_p->get_regular_price() : '';
								$fix_label = '' !== $fix_reg
									/* translators: 1: product name, 2: price */
									? sprintf( __( '%1$s: the bazna cijena will be %2$s.', 'cx-sidrena' ), $row['name'], html_entity_decode( wp_strip_all_tags( wc_price( (float) $fix_reg ) ), ENT_QUOTES, 'UTF-8' ) )
									: '';
								?>
								<button type="button" class="button cx-fix-one" data-id="<?php echo esc_attr( (string) $row['id'] ); ?>" data-count="1" data-label="<?php echo esc_attr( $fix_label ); ?>"<?php disabled( ! $ready || (bool) $running ); ?>><?php echo esc_html__( 'Use regular price', 'cx-sidrena' ); ?></button>
							<?php elseif ( 'two_cat' === $row['problem'] ) : ?>
								<button type="button" class="button cx-keep-earlier" data-id="<?php echo esc_attr( (string) $row['id'] ); ?>"><?php echo esc_html__( 'Keep earlier', 'cx-sidrena' ); ?></button>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( $row['edit'] ); ?>"><?php echo esc_html__( 'Open product', 'cx-sidrena' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $list['pages'] > 1 ) : ?>
			<p class="cx-status__pages">
				<?php for ( $i = 1; $i <= $list['pages']; $i++ ) : ?>
					<?php if ( $i === $list['page'] ) : ?>
						<strong><?php echo esc_html( (string) $i ); ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( CX_Sidrena_Admin_Status::url( array( 'paged' => $i ) ) . '#cx-attention' ); ?>"><?php echo esc_html( (string) $i ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="cx-status__report">
		<?php foreach ( CX_Sidrena_Admin_Status::report_links() as $key => $link ) : ?>
			<a href="<?php echo esc_url( $link['url'] ); ?>"<?php echo 'mail' === $key ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>><?php echo esc_html( $link['label'] ); ?></a><?php echo 'mail' === $key ? '' : ' · '; ?>
		<?php endforeach; ?>
	</p>
</div>

<dialog class="cx-dialog" id="cx-fill-dialog" aria-labelledby="cx-fill-title">
	<form method="dialog" class="cx-dialog__form" novalidate>
		<h2 class="cx-dialog__title" id="cx-fill-title"><?php echo esc_html__( 'Use the regular price as bazna cijena?', 'cx-sidrena' ); ?></h2>
		<p class="cx-dialog__count"></p>
		<p>
			<label for="cx-fill-by"><?php echo esc_html__( 'Confirmed by', 'cx-sidrena' ); ?></label><br />
			<input type="text" class="regular-text" id="cx-fill-by" maxlength="100" required value="<?php echo esc_attr( $me ? $me->display_name : '' ); ?>" />
		</p>
		<p><label class="cx-dialog__consent"><input type="checkbox" id="cx-fill-consent" required /> <span><?php echo esc_html( CX_Sidrena_Fill::consent_text() ); ?></span></label></p>
		<p class="cx-dialog__error" role="alert" hidden></p>
		<p class="cx-dialog__buttons">
			<button type="button" class="button cx-dialog__cancel"><?php echo esc_html__( 'Cancel', 'cx-sidrena' ); ?></button>
			<button type="submit" class="button button-primary cx-dialog__ok"><?php echo esc_html__( 'Use regular price', 'cx-sidrena' ); ?></button>
		</p>
	</form>
</dialog>
</div>
