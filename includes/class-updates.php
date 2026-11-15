<?php
/**
 * Updates from the GitHub releases of cx-core/cx-sidrena.
 *
 * A shop is only ever offered a published release that carries our own zip (cx-sidrena.zip, built and tested from
 * one commit). Never a tag without a release, never GitHub's source zip of a release, never the main branch, never a
 * draft or a pre-release, and never anything from wordpress.org (the plugin header has an Update URI, and the slug
 * cx-sidrena is free there). Updates install automatically, for CX Sidrena only, unless the site or this constant
 * says otherwise: define( 'CX_SIDRENA_AUTO_UPDATE', false );
 *
 * @package CX_Sidrena
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v6\PucFactory;
use YahnisElsts\PluginUpdateChecker\v6p0\Vcs\Api as PucVcsApi;

/**
 * Update checker setup and the update status shown on the check page.
 */
final class CX_Sidrena_Updates {

	const REPOSITORY = 'https://github.com/cx-core/cx-sidrena/';
	const SLUG       = 'cx-sidrena';

	/** Name of the release asset, exactly. */
	const ASSET_REGEX = '/^cx-sidrena\.zip$/i';

	/**
	 * Plugin Update Checker instance.
	 *
	 * @var object|null
	 */
	private static $checker = null;

	/**
	 * Hook up. Called from the main file.
	 */
	public static function register() {
		require_once CX_SIDRENA_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

		self::$checker = PucFactory::buildUpdateChecker( self::REPOSITORY, CX_SIDRENA_FILE, self::SLUG );
		self::$checker->setBranch( 'main' );
		// Without our asset a release is not an update (PUC would otherwise offer GitHub's source zip).
		self::$checker->getVcsApi()->enableReleaseAssets( self::ASSET_REGEX, PucVcsApi::REQUIRE_RELEASE_ASSETS );

		// The release requirement above only applies inside PUC's "latest release" strategy; when it finds nothing,
		// PUC goes on to the highest tag and then the branch, both GitHub source zips. Only the latest release counts.
		add_filter( 'puc_vcs_update_detection_strategies-' . self::SLUG, array( __CLASS__, 'only_latest_release' ) );

		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 20, 2 );
	}

	/**
	 * @param array<string,callable> $strategies PUC's strategies, in order.
	 * @return array<string,callable>
	 */
	public static function only_latest_release( $strategies ) {
		return isset( $strategies[ PucVcsApi::STRATEGY_LATEST_RELEASE ] )
			? array( PucVcsApi::STRATEGY_LATEST_RELEASE => $strategies[ PucVcsApi::STRATEGY_LATEST_RELEASE ] )
			: array();
	}

	/**
	 * Automatic updates: on for CX Sidrena (unless CX_SIDRENA_AUTO_UPDATE is false), off for MJDD and the bazna
	 * add-on while we run, so a switched-off MJDD stays on the version it had and going back to it is predictable.
	 *
	 * @param bool|null $update Current decision.
	 * @param object    $item   Update offer (slug, plugin).
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		$file = isset( $item->plugin ) ? (string) $item->plugin : '';
		$slug = isset( $item->slug ) && '' !== (string) $item->slug ? (string) $item->slug : dirname( $file );

		if ( $file === plugin_basename( CX_SIDRENA_FILE ) || ( '' === $file && self::SLUG === $slug ) ) {
			return ! self::opted_out();
		}
		if ( in_array( basename( $file ), array( CX_Sidrena_Migration::MJDD_MAIN, CX_Sidrena_Migration::ADDON_MAIN ), true )
			|| in_array( $slug, array( 'mjdd-sidrena-cijena', 'bazna-dodatak' ), true ) ) {
			return false;
		}

		return $update;
	}

	/**
	 * The site owner turned our automatic updates off.
	 *
	 * @return bool
	 */
	public static function opted_out() {
		return defined( 'CX_SIDRENA_AUTO_UPDATE' ) && ! CX_SIDRENA_AUTO_UPDATE;
	}

	/**
	 * How this plugin gets updated here, said truthfully (a hosting panel such as Installatron switches WordPress's
	 * automatic updates off with AUTOMATIC_UPDATER_DISABLED, and then nothing updates by itself).
	 *
	 * @return array{mode:string, text:string, last_check:int, available:string}
	 *   mode: auto | constant | site (automatic updates switched off for the whole site).
	 */
	public static function status() {
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( self::opted_out() ) {
			$mode = 'constant';
			$text = __( 'manual: automatic updates are turned off by the CX_SIDRENA_AUTO_UPDATE constant in wp-config.php', 'cx-sidrena' );
		} elseif ( ! wp_is_auto_update_enabled_for_type( 'plugin' ) ) {
			$mode = 'site';
			$text = __( 'manual: automatic updates are turned off for the whole site (by the hosting or in wp-config.php)', 'cx-sidrena' );
		} else {
			$mode = 'auto';
			$text = __( 'automatic, from the releases on GitHub (cx-core/cx-sidrena)', 'cx-sidrena' );
		}

		$last      = 0;
		$available = '';
		if ( self::$checker ) {
			$state = self::$checker->getUpdateState();
			$last  = $state ? (int) $state->getLastCheck() : 0;
			$offer = self::$checker->getUpdate();
			if ( $offer && ! empty( $offer->version ) ) {
				$available = (string) $offer->version;
			}
		}

		return array(
			'mode'       => $mode,
			'text'       => $text,
			'last_check' => $last,
			'available'  => $available,
		);
	}
}
