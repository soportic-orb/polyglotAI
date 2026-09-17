/**
 * Recolección de texto insertado después de cargar la página.
 *
 * La lógica pura vive aparte del observador para poder probarla: decidir qué
 * nodo merece traducirse es justo donde un descuido se paga en peticiones de
 * más o en texto sin traducir.
 */

/** Elementos cuyo texto no se traduce nunca. */
const SKIP_TAGS = [
	'SCRIPT',
	'STYLE',
	'TEXTAREA',
	'CODE',
	'PRE',
	'KBD',
	'SAMP',
	'VAR',
	'SVG',
	'MATH',
	'TEMPLATE',
	'NOSCRIPT',
];

/** Atributos que excluyen un elemento y todo su contenido. */
const SKIP_ATTRIBUTES = [ 'data-no-translation', 'data-pgai-skip' ];

/**
 * Si un texto tiene contenido que valga la pena traducir.
 *
 * Replica el criterio del servidor: sin letras o de un solo carácter no es una
 * cadena, y pedir su traducción sería una petición tirada.
 *
 * @param {string} text Texto.
 * @return {boolean} Si es traducible.
 */
export function isTranslatable( text ) {
	const trimmed = ( text || '' ).replace( /\s+/g, ' ' ).trim();

	if ( trimmed.length < 2 ) {
		return false;
	}

	return /\p{L}/u.test( trimmed );
}

/**
 * Si un nodo de texto está dentro de una zona que no se traduce.
 *
 * @param {Node} node Nodo de texto.
 * @return {boolean} Si hay que saltárselo.
 */
export function isExcluded( node ) {
	let element = node.parentElement;

	while ( element ) {
		if ( SKIP_TAGS.includes( element.tagName ) ) {
			return true;
		}

		if (
			element.classList &&
			element.classList.contains( 'notranslate' )
		) {
			return true;
		}

		if (
			element.getAttribute &&
			element.getAttribute( 'translate' ) === 'no'
		) {
			return true;
		}

		if (
			element.hasAttribute &&
			SKIP_ATTRIBUTES.some( ( attribute ) =>
				element.hasAttribute( attribute )
			)
		) {
			return true;
		}

		element = element.parentElement;
	}

	return false;
}

/**
 * Recorre un nodo y devuelve sus nodos de texto traducibles.
 *
 * @param {Node}    root    Nodo raíz.
 * @param {WeakSet} handled Nodos ya procesados, para no repetirlos.
 * @return {Text[]} Nodos de texto.
 */
export function collectTextNodes( root, handled ) {
	const found = [];

	if ( root.nodeType === Node.TEXT_NODE ) {
		if (
			! handled.has( root ) &&
			isTranslatable( root.nodeValue ) &&
			! isExcluded( root )
		) {
			found.push( root );
		}

		return found;
	}

	if ( root.nodeType !== Node.ELEMENT_NODE ) {
		return found;
	}

	const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
	let node = walker.nextNode();

	while ( node ) {
		if (
			! handled.has( node ) &&
			isTranslatable( node.nodeValue ) &&
			! isExcluded( node )
		) {
			found.push( node );
		}

		node = walker.nextNode();
	}

	return found;
}
