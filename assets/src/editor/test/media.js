import { buildSrcset } from '../media';

describe( 'buildSrcset', () => {
	it( 'ordena los candidatos por anchura', () => {
		const result = buildSrcset( {
			sizes: {
				full: { url: '/gat.png', width: 1200 },
				thumbnail: { url: '/gat-150.png', width: 150 },
				medium: { url: '/gat-600.png', width: 600 },
			},
		} );

		expect( result ).toBe(
			'/gat-150.png 150w, /gat-600.png 600w, /gat.png 1200w'
		);
	} );

	it( 'descarta anchuras repetidas, que harían el srcset inválido', () => {
		const result = buildSrcset( {
			sizes: {
				medium: { url: '/a-600.png', width: 600 },
				recorte: { url: '/b-600.png', width: 600 },
				full: { url: '/a.png', width: 1200 },
			},
		} );

		expect( result ).toBe( '/a-600.png 600w, /a.png 1200w' );
	} );

	it( 'no devuelve srcset con un solo tamaño', () => {
		expect(
			buildSrcset( { sizes: { full: { url: '/gat.png', width: 1200 } } } )
		).toBe( '' );
	} );

	it( 'tolera un adjunto sin tamaños', () => {
		expect( buildSrcset( {} ) ).toBe( '' );
		expect( buildSrcset( null ) ).toBe( '' );
	} );

	it( 'ignora los tamaños incompletos', () => {
		const result = buildSrcset( {
			sizes: {
				roto: { width: 300 },
				medium: { url: '/gat-600.png', width: 600 },
				full: { url: '/gat.png', width: 1200 },
			},
		} );

		expect( result ).toBe( '/gat-600.png 600w, /gat.png 1200w' );
	} );
} );
