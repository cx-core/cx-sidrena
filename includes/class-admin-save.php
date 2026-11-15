<?php
/**
 * Admin form saves and AJAX writes for Sidrena cijena.
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings, catalog, outlet and snapshot writes.
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
			CX_Sidrena_Settings::instance()->save(
				array(
					'file_oblik'   => wp_unslash( $_POST['sidrena_cijena_file_oblik'] ),
					'file_adresa'  => isset( $_POST['sidrena_cijena_file_adresa'] ) ? wp_unslash( $_POST['sidrena_cijena_file_adresa'] ) : '',
					'file_oznaka'  => isset( $_POST['sidrena_cijena_file_oznaka'] ) ? wp_unslash( $_POST['sidrena_cijena_file_oznaka'] ) : '001',
					'file_pohrana' => isset( $_POST['sidrena_cijena_file_pohrana'] ) ? wp_unslash( $_POST['sidrena_cijena_file_pohrana'] ) : 1,
				)
			);
		}

		if ( class_exists( 'CX_Sidrena_Export' ) ) {
			CX_Sidrena_Export::instance()->queue_public_xml();
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'poslovnice', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save settings form.
	 */
	public function handle_settings_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) || 'save_settings' !== $_POST['sidrena_cijena_action'] ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_settings', 'sidrena_cijena_nonce' );

		$payload = array(
			'prefix'         => isset( $_POST['sidrena_cijena_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_prefix'] ) ) : '',
			'tooltip'        => isset( $_POST['sidrena_cijena_tooltip'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sidrena_cijena_tooltip'] ) ) : '',
			'enable_tooltip' => isset( $_POST['sidrena_cijena_enable_tooltip'] ) ? 'yes' : 'no',
			'default_date'   => isset( $_POST['sidrena_cijena_default_date'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_default_date'] ) ) : '',
			'date_style'     => isset( $_POST['sidrena_cijena_date_style'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_date_style'] ) ) : 'parens',
			'enable_cjm'     => isset( $_POST['sidrena_cijena_enable_cjm'] ) ? 'yes' : 'no',
			'brand_manual'   => isset( $_POST['sidrena_cijena_brand_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_brand_manual'] ) ) : '',
		);

		if ( CX_Sidrena_Plugin::woocommerce_active() ) {
			$payload['brand_taxonomy'] = isset( $_POST['sidrena_cijena_brand_taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_brand_taxonomy'] ) ) : '';
			$payload['auto_display'] = isset( $_POST['sidrena_cijena_auto_display'] ) ? 'yes' : 'no';
			$payload['auto_fill']    = isset( $_POST['sidrena_cijena_auto_fill'] ) ? 'yes' : 'no';
		}

		CX_Sidrena_Settings::instance()->save( $payload );

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'postavke', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save public XML options from the Javni XML tab.
	 */
	public function handle_xml_settings_save() {
		if ( ! isset( $_POST['sidrena_cijena_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['sidrena_cijena_action'] ) );
		if ( ! in_array( $action, array( 'save_xml_settings', 'regenerate_public_xml' ), true ) ) {
			return;
		}

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			return;
		}

		check_admin_referer( 'sidrena_cijena_save_xml', 'sidrena_cijena_xml_nonce' );

		$payload = array(
			'daily_xml'     => isset( $_POST['cx_sidrena_daily_xml'] ) ? 'yes' : 'no',
			'export_format' => isset( $_POST['sidrena_cijena_public_format'] ) ? wp_unslash( $_POST['sidrena_cijena_public_format'] ) : 'xml',
		);

		CX_Sidrena_Settings::instance()->save( $payload );

		CX_Sidrena_Export::instance()->sync_daily_schedule();

		if ( 'regenerate_public_xml' === $action ) {
			$result = CX_Sidrena_Export::instance()->regenerate_public_xml();
			if ( ! is_wp_error( $result ) ) {
				CX_Sidrena_Export::instance()->archive_public_xml();
			}
			$flag = '1';
			if ( is_wp_error( $result ) ) {
				$flag = 'sidrena_poslovnice_empty' === $result->get_error_code() ? '2' : '0';
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'sidrena-cijena',
						'tab'         => 'javni-xml',
						'sidrena_xml' => $flag,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'javni-xml', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
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

		wp_safe_redirect( add_query_arg( array( 'page' => 'sidrena-cijena', 'tab' => 'proizvodi', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX batch snapshot from the settings page.
	 */
	public function ajax_snapshot() {
		check_ajax_referer( 'sidrena_cijena_snapshot', 'nonce' );

		if ( ! CX_Sidrena_Plugin::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$page        = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;
		$offset      = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$child       = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$stamp_today = isset( $_POST['stamp_today'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['stamp_today'] ) );

		try {
			$result = CX_Sidrena_Activator::process_batch( $page, $stamp_today, $offset, $child );
			wp_send_json_success( $result );
		} catch ( Throwable $e ) {
			CX_Sidrena_Plugin::log_exception( $e );
			wp_send_json_error( array( 'message' => __( 'Price snapshot failed. Details are in the server log.', 'cx-sidrena' ) ) );
		}
	}
}
