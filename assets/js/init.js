/**
 * Tainacan WACZ Player — front-end init.
 *
 * The vendored ReplayWeb.page ui.js defines the <replay-web-page> custom
 * element and registers its service worker on its own (from the replayBase
 * directory). This script only handles graceful degradation: when the browser
 * cannot run the player — no Service Worker support, or an insecure (plain
 * HTTP, non-localhost) context — it hides the player and reveals the download
 * fallback baked into each section.
 *
 * @package Tainacan\WaczPlayer
 */
( function () {
	'use strict';

	var data = window.twpPlayerData || { i18n: {} };

	function canReplay() {
		var hasSW = 'serviceWorker' in navigator;
		// window.isSecureContext is true for HTTPS and for localhost.
		var secure = ( typeof window.isSecureContext === 'undefined' ) ? true : window.isSecureContext;
		return { ok: hasSW && secure, hasSW: hasSW, secure: secure };
	}

	function degrade( reason ) {
		var sections = document.querySelectorAll( '.twp-player-section' );
		for ( var i = 0; i < sections.length; i++ ) {
			var section = sections[ i ];
			var player = section.querySelector( 'replay-web-page' );
			var fallback = section.querySelector( '.twp-player-fallback' );
			var message = section.querySelector( '.twp-player-fallback-message' );

			if ( player ) {
				player.style.display = 'none';
			}
			if ( message ) {
				message.textContent = reason;
			}
			if ( fallback ) {
				fallback.hidden = false;
			}
		}
	}

	function run() {
		var state = canReplay();
		if ( state.ok ) {
			return;
		}
		var reason = state.secure
			? ( data.i18n.noServiceWorker || 'Your browser cannot display this web archive.' )
			: ( data.i18n.secureContext || 'A secure connection (HTTPS) is required to display this web archive.' );
		degrade( reason );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
