/* Doneren: titel en tekst instellen in de zijbalk; voorbeeld door de server gerenderd. */
( ( blocks, element, blockEditor, components, ServerSideRender ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/donate-widget', {
		edit: ( { attributes, setAttributes } ) => el(
			'div',
			blockEditor.useBlockProps(),
			el( blockEditor.InspectorControls, null,
				el( components.PanelBody, { title: 'Tekst' },
					el( components.TextControl, { label: 'Titel', value: attributes.title, onChange: ( v ) => setAttributes( { title: v } ) } ),
					el( components.TextareaControl, { label: 'Uitleg', value: attributes.text, onChange: ( v ) => setAttributes( { text: v } ) } )
				)
			),
			el( 'div', { style: { pointerEvents: 'none' } }, el( ServerSideRender, { block: 'pixelfart/donate-widget', attributes } ) )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender );
