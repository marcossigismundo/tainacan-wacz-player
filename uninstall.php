<?php
/**
 * Uninstall routine: removes the plugin's options and cached post meta.
 *
 * @package Tainacan\WaczPlayer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the plugin data for the current site.
 *
 * @return void
 */
function twacz_uninstall_site() {
	$options = array(
		'twp_enable_autoinject',
		'twp_player_height',
		'twp_default_entry_url',
		'twp_accept_raw_warc',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Cached entry-point URLs detected from each .wacz attachment.
	delete_post_meta_by_key( '_twp_entry_url' );
}

if ( is_multisite() ) {
	$twacz_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $twacz_site_ids as $twacz_site_id ) {
		switch_to_blog( (int) $twacz_site_id );
		twacz_uninstall_site();
		restore_current_blog();
	}
} else {
	twacz_uninstall_site();
}
