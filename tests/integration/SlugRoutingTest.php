<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\RequestRouter;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * Enrutado inverso: /en/contact-us/ tiene que servir la página cuyo slug real
 * es «contacto».
 *
 * @covers \PolyglotAI\Routing\SlugResolver
 * @covers \PolyglotAI\Routing\RequestRouter
 */
final class SlugRoutingTest extends WP_UnitTestCase {

	private LanguageRegistry $languages;
	private RequestContext $request;
	private SlugRepository $slugs;

	/**
	 * Idiomas, enlaces bonitos y tabla limpia.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->request = new RequestContext( $this->languages, $this->converter() );
		$this->slugs   = new SlugRepository( new StatusPrecedence() );

		$this->set_permalink_structure( '/%postname%/' );
	}

	private function converter(): UrlConverter {
		return new UrlConverter( $this->languages, '/', false );
	}

	private function router(): RequestRouter {
		return new RequestRouter(
			$this->languages,
			$this->converter(),
			$this->request,
			new SlugResolver( $this->slugs )
		);
	}

	/**
	 * Simula una petición y devuelve el REQUEST_URI resultante.
	 *
	 * @param string $uri URI pedida.
	 */
	private function resolve( string $uri ): string {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		$this->router()->resolve();

		return isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
	}

	public function test_una_pagina_se_sirve_por_su_slug_traducido(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_title'  => 'Contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );

		$stripped = $this->resolve( '/en/contact-us/' );

		$this->assertSame( '/contacto/', $stripped );

		$this->go_to( $stripped );

		$this->assertTrue( is_page() );
		$this->assertSame( $page_id, get_queried_object_id() );
	}

	public function test_traduce_todos_los_segmentos_de_una_ruta_jerarquica(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'empresa',
				'post_status' => 'publish',
			)
		);

		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_parent' => $parent,
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $parent, 'en_US', 'empresa', 'company' ) );
		$this->slugs->save( new SlugRecord( 'post', 'page', $child, 'en_US', 'contacto', 'contact-us' ) );

		$stripped = $this->resolve( '/en/company/contact-us/' );

		$this->assertSame( '/empresa/contacto/', $stripped );

		$this->go_to( $stripped );

		$this->assertSame( $child, get_queried_object_id() );
	}

	public function test_deja_pasar_los_segmentos_que_no_son_slugs(): void {
		// «page» y el número de paginación no están en la tabla y tienen que
		// llegar a WordPress tal cual.
		$this->slugs->save( new SlugRecord( 'term', 'category', 3, 'en_US', 'noticias', 'news' ) );

		$this->assertSame( '/noticias/page/2/', $this->resolve( '/en/news/page/2/' ) );
	}

	public function test_una_ruta_sin_traducciones_no_cambia(): void {
		$this->assertSame( '/algo/que/nadie/tradujo/', $this->resolve( '/en/algo/que/nadie/tradujo/' ) );
	}

	public function test_el_idioma_por_defecto_no_traduce_slugs(): void {
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );

		$this->assertSame( '/contacto/', $this->resolve( '/contacto/' ) );
	}

	public function test_conserva_la_cadena_de_consulta_al_traducir_slugs(): void {
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );

		$this->assertSame( '/contacto/?utm_source=boletin', $this->resolve( '/en/contact-us/?utm_source=boletin' ) );
	}

	public function test_redirige_del_slug_sin_traducir_al_traducido(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );

		$router = $this->router();

		$_SERVER['REQUEST_URI']    = '/en/contacto/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';

		$router->resolve();
		$this->go_to( '/contacto/' );

		$this->assertSame( home_url( '/en/contact-us/' ), $router->translated_slug_redirect() );
	}

	public function test_la_url_buena_no_redirige(): void {
		// Si redirigiera, el visitante entraría en un bucle.
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );

		$router = $this->router();

		$_SERVER['REQUEST_URI']    = '/en/contact-us/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';

		$router->resolve();
		$this->go_to( '/contacto/' );

		$this->assertNull( $router->translated_slug_redirect() );
	}

	public function test_el_idioma_por_defecto_nunca_redirige(): void {
		$this->slugs->save( new SlugRecord( 'post', 'page', 12, 'en_US', 'contacto', 'contact-us' ) );

		$router = $this->router();

		$_SERVER['REQUEST_URI']    = '/contacto/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';

		$router->resolve();
		$this->go_to( '/contacto/' );

		$this->assertNull( $router->translated_slug_redirect() );
	}

	public function test_no_redirige_un_envio_de_formulario(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );

		$router = $this->router();

		$_SERVER['REQUEST_URI']    = '/en/contacto/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['QUERY_STRING']   = '';

		$router->resolve();
		$this->go_to( '/contacto/' );

		// go_to() reconstruye parte de $_SERVER, así que el método se vuelve a
		// poner después.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Un 301 sobre un POST se lleva por delante los datos del formulario.
		$this->assertNull( $router->translated_slug_redirect() );

		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_la_redireccion_conserva_la_cadena_de_consulta(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_status' => 'publish',
			)
		);

		$this->slugs->save( new SlugRecord( 'post', 'page', $page_id, 'en_US', 'contacto', 'contact-us' ) );

		$router = $this->router();

		$_SERVER['REQUEST_URI']    = '/en/contacto/?utm_source=boletin';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = 'utm_source=boletin';

		$router->resolve();
		$this->go_to( '/contacto/' );

		$_SERVER['QUERY_STRING'] = 'utm_source=boletin';

		$this->assertSame( home_url( '/en/contact-us/?utm_source=boletin' ), $router->translated_slug_redirect() );

		$_SERVER['QUERY_STRING'] = '';
	}
}
