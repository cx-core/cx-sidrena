<?php
/**
 * Admin form saves and AJAX writes for Sidrena cijena.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings, catalog and outlet writes, and the export of bazna cijene.
 */
class CX_Sidrena_Admin_Save {

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
	 * Save the Poslovnice repeater.
	 */
	public function handle_poslovnice_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_poslovnice' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_poslovnice', 'sidrena_cijena_poslovnice_nonce' );

		$json    = isset( $_POST['sidrena_cijena_poslovnice_json'] ) ? wp_unslash( $_POST['sidrena_cijena_poslovnice_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted  = json_decode( is_string( $json ) ? $json : '', true );

		// CX-SID-142: one click never deletes the places the page did not show. The page's script sets _ok=1 only once it
		// has read the list (the _loaded ids are written by the server, so a broken page sends them too), and _cleared=1
		// when the owner deleted every place it showed. Without _ok, or with a list that would delete every stored place
		// and no _cleared, nothing on the form is saved, the webshop's fields neither: the reason stands alone, and
		// the form does not offer what was sent again (Norman C3 for the outlets, 131).
		$ok      = isset( $_POST['sidrena_cijena_poslovnice_ok'] ) && '1' === $_POST['sidrena_cijena_poslovnice_ok']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		$cleared = isset( $_POST['sidrena_cijena_poslovnice_cleared'] ) && '1' === $_POST['sidrena_cijena_poslovnice_cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		if ( ! $ok || ! is_array( $posted ) || ( ! $cleared && CX_Sidrena_Poslovnice::instance()->form_empties( $posted ) ) ) {
			wp_safe_redirect( self::settings_url( 'cx-outlets', array( 'updated' => false, 'cx_outlet_error' => 'nepotvrdeno' ) ) );
			exit;
		}
		$loaded  = null;
		if ( isset( $_POST['sidrena_cijena_poslovnice_loaded'] ) ) {
			$loaded = json_decode( (string) wp_unslash( $_POST['sidrena_cijena_poslovnice_loaded'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$loaded = is_array( $loaded ) ? $loaded : array();
		}
		$webshop = isset( $_POST['sidrena_cijena_file_oznaka'] ) ? (string) wp_unslash( $_POST['sidrena_cijena_file_oznaka'] ) : (string) CX_Sidrena_Settings::instance()->get( 'file_oznaka', '001' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// CX-SID-113: the box "the same price list for all places of sale" is on this form. A page from before it (no
		// _shown) keeps the stored value. Switching it on is refused while a place has rows of its own (T75): the same
		// price list means the same content, not one the plugin picks. Nothing on the form is saved then (as 74).
		$settings = CX_Sidrena_Settings::instance();
		$isti_was = $settings->is_isti_cjenik();
		$isti     = (string) $settings->get( 'isti_cjenik', 'no' );
		if ( isset( $_POST['sidrena_cijena_isti_cjenik_shown'] ) ) {
			$isti = isset( $_POST['sidrena_cijena_isti_cjenik'] ) && 'yes' === $_POST['sidrena_cijena_isti_cjenik'] ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		}
		if ( 'yes' === $isti && 'yes' !== $settings->get( 'isti_cjenik', 'no' ) ) {
			$own = CX_Sidrena_Poslovnice::instance()->first_with_own_rows();
			if ( null !== $own ) {
				wp_safe_redirect( self::settings_url( 'cx-outlets', array( 'updated' => false, 'cx_outlet_error' => rawurlencode( 'isti:' . $own['oznaka'] ) ) ) );
				exit;
			}
		}

		// All or nothing (CX-SID-74): a place that breaks a rule saves nothing, also not the webshop's fields of the same
		// form, and nothing is kept on the server (the form keeps what was typed; Rook with Linus).
		$result = CX_Sidrena_Poslovnice::instance()->save_from_form( $posted, $loaded, $webshop );
		if ( is_wp_error( $result ) ) {
			// Encoded: wp_sanitize_redirect() drops "|" (it keeps only URL-safe characters), so adresa:0|vrsta:1 came out
			// as adresa:0vrsta:1 (found by Playground pos). The page reads it decoded ($_GET, URLSearchParams).
			wp_safe_redirect( self::settings_url( 'cx-outlets', array( 'updated' => false, 'cx_outlet_error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}
		$saved = $result['saved'];
		// Each outlet's typed storage number only raises its counter (CX-SID-119 dopuna, Kai 3): a form loaded before a
		// build sends the number it showed then, which must not take the counter back. The list keeps the typed value as
		// a lower bound, as before.
		foreach ( $saved as $outlet ) {
			if ( '' !== $outlet['id'] ) {
				CX_Sidrena_Archive::instance()->raise_pohrana( $outlet['id'], $outlet['pohrana'] );
			}
		}

		// A deleted place's own manual rows go with it; its archive stays (CX-SID-27). A place whose code changed keeps
		// its rows under the new code.
		foreach ( $result['deleted'] as $oznaka ) {
			if ( '' !== $oznaka ) {
				CX_Sidrena_Catalog::instance()->delete_for_poslovnica( $oznaka );
				CX_Sidrena_Upit::instance()->delete_for_poslovnica( $oznaka );
			}
		}
		foreach ( $result['renamed'] as $from => $to ) {
			CX_Sidrena_Catalog::instance()->move_poslovnica( (string) $from, (string) $to );
			CX_Sidrena_Upit::instance()->move_poslovnica( (string) $from, (string) $to );
		}
		// The shape of a deleted place's last imported file goes with it, also when a new place gets its id (CX-SID-196).
		if ( class_exists( 'CX_Sidrena_Bulk_Import' ) ) {
			CX_Sidrena_Bulk_Import::forget_gone_places( $result['deleted'], $result['renamed'] );
		}

		// Without a place the box does not apply: it is off, so a first place added later publishes its own (CX-SID-113).
		$isti = array() === $saved ? 'no' : $isti;
		if ( $isti !== (string) $settings->get( 'isti_cjenik', 'no' ) && ! isset( $_POST['sidrena_cijena_file_oblik'] ) ) {
			$settings->save( array( 'isti_cjenik' => $isti ) );
		}
		if ( isset( $_POST['sidrena_cijena_file_oblik'] ) ) {
			// The shop address is on the main Settings form (spec §1.8): a field that is not posted keeps its value.
			$file = array(
				'isti_cjenik' => $isti,
				'file_oblik'  => wp_unslash( $_POST['sidrena_cijena_file_oblik'] ),
				'file_oznaka' => isset( $_POST['sidrena_cijena_file_oznaka'] ) ? wp_unslash( $_POST['sidrena_cijena_file_oznaka'] ) : '001',
			);
			// The webshop's storage number only raises its counter, never through the settings (CX-SID-119 dopuna,
			// Kai 3): the settings' old value stays as it is, a lower bound.
			if ( isset( $_POST['sidrena_cijena_file_pohrana'] ) ) {
				CX_Sidrena_Archive::instance()->raise_pohrana( '', (int) wp_unslash( $_POST['sidrena_cijena_file_pohrana'] ) );
			}
			if ( isset( $_POST['sidrena_cijena_file_adresa'] ) ) {
				$file['file_adresa'] = wp_unslash( $_POST['sidrena_cijena_file_adresa'] );
			}
			CX_Sidrena_Settings::instance()->save( $file );
		}
		// Switched on or off (also by deleting the last place): the files current now never become current again (Q4).
		if ( $isti_was !== $settings->is_isti_cjenik() ) {
			CX_Sidrena_Archive::instance()->mark_switch( ! $isti_was );
		}

		if ( class_exists( 'CX_Sidrena_Export' ) ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}

		wp_safe_redirect( self::settings_url( 'cx-outlets', array( 'updated' => 'outlets' ) ) );
		exit;
	}

	/**
	 * Settings after a save, at a section.
	 *
	 * @param string $anchor Section id.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	private static function settings_url( $anchor = '', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'sidrena-cijena', 'tab' => 'settings', 'updated' => '1' ), $args ), admin_url( 'admin.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * The Settings form (spec §1.8): one form for every setting on the page. A setting that is not on the form keeps its
	 * stored value (auto_fill: its switch is gone, its behaviour stays until CX-SID-21).
	 */
	public function handle_main_settings_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_main_settings' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'cx_sidrena_settings', 'cx_sidrena_settings_nonce' );

		$text = function ( $key, $default = '' ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};

		$payload = array(
			'prefix'         => $text( 'sidrena_cijena_prefix' ),
			'tooltip'        => isset( $_POST['sidrena_cijena_tooltip'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sidrena_cijena_tooltip'] ) ) : '',
			'enable_tooltip' => isset( $_POST['sidrena_cijena_enable_tooltip'] ) ? 'yes' : 'no',
			'default_date'   => $text( 'sidrena_cijena_default_date' ),
			'date_style'     => $text( 'sidrena_cijena_date_style', 'plain' ),
			'enable_cjm'     => isset( $_POST['sidrena_cijena_enable_cjm'] ) ? 'yes' : 'no',
			'brand_manual'   => $text( 'sidrena_cijena_brand_manual' ),
			'export_format'  => $text( 'sidrena_cijena_public_format', 'xml' ),
			'daily_xml'      => isset( $_POST['cx_sidrena_daily_xml'] ) ? 'yes' : 'no',
			'file_adresa'    => $text( 'sidrena_cijena_file_adresa' ),
			'cjenik_link'    => isset( $_POST['cx_cjenik_link'] ) ? 'yes' : 'no',
		);

		// Colour of the lines (CX-SID-41): the own colour only when chosen, '' = the shop's text colour; Settings keeps it
		// to a #rrggbb. A form without the choice leaves the stored colour as it is.
		if ( isset( $_POST['cx_line_color_mode'] ) ) {
			$payload['line_color'] = 'own' === $text( 'cx_line_color_mode' ) ? $text( 'cx_line_color' ) : '';
		}

		// CX-SID-28: the availability column in the public price list, off unless the owner ticks it.
		$payload['feed_dostupnost'] = isset( $_POST['cx_feed_dostupnost'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above

		// CX-SID-27: days a price list file older than the public 30 is kept (0 = for ever); not posted = unchanged.
		if ( isset( $_POST['cx_archive_keep_days'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
			$payload['archive_keep_days'] = (int) $_POST['cx_archive_keep_days'];
		}

		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			$payload['brand_taxonomy'] = $text( 'sidrena_cijena_brand_taxonomy' );
			$payload['auto_display']   = isset( $_POST['sidrena_cijena_auto_display'] ) ? 'yes' : 'no';

			// Stored only when it differs from the theme's default, or was already chosen: the default follows a theme
			// switch (CX-SID-26).
			if ( isset( $_POST['cx_discount_badge'] ) && class_exists( 'CX_Sidrena_Badge' ) ) {
				$badge  = $text( 'cx_discount_badge' );
				$stored = (string) CX_Sidrena_Settings::instance()->get( CX_Sidrena_Badge::SETTING, '' );
				if ( '' !== $stored || CX_Sidrena_Badge::default_mode() !== $badge ) {
					$payload[ CX_Sidrena_Badge::SETTING ] = $badge;
				}
			}
		}

		// CX-SID-110: the OIB or registration number only when the field was posted, so a form without it (another tab,
		// CX-SID-31) never clears it. A refused value is not stored (Settings::save() keeps the old one); a warned one is.
		$oib_flag  = '';
		$oib_typed = '';
		if ( isset( $_POST['sidrena_cijena_file_oib'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
			$oib_raw   = $text( 'sidrena_cijena_file_oib' );
			$oib_check = CX_Sidrena_Settings::check_legal_id( $oib_raw );
			if ( CX_Sidrena_Settings::LEGAL_ID_REFUSED === $oib_check['status'] ) {
				$oib_flag = 'oib' === $oib_check['reason'] ? 'refused_oib' : 'refused_digits';
				// The field shows what was typed, marked, so one wrong digit is fixed there (Norman); the stored value stays.
				$oib_typed = 'oib' === $oib_check['reason'] ? CX_Sidrena_Settings::clean_legal_id( $oib_raw ) : $oib_raw;
			} else {
				$payload['file_oib'] = $oib_check['value'];
				if ( CX_Sidrena_Settings::LEGAL_ID_WARN === $oib_check['status'] ) {
					$oib_flag  = 'warn';
					$oib_typed = $oib_check['value'];
				}
			}
		}

		// The lowest price in 30 days (CX-SID-63): only when the form had the row (cx_n30_present), so a save of a form
		// without it never turns the line off. A change rebuilds the price list (88: DA/NE follows the page).
		$n30_changed = false;
		if ( isset( $_POST['cx_n30_present'] ) && CX_Sidrena_Plugin::woocommerce_active() && function_exists( 'cx_sidrena_n30_display_enabled' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
			$n30_want = isset( $_POST['cx_n30_display'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
			if ( cx_sidrena_n30_display_enabled() !== $n30_want ) {
				update_option( 'cx_sidrena_n30_display', $n30_want ? 'yes' : 'no', true );
				$n30_changed = true;
			}
		}

		$dates = $this->save_dates();

		$name_before = CX_Sidrena_Archive::instance()->current_name_key();
		CX_Sidrena_Settings::instance()->save( $payload );
		CX_Sidrena_Export::instance()->sync_daily_schedule();
		// A new address or format is in the next file's name within a minute, not from tomorrow's (CX-SID-27).
		if ( CX_Sidrena_Archive::instance()->current_name_key() !== $name_before || $n30_changed ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}

		if ( is_wp_error( $dates ) ) {
			wp_safe_redirect( self::settings_url( 'cx-dates', array( 'updated' => '1', 'cx_dates_error' => $dates->get_error_code() ) ) );
			exit;
		}

		// "Save and generate now": the price list is built before any return to the page, also when the OIB was refused or
		// saved with a warning (Argus 110-C: the OIB's return came first, so with a warned MIBPG or MBO the button
		// never built anything and said nothing). Its result goes with whichever return follows.
		$xml_flag = '';
		if ( isset( $_POST['cx_regenerate'] ) ) {
			$result = CX_Sidrena_Export::instance()->regenerate_public_xml();
			if ( ! is_wp_error( $result ) ) {
				CX_Sidrena_Export::instance()->archive_public_xml();
			}
			// Busy is not a failure: the build that was running is followed by one with what was just saved (CX-SID-119).
			$xml_flag = CX_Sidrena_Export::result_flag( $result );
			if ( in_array( $xml_flag, array( '0', 'fail' ), true ) ) {
				CX_Sidrena_Export::instance()->retry_soon();
			}
		}
		$xml_arg = '' !== $xml_flag ? array( 'sidrena_xml' => $xml_flag ) : array();

		if ( '' !== $oib_flag ) {
			$typed = function_exists( 'mb_substr' ) ? mb_substr( $oib_typed, 0, 40, 'UTF-8' ) : substr( $oib_typed, 0, 40 );
			// To the field's row, where the reason is shown (Norman 110-1: at the section, the notice at the top was out of view).
			wp_safe_redirect( self::settings_url( 'cx-oib-row', array_merge( array( 'updated' => '1', 'cx_oib' => $oib_flag, 'cx_oib_typed' => rawurlencode( $typed ) ), $xml_arg ) ) );
			exit;
		}

		if ( '' !== $xml_flag ) {
			wp_safe_redirect( self::settings_url( 'cx-options', $xml_arg ) );
			exit;
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * Dates per category from the Settings form (spec §1.8), saved through CX-SID-21's model. A subcategory with the
	 * same date as its parent is not set directly: it follows its parent (as the suggestion does). Saved when they were
	 * only suggested (saving the page is how the owner confirms them, spec §1.1 step 1) or when a date changed; an
	 * untouched "Taken from your existing data" stays taken.
	 *
	 * @return bool|WP_Error|null True saved, false unchanged, null not on the form.
	 */
	private function save_dates() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle_main_settings_save().
		if ( ! class_exists( 'CX_Sidrena_Dates' ) || ! isset( $_POST['cx_dates_other'] ) ) {
			return null;
		}
		$posted = isset( $_POST['cx_dates'] ) && is_array( $_POST['cx_dates'] ) ? wp_unslash( $_POST['cx_dates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$other  = sanitize_text_field( wp_unslash( $_POST['cx_dates_other'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$map     = array();
		$eff     = array();
		$changed = false;
		foreach ( CX_Sidrena_Dates::rows() as $row ) {
			$id = (int) $row['term_id'];
			if ( 0 === $id ) {
				$changed = $changed || $other !== $row['date'];
				continue;
			}
			$parent  = (int) $row['parent'];
			$inherit = $parent && isset( $eff[ $parent ] ) ? $eff[ $parent ] : $other;
			$date    = isset( $posted[ $id ] ) ? sanitize_text_field( (string) $posted[ $id ] ) : $inherit;
			if ( ! $parent || ! isset( $eff[ $parent ] ) || $date !== $inherit ) {
				$map[ $id ] = $date;
			}
			$eff[ $id ] = $date;
			$changed    = $changed || $date !== $row['date'];
		}

		$state = CX_Sidrena_Dates::state();
		if ( ! $changed && in_array( $state['state'], array( 'saved', 'taken' ), true ) ) {
			return false;
		}
		$result = CX_Sidrena_Dates::save( $map, $other );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Export bazna cijene (Settings > Advanced): CX_Sidrena_Import::export_csv(), the columns of the bazna import
	 * template plus ID, so it imports back unchanged. Read only.
	 */
	public function handle_export_bazna() {
		if ( ! isset( $_GET['cx_export_bazna'], $_GET['page'] ) || 'sidrena-cijena' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() || ! CX_Sidrena_Plugin::woocommerce_active() ) {
			return;
		}

		check_admin_referer( 'cx_sidrena_export_bazna' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bazna-cijene-' . wp_date( 'Y-m-d' ) . '.csv"' );
		echo CX_Sidrena_Import::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, fields neutralized by csv_line().
		exit;
	}

	/**
	 * Whether a list sent by the page has a row with something in it (CX-SID-142). A row with nothing typed is not one:
	 * the table always shows one empty row, and a save drops it.
	 *
	 * @param array<int, mixed> $rows   Decoded list.
	 * @param array<int,string> $fields Fields that make a row.
	 * @return bool
	 */
	private static function has_rows( $rows, $fields ) {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $fields as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The code of the first place a sent row is given to, or '' (CX-SID-113). A code no place has is not a place's.
	 *
	 * @param array<int, mixed> $rows Decoded list.
	 * @param callable          $code The list's row_poslovnica().
	 * @return string
	 */
	private static function place_code_in( $rows, $code ) {
		foreach ( $rows as $row ) {
			$oznaka = is_array( $row ) ? (string) call_user_func( $code, $row ) : '';
			if ( '' !== $oznaka && is_array( CX_Sidrena_Poslovnice::instance()->get_by_oznaka( $oznaka ) ) ) {
				return $oznaka;
			}
		}

		return '';
	}

	/**
	 * Save standalone catalog repeater.
	 */
	public function handle_catalog_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_catalog' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_catalog', 'sidrena_cijena_catalog_nonce' );

		$json = isset( $_POST['sidrena_cijena_catalog_json'] ) ? wp_unslash( $_POST['sidrena_cijena_catalog_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$upit = isset( $_POST['sidrena_cijena_upit_json'] ) ? wp_unslash( $_POST['sidrena_cijena_upit_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$json = is_string( $json ) ? $json : '';
		$upit = is_string( $upit ) ? $upit : '';

		// CX-SID-142: one click never wipes what the page did not show. The whole save is refused, neither list written,
		// when the page did not confirm that its script read both lists (_loaded; a page from before the update has none),
		// or when it sends an empty list while rows are stored and the owner did not delete them on the page (_cleared).
		$flag = static function ( $key ) {
			return isset( $_POST[ $key ] ) && '1' === $_POST[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
		};
		$sent_catalog = json_decode( $json, true );
		$sent_upit    = json_decode( $upit, true );
		$refused      = ! $flag( 'sidrena_cijena_catalog_loaded' ) || ! is_array( $sent_catalog ) || ! is_array( $sent_upit )
			|| ( ! $flag( 'sidrena_cijena_catalog_cleared' ) && ! self::has_rows( $sent_catalog, array( 'naziv', 'sku', 'marka', 'url', 'cijena_redovna', 'cijena_akcijska', 'naziv_posebnog_oblika', 'jedinica_mjere', 'cijena_jedinice_mjere', 'sidrena_cijena', 'sidrena_datum', 'cjm_suffix' ) ) && array() !== CX_Sidrena_Catalog::instance()->all() )
			|| ( ! $flag( 'sidrena_cijena_upit_cleared' ) && ! self::has_rows( $sent_upit, array( 'naziv', 'kriteriji' ) ) && array() !== CX_Sidrena_Upit::instance()->all() );
		if ( $refused ) {
			// Norman C3. What was sent is not shown again: it came from a page that did not load.
			wp_safe_redirect( self::settings_url( 'cx-items', array( 'updated' => false, 'cx_items_error' => 'unloaded' ) ) );
			exit;
		}

		// One price list for all places of sale (CX-SID-113, T77): no place gets rows of its own, nothing is saved.
		if ( CX_Sidrena_Settings::instance()->is_isti_cjenik() ) {
			$code = self::place_code_in( $sent_catalog, array( CX_Sidrena_Catalog::instance(), 'row_poslovnica' ) );
			$code = '' !== $code ? $code : self::place_code_in( $sent_upit, array( CX_Sidrena_Upit::instance(), 'row_poslovnica' ) );
			if ( '' !== $code ) {
				wp_safe_redirect( self::settings_url( 'cx-items', array( 'updated' => false, 'cx_items_error' => rawurlencode( 'isti:' . $code ) ) ) );
				exit;
			}
		}

		CX_Sidrena_Catalog::instance()->save( $sent_catalog, true );
		CX_Sidrena_Upit::instance()->save( $sent_upit, true );

		$unpub   = count( CX_Sidrena_Catalog::instance()->unpublished_services() );
		$nobazna = CX_Sidrena_Catalog::instance()->goods_without_bazna(); // CX-SID-133
		wp_safe_redirect(
			self::settings_url(
				'cx-items',
				array(
					'updated'    => 'items',
					'cx_unpub'   => $unpub > 0 ? $unpub : false,
					'cx_nobazna' => $nobazna > 0 ? $nobazna : false,
				)
			)
		);
		exit;
	}
}
