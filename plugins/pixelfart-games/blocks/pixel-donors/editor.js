/* Pixelfans: in de editor alleen een plaatshouder; de lijst komt op de site uit dezelfde gegevens als de muur. */
( ( blocks, element, blockEditor ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/pixel-donors', {
		edit: ( { attributes, setAttributes } ) => el( 'div', blockEditor.useBlockProps( { style: { padding: '24px', boxShadow: '0 0 0 4px #ffcf4a', textAlign: 'center' } } ),
			el( 'input', {
				type: 'text',
				value: attributes.title,
				placeholder: 'Titel boven de lijst (leeg = geen titel)',
				'aria-label': 'Titel boven de lijst met pixelfans',
				onChange: ( e ) => setAttributes( { title: e.target.value } ),
				style: { width: '100%', textAlign: 'center', fontWeight: 700, marginBottom: '8px' },
			} ),
			el( 'p', null, 'Hier staan op de site alle pixelkopers: hun tekening, letters, boodschap en datum, de nieuwste eerst. Alleen wat we goedgekeurd hebben.' )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor );
