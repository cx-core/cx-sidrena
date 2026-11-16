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

		$before = array();
		foreach ( CX_Sidrena_Poslovnice::instance()->all() as $outlet ) {
			if ( '' !== $outlet['id'] ) {
				$before[ $outlet['id'] ] = $outlet['oznaka'];
			}
		}

		$json = isset( $_POST['sidrena_cijena_poslovnice_json'] ) ? wp_unslash( $_POST['sidrena_cijena_poslovnice_json'] ) : '[]';
		$saved = CX_Sidrena_Poslovnice::instance()->save_from_json( is_string( $json ) ? $json : '[]' );

		$kept = array();
		foreach ( $saved as $outlet ) {
			if ( '' !== $outlet['id'] ) {
				$kept[ $outlet['id'] ] = true;
			}
		}
		foreach ( $before as $id => $oznaka ) {
			if ( ! isset( $kept[ $id ] ) && '' !== $oznaka ) {
				CX_Sidrena_Catalog::instance()->delete_for_poslovnica( $oznaka );
				CX_Sidrena_Upit::instance()->delete_for_poslovnica( $oznaka );
			}
		}

		if ( isset( $_POST['sidrena_cijena_file_oblik'] ) ) {
			// The shop address is on the main Settings form (spec §1.8): a field that is not posted keeps its value.
			$file = array(
				'file_oblik'   => wp_unslash( $_POST['sidrena_cijena_file_oblik'] ),
				'file_oznaka'  => isset( $_POST['sidrena_cijena_file_oznaka'] ) ? wp_unslash( $_POST['sidrena_cijena_file_oznaka'] ) : '001',
				'file_pohrana' => isset( $_POST['sidrena_cijena_file_pohrana'] ) ? wp_unslash( $_POST['sidrena_cijena_file_pohrana'] ) : 1,
			);
			if ( isset( $_POST['sidrena_cijena_file_adresa'] ) ) {
				$file['file_adresa'] = wp_unslash( $_POST['sidrena_cijena_file_adresa'] );
			}
			CX_Sidrena_Settings::instance()->save( $file );
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

		$dates = $this->save_dates();

		$name_before = CX_Sidrena_Archive::instance()->current_name_key();
		CX_Sidrena_Settings::instance()->save( $payload );
		CX_Sidrena_Export::instance()->sync_daily_schedule();
		// A new address or format is in the next file's name within a minute, not from tomorrow's (CX-SID-27).
		if ( CX_Sidrena_Archive::instance()->current_name_key() !== $name_before ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}

		if ( is_wp_error( $dates ) ) {
			wp_safe_redirect( self::settings_url( 'cx-dates', array( 'updated' => '1', 'cx_dates_error' => $dates->get_error_code() ) ) );
			exit;
		}

		if ( isset( $_POST['cx_regenerate'] ) ) {
			$result = CX_Sidrena_Export::instance()->regenerate_public_xml();
			if ( ! is_wp_error( $result ) ) {
				CX_Sidrena_Export::instance()->archive_public_xml();
			}
			$flag = '1';
			if ( is_wp_error( $result ) ) {
				$flag = 'sidrena_poslovnice_empty' === $result->get_error_code() ? '2' : '0';
				if ( 'sidrena_b2b_only' === $result->get_error_code() ) {
					$flag = 'b2b'; // CX-SID-28: not a failure, a B2B shop publishes no price list
				}
			}
			wp_safe_redirect( self::settings_url( 'cx-options', array( 'sidrena_xml' => $flag ) ) );
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
		CX_Sidrena_Catalog::instance()->save_from_json( is_string( $json ) ? $json : '[]' );

		$upit = isset( $_POST['sidrena_cijena_upit_json'] ) ? wp_unslash( $_POST['sidrena_cijena_upit_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		CX_Sidrena_Upit::instance()->save_from_json( is_string( $upit ) ? $upit : '[]' );

		wp_safe_redirect( self::settings_url( 'cx-items', array( 'updated' => 'items' ) ) );
		exit;
	}
}
