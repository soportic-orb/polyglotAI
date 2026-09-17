<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\RequestRouter;
use PolyglotAI\Routing\UrlConverter;
use WP_UnitTestCase;

/**
 * Comprueba que una URL con prefijo de idioma resuelve de verdad dentro de
 * WordPress.
 *
 * Es la prueba que faltaba: UrlConverter es lógica de cadenas y se probaba
 * sola, pero nada verificaba que WordPress llegara a encontrar la página. Sin
 * esto, /en/contacto/ devolvía un 404 y ningún test se enteraba.
 *
 * @covers \PolyglotAI\Routing\RequestRouter
 */
final class RequestRouterTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;

	/**
	 * Prepara idiomas y enlaces permanentes.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->request = new RequestContext( $this->languages, $this->converter() );

		// Sin enlaces bonitos no hay rutas que analizar.
		$this->set_permalink_structure( '/%postname%/' );
	}

	private function converter(): UrlConverter {
		return new UrlConverter( $this->languages, '/', false );
	}

	private function router(): RequestRouter {
		return new RequestRouter( $this->languages, $this->converter(), $this->request );
	}

	/**
	 * Simula una petición y devuelve el REQUEST_URI resultante.
	 *
	 * @param string $uri URI pedida.
	 */
	private function resolve( string $uri ): string {
		$_SERVER['REQUEST_URI'] = $uri;

		$this->router()->resolve();

		return isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
	}

	public function test_una_pagina_se_encuentra_a_traves_de_su_url_con_idioma(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_title'  => 'Contacto',
				'post_status' => 'publish',
			)
		);

		$stripped = $this->resolve( '/en/contacto/' );

		$this->assertSame( '/contacto/', $stripped, 'El prefijo de idioma debe quitarse antes de que WordPress analice la petición.' );

		$this->go_to( $stripped );

		$this->assertTrue( is_page(), 'WordPress no ha reconocido la petición como una página.' );
		$this->assertSame( $page_id, get_queried_object_id() );
	}

	public function test_reconoce_el_idioma_de_la_url(): void {
		$this->resolve( '/en/contacto/' );

		$this->assertSame( 'en_US', $this->request->language()->locale );
		$this->assertFalse( $this->request->is_default() );
	}

	public function test_conserva_la_ruta_original_para_quien_la_necesite(): void {
		// hreflang, selector de idioma y editor necesitan saber dónde está el
		// visitante DE VERDAD, no la ruta ya recortada.
		$this->resolve( '/en/contacto/' );

		$this->assertSame( '/en/contacto/', $this->request->path() );
	}

	public function test_el_idioma_por_defecto_no_toca_la_peticion(): void {
		$this->assertSame( '/contacto/', $this->resolve( '/contacto/' ) );
		$this->assertTrue( $this->request->is_default() );
	}

	public function test_conserva_la_cadena_de_consulta(): void {
		$this->assertSame( '/tienda/?orderby=precio&page=2', $this->resolve( '/en/tienda/?orderby=precio&page=2' ) );
	}

	public function test_no_toca_una_ruta_que_empieza_por_algo_parecido(): void {
		// «entrada» empieza por «en» pero no es el segmento de idioma.
		$this->assertSame( '/entrada-de-blog/', $this->resolve( '/entrada-de-blog/' ) );
		$this->assertTrue( $this->request->is_default() );
	}

	public function test_la_raiz_de_un_idioma_resuelve_a_la_portada(): void {
		$this->assertSame( '/', $this->resolve( '/en/' ) );
		$this->assertSame( 'en_US', $this->request->language()->locale );
	}

	public function test_una_entrada_se_encuentra_en_cualquier_idioma(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'   => 'mi-entrada',
				'post_status' => 'publish',
			)
		);

		foreach ( array( '/en/mi-entrada/', '/ca/mi-entrada/' ) as $uri ) {
			$this->go_to( $this->resolve( $uri ) );

			$this->assertTrue( is_single(), "No se ha resuelto {$uri}" );
			$this->assertSame( $post_id, get_queried_object_id() );
		}
	}

	public function test_la_redireccion_canonica_no_se_come_el_idioma(): void {
		// WordPress razona sobre la ruta sin prefijo: si se le dejara redirigir
		// a su destino tal cual, el idioma se perdería en cada visita.
		$this->resolve( '/en/contacto/' );

		$redirect = $this->router()->keep_language_prefix( 'https://example.org/contacto/' );

		$this->assertSame( 'https://example.org/en/contacto/', $redirect );
	}

	public function test_no_altera_la_redireccion_en_el_idioma_por_defecto(): void {
		$this->resolve( '/contacto/' );

		$this->assertSame(
			'https://example.org/contacto/',
			$this->router()->keep_language_prefix( 'https://example.org/contacto/' )
		);
	}
}
