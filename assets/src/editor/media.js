/**
 * Selector de imágenes del editor.
 */

import { __ } from '@wordpress/i18n';

/**
 * Construye un srcset a partir de los tamaños de un adjunto.
 *
 * Al sustituir una imagen hay que sustituir también sus variantes responsive:
 * si no, la imagen traducida solo se vería en algunos tamaños de pantalla y en
 * el resto seguiría apareciendo la original.
 *
 * @param {Object} attachment Adjunto de la mediateca.
 * @return {string} Valor para el atributo srcset, vacío si no hay tamaños.
 */
export function buildSrcset( attachment ) {
	const sizes = attachment && attachment.sizes ? attachment.sizes : {};

	const candidates = Object.values( sizes )
		.filter( ( size ) => size && size.url && size.width )
		.reduce( ( unique, size ) => {
			// Dos tamaños pueden compartir anchura (por ejemplo un recorte);
			// un srcset con anchuras repetidas es inválido.
			if (
				! unique.some( ( existing ) => existing.width === size.width )
			) {
				unique.push( size );
			}

			return unique;
		}, [] )
		.sort( ( a, b ) => a.width - b.width );

	if ( candidates.length < 2 ) {
		return '';
	}

	return candidates
		.map( ( size ) => `${ size.url } ${ size.width }w` )
		.join( ', ' );
}

/**
 * Abre la mediateca y devuelve el adjunto elegido.
 *
 * @param {Function} onSelect Devolución con el adjunto.
 */
export function openMediaLibrary( onSelect ) {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}

	const frame = window.wp.media( {
		title: __( 'Elegir la imagen de este idioma', 'polyglot-ai' ),
		button: { text: __( 'Usar esta imagen', 'polyglot-ai' ) },
		library: { type: 'image' },
		multiple: false,
	} );

	frame.on( 'select', () => {
		onSelect( frame.state().get( 'selection' ).first().toJSON() );
	} );

	frame.open();
}
