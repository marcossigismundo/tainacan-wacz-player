<?php
/**
 * Self-heals the DIP objects directory so the archived files can be served as
 * static files by the web server (efficient native Range, no per-range PHP).
 *
 * The DIP Importer historically wrote a blocking .htaccess into
 * wp-content/uploads/tainacan-dip-objects/, which makes the web server answer
 * 403 for every file there. Serving the .wacz through PHP instead works, but on
 * hosts with an aggressive request-rate WAF the many Range requests get blocked.
 * Restoring static access avoids that entirely.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a permissive .htaccess into the DIP objects directory.
 */
class Htaccess {

	/**
	 * Option storing the plugin version that last wrote the .htaccess.
	 */
	const FLAG_OPTION = 'twacz_htaccess_version';

	/**
	 * Sub-directory of uploads where the DIP Importer stores its objects.
	 */
	const DIP_SUBDIR = 'tainacan-dip-objects';

	/**
	 * Permissive .htaccess contents: keeps directory listing off, but grants
	 * direct read access (Apache 2.2 and 2.4 syntaxes).
	 */
	const CONTENT = "# Managed by Tainacan WACZ Player.\n# Restores direct, Range-enabled read access to archived .wacz/.warc files,\n# replacing a blocking .htaccess left by older DIP Importer versions.\nOptions -Indexes\n<IfModule mod_authz_core.c>\nRequire all granted\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nAllow from all\n</IfModule>\n";

	/**
	 * Registers the (cheap, idempotent) repair on admin_init.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'maybe_repair' ) );
	}

	/**
	 * Runs the repair once per plugin version.
	 *
	 * @return void
	 */
	public function maybe_repair() {
		if ( TWACZ_VERSION === get_option( self::FLAG_OPTION ) ) {
			return;
		}
		$this->repair();
		update_option( self::FLAG_OPTION, TWACZ_VERSION );
	}

	/**
	 * Writes the permissive .htaccess into the DIP objects directory.
	 *
	 * @return bool True on success, false if there was nothing to do or it failed.
	 */
	public function repair() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return false;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIP_SUBDIR;
		if ( ! is_dir( $dir ) ) {
			// The DIP Importer is not in use here; nothing to repair.
			return false;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return false;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return false;
		}

		$file  = trailingslashit( $dir ) . '.htaccess';
		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		return (bool) $wp_filesystem->put_contents( $file, self::CONTENT, $chmod );
	}
}
