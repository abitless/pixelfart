/* Pixelmuur: in de editor alleen een plaatshouder; de muur zelf leeft op de site. */
( ( blocks, element, blockEditor ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/pixel-wall', {
		edit: () => el( 'div', blockEditor.useBlockProps( { style: { padding: '24px', boxShadow: '0 0 0 4px #ff5c8a', textAlign: 'center' } } ),
			el( 'strong', null, 'PIXELMUUR' ),
			el( 'p', null, 'Hier staat op de site de pixelmuur. Beheer: Donaties › Pixelmuur.' )
		),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor );
