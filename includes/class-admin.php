<?php
/**
 * Admin: settings, standalone catalog, and WooCommerce product fields.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

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
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_xml_settings_save' ) );
		add_action( 'admin_init', array( $this, 'handle_poslovnice_save' ) );
		add_action( 'admin_init', array( $this, 'handle_catalog_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
		add_action( 'admin_head', array( $this, 'hide_footer_thankyou' ) );
		add_action( 'admin_footer', array( $this, 'hide_footer_thankyou' ), 999 );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ), 999 );
		add_action( 'admin_init', array( $this, 'handle_prefix_choice' ) );
		add_action( 'admin_notices', array( $this, 'prefix_notice' ) );
		add_action( 'admin_notices', array( $this, 'followup_notice' ) );

		if ( ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'maybe_dismiss_fill_prompt' ) );
		add_action( 'admin_notices', array( $this, 'snapshot_notice' ) );
		add_action( 'wp_ajax_sidrena_cijena_snapshot', array( $this, 'ajax_snapshot' ) );

		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_simple_fields' ) );
		add_action( 'woocommerce_variation_options_pricing', array( $this, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_simple_fields' ) );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 10, 2 );

		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );
	}

	/**
	 * WooCommerce submenu, or a top-level menu without Woo.
	 */
	public function register_menu() {
		$cap   = CX_Sidrena_Plugin::manage_capability();
		$title = __( 'Sidrena cijena', 'cx-sidrena' );

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
		$saved     = isset( $_GET['updated'] ) && '1' === $_GET['updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_ok    = isset( $_GET['sidrena_xml'] ) && '1' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_fail  = isset( $_GET['sidrena_xml'] ) && '0' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$xml_empty = isset( $_GET['sidrena_xml'] ) && '2' === $_GET['sidrena_xml']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$multi     = $settings->is_multi_poslovnica();
		$xml_url   = $export->get_public_url();
		?>
		<div class="wrap sidrena-cijena-settings">
			<div class="sidrena-cijena-hero">
				<h1><?php echo esc_html__( 'Sidrena cijena', 'cx-sidrena' ); ?></h1>
			</div>

			<nav class="sidrena-cijena-tabs" aria-label="<?php echo esc_attr__( 'Sidrena cijena', 'cx-sidrena' ); ?>">
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

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'cx-sidrena' ); ?></p></div>
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

			<?php if ( $xml_empty ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php echo esc_html__( 'No outlet has products added. Add products on the Outlets tab.', 'cx-sidrena' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $xml_fail ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'The public price list could not be generated. Check uploads folder permissions.', 'cx-sidrena' ); ?></p></div>
			<?php endif; ?>

			<?php do_action( 'sidrena_cijena_settings_notices', $tab ); ?>

			<div class="sidrena-cijena-panels">
				<?php
				if ( 'proizvodi' === $tab ) {
					$this->render_tab_proizvodi();
				} elseif ( 'uvoz-izvoz' === $tab ) {
					$this->render_tab_uvoz_izvoz();
				} elseif ( 'javni-xml' === $tab ) {
					$this->render_tab_javni_xml( $export );
				} elseif ( 'poslovnice' === $tab ) {
					$this->render_tab_poslovnice();
				} elseif ( 'postavke' === $tab ) {
					$this->render_tab_postavke( $settings );
				} else {
					do_action( 'sidrena_cijena_render_settings_tab', $tab );
				}
				?>

				<section class="sidrena-cijena-card sidrena-cijena-credit">
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: developer website URL */
								__( 'Developed by <a href="%s" target="_blank" rel="noopener noreferrer">Jure Zadravec / MJDD</a>', 'cx-sidrena' ),
								'https://mjdd.hr'
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
					<a class="sidrena-cijena-credit__logo" href="https://mjdd.hr" target="_blank" rel="noopener noreferrer">
						<img src="<?php echo esc_url( CX_SIDRENA_URL . 'assets/images/mjdd-logo-black.webp' ); ?>" alt="MJDD" width="135" height="36" />
					</a>
				</section>
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

		$tabs['proizvodi'] = array(
			'label' => CX_Sidrena_Plugin::woocommerce_active()
				? __( 'Additional items', 'cx-sidrena' )
				: __( 'Products', 'cx-sidrena' ),
			'icon'  => 'dashicons-products',
		);

		$tabs['postavke'] = array(
			'label' => __( 'Settings', 'cx-sidrena' ),
			'icon'  => 'dashicons-admin-generic',
		);
		$tabs['uvoz-izvoz'] = array(
			'label' => __( 'Import/Export', 'cx-sidrena' ),
			'icon'  => 'dashicons-migrate',
		);
		$tabs['javni-xml'] = array(
			'label' => __( 'Public price list', 'cx-sidrena' ),
			'icon'  => 'dashicons-media-code',
		);
		$tabs['poslovnice'] = array(
			'label' => __( 'Outlets', 'cx-sidrena' ),
			'icon'  => 'dashicons-store',
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
			return 'javni-xml';
		}

		$keys = array_keys( $tabs );
		return isset( $keys[0] ) ? $keys[0] : 'postavke';
	}

	/**
	 * Settings tab URL.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function get_settings_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => 'sidrena-cijena',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
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
	 * Postavke tab: display options, then snapshot fill.
	 *
	 * @param CX_Sidrena_Settings $settings Settings helper.
	 */
	private function render_tab_postavke( $settings ) {
		include CX_SIDRENA_PATH . 'includes/admin/tab-postavke.php';
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
	 * Javni XML tab.
	 *
	 * @param CX_Sidrena_Export $export Export helper.
	 */
	private function render_tab_javni_xml( $export ) {
		include CX_SIDRENA_PATH . 'includes/admin/tab-javni-xml.php';
	}

	/**
	 * Save settings form.
	 */
	public function handle_settings_save() {
		CX_Sidrena_Admin_Save::instance()->handle_settings_save();
	}

	/**
	 * Save public XML options from the Javni XML tab.
	 */
	public function handle_xml_settings_save() {
		CX_Sidrena_Admin_Save::instance()->handle_xml_settings_save();
	}

	/**
	 * Save standalone catalog repeater.
	 */
	public function handle_catalog_save() {
		CX_Sidrena_Admin_Save::instance()->handle_catalog_save();
	}

	/**
	 * Hide the post-activation fill prompt.
	 */
	public function maybe_dismiss_fill_prompt() {
		if ( ! isset( $_GET['sidrena_cijena_dismiss_fill'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer( 'sidrena_cijena_dismiss_fill' );

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		delete_option( CX_Sidrena_Activator::FILL_PROMPT_OPTION );
		wp_safe_redirect( remove_query_arg( array( 'sidrena_cijena_dismiss_fill', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * After activation, point at the two fill buttons. Shown on every admin screen until dismissed or a fill finishes.
	 */
	private function render_fill_prompt_notice() {
		if ( 'yes' !== get_option( CX_Sidrena_Activator::FILL_PROMPT_OPTION ) ) {
			return;
		}

		$settings_url = $this->get_settings_tab_url( 'postavke' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'sidrena_cijena_dismiss_fill', '1' ),
			'sidrena_cijena_dismiss_fill'
		);

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'The plugin does not write prices on activation. You can fill empty fields on the Settings tab: from the regular price (the date stays the default) or with today\'s date.', 'cx-sidrena' );
		echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open settings', 'cx-sidrena' ) . '</a>';
		echo ' · <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Hide', 'cx-sidrena' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Confirm replacing a bare "Cijena" label, or remember that the merchant kept it.
	 */
	public function handle_prefix_choice() {
		$use     = isset( $_GET['sidrena_cijena_use_bazna'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$dismiss = isset( $_GET['sidrena_cijena_dismiss_prefix'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$later   = isset( $_GET['sidrena_cijena_dismiss_followup'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $use && ! $dismiss && ! $later ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( $use ) {
			check_admin_referer( 'sidrena_cijena_use_bazna' );
			CX_Sidrena_Settings::instance()->apply_bazna_prefix();
			delete_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION );
		} elseif ( $dismiss ) {
			check_admin_referer( 'sidrena_cijena_dismiss_prefix' );
			$prefix = trim( (string) CX_Sidrena_Settings::instance()->get( 'prefix' ) );
			update_option( CX_Sidrena_Settings::PREFIX_CHOICE_OPTION, $prefix, false );
		} else {
			check_admin_referer( 'sidrena_cijena_dismiss_followup' );
			update_option( CX_Sidrena_Settings::FOLLOWUP_NOTICE_OPTION, 'yes', false );
		}

		wp_safe_redirect( remove_query_arg( array( 'sidrena_cijena_use_bazna', 'sidrena_cijena_dismiss_prefix', 'sidrena_cijena_dismiss_followup', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Offer the statutory label when the shop still shows only "Cijena".
	 */
	public function prefix_notice() {
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

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

		echo '<div class="notice notice-warning"><p>';
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
		echo '</p></div>';
	}

	/**
	 * The draft pravilnici are still in consultation. The next release follows the published text.
	 */
	public function followup_notice() {
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( 'yes' === get_option( CX_Sidrena_Settings::FOLLOWUP_NOTICE_OPTION ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_sidrena-cijena', 'toplevel_page_sidrena-cijena' ), true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'sidrena_cijena_dismiss_followup', '1' ), 'sidrena_cijena_dismiss_followup' );

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'The draft regulations on bazna cijena and on publishing price lists are open for consultation until 13 November 2026. The next version, after publication in Narodne novine, adds net quantity, a bazna_cijena column, exceptions, and publishing the price list only when a price changes. Until then, the daily generation after 00:05 stays, and the file is refreshed about a minute after a price, sale or stock change.', 'cx-sidrena' );
		echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Hide', 'cx-sidrena' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Notice while a snapshot is running, or after activation until prices are filled or the prompt is dismissed.
	 */
	public function snapshot_notice() {
		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		if ( ! CX_Sidrena_Activator::is_pending() ) {
			$this->render_fill_prompt_notice();
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$allowed = (
			'woocommerce_page_sidrena-cijena' === $screen->id
			|| 'toplevel_page_sidrena-cijena' === $screen->id
			|| 'product' === $screen->id
			|| 'edit-product' === $screen->id
			|| ( isset( $screen->post_type ) && 'product' === $screen->post_type )
			|| ( is_string( $screen->id ) && false !== strpos( $screen->id, 'woocommerce' ) )
		);

		if ( ! $allowed ) {
			return;
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'CX Sidrena: saving the regular prices of products and variations is in progress. Sidrena cijena values that are already entered will not be overwritten.', 'cx-sidrena' );
		echo '</p></div>';
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

		if ( ! $is_product && ! $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'sidrena-cijena-admin',
			CX_SIDRENA_URL . 'assets/css/admin.css',
			array(),
			CX_SIDRENA_VERSION
		);

		$script_deps = array( 'jquery' );
		if ( $is_settings && CX_Sidrena_Plugin::woocommerce_active() ) {
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
				$script_deps[] = 'selectWoo';
			}
		}

		wp_enqueue_script(
			'sidrena-cijena-admin',
			CX_SIDRENA_URL . 'assets/js/admin.js',
			$script_deps,
			CX_SIDRENA_VERSION,
			true
		);

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
					'nonce'              => wp_create_nonce( 'sidrena_cijena_snapshot' ),
					'importNonce'        => wp_create_nonce( 'sidrena_cijena_import' ),
					'productSearchNonce' => wp_create_nonce( 'search-products' ),
					'standalone'         => ! CX_Sidrena_Plugin::woocommerce_active(),
					'catalogIdPrefix'    => CX_Sidrena_Plugin::woocommerce_active() ? 'd' : '',
					'cjmEnabled'         => CX_Sidrena_Settings::instance()->is_cjm_enabled(),
					'multiPoslovnica'    => CX_Sidrena_Settings::instance()->is_multi_poslovnica(),
					'defaultDate'        => (string) CX_Sidrena_Settings::instance()->get( 'default_date' ),
					'defaultPrefix'      => __( 'Bazna cijena', 'cx-sidrena' ),
					'outlets'            => $this->catalog_outlet_choices(),
					'catalogChoices'     => $catalog_choices,
					'productLabels'      => CX_Sidrena_Poslovnice::instance()->product_labels(),
					'i18n'               => array(
						'running'      => __( 'Processing…', 'cx-sidrena' ),
						'progress'     => __( 'Processing… %1$s / %2$s', 'cx-sidrena' ),
						'retry'        => __( 'Connection lost. Continuing automatically…', 'cx-sidrena' ),
						'stalled'      => __( 'Processing stopped. Press the button again to continue from the same place. Already entered prices are not overwritten.', 'cx-sidrena' ),
						'done'         => __( 'Done. Empty fields were filled.', 'cx-sidrena' ),
						'doneToday'    => __( 'Done. Empty fields were filled with today\'s date.', 'cx-sidrena' ),
						'todayConfirm' => __( 'This fills empty sidrena cijena fields with the current regular price and sets today\'s date as the reference date (only if no date is entered yet). Products that existed on 10.09.2026. should keep the default date. Continue?', 'cx-sidrena' ),
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
						'webshop'          => __( 'Webshop', 'cx-sidrena' ),
						'rowCount'       => CX_Sidrena_Plugin::woocommerce_active() ? __( '%d items', 'cx-sidrena' ) : __( '%d products', 'cx-sidrena' ),
						'outletName'     => __( 'Name', 'cx-sidrena' ),
						'outletNameHelp' => __( 'For you in admin only. Not included in the file name.', 'cx-sidrena' ),
						'outletOblik'    => __( 'Outlet type', 'cx-sidrena' ),
						'outletOblikHelp' => __( 'Written exactly as it should appear, e.g. prodavaonica, webshop or servis.', 'cx-sidrena' ),
						'outletAddress'  => __( 'Outlet address', 'cx-sidrena' ),
						'outletAddressHelp' => __( 'Street and city remain in the name, e.g. Ilica 150 Zagreb.', 'cx-sidrena' ),
						'outletCode'     => __( 'Outlet label', 'cx-sidrena' ),
						'outletCodeHelp' => __( 'Internal label, e.g. P-01 or 001. The hyphen is kept. Used in the price list URL: /sidrena-cijena-P-01.xml.', 'cx-sidrena' ),
						'outletSeq'      => __( 'Next storage number', 'cx-sidrena' ),
						'outletSeqHelp'  => __( 'Increases once per day. Regenerating on the same day does not increase the number. The name uses what you enter, e.g. 104.', 'cx-sidrena' ),
						'outletProducts' => __( 'Products', 'cx-sidrena' ),
						'outletProductsHelp' => __( 'Only selected products are included in this outlet\'s price list.', 'cx-sidrena' ),
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
			wp_enqueue_script(
				'sidrena-cijena-admin-import',
				CX_SIDRENA_URL . 'assets/js/admin-import.js',
				array( 'sidrena-cijena-admin' ),
				CX_SIDRENA_VERSION,
				true
			);
			wp_enqueue_script(
				'sidrena-cijena-admin-outlets',
				CX_SIDRENA_URL . 'assets/js/admin-outlets.js',
				array( 'sidrena-cijena-admin' ),
				CX_SIDRENA_VERSION,
				true
			);
		}
	}

	/**
	 * AJAX batch snapshot from the settings page.
	 */
	public function ajax_snapshot() {
		CX_Sidrena_Admin_Save::instance()->ajax_snapshot();
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

	/**
	 * Product list column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_product_column( $columns ) {
		$columns['sidrena_cijena'] = __( 'Sidrena cijena', 'cx-sidrena' );
		return $columns;
	}

	/**
	 * Render product list column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public function render_product_column( $column, $post_id ) {
		if ( 'sidrena_cijena' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '-';
			return;
		}

		$meta = CX_Sidrena_Product_Meta::instance();

		if ( $product->is_type( 'variable' ) ) {
			$prices = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}

				$display = $meta->get_price_to_display( $variation );
				if ( null !== $display ) {
					$prices[] = $display;
				}
			}

			if ( empty( $prices ) ) {
				echo '-';
				return;
			}

			$min = min( $prices );
			$max = max( $prices );
			if ( $min === $max ) {
				echo wp_kses_post( wc_price( $min ) );
				return;
			}

			echo wp_kses_post( wc_price( $min ) . ' - ' . wc_price( $max ) );
			return;
		}

		$html = $meta->format_price_html( $product );
		echo '' === $html ? '-' : wp_kses_post( $html );
	}
}
