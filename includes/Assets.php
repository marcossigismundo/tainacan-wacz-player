<?php
/**
 * Front-end asset registration and enqueueing.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues the locally bundled ReplayWeb.page assets.
 *
 * Everything is served from assets/vendor/replaywebpage/ — there is zero
 * external CDN dependency, as required by the project standards.
 *
 * Pinned ReplayWeb.page version: 2.4.6 (see TWACZ_REPLAYWEBPAGE_VERSION).
 *   - assets/vendor/replaywebpage/ui.js  sha256 b7d386457aa77f4a3e6029c6b90211672df33b1d77ad1c579ee3de7bc9f3b982
 *   - assets/vendor/replaywebpage/sw.js  sha256 5d1388d7e29cbbb4e24f5adbbc763a1283a9da43ab6906441e845590c17dab1b
 * Source: https://cdn.jsdelivr.net/npm/replaywebpage@2.4.6/{ui.js,sw.js}
 */
class Assets {

	/**
	 * Handle for the vendored ReplayWeb.page UI bundle.
	 */
	const HANDLE_UI = 'twp-replaywebpage-ui';

	/**
	 * Handle for the plugin's init script.
	 */
	const HANDLE_INIT = 'twp-player-init';

	/**
	 * Handle for the player stylesheet.
	 */
	const HANDLE_STYLE = 'twp-player';

	/**
	 * Registers the player assets if not registered yet.
	 *
	 * @return void
	 */
	public function register_player_assets() {
		if ( wp_script_is( self::HANDLE_UI, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE_STYLE,
			TWACZ_PLUGIN_URL . 'assets/css/player.css',
			array(),
			TWACZ_VERSION
		);

		// Vendored ReplayWeb.page custom-element bundle (pinned, no CDN).
		wp_register_script(
			self::HANDLE_UI,
			TWACZ_PLUGIN_URL . 'assets/vendor/replaywebpage/ui.js',
			array(),
			TWACZ_REPLAYWEBPAGE_VERSION,
			true
		);

		wp_register_script(
			self::HANDLE_INIT,
			TWACZ_PLUGIN_URL . 'assets/js/init.js',
			array( self::HANDLE_UI ),
			TWACZ_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE_INIT,
			'twpPlayerData',
			array(
				'i18n' => array(
					'secureContext'   => __( 'This web-archive viewer needs a secure connection (HTTPS) or localhost to run.', 'tainacan-wacz-player' ),
					'noServiceWorker' => __( 'Your browser does not support the Service Worker technology required to display this web archive.', 'tainacan-wacz-player' ),
				),
			)
		);

		wp_set_script_translations( self::HANDLE_INIT, 'tainacan-wacz-player' );
	}

	/**
	 * Registers (if needed) and enqueues the player assets.
	 *
	 * Safe to call from within the_content / block render callbacks: the
	 * scripts are flagged for the footer, which is printed after the content.
	 *
	 * @return void
	 */
	public function enqueue_player_assets() {
		$this->register_player_assets();
		wp_enqueue_style( self::HANDLE_STYLE );
		wp_enqueue_script( self::HANDLE_UI );
		wp_enqueue_script( self::HANDLE_INIT );
	}
}
