/**
 * Selector de idioma.
 */

const { test, expect } = require( '@playwright/test' );

test.describe( 'Selector de idioma', () => {
	test( 'lista los idiomas y lleva a la misma página en cada uno', async ( { page } ) => {
		await page.goto( '/sobre-nosotros/' );

		const switcher = page.locator( '.pgai-switcher' ).first();

		await expect( switcher ).toBeVisible();
		await expect( switcher.getByRole( 'link', { name: /English/ } ) ).toHaveAttribute(
			'href',
			/\/en\/sobre-nosotros\/$/
		);
	} );

	test( 'al pulsar un idioma se navega a su versión de la misma página', async ( { page } ) => {
		await page.goto( '/sobre-nosotros/' );

		await page.locator( '.pgai-switcher' ).first().getByRole( 'link', { name: /English/ } ).click();

		await expect( page ).toHaveURL( /\/en\/sobre-nosotros\/$/ );
		await expect( page.getByText( 'We sell wooden boxes.' ) ).toBeVisible();
	} );

	test( 'desde una página traducida se vuelve al idioma por defecto', async ( { page } ) => {
		// Es el caso que se colaba al calcular el enlace: hay que volver al slug
		// original antes de traducir al idioma de destino (ADR-18).
		await page.goto( '/en/sobre-nosotros/' );

		const back = page.locator( '.pgai-switcher' ).first().getByRole( 'link', { name: /Español/ } );

		await expect( back ).toHaveAttribute( 'href', /\/sobre-nosotros\/$/ );
	} );
} );
