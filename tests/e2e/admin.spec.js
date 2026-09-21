/**
 * Las pantallas del escritorio que hablan con la API REST.
 */

const { test, expect } = require( '@playwright/test' );

/**
 * Entra en el escritorio.
 *
 * @param {import('@playwright/test').Page} page Página.
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

test.describe( 'Pantallas del escritorio', () => {
	test( 'el gestor de cadenas llama a la API con su espacio de nombres', async ( {
		page,
	} ) => {
		// Esto solo se ve en un navegador. WordPress registra su propio
		// middleware de raíz al cargar wp-api-fetch, y corre después del que
		// registrábamos nosotros: rehacía la URL desde «path» y la petición
		// acababa en /wp-json/manager, sin el pgai/v1, con rest_no_route.
		const responses = [];

		page.on( 'response', ( response ) => {
			if ( response.url().includes( '/wp-json/' ) ) {
				responses.push( {
					url: response.url(),
					status: response.status(),
				} );
			}
		} );

		await login( page );
		await page.goto( '/wp-admin/admin.php?page=polyglot-ai-strings' );
		await page.waitForTimeout( 2500 );

		const ours = responses.filter( ( r ) => r.url.includes( '/pgai/v1/' ) );

		expect( ours.length ).toBeGreaterThan( 0 );

		for ( const response of ours ) {
			expect( response.status, response.url ).toBe( 200 );
		}

		// Y ninguna petición nuestra puede haber salido sin el espacio de
		// nombres, que es como se manifestaba el fallo.
		const orphans = responses.filter( ( r ) =>
			/\/wp-json\/(manager|site|strings|slugs|merges|suggest)\b/.test(
				r.url
			)
		);

		expect( orphans ).toEqual( [] );
	} );

	test( 'ninguna llamada de la pantalla acaba en 404', async ( { page } ) => {
		// Buscar el mensaje de error en pantalla no sirve: depende del idioma
		// del escritorio y de cómo lo pinte la aplicación. El 404 sí es la
		// misma señal en cualquier instalación.
		const missing = [];

		page.on( 'response', ( response ) => {
			if ( response.url().includes( '/wp-json/' ) && 404 === response.status() ) {
				missing.push( response.url() );
			}
		} );

		await login( page );
		await page.goto( '/wp-admin/admin.php?page=polyglot-ai-strings' );
		await page.waitForTimeout( 2500 );

		expect( missing ).toEqual( [] );
	} );

	test( 'los ajustes se guardan y vuelven a su pantalla', async ( { page } ) => {
		// Guardar dejaba la pantalla en blanco: el destino de la vuelta se
		// construía con menu_page_url(), que en admin-post.php no conoce
		// ninguna página todavía.
		await login( page );
		await page.goto( '/wp-admin/admin.php?page=polyglot-ai' );

		await page.click( '#submit' );

		await expect( page ).toHaveURL( /page=polyglot-ai&pgai-saved=1/ );
		await expect( page.locator( '.notice-success' ) ).toContainText(
			'Ajustes guardados'
		);
	} );
} );
