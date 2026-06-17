/**
 * Tainacan WACZ Player — block editor registration (no build step).
 *
 * Registers the dynamic `wacz-player/inline` block. Rendering happens
 * server-side via the PHP render_callback (Player::render_for_item), so the
 * editor only needs to collect the item ID and an optional height.
 *
 * @package Tainacan\WaczPlayer
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Placeholder = components.Placeholder;

	blocks.registerBlockType( 'wacz-player/inline', {
		edit: function ( props ) {
			var atts = props.attributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			var instructions = atts.itemId
				? sprintf(
					/* translators: %d: Tainacan item ID. */
					__( 'Web archives attached to item #%d will be rendered here on the front-end.', 'tainacan-wacz-player' ),
					atts.itemId
				)
				: __( 'Set a Tainacan item ID in the block settings to embed its web archives.', 'tainacan-wacz-player' );

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'WACZ Player', 'tainacan-wacz-player' ), initialOpen: true },
						el( TextControl, {
							type: 'number',
							label: __( 'Tainacan item ID', 'tainacan-wacz-player' ),
							value: atts.itemId || '',
							onChange: function ( value ) {
								props.setAttributes( { itemId: parseInt( value, 10 ) || 0 } );
							}
						} ),
						el( TextControl, {
							type: 'number',
							label: __( 'Player height in pixels (0 = use default)', 'tainacan-wacz-player' ),
							value: atts.height || '',
							onChange: function ( value ) {
								props.setAttributes( { height: parseInt( value, 10 ) || 0 } );
							}
						} )
					)
				),
				el( Placeholder, {
					icon: 'media-archive',
					label: __( 'WACZ Player', 'tainacan-wacz-player' ),
					instructions: instructions
				} )
			);
		},
		save: function () {
			// Dynamic block: rendered by PHP.
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
