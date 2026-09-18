/**
 * Sitemaps por idioma.
 */

const { test, expect } = require( '@playwright/test' );

test.describe( 'Sitemaps', () => {
	test( 'el índice del núcleo incluye un sitemap por idioma', async ( { request } ) => {
		const xml = await ( await request.get( '/wp-sitemap.xml' ) ).text();

		expect( xml ).toContain( 'wp-sitemap-pgai-en-1.xml' );
		expect( xml ).toContain( 'wp-sitemap-pgai-ca-1.xml' );
	} );

	test( 'el sitemap de un idioma lista sus entradas y sus páginas', async ( { request } ) => {
		const response = await request.get( '/wp-sitemap-pgai-en-1.xml' );

		expect( response.status() ).toBe( 200 );

		const xml = await response.text();

		// Las páginas se quedaban fuera por filtrar por publicly_queryable, que
		// el núcleo pone en false para «page».
		expect( xml ).toContain( '/en/sobre-nosotros/' );
		expect( xml ).toContain( '/en/hello-world/' );
	} );

	test( 'el idioma por defecto no tiene sitemap propio', async ( { request } ) => {
		// Sus URLs ya están en el sitemap normal de WordPress.
		const xml = await ( await request.get( '/wp-sitemap.xml' ) ).text();

		expect( xml ).not.toContain( 'wp-sitemap-pgai-es-' );
	} );
} );
