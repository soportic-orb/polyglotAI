/**
 * Utilidades de URL de la vista previa.
 *
 * Viven aparte del guion para poder probarse: conservar los parámetros del modo
 * de edición al navegar es justo la clase de detalle que se rompe en silencio y
 * deja al traductor fuera del editor sin saber por qué.
 */

/** Parámetros que identifican la vista previa del editor. */
export const EDIT_PARAMS = [ 'pgai-edit', 'pgai-lang', '_wpnonce' ];

/**
 * Copia los parámetros del modo de edición de una URL a otra.
 *
 * @param {string} href    Destino al que se navega.
 * @param {string} current URL actual, de la que se toman los parámetros.
 * @return {string} Destino con los parámetros de edición.
 */
export function keepEditMode( href, current ) {
	const from = new URL( current );
	const target = new URL( href, current );

	EDIT_PARAMS.forEach( ( parameter ) => {
		const value = from.searchParams.get( parameter );

		if ( value !== null ) {
			target.searchParams.set( parameter, value );
		}
	} );

	return target.toString();
}

/**
 * Si un enlace debe navegar dentro de la vista previa.
 *
 * Un ancla de la misma página no navega, y un enlace externo tampoco: sacaría
 * al traductor del sitio que está traduciendo.
 *
 * @param {string} href   Destino del enlace.
 * @param {string} origin Origen del sitio.
 * @return {boolean} Si hay que navegar.
 */
export function shouldNavigate( href, origin ) {
	if ( ! href || href.startsWith( '#' ) ) {
		return false;
	}

	if ( /^(mailto|tel|javascript|data):/i.test( href ) ) {
		return false;
	}

	try {
		return new URL( href, origin ).origin === new URL( origin ).origin;
	} catch ( error ) {
		return false;
	}
}
