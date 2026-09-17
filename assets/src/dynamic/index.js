/**
 * Traducción del contenido que aparece después de cargar la página.
 *
 * Un filtro por AJAX, un carrito que se actualiza o un carrusel que monta su
 * contenido con JavaScript se saltan el barrido del HTML, porque cuando este
 * ocurrió ese texto todavía no existía.
 */

import { collectTextNodes } from './collect';

const config = window.pgaiDynamic || {};

/** Nodos ya procesados. Evita el bucle de observar lo que uno mismo cambia. */
const handled = new WeakSet();

/** Traducciones ya conocidas en esta pestaña. */
const cache = new Map();

/** Nodos pendientes de resolver. */
let queue = [];

/** Temporizador de agrupación. */
let timer = null;

/**
 * Lee la caché guardada en la pestaña.
 *
 * Evita volver a pedir lo mismo al navegar por el sitio. Si el navegador la
 * tiene bloqueada, simplemente se trabaja sin ella.
 */
function loadCache() {
	try {
		const stored = window.sessionStorage.getItem(
			`pgai:${ config.language }`
		);

		if ( stored ) {
			Object.entries( JSON.parse( stored ) ).forEach(
				( [ key, value ] ) => {
					cache.set( key, value );
				}
			);
		}
	} catch ( error ) {
		// Sin almacenamiento de sesión se sigue funcionando, solo que pidiendo
		// más veces lo mismo.
	}
}

/**
 * Guarda la caché de la pestaña.
 */
function saveCache() {
	try {
		window.sessionStorage.setItem(
			`pgai:${ config.language }`,
			JSON.stringify( Object.fromEntries( cache ) )
		);
	} catch ( error ) {
		// Cuota llena o almacenamiento bloqueado: no es motivo para fallar.
	}
}

/**
 * Aplica una traducción a un nodo de texto conservando su espaciado.
 *
 * @param {Text}   node        Nodo.
 * @param {string} translation Traducción.
 */
function apply( node, translation ) {
	const original = node.nodeValue;
	const leading = original.match( /^\s*/ )[ 0 ];
	const trailing = original.match( /\s*$/ )[ 0 ];

	handled.add( node );
	node.nodeValue = leading + translation + trailing;
}

/**
 * Resuelve los nodos pendientes.
 */
async function resolve() {
	const pending = queue;

	queue = [];

	const unknown = [];

	pending.forEach( ( node ) => {
		const key = node.nodeValue.replace( /\s+/g, ' ' ).trim();

		if ( cache.has( key ) ) {
			apply( node, cache.get( key ) );

			return;
		}

		handled.add( node );
		unknown.push( { node, key } );
	} );

	if ( unknown.length === 0 ) {
		return;
	}

	try {
		const response = await window.fetch( `${ config.restUrl }/dynamic`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify( {
				language: config.language,
				texts: unknown.map( ( item ) => item.key ),
			} ),
		} );

		if ( ! response.ok ) {
			return;
		}

		const data = await response.json();
		const translations = data.translations || {};

		unknown.forEach( ( item ) => {
			if ( translations[ item.key ] ) {
				cache.set( item.key, translations[ item.key ] );
				apply( item.node, translations[ item.key ] );
			}
		} );

		saveCache();
	} catch ( error ) {
		// Una traducción que no llega deja el texto original: molesta, pero no
		// rompe la página.
	}
}

/**
 * Encola nodos y agrupa las peticiones.
 *
 * @param {Text[]} nodes Nodos.
 */
function enqueue( nodes ) {
	if ( nodes.length === 0 ) {
		return;
	}

	queue = queue.concat( nodes );

	window.clearTimeout( timer );
	timer = window.setTimeout( resolve, 60 );
}

/**
 * Arranca el observador.
 */
function start() {
	if ( ! config.language || ! config.restUrl ) {
		return;
	}

	loadCache();

	// Lo que ya está en la página lo ha traducido el servidor: se marca como
	// visto para no volver a pedirlo.
	collectTextNodes( document.body, handled ).forEach( ( node ) =>
		handled.add( node )
	);

	const observer = new window.MutationObserver( ( mutations ) => {
		const nodes = [];

		mutations.forEach( ( mutation ) => {
			if ( mutation.type === 'characterData' ) {
				nodes.push( ...collectTextNodes( mutation.target, handled ) );

				return;
			}

			mutation.addedNodes.forEach( ( node ) => {
				nodes.push( ...collectTextNodes( node, handled ) );
			} );
		} );

		enqueue( nodes );
	} );

	observer.observe( document.body, {
		childList: true,
		subtree: true,
		characterData: true,
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
