<?php
/**
 * Plugin Name: CX Sidrena
 * Plugin URI: https://github.com/cx-core/cx-sidrena
 * Description: Bazna cijena (sidrena cijena) for Croatian WooCommerce shops, with the lowest price in the 30 days before a sale and a public pricelist. Based on MJDD Sidrena i bazna cijena 1.26.0 by Jure Zadravec / MJDD (GPL-2.0-or-later).
 * Version: 0.3.0
 * Author: Hrvoje Puzin
 * Author URI: https://github.com/hpuzin
 * Text Domain: cx-sidrena
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.9
 * WC tested up to: 11.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/cx-core/cx-sidrena
 */

defined( 'ABSPATH' ) || exit;

define( 'CX_SIDRENA_VERSION', '0.3.0' );
// Schema of our own tables (includes/class-history.php). Raise it when a table changes; plugins_loaded then upgrades.
define( 'CX_SIDRENA_DB_VERSION', 1 );
define( 'CX_SIDRENA_FILE', __FILE__ );
define( 'CX_SIDRENA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CX_SIDRENA_URL', plugin_dir_url( __FILE__ ) );
define( 'CX_SIDRENA_META_PRICE', '_sidrena_cijena' );
define( 'CX_SIDRENA_META_DATE', '_sidrena_cijena_datum' );
define( 'CX_SIDRENA_META_CJM', '_sidrena_cijena_cjm' );
define( 'CX_SIDRENA_META_CJM_SUFFIX', '_sidrena_cijena_cjm_suffix' );
define( 'CX_SIDRENA_META_OBLIK', '_sidrena_naziv_posebnog_oblika' );
define( 'CX_SIDRENA_META_JEDINICA', '_sidrena_jedinica_mjere' );
define( 'CX_SIDRENA_META_JEDINICA_CIJENA', '_sidrena_cijena_jedinice_mjere' );

require_once CX_SIDRENA_PATH . 'includes/class-plugin.php';
require_once CX_SIDRENA_PATH . 'includes/class-migration.php';
require_once CX_SIDRENA_PATH . 'includes/class-history.php';
require_once CX_SIDRENA_PATH . 'includes/class-dates.php';
require_once CX_SIDRENA_PATH . 'includes/class-bulk.php';
require_once CX_SIDRENA_PATH . 'includes/class-fill.php';
require_once CX_SIDRENA_PATH . 'includes/class-import.php';
require_once CX_SIDRENA_PATH . 'includes/class-sale-rule.php';

// Price history: hooked here, before other plugins load, so whatever they write while loading is recorded too.
CX_Sidrena_History::register();

// Dates of bazna cijena per category (spec §1.3).
CX_Sidrena_Dates::register();

// Confirmed fill and import of bazna cijene (CX-SID-22): the background job runs from WP-cron.
CX_Sidrena_Bulk::register();

// Taking over from MJDD and the bazna add-on, and keeping them off while we run.
CX_Sidrena_Migration::register();

// Updates only from our GitHub releases (see includes/class-updates.php).
require_once CX_SIDRENA_PATH . 'includes/class-updates.php';
CX_Sidrena_Updates::register();

register_activation_hook( __FILE__, array( 'CX_Sidrena_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CX_Sidrena_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CX_SIDRENA_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'CX_Sidrena_Plugin', 'init' ) );

/**
 * Universal output helper for themes: sidrena_cijena(); or sidrena_cijena( $product );
 *
 * @param WC_Product|int|null $product Product, ID, or current product.
 */
if ( ! function_exists( 'sidrena_cijena' ) ) {
	function sidrena_cijena( $product = null ) {
		if ( ! class_exists( 'CX_Sidrena_Frontend' ) ) {
			return;
		}

		echo wp_kses_post( CX_Sidrena_Frontend::instance()->get_html_for_context( $product ) );
	}
}
