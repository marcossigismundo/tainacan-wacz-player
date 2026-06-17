<?php
/**
 * Optional Gutenberg block: wacz-player/inline.
 *
 * @package Tainacan\WaczPlayer
 */

namespace Tainacan\WaczPlayer\Blocks;

use Tainacan\WaczPlayer\Player;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A dynamic block that embeds the same ReplayWeb.page player for a chosen
 * Tainacan item. Useful in FSE/block themes where the_content override is not
 * used and the player must be placed manually in the item template.
 */
class WaczInlineBlock {

	/**
	 * Block name.
	 */
	const BLOCK_NAME = 'wacz-player/inline';

	/**
	 * Editor script handle.
	 */
	const EDITOR_HANDLE = 'twp-inline-block-editor';

	/**
	 * The shared player renderer.
	 *
	 * @var Player
	 */
	private $player;

	/**
	 * Constructor.
	 *
	 * @param Player $player Player renderer.
	 */
	public function __construct( Player $player ) {
		$this->player = $player;
	}

	/**
	 * Registers the block and its editor script. Hooked on `init`.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_HANDLE,
			TWACZ_PLUGIN_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			TWACZ_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'tainacan-wacz-player' );

		register_block_type(
			self::BLOCK_NAME,
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR_HANDLE,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'itemId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'height' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Server-side render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML markup (escaped at each output point inside Player).
	 */
	public function render( $attributes ) {
		$item_id = isset( $attributes['itemId'] ) ? (int) $attributes['itemId'] : 0;
		if ( $item_id <= 0 ) {
			return '';
		}
		$height = isset( $attributes['height'] ) ? (int) $attributes['height'] : 0;
		return $this->player->render_for_item( $item_id, $height > 0 ? $height : null );
	}
}
