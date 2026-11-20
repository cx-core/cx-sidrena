<?php
/**
 * Admin: settings, standalone catalog, and WooCommerce product fields.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/admin/class-admin-status.php';
require_once __DIR__ . '/admin/class-admin-products.php';

/**
 * Admin UI.
 */
class CX_Sidrena_Admin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Mark the settings screen so its footer can be styled apart from other admin pages.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true ) ) {
			$classes .= ' sidrena-cijena-screen';
		} elseif ( isset( $_GET['page'] ) && 'sidrena-cijena' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes .= ' sidrena-cijena-screen';
		}

		return $classes;
	}

	/**
	 * Hide the WordPress footer credit on this screen only.
	 */
	public function hide_footer_thankyou() {
		if ( ! $this->is_settings_screen() ) {
			return;
		}

		echo '<style id="sidrena-cijena-footer-thankyou">#footer-thankyou,#footer-left{display:none!important}</style>';
	}

	/**
	 * Drop the WordPress footer credit. Late, so other admin CSS cannot show it again.
	 *
	 * @param string $text Footer HTML.
	 * @return string
	 */
	public function filter_admin_footer_text( $text ) {
		if ( $this->is_settings_screen() ) {
			return '';
		}

		return $text;
	}

	/**
	 * Whether the current request is the Sidrena cijena settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		if ( isset( $_GET['page'] ) && 'sidrena-cijena' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true );
	}

	/**
	 * Register admin hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'redirect_old_urls' ), 1 );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_old_check_page' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( CX_Sidrena_Admin_Save::instance(), 'handle_export_bazna' ) );
		add_action( 'admin_init', array( $this, 'handle_poslovnice_save' ) );
		add_action( 'admin_init', array( $this, 'handle_catalog_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		add_action( 'admin_head', array( $this, 'hide_footer_thankyou' ) );
		add_action( 'admin_footer', array( $this, 'hide_footer_thankyou' ), 999 );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ), 999 );
		add_action( 'admin_init', array( $this, 'handle_prefix_choice' ) );
		// The notice about a bare "Cijena" label is a line under the label in Settings now (warnings are discreet,
		// Hrvoje 15.11.); the consultation notice (until 13.11.2026.) and the fill prompt are gone (CX-SID-23).

		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		CX_Sidrena_Admin_Status::instance();
		// MJDD's old "fill empty fields" (ajax sidrena_cijena_snapshot and its cron) is gone: it wrote the regular price
		// with no confirmation and could stamp today's date (CX-SID-22, decision 1606 c). The fill is CX_Sidrena_Fill.

		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple_fields' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );
		// A WooCommerce product the owner marks as a service goes into the services price list (CX-SID-138).
		$fields = CX_Sidrena_Product_Fields::instance();
		add_filter( 'product_type_options', array( $fields, 'service_type_option' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( $fields, 'render_service_marker' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $fields, 'save_service_mark' ) );

		CX_Sidrena_Admin_Products::instance(); // the product list's column and filter (CX-SID-24)
	}

	/**
	 * WooCommerce submenu, or a top-level menu without Woo.
	 */
	public function register_menu() {
		$cap   = CX_Sidrena_Plugin::manage_capability();
		$title = __( 'Bazna cijena', 'cx-sidrena' );

		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			add_submenu_page(
				'woocommerce',
				$title,
				$title,
				$cap,
				'sidrena-cijena',
				array( $this, 'render_settings_page' )
			);
			return;
		}

		add_menu_page(
			$title,
			$title,
			$cap,
			'sidrena-cijena',
			array( $this, 'render_settings_page' ),
			'dashicons-tag',
			56
		);
	}

	/**
	 * Settings screen.
	 */
	public function render_settings_page() {
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		$tab      = $this->get_settings_tab();
		$tabs     = $this->get_tabs();
		$settings = CX_Sidrena_Settings::instance();
		$export   = CX_Sidrena_Export::instance();
		// Which form was saved: four forms share the Settings page, so the notice names the part that was saved.
		$saved_part = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved_msgs = array(
			'1'       => __( 'Settings saved.', 'cx-sidrena' ),
			'outlets' => __( 'Outlets saved.', 'cx-sidrena' ),
			'items'   => __( 'Additional items saved.', 'cx-sidrena' ),
		);
		// CX-SID-130 (Norman 3113 K): services saved but not published say so, yellow, instead of the green "saved".
		$unpub = isset( $_GET['cx_unpub'] ) ? absint( wp_unslash( $_GET['cx_unpub'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// CX-SID-133: goods saved without a bazna cijena say so too (before it they vanished under the green "saved").
		$nobazna = isset( $_GET['cx_nobazna'] ) ? absint( wp_unslash( $_GET['cx_nobazna'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'items' === $saved_part && ( $unpub > 0 || $nobazna > 0 ) ) {
			unset( $saved_msgs['items'] );
		}
		$xml_ok    = isset( $_GET['sidrena_xml'] ) && '1' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_other = $xml_ok ? null : self::xml_notice(); // busy, failed, no outlet products, B2B: the same text as at the OIB
		$multi     = $settings->is_multi_poslovnica();
		$xml_url   = $export->get_public_url();
		?>
		<div class="wrap sidrena-cijena-settings">
			<div class="sidrena-cijena-hero">
				<h1><?php echo esc_html__( 'Bazna cijena', 'cx-sidrena' ); ?></h1>
			</div>

			<nav class="sidrena-cijena-tabs" aria-label="<?php echo esc_attr__( 'Bazna cijena', 'cx-sidrena' ); ?>">
				<?php foreach ( $tabs as $key => $item ) : ?>
					<a
						class="sidrena-cijena-tabs__item<?php echo $tab === $key ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( $this->get_settings_tab_url( $key ) ); ?>"
					>
						<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $item['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			// WordPress moves every notice on the page (other plugins' too) after .wp-header-end, or without one after
			// the first h1: ours is in the blue header, so their white-on-header text sat there white on white
			// (CX-SID-62, CX-SID-100). Here they stand below the tabs, where ours are.
			?>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['cx_dates_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'Dates were not saved. Choose one of the two dates for every category.', 'cx-sidrena' ); ?></p></div>
			<?php endif; ?>

			<?php
			// CX-SID-110: the OIB, MBO or MIBPG was refused (not stored) or stored with a warning. The same text stands
			// under the field, where the save lands (Norman 110-1); here it is for whoever scrolls up.
			$oib_notice = self::oib_notice();
			if ( null !== $oib_notice && 'error' === $oib_notice[0] && '1' === $saved_part ) {
				$saved_msgs['1'] = __( 'Other settings saved.', 'cx-sidrena' ); // the OIB was not: "Settings saved." next to the red said both
			}
			?>
			<?php if ( null !== $oib_notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $oib_notice[0] ); ?>"><p><?php echo esc_html( $oib_notice[1] ); ?></p></div>
			<?php endif; ?>

			<?php $items_refused = self::items_refused_notice(); ?>
			<?php if ( null !== $items_refused ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $items_refused ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $saved_msgs[ $saved_part ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $saved_msgs[ $saved_part ] ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'items' === $saved_part && $unpub > 0 ) : ?>
				<div class="notice notice-warning is-dismissible"><p>
					<?php /* translators: %d: number of services not in the price list */ ?>
					<?php echo esc_html( sprintf( __( 'Saved. Services not in the price list yet, because they have no date or bazna cijena: %d. They are marked in the table.', 'cx-sidrena' ), $unpub ) ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( 'items' === $saved_part && $nobazna > 0 ) : ?>
				<div class="notice notice-warning is-dismissible"><p>
					<?php
					// Norman 3272: an instruction (Kai 3265). Under the yellow message of the services (130 K) without
					// its "Saved.", so it is not read twice.
					if ( $unpub > 0 ) {
						/* translators: %d: number of products in the table without a bazna cijena */
						$nobazna_text = __( 'Products in this table without a bazna cijena: %d. They are in the price list without it. Enter the bazna cijena in the Bazna cijena column.', 'cx-sidrena' );
					} else {
						/* translators: %d: number of products in the table without a bazna cijena */
						$nobazna_text = __( 'Saved. Products in this table without a bazna cijena: %d. They are in the price list without it. Enter the bazna cijena in the Bazna cijena column.', 'cx-sidrena' );
					}
					echo esc_html( sprintf( $nobazna_text, $nobazna ) );
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $xml_ok ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					if ( $multi ) {
						echo esc_html__( 'Public outlet price lists have been generated.', 'cx-sidrena' );
					} else {
						echo esc_html__( 'The public price list has been generated.', 'cx-sidrena' );
						echo ' <a href="' . esc_url( $xml_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $xml_url ) . '</a>';
					}
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( null !== $xml_other ) : ?>
				<div class="notice notice-<?php echo esc_attr( $xml_other[0] ); ?> is-dismissible"><p><?php echo esc_html( $xml_other[1] ); ?></p></div>
			<?php endif; ?>

			<?php do_action( 'sidrena_cijena_settings_notices', $tab ); ?>

			<div class="sidrena-cijena-panels">
				<?php
				if ( 'status' === $tab ) {
					CX_Sidrena_Admin_Status::instance()->render();
				} elseif ( 'settings' === $tab ) {
					include CX_SIDRENA_PATH . 'includes/admin/tab-settings.php';
				} elseif ( 'import' === $tab ) {
					include CX_SIDRENA_PATH . 'includes/admin/tab-import.php';
				} else {
					do_action( 'sidrena_cijena_render_settings_tab', $tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings tabs (slug => label/icon).
	 *
	 * @return array<string, array{label:string, icon:string}>
	 */
	private function get_tabs() {
		$tabs = array();

		// Status first and always (spec §1.0). Without WooCommerce there is nothing to check, only the settings.
		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			$tabs['status'] = array(
				'label' => __( 'Status', 'cx-sidrena' ),
				'icon'  => 'dashicons-yes-alt',
			);
		}

		/**
		 * Whether the bazna import (CX-SID-22) is there. The tab shows only then.
		 *
		 * @param bool $available Available.
		 */
		if ( apply_filters( 'cx_sidrena_import_available', false ) ) {
			$tabs['import'] = array(
				'label' => __( 'Import', 'cx-sidrena' ),
				'icon'  => 'dashicons-upload',
			);
		}

		$tabs['settings'] = array(
			'label' => __( 'Settings', 'cx-sidrena' ),
			'icon'  => 'dashicons-admin-generic',
		);

		/**
		 * Extra Sidrena cijena settings tabs.
		 *
		 * @param array<string, array{label:string, icon:string}> $tabs Tabs.
		 */
		$filtered = apply_filters( 'sidrena_cijena_settings_tabs', $tabs );

		return is_array( $filtered ) ? $filtered : $tabs;
	}

	/**
	 * Current settings tab.
	 *
	 * @return string
	 */
	private function get_settings_tab() {
		$tabs = $this->get_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		if ( isset( $_GET['sidrena_xml'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'settings';
		}

		$keys = array_keys( $tabs );
		return isset( $keys[0] ) ? $keys[0] : 'settings';
	}

	/**
	 * MJDD's tabs and where their content is now (spec §1.0, §1.8): bookmarks and notices that point at them still work.
	 */
	const OLD_TABS = array(
		'postavke'   => 'cx-label',
		'javni-xml'  => 'cx-public',
		'proizvodi'  => 'cx-items',
		'poslovnice' => 'cx-outlets',
		'uvoz-izvoz' => 'cx-outlets-import',
	);

	/**
	 * Old tab URLs (GET only: a form posted to an old URL is saved by its handler first) go to Settings, at the section.
	 */
	public function redirect_old_urls() {
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || 'sidrena-cijena' !== $_GET['page'] || 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$old = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::OLD_TABS[ $old ] ) ) {
			return;
		}

		$keep = array();
		foreach ( array( 'updated', 'sidrena_xml' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$keep[ $arg ] = sanitize_key( wp_unslash( $_GET[ $arg ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		wp_safe_redirect( $this->get_settings_tab_url( $old, $keep ) );
		exit;
	}

	/**
	 * The add-on's check page (Tools > Bazna cijena: check) is now Status. WordPress refuses an unregistered page
	 * before admin_init, so the redirect hangs on the refusal.
	 */
	public function redirect_old_check_page() {
		if ( isset( $_GET['page'] ) && 'bazna-provjera' === $_GET['page'] && CX_Sidrena_Plugin::current_user_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( CX_Sidrena_Admin_Status::url() );
			exit;
		}
	}

	/**
	 * Settings tab URL. MJDD's old tab names give the Settings section their content moved to.
	 *
	 * @param string $tab  Tab slug (status, import, settings, or an old one).
	 * @param array  $args Extra query args.
	 * @return string
	 */
	private function get_settings_tab_url( $tab, $args = array() ) {
		$anchor = '';
		if ( isset( self::OLD_TABS[ $tab ] ) ) {
			$anchor = '#' . self::OLD_TABS[ $tab ];
			$tab    = 'settings';
		}

		return add_query_arg(
			array_merge(
				array(
					'page' => 'sidrena-cijena',
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		) . $anchor;
	}

	/**
	 * Outlet codes for the catalog row select.
	 *
	 * @return array<int, array{oznaka:string,naziv:string}>
	 */
	private function catalog_outlet_choices() {
		$out = array();
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
			$label = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			$out[] = array(
				'oznaka' => $outlet['oznaka'],
				'naziv'  => $label,
			);
		}

		return $out;
	}

	/**
	 * Poslovnice tab: legal filename fields and assigned products.
	 */
	private function render_tab_poslovnice() {
		include CX_SIDRENA_PATH . 'includes/admin/tab-poslovnice.php';
	}

	/**
	 * Save the Poslovnice repeater.
	 */
	public function handle_poslovnice_save() {
		CX_Sidrena_Admin_Save::instance()->handle_poslovnice_save();
	}

	/**
	 * iOS-style on/off toggle.
	 *
	 * @param string $name    Input name.
	 * @param string $id      Input id.
	 * @param bool   $checked On/off.
	 * @param string $label   Visible label.
	 * @param string $class   Extra class on the checkbox.
	 */
	public function render_toggle( $name, $id, $checked, $label, $class = '' ) {
		?>
		<label class="sidrena-cijena-toggle" for="<?php echo esc_attr( $id ); ?>">
			<input
				type="checkbox"
				name="<?php echo esc_attr( $name ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				value="yes"
				class="<?php echo esc_attr( trim( $class ) ); ?>"
				<?php checked( $checked ); ?>
			/>
			<span class="sidrena-cijena-toggle__track" aria-hidden="true"></span>
			<span class="sidrena-cijena-toggle__label"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Floppy-disk glyph used on primary save buttons.
	 *
	 * @return string
	 */
	public function save_icon_html() {
		return '<span class="sidrena-cijena-save-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20.5 5.8 18.2 3.5c-.4-.4-.9-.6-1.4-.6H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V7.2c0-.5-.2-1-.5-1.4zM12 19c-1.7 0-3-1.3-3-3s1.3-3 3-3 3 1.3 3 3-1.3 3-3 3zm3-10H6V5h9v4z"/></svg></span>';
	}

	/**
	 * Primary submit button with the save icon.
	 *
	 * @param string $label Button label.
	 */
	public function render_save_button( $label ) {
		echo '<p class="submit"><button type="submit" class="button button-primary">';
		echo $this->save_icon_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo esc_html( $label );
		echo '</button></p>';
	}

	/**
	 * After a save that refused the OIB, MBO or MIBPG or stored it with a warning (CX-SID-110): the notice's kind
	 * (error, warning) and its text; else null. Shown at the top of the page and under the field (Norman 110-1).
	 *
	 * @return array{0:string, 1:string}|null
	 */
	public static function oib_notice() {
		$flag  = isset( $_GET['cx_oib'] ) ? sanitize_key( wp_unslash( $_GET['cx_oib'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$typed = self::oib_typed();
		switch ( $flag ) {
			case 'refused_oib':
				/* translators: %s: the number as typed. */
				return array( 'error', sprintf( __( 'The OIB %s was not saved: its check digit does not match. Check the number.', 'cx-sidrena' ), $typed ) );
			case 'refused_digits':
				return array( 'error', __( 'The OIB, MBO or MIBPG was not saved: enter digits only.', 'cx-sidrena' ) );
			case 'warn':
				/* translators: %s: the number as saved. */
				return array( 'warning', sprintf( __( 'The number %s was saved, but check it: it does not look like an OIB, MBO or MIBPG.', 'cx-sidrena' ), $typed ) );
		}

		return null;
	}

	/**
	 * A value as JSON in an HTML attribute that the page's script reads back with JSON.parse (CX-SID-142). Every '<',
	 * '>', '&', quote and apostrophe in it is a \u escape, so the attribute holds no entity for the browser to decode
	 * into the JSON: esc_attr() alone does not encode an entity again, and a name stored as '&quot;' came back as a raw
	 * quote inside a JSON string, so the list did not parse.
	 *
	 * @param mixed $value Value.
	 * @return string Escaped for the attribute.
	 */
	public static function json_attr( $value ) {
		$json = wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		return esc_attr( is_string( $json ) ? $json : '[]' );
	}

	/**
	 * The save of Products / Additional items was refused (CX-SID-142): the page did not confirm that it had read both
	 * lists, or it sent an empty list the owner did not clear. Norman C3 (ev 3240), at the top and at #cx-items.
	 *
	 * @return string|null
	 */
	public static function items_refused_notice() {
		if ( ! isset( $_GET['cx_items_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		return __( 'Nothing was saved, because the page did not confirm that it had loaded all items, and saving could have deleted the ones it did not show. Your saved items and your price list have not changed. Refresh the page and make your changes again.', 'cx-sidrena' );
	}

	/**
	 * Query args shown once. WordPress's canonical script (wp_admin_canonical_url()) takes them out of the address right
	 * after the page loads, so a refresh does not show the notice again (CX-SID-142, Norman SHOULD 1, ev 3316).
	 * cx_outlet_error is not here: admin-outlets.js reads it from location.search, and the canonical script would remove
	 * it before that (CX-SID-131 handles it in the script).
	 *
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$args[] = 'cx_items_error';

		return $args;
	}

	/**
	 * After a failed build the owner asked for: the next attempt is queued within 5 minutes
	 * (CX_Sidrena_Export::retry_soon(), CX-SID-119). Settings and Status.
	 *
	 * @return string
	 */
	public static function retry_text() {
		return __( 'It will be tried again automatically in a few minutes.', 'cx-sidrena' );
	}

	/**
	 * After "Save and generate now" (sidrena_xml): the notice's kind and its text, without the link; else null. Next to
	 * the OIB field when the save landed there (Argus 110-C), the same words as at the top of the page.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	public static function xml_notice() {
		$flag = isset( $_GET['sidrena_xml'] ) ? sanitize_key( wp_unslash( $_GET['sidrena_xml'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		switch ( $flag ) {
			case '1':
				return array(
					'success',
					CX_Sidrena_Settings::instance()->is_multi_poslovnica()
						? __( 'Public outlet price lists have been generated.', 'cx-sidrena' )
						: __( 'The public price list has been generated.', 'cx-sidrena' ),
				);
			case '2':
				return array( 'warning', __( 'No additional outlet has products. Below, open Show advanced settings and, in Outlets, choose All published webshop products or at least one product for each outlet.', 'cx-sidrena' ) );
			case 'b2b':
				return array( 'info', CX_Sidrena_Export::b2b_message() );
			case 'nobazna':
				// The first price list waits for the first bazna (CX-SID-68). Not a failure: nothing is retried.
				return array( 'error', CX_Sidrena_Export::no_bazna_message() );
			case 'nooib':
				// No OIB or MBO (CX-SID-159). Not a failure: nothing is retried; the red block at the top links to the field.
				return array( 'error', CX_Sidrena_Export::no_legal_id_message() );
			case 'busy':
				// Another build was running; the one with this save follows it (CX-SID-119). Not a failure.
				return array( 'info', __( 'The price list is being generated right now. As soon as that is done, it will be generated once more, with what you just saved.', 'cx-sidrena' ) );
			case '0':
				// A file or folder could not be written: the uploads folder is where to look.
				return array( 'error', __( 'The public price list could not be generated. Check uploads folder permissions.', 'cx-sidrena' ) . ' ' . self::retry_text() );
			case 'fail':
				// Any other failure: no reason we do not know (CX-SID-119: busy and errors were all "check permissions").
				return array( 'error', __( 'The public price list was not generated.', 'cx-sidrena' ) . ' ' . self::retry_text() );
		}

		return null;
	}

	/**
	 * The OIB, MBO or MIBPG as typed, after a save that refused it or warned (CX-SID-110), else ''.
	 *
	 * @return string
	 */
	public static function oib_typed() {
		$raw = isset( $_GET['cx_oib_typed'] ) ? (string) wp_unslash( $_GET['cx_oib_typed'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = sanitize_text_field( $raw );

		return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 40, 'UTF-8' ) : substr( $raw, 0, 40 );
	}

	/**
	 * Copyable URL or shortcode row.
	 *
	 * @param string $label      Box label.
	 * @param string $value      Code / URL text.
	 * @param string $open_url   Optional open-link href.
	 * @param string $open_label Optional open-link label.
	 * @param string $open_class Open-link button class.
	 * @param string $open_icon  Dashicon class.
	 * @param string $code_id    Optional id on the code element.
	 */
	public function render_url_box( $label, $value, $open_url = '', $open_label = '', $open_class = 'button button-primary', $open_icon = 'dashicons-external', $code_id = '' ) {
		?>
		<div class="sidrena-cijena-urlbox">
			<span class="sidrena-cijena-urlbox__label"><?php echo esc_html( $label ); ?></span>
			<code<?php echo '' !== $code_id ? ' id="' . esc_attr( $code_id ) . '"' : ''; ?>><?php echo esc_html( $value ); ?></code>
			<div class="sidrena-cijena-urlbox__actions">
				<button type="button" class="button button-secondary sidrena-cijena-copy" data-copy="<?php echo esc_attr( $value ); ?>">
					<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
					<?php echo esc_html__( 'Copy', 'cx-sidrena' ); ?>
				</button>
				<?php if ( '' !== $open_url && '' !== $open_label ) : ?>
					<a class="<?php echo esc_attr( $open_class ); ?>" href="<?php echo esc_url( $open_url ); ?>" target="_blank" rel="noopener noreferrer">
						<span class="dashicons <?php echo esc_attr( $open_icon ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $open_label ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Catalog repeater. Without Woo this is the whole pricelist. With Woo it is extra rows appended to the shop feed.
	 */
	private function render_tab_proizvodi() {
		include CX_SIDRENA_PATH . 'includes/admin/tab-proizvodi.php';
	}

	/**
	 * Uvoz / izvoz tab.
	 */
	private function render_tab_uvoz_izvoz() {
		include CX_SIDRENA_PATH . 'includes/admin/tab-uvoz-izvoz.php';
	}

	/**
	 * Javni cjenik tab: shortcode that prints the archive file list.
	 */
	private function render_archive_shortcode_docs() {
		$settings = CX_Sidrena_Settings::instance();
		$code     = '[sidrena-arhiva]';
		$this->render_url_box( __( 'Archive shortcode', 'cx-sidrena' ), $code );
		if ( ! $settings->is_multi_poslovnica() ) {
			return;
		}

		$web = CX_Sidrena_Feed_Routes::instance()->webshop_oznaka();
		$this->render_url_box( $web, '[sidrena-arhiva oznaka="' . $web . '"]' );
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
			$outlet_code = '[sidrena-arhiva oznaka="' . $outlet['oznaka'] . '"]';
			$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
			$this->render_url_box( $outlet_name, $outlet_code );
		}
	}

	/**
	 * Javni cjenik tab: shortcode that prints the table.
	 *
	 * @param CX_Sidrena_Settings $settings Settings helper.
	 */
	private function render_cjenik_shortcode_docs( $settings ) {
		$code = '[sidrena-cjenik]';
		$this->render_url_box( __( 'Table shortcode', 'cx-sidrena' ), $code );
		if ( $settings->is_multi_poslovnica() ) {
			foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
				$outlet_code = '[sidrena-cjenik oznaka="' . $outlet['oznaka'] . '"]';
				$outlet_name = '' !== $outlet['naziv'] ? $outlet['naziv'] : $outlet['oznaka'];
				$this->render_url_box( $outlet_name, $outlet_code );
			}
		}
	}

	/**
	 * The Settings form.
	 */
	public function handle_settings_save() {
		CX_Sidrena_Admin_Save::instance()->handle_main_settings_save();
	}

	/**
	 * Save standalone catalog repeater.
	 */
	public function handle_catalog_save() {
		CX_Sidrena_Admin_Save::instance()->handle_catalog_save();
	}

	/**
	 * Confirm replacing a bare "Cijena" label, or remember that the merchant kept it.
	 */
	public function handle_prefix_choice() {
		$use     = isset( $_GET['sidrena_cijena_use_bazna'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dismiss = isset( $_GET['sidrena_cijena_dismiss_prefix'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $use && ! $dismiss ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( $use ) {
			check_admin_referer( 'sidrena_cijena_use_bazna' );
			CX_Sidrena_Settings::instance()->apply_bazna_prefix();
			delete_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION );
		} else {
			check_admin_referer( 'sidrena_cijena_dismiss_prefix' );
			$prefix = trim( (string) CX_Sidrena_Settings::instance()->get( 'prefix' ) );
			update_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION, $prefix, false );
		}

		wp_safe_redirect( remove_query_arg( array( 'sidrena_cijena_use_bazna', 'sidrena_cijena_dismiss_prefix', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Offer the statutory label when the shop still shows only "Cijena": one line under the label in Settings, once
	 * (warnings are discreet and on the merchant's side, Hrvoje 15.11.; before CX-SID-23 a notice on every screen).
	 */
	public function prefix_advice() {
		$prefix = trim( (string) CX_Sidrena_Settings::instance()->get( 'prefix' ) );
		if ( ! CX_Sidrena_Settings::is_generic_prefix( $prefix ) ) {
			return;
		}

		if ( (string) get_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION, '' ) === $prefix ) {
			return;
		}

		$replacement = CX_Sidrena_Settings::bazna_replacement( $prefix );
		$use_url     = wp_nonce_url( add_query_arg( 'sidrena_cijena_use_bazna', '1' ), 'sidrena_cijena_use_bazna' );
		$keep_url    = wp_nonce_url( add_query_arg( 'sidrena_cijena_dismiss_prefix', '1' ), 'sidrena_cijena_dismiss_prefix' );

		echo '<p class="description cx-settings__advice">';
		echo esc_html(
			sprintf(
				/* translators: 1: current prefix, 2: suggested prefix */
				__( 'The label next to the price is “%1$s”. Customers may confuse it with the price they pay. The legal name is bazna cijena. Suggested label: %2$s.', 'cx-sidrena' ),
				$prefix,
				$replacement
			)
		);
		echo ' <a href="' . esc_url( $use_url ) . '">' . esc_html( sprintf( /* translators: %s: prefix */ __( 'Set “%s”', 'cx-sidrena' ), $replacement ) ) . '</a>';
		echo ' · <a href="' . esc_url( $keep_url ) . '">' . esc_html__( 'Keep current label', 'cx-sidrena' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Settings > Dates, the Status fixes and the Import tab (CX-SID-42): assets/js/admin-bazna.js and its texts.
	 */
	private function enqueue_bazna_screens() {
		// Counts are written "Label: N" (or "(N)") as everywhere in the plugin: the i18n tools have no plural forms.
		wp_enqueue_script( 'cx-sidrena-admin-bazna', CX_SIDRENA_URL . 'assets/js/admin-bazna.js', array(), CX_SIDRENA_VERSION, true );
		wp_localize_script(
			'cx-sidrena-admin-bazna',
			'cxBazna',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'statusNonce' => wp_create_nonce( 'cx_sidrena_status' ),
				'importNonce' => wp_create_nonce( 'cx_sidrena_import' ),
				't'           => array(
					'error'        => __( 'An error occurred. Please try again.', 'cx-sidrena' ),
					'noName'       => __( 'Enter the name of who confirmed.', 'cx-sidrena' ),
					'noConsent'    => __( 'Tick the confirmation.', 'cx-sidrena' ),
					'noFile'       => __( 'Choose a file.', 'cx-sidrena' ),
					'noDate'       => __( 'Enter the date of the confirmation.', 'cx-sidrena' ),
					'reading'      => __( 'Reading the file…', 'cx-sidrena' ),
					'retry'        => __( 'Connection lost. Trying again…', 'cx-sidrena' ),
					'product'      => __( 'Product', 'cx-sidrena' ),
					'sku'          => __( 'SKU', 'cx-sidrena' ),
					'line'         => __( 'Line', 'cx-sidrena' ),
					'nameInFile'   => __( 'Name in the file', 'cx-sidrena' ),
					'now'          => __( 'Now', 'cx-sidrena' ),
					'new'          => __( 'New', 'cx-sidrena' ),
					'bazna'        => __( 'Bazna cijena', 'cx-sidrena' ),
					'reason'       => __( 'Why', 'cx-sidrena' ),
					'none'         => __( 'none', 'cx-sidrena' ),
					'fromCategory' => __( '(from category)', 'cx-sidrena' ),
					'dayAdded'     => __( '(day added)', 'cx-sidrena' ),
					'waits'        => __( 'waits for Dates', 'cx-sidrena' ),
					'empty'        => __( 'Nothing here.', 'cx-sidrena' ),
					'noHistory'    => __( 'No imports yet.', 'cx-sidrena' ),
					/* translators: 1: page, 2: pages */
					'page'         => __( 'Page %1$d of %2$d', 'cx-sidrena' ),
					'prev'         => __( 'Previous', 'cx-sidrena' ),
					'next'         => __( 'Next', 'cx-sidrena' ),
					/* translators: 1: name of who confirmed, 2: date */
					'confirmedBy'  => __( 'Confirmed by %1$s on %2$s', 'cx-sidrena' ),
					/* translators: %s: first 8 characters of the file's sha256 */
					'fingerprint'  => __( 'File fingerprint: %s', 'cx-sidrena' ),
					/* translators: %d: products and variations */
					'count'        => __( 'Products and variations: %d', 'cx-sidrena' ),
					// Norman T6 (CX-SID-122): true for a file of prices and for one of brands and codes.
					/* translators: %d: rows of the file that will be imported */
					'importN'      => __( 'Import (%d)', 'cx-sidrena' ),
					/* translators: %d: rows imported */
					'imported'     => __( 'Imported: %d.', 'cx-sidrena' ),
					/* translators: %d: rows */
					'notImported'  => __( 'Not imported: %d.', 'cx-sidrena' ),
					/* translators: %d: rows */
					'noDateRows'   => __( 'Rows that take their date from Dates: %d. They wait until Dates are saved in Settings; the other rows can be imported now.', 'cx-sidrena' ),
					/* translators: %d: rows */
					'waited'       => __( 'Not imported (they wait for Dates): %d. Save Dates in Settings, then import the same file again.', 'cx-sidrena' ),
					// The import's job (CX-SID-22): it runs without this page, so the tab follows it and can stop it.
					'lowest'       => __( 'Lowest price in 30 days', 'cx-sidrena' ),
					/* translators: 1: name of who confirmed, 2: date and time it started */
					'runningWho'   => __( 'Confirmed by %1$s, started %2$s.', 'cx-sidrena' ),
					'stopAsk'      => __( 'Stop the import? What is already imported stays; the rest is not imported.', 'cx-sidrena' ),
					/* translators: %d: rows */
					'stopped'      => __( 'Stopped. Not imported: %d.', 'cx-sidrena' ),
					'otherDone'    => __( 'The other import has finished. You can import your file now.', 'cx-sidrena' ),
					'openStatus'   => __( 'Open Status', 'cx-sidrena' ),
					'inProgress'   => __( 'In progress', 'cx-sidrena' ),
					// After Norman's review of the import on 22 (CX-SID-42): whose import a Stop stops, a refused Stop
					// that is not a pause, and a lost connection that does not stop the import.
					'otherRunning' => __( 'Another import is running', 'cx-sidrena' ),
					/* translators: 1: name of who confirmed, 2: date and time it started */
					'stopAskWho'   => __( 'Stop the import (confirmed by %1$s, started %2$s)? What is already imported stays; the rest is not imported.', 'cx-sidrena' ),
					'notStopped'   => __( 'Not stopped yet: some rows are being saved right now. The import goes on; press Stop import again in a moment.', 'cx-sidrena' ),
					// CX-SID-122, Norman T4: the column of Brand, EAN and Barcode in the preview.
					'codesHead'    => __( 'Brand · EAN · Barcode', 'cx-sidrena' ),
					'codeLabels'   => array(
						'marka'  => __( 'Brand', 'cx-sidrena' ),
						'ean'    => __( 'EAN', 'cx-sidrena' ),
						'barkod' => __( 'Barcode', 'cx-sidrena' ),
					),
					/* translators: 1: Brand, EAN or Barcode, 2: now, 3: after the import */
					'codeChange'   => __( '%1$s: %2$s → %3$s', 'cx-sidrena' ),
					/* translators: 1: Brand, EAN or Barcode, 2: the value WooCommerce has */
					'codeKept'     => __( '%1$s: %2$s from WooCommerce stays', 'cx-sidrena' ),
					'noChange'     => __( 'no change', 'cx-sidrena' ),
					'lost'         => __( 'The connection to the shop was lost. The import goes on without this page. Press Continue to show its progress again, or reload the page later.', 'cx-sidrena' ),
				),
				'statusUrl'   => CX_Sidrena_Admin_Status::url(),
				'priceFormat' => html_entity_decode( sprintf( get_woocommerce_price_format(), get_woocommerce_currency_symbol(), '%s' ), ENT_QUOTES, 'UTF-8' ),
				'decimal'     => wc_get_price_decimal_separator(),
			)
		);
	}

	/**
	 * Admin assets on product and settings screens.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product  = $screen && 'product' === $screen->id;
		$is_settings = in_array( $hook, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true );
		// The product list: only the styles of its "Bazna cijena" column (width, the yellow "missing"). Without them the
		// column got no width and its text stood one letter a line (Norman, CX-SID-24). Every rule in admin.css is
		// scoped to our classes, so nothing else on the list changes.
		$is_list = $screen && 'edit-product' === $screen->id;

		if ( ! $is_product && ! $is_settings && ! $is_list ) {
			return;
		}

		wp_enqueue_style(
			'sidrena-cijena-admin',
			CX_SIDRENA_URL . 'assets/css/admin.css',
			array(),
			CX_SIDRENA_VERSION
		);

		if ( $is_list ) {
			return;
		}

		$script_deps = array( 'jquery' );
		if ( $is_settings && CX_Sidrena_Plugin::woocommerce_active() ) {
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
				$script_deps[] = 'selectWoo';
			}
			// Only for wc_enhanced_select_params: the outlet product picker's own messages (type 2 characters, no
			// results, searching) in WooCommerce's translation (CX-SID-131). It touches only WooCommerce's own classes.
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		wp_enqueue_script(
			'sidrena-cijena-admin',
			CX_SIDRENA_URL . 'assets/js/admin.js',
			$script_deps,
			CX_SIDRENA_VERSION,
			true
		);

		if ( $is_product ) {
			wp_enqueue_script(
				'cx-sidrena-admin-product',
				CX_SIDRENA_URL . 'assets/js/admin-product.js',
				array( 'jquery', 'jquery-ui-datepicker' ),
				CX_SIDRENA_VERSION,
				true
			);
		}

		if ( $is_settings ) {
			$catalog_choices = array();
			if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
				foreach ( CX_Sidrena_Catalog::instance()->all() as $row ) {
					$label = $row['naziv'];
					if ( '' !== $row['sku'] ) {
						$label .= ' (' . $row['sku'] . ')';
					}
					$catalog_choices[] = array(
						'id'    => $row['id'],
						'label' => $label,
					);
				}
			}

			wp_localize_script(
				'sidrena-cijena-admin',
				'sidrenaCijenaAdmin',
				array(
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'importNonce'        => wp_create_nonce( 'sidrena_cijena_import' ),
					'productSearchNonce' => wp_create_nonce( 'search-products' ),
					'standalone'         => ! CX_Sidrena_Plugin::woocommerce_active(),
					// The table shows id="dN" in both modes (CX-SID-100): it works with and without WooCommerce, and a page
					// keeps showing this row if WooCommerce is added later (id="N" would then name the product N).
					'catalogIdPrefix'    => 'd',
					'cjmEnabled'         => CX_Sidrena_Settings::instance()->is_cjm_enabled(),
					'multiPoslovnica'    => CX_Sidrena_Settings::instance()->is_multi_poslovnica(),
					'defaultDate'        => (string) CX_Sidrena_Settings::instance()->get( 'default_date' ),
					'defaultPrefix'      => __( 'Bazna cijena', 'cx-sidrena' ),
					'unsavedConfirm'     => __( 'Your changes in Settings are not saved yet. This button saves only its own section, and those changes will be lost. Continue?', 'cx-sidrena' ),
					'outlets'            => $this->catalog_outlet_choices(),
					'catalogChoices'     => $catalog_choices,
					'productLabels'      => CX_Sidrena_Poslovnice::instance()->product_labels(),
					'i18n'               => array(
						'error'        => __( 'An error occurred. Please try again.', 'cx-sidrena' ),
						'copied'       => __( 'Copied', 'cx-sidrena' ),
						'noFile'       => __( 'Select a CSV or XML file.', 'cx-sidrena' ),
						'analyzing'    => __( 'Reading file…', 'cx-sidrena' ),
						'skuRequired'     => __( 'Select the column/node that contains SKU.', 'cx-sidrena' ),
						'skuOrIdRequired' => __( 'Select the column for SKU or ID.', 'cx-sidrena' ),
						'pageStatus'      => __( 'Page %1$d of %2$d', 'cx-sidrena' ),
						'noImport'     => __( 'Do not import', 'cx-sidrena' ),
						'chooseSku'    => __( 'Select SKU field', 'cx-sidrena' ),
						'importing'    => __( 'Import in progress…', 'cx-sidrena' ),
						'importDone'   => __( 'Import complete.', 'cx-sidrena' ),
						'rowsLabel'    => __( 'rows', 'cx-sidrena' ),
						'updated'      => __( 'updated', 'cx-sidrena' ),
						'skipped'      => __( 'skipped', 'cx-sidrena' ),
						'missing'      => __( 'SKU not found', 'cx-sidrena' ),
						'created'      => __( 'added', 'cx-sidrena' ),
						/* translators: %d: number of services */
						'importUnpub'  => __( "Services imported but not in the price list yet, because the file has no date or bazna cijena for them: %d. Today's price would be wrong for a service you already offer. Fill in what is missing in the table above; a service goes into the price list when you save.", 'cx-sidrena' ),
						'special'      => __( 'not imported: the retail price is a special form of sale, and the file has no name of that form (column naziv_posebnog_oblika), so it is not known which price it is. Import the price list downloaded from this page.', 'cx-sidrena' ),
						// CX-SID-164: an import writes only the price list chosen on its form.
						'other'        => __( 'not imported: these rows belong to another price list (another outlet or the webshop) and were left as they were. To change them, import the file into that outlet.', 'cx-sidrena' ),
						// CX-SID-116: a price that cannot be read for sure is never imported as a guess (Kai).
						'unclear'      => __( 'not imported: a price in the row cannot be read for sure (1.234 can be one thousand two hundred and thirty-four or one point two three four). Nothing in these rows was changed. Write the price with its decimals, e.g. 1234,00 or 1,23, and import the file again.', 'cx-sidrena' ),
						/* translators: %d: row number in the file, as a spreadsheet shows it */
						'unclearRow'   => __( 'Row %d', 'cx-sidrena' ),
						/* translators: %d: number of the item in the XML file */
						'unclearItem'  => __( 'Item %d', 'cx-sidrena' ),
						'addProduct'    => __( 'Add product', 'cx-sidrena' ),
						'duplicate'     => __( 'Duplicate', 'cx-sidrena' ),
						'remove'        => __( 'Remove', 'cx-sidrena' ),
						'addRow'        => __( 'Add row', 'cx-sidrena' ),
						'copyShortcode' => __( 'Copy shortcode', 'cx-sidrena' ),
						'saveForCode'   => __( 'Save price list for shortcode', 'cx-sidrena' ),
						'dostupno'      => __( 'In stock', 'cx-sidrena' ),
						'nedostupno'    => __( 'Out of stock', 'cx-sidrena' ),
						'cjm'           => __( 'CJM', 'cx-sidrena' ),
						'cjmPlaceholder' => __( 'per kg/l', 'cx-sidrena' ),
						'oblikPlaceholder' => 'akcija',
						'jedinicaPlaceholder' => 'kg',
						'datePlaceholder'  => (string) CX_Sidrena_Settings::instance()->get( 'default_date' ),
						'vrstaProizvod'    => __( 'Product', 'cx-sidrena' ),
						'vrstaUsluga'      => __( 'Service', 'cx-sidrena' ),
						'upitProductWarn'  => __( 'A product must have a displayed price (ZZP art. 7(1)). Enter it with its price, like your other products.', 'cx-sidrena' ),
						// CX-SID-130 (Norman 3113): the question in a new service row, its help, the row's mark, placeholders.
						'odPrijeLabel'     => __( 'Do you already offer this service?', 'cx-sidrena' ),
						'odPrijeNe'        => __( 'No, it is new (from today)', 'cx-sidrena' ),
						'odPrijeDa'        => __( 'Yes, I already offer it', 'cx-sidrena' ),
						'odPrijeHelpNe'    => __( "Its date is today and its bazna cijena is today's regular price. That is right only for a new service.", 'cx-sidrena' ),
						'odPrijeHelpDa'    => __( 'Enter the day you started offering it (for any day before 10.9.2026., 10.9.2026. applies) and, as the bazna cijena, its price on that day. Until both are entered, the service is not in the price list.', 'cx-sidrena' ),
						'unpubRow'         => __( 'Not in the price list: no date or bazna cijena.', 'cx-sidrena' ),
						'noBaznaRow'       => __( 'No bazna cijena.', 'cx-sidrena' ), // CX-SID-133, Norman 3272
						'todayDate'        => CX_Sidrena_Money::customer_date( CX_Sidrena_Archive::legal_date( 'd.m.Y.' ) ),
						'serviceFrom'      => CX_Sidrena_Money::customer_date( CX_Sidrena_Catalog::SERVICE_FROM ),
						'regularPrice'     => __( 'Regular price', 'cx-sidrena' ),
						'webshop'          => __( 'Webshop', 'cx-sidrena' ),
						'rowCount'       => CX_Sidrena_Plugin::woocommerce_active() ? __( '%d items', 'cx-sidrena' ) : __( '%d products', 'cx-sidrena' ),
						'outletName'     => __( 'Name', 'cx-sidrena' ),
						'outletNameHelp' => __( 'Customers see it on this outlet\'s price list page and in the archive. Not included in the file name.', 'cx-sidrena' ),
						'outletOblik'    => __( 'Outlet type', 'cx-sidrena' ),
						'outletOblikHelp' => __( 'Goes into the file name exactly as written, e.g. prodavaonica.', 'cx-sidrena' ),
						'outletAddress'  => __( 'Outlet address', 'cx-sidrena' ),
						'outletAddressHelp' => __( 'Street, number and town, e.g. Ilica 150 Zagreb. Goes into the file name.', 'cx-sidrena' ),
						'outletCode'     => __( 'Outlet label', 'cx-sidrena' ),
						'outletCodeHelp' => __( 'E.g. P-01 or 002: letters without diacritics, digits and hyphens. Goes into the price list address (/sidrena-cijena-P-01.xml) and the file name. Once the outlet publishes its first price list, the label no longer changes.', 'cx-sidrena' ),
						'outletCodeEmpty' => __( 'Next free number', 'cx-sidrena' ),
						// D1 (CX-SID-74): a place that has published keeps its code, the field is read-only.
						'outletCodeLocked' => __( 'This outlet has already published a price list, so its label no longer changes: the address of its price list must stay the same.', 'cx-sidrena' ),
						'outletSeq'      => __( 'Next storage number', 'cx-sidrena' ),
						'outletSeqHelp'  => __( 'Goes up by one with each new price list file. You can raise it, but not lower it.', 'cx-sidrena' ),
						'outletProducts' => __( 'Products', 'cx-sidrena' ),
						'outletAll'      => __( 'All published webshop products', 'cx-sidrena' ),
						'outletPicked'   => __( 'Selected products', 'cx-sidrena' ),
						'outletSearchHelp' => __( 'Search by name or SKU.', 'cx-sidrena' ),
						// Without WooCommerce a row checked on an outlet leaves the webshop's price list (belongs_to_webshop).
						'outletProductsHelp' => __( 'Checked products move from the webshop\'s price list to this outlet\'s.', 'cx-sidrena' ),
						// Kai (CX-SID-74 3005): a place's price list has the webshop's prices.
						'outletPrices'   => __( 'This outlet\'s price list uses the webshop\'s prices. If prices at this outlet are different, the price list does not show them correctly.', 'cx-sidrena' ),
						'outletNoAddress' => __( 'Enter the address. It goes into the official name of this outlet\'s price list file.', 'cx-sidrena' ),
						'outletNoOblik'  => __( 'Enter the type, e.g. prodavaonica. It goes into the official name of this outlet\'s price list file.', 'cx-sidrena' ),
						'outletCodeWebshop' => /* translators: %s: the label as it would be saved */ __( 'The webshop already has the label %s. Enter another one, or leave the field empty for the next free number.', 'cx-sidrena' ),
						'outletCodeTaken' => /* translators: 1: the label as it would be saved, 2: the name of the outlet that has it */ __( 'The outlet %2$s already has the label %1$s. Enter another one, or leave the field empty for the next free number.', 'cx-sidrena' ),
						'outletCodeOther' => /* translators: %s: the label as it would be saved */ __( 'Another outlet already has the label %s. Enter another one, or leave the field empty for the next free number.', 'cx-sidrena' ),
						'outletNoProducts' => __( 'Choose at least one product, or All published webshop products.', 'cx-sidrena' ),
						// A save the server refused (cx_outlet_error): the form shows what was sent (S1), or only the stored state (S2).
						'outletsRefused' => __( 'The outlets are not saved. The reason is next to the outlet below.', 'cx-sidrena' ),
						'outletsRefusedStored' => /* translators: %s: the reason */ __( 'The outlets are not saved, so you see the state before your changes. Reason: %s', 'cx-sidrena' ),
						// S2's reasons (Norman 3132): without the sent values the page knows neither the place nor the label.
						'outletsWhyAddress' => __( 'An outlet without an address can\'t be saved, because the address goes into the official name of its price list file.', 'cx-sidrena' ),
						'outletsWhyOblik'   => __( 'An outlet without a type can\'t be saved, because the type goes into the official name of its price list file.', 'cx-sidrena' ),
						'outletsWhyCode'    => __( 'The webshop or another outlet already has the label you entered, or the outlet has published a price list in the meantime, so its label no longer changes.', 'cx-sidrena' ),
						// The webshop's label is one a place keeps (cx_outlet_error=oznaka:webshop; Norman W1-W3, 3242/3245).
						'outletWebCodeTaken' => /* translators: 1: the label as it would be saved, 2: the name of the outlet that has it */ __( 'The outlet %2$s already has the label %1$s. Enter another label for the webshop: the address of the webshop\'s price list has no label, so it stays the same.', 'cx-sidrena' ),
						'outletWebCodeOther' => /* translators: %s: the label as it would be saved */ __( 'Another outlet already has the label %s. Enter another label for the webshop: the address of the webshop\'s price list has no label, so it stays the same.', 'cx-sidrena' ),
						'outletWebCodeEmpty' => __( 'An empty field means the label 001.', 'cx-sidrena' ),
						'outletsRefusedFields' => __( 'The outlets are not saved. The reason is next to the marked fields below.', 'cx-sidrena' ),
						'outletsWhyWebCode' => __( 'The label you entered for the webshop is already the label of an outlet.', 'cx-sidrena' ),
						// cx_outlet_error=nepotvrdeno (Tux, CX-SID-142): the page did not confirm it loaded (Norman C3, 3240).
						'outletsUnconfirmed' => __( 'The outlets are not saved, because the page did not confirm that it had loaded all outlets, and saving could have deleted the ones it did not show. Your saved outlets have not changed. Refresh the page and make your changes again.', 'cx-sidrena' ),
						'outletsBusy'    => __( 'The outlets are not saved, because they were also being saved somewhere else at the same moment, for example in another browser tab. Your changes are still here. Click Save outlets again.', 'cx-sidrena' ),
						'searchProducts' => __( 'Search product…', 'cx-sidrena' ),
						'noCatalog'      => __( 'First add products on the Products tab.', 'cx-sidrena' ),
						'chooseOutlet'   => __( 'Select the outlet to import into.', 'cx-sidrena' ),
						'removeOutlet'   => __( 'Remove outlet', 'cx-sidrena' ),
					),
				)
			);

			wp_enqueue_script(
				'sidrena-cijena-admin-catalog',
				CX_SIDRENA_URL . 'assets/js/admin-catalog.js',
				array( 'sidrena-cijena-admin' ),
				CX_SIDRENA_VERSION,
				true
			);
			// MJDD's bulk import is there only without WooCommerce (CX-SID-43, tab-uvoz-izvoz.php).
			if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
				wp_enqueue_script(
					'sidrena-cijena-admin-import',
					CX_SIDRENA_URL . 'assets/js/admin-import.js',
					array( 'sidrena-cijena-admin' ),
					CX_SIDRENA_VERSION,
					true
				);
			}
			wp_enqueue_script(
				'sidrena-cijena-admin-outlets',
				CX_SIDRENA_URL . 'assets/js/admin-outlets.js',
				array( 'sidrena-cijena-admin' ),
				CX_SIDRENA_VERSION,
				true
			);
			if ( CX_Sidrena_Plugin::woocommerce_active() ) {
				$this->enqueue_bazna_screens();
			}
		}
	}

	/**
	 * Simple / external product fields under sale price.
	 */
	public function render_simple_fields() {
		CX_Sidrena_Product_Fields::instance()->render_simple_fields();
	}

	/**
	 * Variation fields under variation sale price.
	 *
	 * @param int     $loop           Loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {
		CX_Sidrena_Product_Fields::instance()->render_variation_fields( $loop, $variation_data, $variation );
	}


	/**
	 * Persist simple product fields.
	 *
	 * @param WC_Product $product Product.
	 */
	public function save_simple_fields( $product ) {
		CX_Sidrena_Fields::save_simple( $product );
	}

	/**
	 * Persist variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Loop index.
	 */
	public function save_variation_fields( $variation_id, $i ) {
		CX_Sidrena_Fields::save_variation( $variation_id, $i );
	}
}
