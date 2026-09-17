import { collectTextNodes, isExcluded, isTranslatable } from '../collect';

describe( 'isTranslatable', () => {
	it.each( [
		[ 'Hola món', true ],
		[ 'Sí', true ],
		[ '  Añadir al carrito  ', true ],
		[ '', false ],
		[ '   ', false ],
		[ '19,99', false ],
		[ '—', false ],
		[ 'a', false ],
		[ 'مرحبا', true ],
	] )( 'decide correctamente para %s', ( text, expected ) => {
		expect( isTranslatable( text ) ).toBe( expected );
	} );
} );

describe( 'isExcluded', () => {
	/**
	 * Crea un árbol y devuelve su nodo de texto.
	 *
	 * @param {string} html HTML del contenedor.
	 * @return {Node} Nodo de texto interior.
	 */
	function textNodeIn( html ) {
		const host = document.createElement( 'div' );

		host.innerHTML = html;

		return document
			.createTreeWalker( host, NodeFilter.SHOW_TEXT )
			.nextNode();
	}

	it.each( [
		[ '<p>Traducible</p>', false ],
		[ '<p class="notranslate">No tocar</p>', true ],
		[ '<p translate="no">No tocar</p>', true ],
		[ '<p data-no-translation>No tocar</p>', true ],
		[ '<code>echo "hola";</code>', true ],
		[ '<pre>texto</pre>', true ],
		[
			'<div class="notranslate"><span><em>Heredado</em></span></div>',
			true,
		],
	] )( 'decide correctamente para %s', ( html, expected ) => {
		expect( isExcluded( textNodeIn( html ) ) ).toBe( expected );
	} );
} );

describe( 'collectTextNodes', () => {
	it( 'recoge los nodos traducibles y descarta el resto', () => {
		const host = document.createElement( 'div' );

		host.innerHTML =
			'<p>Uno</p><p class="notranslate">No</p><p>123</p><span>Dos</span><script>var a=1;</script>';

		const nodes = collectTextNodes( host, new WeakSet() );

		expect( nodes.map( ( node ) => node.nodeValue.trim() ) ).toEqual( [
			'Uno',
			'Dos',
		] );
	} );

	it( 'no devuelve dos veces un nodo ya procesado', () => {
		const host = document.createElement( 'div' );

		host.innerHTML = '<p>Uno</p><p>Dos</p>';

		const handled = new WeakSet();
		const first = collectTextNodes( host, handled );

		first.forEach( ( node ) => handled.add( node ) );

		expect( collectTextNodes( host, handled ) ).toEqual( [] );
	} );

	it( 'acepta que le pasen directamente un nodo de texto', () => {
		const node = document.createTextNode( 'Hola món' );

		expect( collectTextNodes( node, new WeakSet() ) ).toHaveLength( 1 );
	} );
} );
