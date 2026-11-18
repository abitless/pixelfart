/* Pixelmuur: in de editor alleen een plaatshouder; de muur zelf leeft op de site. */
( ( blocks, element, blockEditor ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/pixel-wall', {
		edit: ( { attributes, setAttributes } ) => el( 'div', blockEditor.useBlockProps( { style: { padding: '24px', boxShadow: '0 0 0 4px #ff5c8a', textAlign: 'center' } } ),
			el( 'input', {
				type: 'text',
				value: attributes.title,
				placeholder: 'Titel boven de muur (leeg = geen titel)',
				'aria-label': 'Titel boven de pixelmuur',
				onChange: ( e ) => setAttributes( { title: e.target.value } ),
				style: { width: '100%', textAlign: 'center', fontWeight: 700, marginBottom: '8px' },
			} ),
			el( 'p', null, 'Hier staat op de site de pixelmuur. Beheer: Donaties › Pixelmuur.' )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor );
