/**
 * Enrutado por idioma de extremo a extremo.
 */

const { test, expect } = require( '@playwright/test' );

test.describe( 'Enrutado por idioma', () => {
	test( 'la URL con prefijo sirve la traducción y la de por defecto el original', async ( { page } ) => {
		await page.goto( '/sobre-nosotros/' );
		await expect( page.getByText( 'Vendemos cajas de madera.' ) ).toBeVisible();

		await page.goto( '/en/sobre-nosotros/' );
		await expect( page.getByText( 'We sell wooden boxes.' ) ).toBeVisible();
		await expect( page.getByText( 'Vendemos cajas de madera.' ) ).toHaveCount( 0 );
	} );

	test( 'un idioma sin traducciones sirve el original en lugar de romperse', async ( { page } ) => {
		await page.goto( '/ca/sobre-nosotros/' );

		await expect( page.getByText( 'Vendemos cajas de madera.' ) ).toBeVisible();
	} );

	test( 'el atributo lang es el del idioma de la URL', async ( { page } ) => {
		await page.goto( '/en/sobre-nosotros/' );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', 'en-US' );

		await page.goto( '/sobre-nosotros/' );
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', /^es/ );
	} );

	test( 'cada página declara sus alternativas con hreflang', async ( { page } ) => {
		await page.goto( '/en/sobre-nosotros/' );

		const href = ( lang ) =>
			page.locator( `link[rel="alternate"][hreflang="${ lang }"]` ).getAttribute( 'href' );

		expect( await href( 'es-ES' ) ).toContain( '/sobre-nosotros/' );
		expect( await href( 'en-US' ) ).toContain( '/en/sobre-nosotros/' );
		expect( await href( 'ca' ) ).toContain( '/ca/sobre-nosotros/' );
		expect( await href( 'x-default' ) ).toContain( '/sobre-nosotros/' );
	} );

	test( 'una URL que no existe sigue siendo un 404 en cualquier idioma', async ( { page } ) => {
		const response = await page.goto( '/en/esto-no-existe/' );

		expect( response.status() ).toBe( 404 );
	} );
} );
