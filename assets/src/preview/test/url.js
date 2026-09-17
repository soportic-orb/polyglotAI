import { keepEditMode, shouldNavigate } from '../url';

const PREVIEW =
	'https://ejemplo.com/en/tienda/?pgai-edit=1&pgai-lang=en_US&_wpnonce=abc123';

describe( 'keepEditMode', () => {
	it( 'conserva los parámetros de edición al seguir un enlace', () => {
		const result = new URL( keepEditMode( '/en/contacto/', PREVIEW ) );

		expect( result.pathname ).toBe( '/en/contacto/' );
		expect( result.searchParams.get( 'pgai-edit' ) ).toBe( '1' );
		expect( result.searchParams.get( 'pgai-lang' ) ).toBe( 'en_US' );
		expect( result.searchParams.get( '_wpnonce' ) ).toBe( 'abc123' );
	} );

	it( 'conserva los parámetros propios del destino', () => {
		const result = new URL(
			keepEditMode( '/en/tienda/?orderby=precio', PREVIEW )
		);

		expect( result.searchParams.get( 'orderby' ) ).toBe( 'precio' );
		expect( result.searchParams.get( 'pgai-edit' ) ).toBe( '1' );
	} );

	it( 'no inventa parámetros que la URL actual no tiene', () => {
		const result = new URL(
			keepEditMode(
				'/en/contacto/',
				'https://ejemplo.com/en/?pgai-edit=1'
			)
		);

		expect( result.searchParams.get( 'pgai-lang' ) ).toBeNull();
	} );

	it( 'acepta una URL absoluta del mismo sitio', () => {
		const result = new URL(
			keepEditMode( 'https://ejemplo.com/en/blog/', PREVIEW )
		);

		expect( result.pathname ).toBe( '/en/blog/' );
		expect( result.searchParams.get( 'pgai-lang' ) ).toBe( 'en_US' );
	} );
} );

describe( 'shouldNavigate', () => {
	it.each( [
		[ '/en/contacto/', true ],
		[ 'https://ejemplo.com/en/blog/', true ],
		[ '#seccion', false ],
		[ '', false ],
		[ 'mailto:info@ejemplo.com', false ],
		[ 'tel:+34900000000', false ],
		[ 'javascript:void(0)', false ],
		[ 'https://otro-sitio.com/pagina/', false ],
	] )( 'decide correctamente para %s', ( href, expected ) => {
		expect( shouldNavigate( href, PREVIEW ) ).toBe( expected );
	} );
} );
