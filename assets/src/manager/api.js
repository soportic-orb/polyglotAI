/**
 * Acceso a la API REST desde el gestor de cadenas.
 */

import apiFetch from '@wordpress/api-fetch';

const boot = window.pgaiManager || {};

apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce || '' ) );
apiFetch.use(
	apiFetch.createRootURLMiddleware( ( boot.restUrl || '' ) + '/' )
);

/**
 * Busca cadenas.
 *
 * @param {Object} filters Criterios: language, search, status, type, page, perPage.
 * @return {Promise<Object>} Página de resultados.
 */
export function fetchStrings( filters ) {
	const query = new URLSearchParams( {
		language: filters.language,
		search: filters.search || '',
		status: filters.status || '',
		type: filters.type || '',
		page: String( filters.page || 1 ),
		per_page: String( filters.perPage || 50 ),
	} );

	return apiFetch( { path: `manager?${ query.toString() }` } );
}

/**
 * Guarda una traducción.
 *
 * @param {string} hash        Hash de la cadena.
 * @param {string} translation Traducción.
 * @param {string} language    Locale.
 * @return {Promise<Object>} Resultado.
 */
export function saveTranslation( hash, translation, language ) {
	return apiFetch( {
		path: 'strings',
		method: 'POST',
		data: {
			language,
			translations: [ { hash, translation, status: 'manual' } ],
		},
	} );
}

/**
 * Aplica una acción a varias cadenas.
 *
 * @param {string}   action    review, retranslate o delete.
 * @param {number[]} sourceIds Identificadores.
 * @param {string}   language  Locale.
 * @return {Promise<Object>} Cuántas cadenas se han visto afectadas.
 */
export function applyBulk( action, sourceIds, language ) {
	return apiFetch( {
		path: 'manager/bulk',
		method: 'POST',
		data: { language, action, source_ids: sourceIds },
	} );
}

/**
 * Estado de la traducción de sitio completo.
 *
 * @param {string} language Locale.
 * @return {Promise<Object>} Estado.
 */
export function fetchSiteRun( language ) {
	return apiFetch( {
		path: `site?language=${ encodeURIComponent( language ) }`,
	} );
}

/**
 * Arranca, para, reanuda o cancela la traducción de sitio completo.
 *
 * @param {string} command  start, pause, resume o cancel.
 * @param {string} language Locale.
 * @return {Promise<Object>} Estado resultante.
 */
export function commandSiteRun( command, language ) {
	return apiFetch( {
		path: 'site',
		method: 'POST',
		data: { language, command },
	} );
}

/**
 * Estima lo que costaría traducir lo pendiente.
 *
 * Va en su propia llamada porque cuesta una petición a la API: pedirla con cada
 * sondeo del progreso sería pagarla cada quince segundos.
 *
 * @param {string} language Locale.
 * @return {Promise<Object>} Cadenas y tokens estimados.
 */
export function estimateSiteRun( language ) {
	return apiFetch( {
		path: `site/estimate?language=${ encodeURIComponent( language ) }`,
	} );
}
