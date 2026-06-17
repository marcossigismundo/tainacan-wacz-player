<?php
/**
 * Plugin Name:       Tainacan WACZ Player
 * Plugin URI:        https://github.com/marcossigismundo/tainacan-wacz-player
 * Description:       Renders .wacz / .warc web archives attached to Tainacan items directly on the public item page, right below the Attachments box, using a locally bundled ReplayWeb.page player. No external CDN.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Marcos Sigismundo
 * Author URI:        https://github.com/marcossigismundo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tainacan-wacz-player
 * Domain Path:       /languages
 *
 * @package Tainacan\WaczPlayer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TWACZ_VERSION', '1.0.0' );
define( 'TWACZ_PLUGIN_FILE', __FILE__ );
define( 'TWACZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWACZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TWACZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Pinned ReplayWeb.page version bundled under assets/vendor/replaywebpage/.
 *
 * Kept in sync with the vendored ui.js / sw.js. See Assets.php and README.md
 * for the documented SHA-256 of each file. Bump this constant whenever the
 * vendor bundle is updated so the browser cache is busted.
 */
define( 'TWACZ_REPLAYWEBPAGE_VERSION', '2.4.6' );

/**
 * PSR-4-ish autoloader for the Tainacan\WaczPlayer namespace.
 *
 * Only handles classes under our own prefix; returns early for everything
 * else so it never interferes with Tainacan core's autoloader.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix   = 'Tainacan\\WaczPlayer\\';
		$base_dir = TWACZ_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Loads the plugin translations.
 *
 * @return void
 */
function twacz_load_textdomain() {
	load_plugin_textdomain(
		'tainacan-wacz-player',
		false,
		dirname( TWACZ_PLUGIN_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'twacz_load_textdomain' );

/**
 * Boots the plugin singleton once all plugins are loaded.
 *
 * Runs late (priority 20) so Tainacan core has registered its classes and
 * theme helper before we hook into the_content and the Tainacan admin menu.
 *
 * @return void
 */
function twacz_bootstrap() {
	\Tainacan\WaczPlayer\Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'twacz_bootstrap', 20 );
