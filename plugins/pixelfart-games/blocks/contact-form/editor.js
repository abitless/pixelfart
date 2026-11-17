/* Contactformulier: in de editor tonen we het echte formulier (door de server gerenderd). */
( ( blocks, element, blockEditor, ServerSideRender ) => {
	const el = element.createElement;
	blocks.registerBlockType( 'pixelfart/contact-form', {
		edit: () => el( 'div', blockEditor.useBlockProps(), el( ServerSideRender, { block: 'pixelfart/contact-form' } ) ),
		save: () => null,
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.serverSideRender );
