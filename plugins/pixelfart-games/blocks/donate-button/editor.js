/* Doneerknop: tekst instellen in de zijbalk; voorbeeld door de server gerenderd. */
( ( blocks, element, blockEditor, components, ServerSideRender ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/donate-button', {
		edit: ( { attributes, setAttributes } ) => el(
			'div',
			blockEditor.useBlockProps(),
			el( blockEditor.InspectorControls, null,
				el( components.PanelBody, { title: 'Knop' },
					el( components.TextControl, {
						label: 'Tekst',
						value: attributes.text,
						onChange: ( v ) => setAttributes( { text: v } ),
						help: 'De knop gaat naar de doneerpagina (Donaties › Instellingen).',
					} )
				)
			),
			el( ServerSideRender, { block: 'pixelfart/donate-button', attributes } )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender );
