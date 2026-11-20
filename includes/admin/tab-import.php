<?php
/**
 * Import tab markup (spec §1.5, CX-SID-42): Upload → Preview → Confirm → progress → Done, and History. The screen only
 * calls the import's endpoints (CX_Sidrena_Import, CX-SID-22) from assets/js/admin-bazna.js; it never writes. Nothing
 * changes before Confirm, and the file confirmed is the file previewed (its sha256 is sent back). An import keeps
 * running without this page (WP-cron): on load the tab asks for one and follows it, and it can be stopped.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

$dates_saved  = class_exists( 'CX_Sidrena_Dates' ) && CX_Sidrena_Dates::dates_saved();
$settings_url = add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'settings' ), admin_url( 'admin.php' ) );
$template_url = wp_nonce_url( admin_url( 'admin-post.php?action=cx_sidrena_import_template' ), 'cx_sidrena_import_template' );
?>
<div class="cx-import" id="cx-import">

	<section class="sidrena-cijena-card cx-import__step" data-step="upload">
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'Import bazna cijene', 'cx-sidrena' ); ?></h2>
			<p><?php echo esc_html__( 'For when the bazna cijene are not today\'s regular prices, or when you set them for a client. Nothing changes until you confirm. Prices and stock are never changed.', 'cx-sidrena' ); ?></p>
		</div>
		<?php if ( ! $dates_saved ) : ?>
			<p class="cx-status__line">
				<?php echo esc_html__( 'Dates are not saved yet. Rows that take their date from Dates wait for them; the other rows can be imported now.', 'cx-sidrena' ); ?>
				<a href="<?php echo esc_url( $settings_url . '#cx-dates' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a>
			</p>
		<?php endif; ?>
		<form class="cx-import__upload" id="cx-import-upload" enctype="multipart/form-data">
			<p>
				<label for="cx-import-file"><?php echo esc_html__( 'File (CSV)', 'cx-sidrena' ); ?></label><br />
				<input type="file" id="cx-import-file" name="file" accept=".csv,text/csv" />
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Upload', 'cx-sidrena' ); ?></button>
				<a class="button" href="<?php echo esc_url( $template_url ); ?>"><?php echo esc_html__( 'Download template', 'cx-sidrena' ); ?></a>
			</p>
			<p class="description"><?php echo esc_html__( 'Columns: SKU, Product name, Bazna cijena, Date, Lowest price in 30 days. An empty date keeps the product\'s own date; a product without one takes it from Dates in Settings, or the day it was added.', 'cx-sidrena' ); ?></p>
			<?php // CX-SID-122, Norman T1. ?>
			<p class="description"><?php echo esc_html__( 'Optional columns: Brand, EAN and Barcode. Take them from the product, its packaging or your supplier. EAN has 8 or 13 digits; any other code goes in Barcode. An empty cell changes nothing, and a dash (-) removes what you imported before. An EAN (GTIN) or brand set on the product in WooCommerce comes first and is not changed here. A file may have only SKU and these columns; bazna cijene then stay as they are.', 'cx-sidrena' ); ?></p>
			<p class="cx-import__error notice notice-error inline" role="alert" hidden></p>
		</form>
		<?php
		// The file for products on sale without a bazna (CX-SID-22 T1): a suggestion per product by the rule for its
		// date, which the owner checks and imports here. It follows the dates, so it answers 409 until they are saved.
		if ( class_exists( 'CX_Sidrena_Sale_Rule' ) ) :
			?>
			<div class="cx-import__sale-rule">
				<?php if ( $dates_saved ) : ?>
					<p><?php echo esc_html__( 'Products on sale without a bazna cijena: download a file with a suggested bazna cijena for each, check it, correct it where needed, then upload it here.', 'cx-sidrena' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( CX_Sidrena_Sale_Rule::url() ); ?>"><?php echo esc_html__( 'Download file for products on sale', 'cx-sidrena' ); ?></a></p>
					<?php // CX-SID-162: the regulation asks for the price before a sale on the reference day; the file only knows today's. ?>
					<p class="notice notice-warning inline cx-import__sale-rule-check"><?php echo esc_html__( 'The suggestion is worked out from today\'s sale and prices. The bazna cijena is the price the product had on its date (Rule column). If it was on sale that day, the bazna cijena is the price before that sale, as the Rule column says, not the sale price. Check this in your own records and correct the file before you upload it.', 'cx-sidrena' ); ?></p>
				<?php else : ?>
					<p class="description"><?php echo esc_html__( 'The file for products on sale without a bazna cijena can be downloaded once Dates are saved in Settings.', 'cx-sidrena' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>

	<section class="sidrena-cijena-card cx-import__step" data-step="preview" hidden>
		<div class="sidrena-cijena-card__head">
			<h2 class="cx-import__preview-head" tabindex="-1"><?php echo esc_html__( 'Preview', 'cx-sidrena' ); ?></h2>
			<p class="cx-import__file"></p>
		</div>
		<div class="cx-status__tiles cx-import__counts" role="group" aria-label="<?php echo esc_attr__( 'Show rows', 'cx-sidrena' ); ?>">
			<?php
			$lists = array(
				'update'    => __( 'Will be updated', 'cx-sidrena' ),
				'not_found' => __( 'Not found', 'cx-sidrena' ),
				'unchanged' => __( 'Unchanged', 'cx-sidrena' ),
				'invalid'   => __( 'Invalid', 'cx-sidrena' ),
			);
			foreach ( $lists as $key => $label ) :
				?>
				<button type="button" class="cx-status__tile cx-import__count" aria-pressed="false" data-list="<?php echo esc_attr( $key ); ?>">
					<span class="cx-status__num">0</span>
					<span class="cx-status__desc"><?php echo esc_html( $label ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<p class="cx-status__line cx-import__nodate" hidden></p>
		<table class="widefat striped cx-import__table"><thead></thead><tbody></tbody></table>
		<p class="cx-import__pages"></p>
	</section>

	<section class="sidrena-cijena-card cx-import__step" data-step="confirm" hidden>
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'Confirm', 'cx-sidrena' ); ?></h2>
			<p><?php echo esc_html__( 'Who confirmed this file for the shop. Kept with the import as proof.', 'cx-sidrena' ); ?></p>
		</div>
		<p>
			<label for="cx-import-by"><?php echo esc_html__( 'Confirmed by', 'cx-sidrena' ); ?></label><br />
			<input type="text" class="regular-text" id="cx-import-by" maxlength="100" required />
		</p>
		<p>
			<label for="cx-import-on"><?php echo esc_html__( 'Date', 'cx-sidrena' ); ?></label><br />
			<input type="date" id="cx-import-on" required value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" />
		</p>
		<p><label class="cx-dialog__consent"><input type="checkbox" id="cx-import-consent" required /> <span id="cx-import-consent-text"><?php echo esc_html( CX_Sidrena_Import::consent_text() ); ?></span></label></p>
		<p class="cx-import__error notice notice-error inline" role="alert" hidden></p>
		<p class="cx-import__info notice notice-info inline" role="status" hidden></p>
		<p>
			<button type="button" class="button button-primary cx-import__go"></button>
			<button type="button" class="button cx-import__restart"><?php echo esc_html__( 'Choose another file', 'cx-sidrena' ); ?></button>
		</p>
	</section>

	<?php // The import running now: this page's own after Confirm, or one found on load (it runs without the page). ?>
	<section class="sidrena-cijena-card cx-import__step" data-step="running" hidden>
		<div class="sidrena-cijena-card__head">
			<h2 class="cx-import__running-head" tabindex="-1"><?php echo esc_html__( 'Import in progress…', 'cx-sidrena' ); ?></h2>
			<p class="cx-import__running-who"></p>
		</div>
		<div class="cx-fill-progress cx-import__progress">
			<p><?php echo esc_html__( 'Importing…', 'cx-sidrena' ); ?> <span class="cx-fill-progress__count"></span></p>
			<progress max="1" value="0"></progress>
			<p class="description"><?php echo esc_html__( 'You may close this page. The work continues, and this bar shows it again when you come back.', 'cx-sidrena' ); ?></p>
		</div>
		<p class="cx-import__error notice notice-error inline" role="alert" hidden></p>
		<?php // A Stop that was refused (a step was being written): said, while the bar goes on following. ?>
		<p class="cx-import__note notice notice-info inline" role="status" hidden></p>
		<p>
			<button type="button" class="button cx-import__resume" hidden><?php echo esc_html__( 'Continue', 'cx-sidrena' ); ?></button>
			<button type="button" class="button cx-import__stop"><?php echo esc_html__( 'Stop import', 'cx-sidrena' ); ?></button>
		</p>
	</section>

	<section class="sidrena-cijena-card cx-import__step" data-step="done" hidden>
		<div class="notice notice-success inline cx-import__result" tabindex="-1"><p></p></div>
		<?php // Stopped with Stop import: how many rows were left out. ?>
		<div class="notice notice-warning inline cx-import__stopped" hidden><p></p></div>
		<?php // Rows that waited for Dates are not imported: say so, or the owner may later use the regular price instead (Norman, CX-SID-42). ?>
		<div class="notice notice-warning inline cx-import__waited" hidden>
			<p><span class="cx-import__waited-text"></span>
				<a href="<?php echo esc_url( $settings_url . '#cx-dates' ); ?>"><?php echo esc_html__( 'Open Settings', 'cx-sidrena' ); ?></a></p>
		</div>
		<div class="cx-import__skipped" hidden>
			<p class="cx-import__skipped-head"></p>
			<ul></ul>
		</div>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( CX_Sidrena_Admin_Status::url() ); ?>"><?php echo esc_html__( 'Open Status', 'cx-sidrena' ); ?></a>
			<button type="button" class="button cx-import__restart"><?php echo esc_html__( 'Import another file', 'cx-sidrena' ); ?></button>
		</p>
	</section>

	<section class="sidrena-cijena-card cx-import__history" id="cx-import-history">
		<div class="sidrena-cijena-card__head">
			<h2><?php echo esc_html__( 'History', 'cx-sidrena' ); ?></h2>
			<p><?php echo esc_html__( 'Every confirmed import is kept here: proof for an inspection.', 'cx-sidrena' ); ?></p>
		</div>
		<table class="widefat striped cx-import__history-table">
			<thead><tr>
				<th scope="col"><?php echo esc_html__( 'Date', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Imported by', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Confirmed by', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Imported', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'File', 'cx-sidrena' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'File fingerprint', 'cx-sidrena' ); ?></th>
			</tr></thead>
			<tbody></tbody>
		</table>
		<p class="cx-import__pages cx-import__history-pages"></p>
	</section>
</div>
