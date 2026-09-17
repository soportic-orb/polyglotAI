/**
 * Acceso a la API REST del plugin.
 */

import apiFetch from '@wordpress/api-fetch';

const boot = window.pgaiEditor || {};

apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce || '' ) );
apiFetch.use(
	apiFetch.createRootURLMiddleware( ( boot.restUrl || '' ) + '/' )
);

/**
 * Recupera cadenas con su traducción y su estado.
 *
 * @param {string[]} hashes   Hashes.
 * @param {string}   language Locale.
 * @return {Promise<Object>} Respuesta.
 */
export function fetchStrings( hashes, language ) {
	return apiFetch( {
		path: `strings?language=${ encodeURIComponent( language ) }&${ hashes
			.map( ( hash ) => `hashes[]=${ encodeURIComponent( hash ) }` )
			.join( '&' ) }`,
	} );
}

/**
 * Guarda traducciones.
 *
 * @param {Array}  translations Lista de {hash, translation, status}.
 * @param {string} language     Locale.
 * @return {Promise<Object>} Respuesta con lo guardado y lo rechazado.
 */
export function saveStrings( translations, language ) {
	return apiFetch( {
		path: 'strings',
		method: 'POST',
		data: { language, translations },
	} );
}

/**
 * Pide traducciones automáticas.
 *
 * @param {string[]} hashes   Hashes.
 * @param {string}   language Locale.
 * @param {boolean}  save     Si se guardan además de devolverse.
 * @return {Promise<Object>} Sugerencias y fallos.
 */
export function suggestStrings( hashes, language, save = false ) {
	return apiFetch( {
		path: 'suggest',
		method: 'POST',
		data: { language, hashes, save },
	} );
}

/**
 * Fusiona varias cadenas en un solo bloque de traducción.
 *
 * @param {string[]} hashes Hashes en el orden en que aparecen en la página.
 * @return {Promise<Object>} Identificador del grupo creado.
 */
export function createMerge( hashes ) {
	return apiFetch( { path: 'merges', method: 'POST', data: { hashes } } );
}

/**
 * Deshace una fusión.
 *
 * @param {string} hash Hash de un miembro, o el contexto del bloque fusionado.
 * @return {Promise<Object>} Resultado.
 */
export function removeMerge( hash ) {
	return apiFetch( { path: 'merges', method: 'DELETE', data: { hash } } );
}

/**
 * Recupera los slugs traducibles de una página.
 *
 * @param {string} url      URL de la página, tal como se está viendo.
 * @param {string} language Locale.
 * @return {Promise<Object>} Respuesta con la lista de slugs.
 */
export function fetchSlugs( url, language ) {
	return apiFetch( {
		path: `slugs?language=${ encodeURIComponent(
			language
		) }&url=${ encodeURIComponent( url ) }`,
	} );
}

/**
 * Guarda un slug traducido.
 *
 * El slug devuelto puede no ser el enviado: si otro objeto ya usaba ese slug en
 * ese idioma, el servidor lo desambigua con un sufijo.
 *
 * @param {Object} slug     Slug a guardar.
 * @param {string} language Locale.
 * @return {Promise<Object>} Slug guardado y su estado.
 */
export function saveSlug( slug, language ) {
	return apiFetch( {
		path: 'slugs',
		method: 'POST',
		data: {
			language,
			object_type: slug.object_type,
			object_subtype: slug.object_subtype,
			object_id: slug.object_id,
			translated_slug: slug.translated_slug,
		},
	} );
}
