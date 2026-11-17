/* Topscores: voorbeeld door de server gerenderd; instelling "aantal per game" in de zijbalk. */
( ( blocks, element, blockEditor, components, ServerSideRender ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/highscores', {
		edit: ( { attributes, setAttributes } ) => el(
			'div',
			blockEditor.useBlockProps(),
			el( blockEditor.InspectorControls, null,
				el( components.PanelBody, { title: 'Instellingen' },
					el( components.RangeControl, {
						label: 'Aantal spelers per game',
						min: 1, max: 10,
						value: attributes.limit,
						onChange: ( v ) => setAttributes( { limit: v } ),
					} )
				)
			),
			el( ServerSideRender, { block: 'pixelfart/highscores', attributes } )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.serverSideRender );
