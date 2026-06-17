<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer;

use Tainacan\WaczPlayer\Admin\Settings;
use Tainacan\WaczPlayer\Blocks\WaczInlineBlock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the plugin: front-end player, assets, Gutenberg block and the
 * Tainacan-integrated settings page.
 */
class Plugin {

	/**
	 * Option name: toggle public auto-injection below the Attachments box.
	 */
	const OPT_AUTOINJECT = 'twp_enable_autoinject';

	/**
	 * Option name: default player height, in pixels.
	 */
	const OPT_HEIGHT = 'twp_player_height';

	/**
	 * Option name: fallback "entry point" URL when none is detected.
	 */
	const OPT_ENTRY = 'twp_default_entry_url';

	/**
	 * Option name: also accept raw .warc attachments.
	 */
	const OPT_WARC = 'twp_accept_raw_warc';

	/**
	 * Default player height, in pixels.
	 */
	const DEFAULT_HEIGHT = 700;

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * The player renderer.
	 *
	 * @var Player|null
	 */
	private $player = null;

	/**
	 * The assets manager.
	 *
	 * @var Assets|null
	 */
	private $assets = null;

	/**
	 * Whether init() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (use get_instance()).
	 */
	private function __construct() {}

	/**
	 * Registers all hooks. Idempotent.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->assets = new Assets();
		$this->player = new Player( $this->assets );

		// Public front-end: append the player after the item content
		// (which already includes the Attachments section).
		$this->player->register_hooks();

		// Optional Gutenberg block for FSE / manual placement.
		add_action( 'init', array( new WaczInlineBlock( $this->player ), 'register' ) );

		// Tainacan-only integrations: settings page in the Tainacan menu and
		// the optional player in the item-edit "Document" area.
		add_action( 'admin_menu', array( $this, 'maybe_register_tainacan_admin' ), 9 );
		add_action( 'init', array( $this, 'maybe_register_admin_document_hook' ) );
	}

	/**
	 * Registers the Tainacan-integrated settings page, but only when the
	 * Tainacan\Pages base class is available (i.e. Tainacan is active).
	 *
	 * Referencing the Settings class triggers the autoloader, which loads a
	 * file that `extends \Tainacan\Pages`; guarding here keeps the plugin from
	 * fataling when Tainacan is not installed.
	 *
	 * @return void
	 */
	public function maybe_register_tainacan_admin() {
		if ( ! class_exists( '\Tainacan\Pages' ) ) {
			return;
		}
		Settings::get_instance();
	}

	/**
	 * Optionally renders the player inside the Tainacan item-edit "Document"
	 * area, when the manually-set main document is a .wacz/.warc attachment.
	 *
	 * Best-effort and fully guarded: it only runs when Tainacan exposes the
	 * admin-hooks API.
	 *
	 * @return void
	 */
	public function maybe_register_admin_document_hook() {
		if ( ! function_exists( 'tainacan_register_admin_hook' ) ) {
			return;
		}
		tainacan_register_admin_hook(
			'item',
			array( $this, 'render_admin_document_player' ),
			'end-left'
		);
	}

	/**
	 * Callback for the Tainacan admin form hook. Echoes the player markup for
	 * the current item, if it has any web-archive attachment.
	 *
	 * @param mixed $context The admin-hook context provided by Tainacan.
	 * @return void
	 */
	public function render_admin_document_player( $context = null ) {
		$item_id = $this->resolve_admin_item_id( $context );
		if ( $item_id <= 0 ) {
			return;
		}
		// Echoed markup is escaped at each output point inside Player.
		echo $this->player->render_for_item( $item_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup is escaped at every output point inside Player::render_section().
	}

	/**
	 * Resolves the item ID from the admin-hook context or the request.
	 *
	 * @param mixed $context The admin-hook context (object/array/id).
	 * @return int
	 */
	private function resolve_admin_item_id( $context ) {
		if ( is_object( $context ) && isset( $context->ID ) ) {
			return (int) $context->ID;
		}
		if ( is_array( $context ) && isset( $context['id'] ) ) {
			return (int) $context['id'];
		}
		if ( is_numeric( $context ) ) {
			return (int) $context;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin-screen context lookup; no state mutation.
		if ( isset( $_GET['post'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin-screen context lookup; no state mutation.
			return absint( wp_unslash( $_GET['post'] ) );
		}
		return 0;
	}

	/**
	 * Returns the sanitized plugin options merged with defaults.
	 *
	 * @return array{autoinject:bool,height:int,default_entry_url:string,accept_warc:bool}
	 */
	public static function get_options() {
		$height = (int) get_option( self::OPT_HEIGHT, self::DEFAULT_HEIGHT );
		if ( $height < 200 ) {
			$height = self::DEFAULT_HEIGHT;
		}

		$entry = (string) get_option( self::OPT_ENTRY, '/' );
		if ( '' === $entry ) {
			$entry = '/';
		}

		return array(
			'autoinject'        => (bool) (int) get_option( self::OPT_AUTOINJECT, 1 ),
			'height'            => $height,
			'default_entry_url' => $entry,
			'accept_warc'       => (bool) (int) get_option( self::OPT_WARC, 1 ),
		);
	}
}
