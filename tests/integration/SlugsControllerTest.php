<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Rest\SlugsController;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\SlugSync;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Rest\SlugsController
 */
final class SlugsControllerTest extends WP_UnitTestCase {

	private SlugRepository $slugs;
	private int $page_id = 0;

	/**
	 * Servidor REST, idiomas, contenido y un usuario que pueda traducir.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		delete_option( SlugSync::BASES_SIGNATURE_OPTION );

		$this->set_permalink_structure( '/%postname%/' );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$this->slugs = new SlugRepository( new StatusPrecedence() );

		$converter = new UrlConverter( $languages, '/', false );

		( new SlugSync( $this->slugs, $languages ) )->register();

		$controller = new SlugsController(
			$languages,
			$this->slugs,
			new SlugResolver( $this->slugs ),
			$converter
		);

		// rest_get_server() arranca el servidor la primera vez; las rutas se
		// añaden después y vuelven a registrarse en cada test sin efectos.
		rest_get_server();
		$controller->register_routes();

		$this->page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'contacto',
				'post_title'  => 'Contacto',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Inicia sesión con un usuario capaz de traducir.
	 */
	private function as_translator(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$user = get_user_by( 'id', $user_id );
		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::TRANSLATE );

		wp_set_current_user( $user_id );
	}

	/**
	 * Lanza una petición REST.
	 *
	 * @param string               $method Método.
	 * @param array<string, mixed> $params Parámetros.
	 */
	private function request( string $method, array $params ) {
		$request = new WP_REST_Request( $method, '/pgai/v1/slugs' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_sin_permiso_no_se_leen_los_slugs(): void {
		wp_set_current_user( 0 );

		$response = $this->request(
			'GET',
			array(
				'language' => 'en_US',
				'url'      => home_url( '/contacto/' ),
			)
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_un_suscriptor_tampoco(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->request(
			'GET',
			array(
				'language' => 'en_US',
				'url'      => home_url( '/contacto/' ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_devuelve_los_slugs_editables_de_una_pagina(): void {
		$this->as_translator();

		$response = $this->request(
			'GET',
			array(
				'language' => 'en_US',
				'url'      => home_url( '/contacto/' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$items = $response->get_data()['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( 'post', $items[0]['object_type'] );
		$this->assertSame( $this->page_id, $items[0]['object_id'] );
		$this->assertSame( 'contacto', $items[0]['original_slug'] );
		$this->assertSame( '', $items[0]['translated_slug'] );
		$this->assertSame( Status::Pending->value, $items[0]['status'] );
		$this->assertSame( 'Contacto', $items[0]['label'] );
	}

	public function test_acepta_la_url_ya_traducida_que_ve_el_traductor(): void {
		$this->as_translator();

		$this->request(
			'POST',
			array(
				'language'        => 'en_US',
				'object_type'     => 'post',
				'object_subtype'  => 'page',
				'object_id'       => $this->page_id,
				'translated_slug' => 'contact-us',
			)
		);

		$response = $this->request(
			'GET',
			array(
				'language' => 'en_US',
				'url'      => home_url( '/en/contact-us/' ),
			)
		);

		$items = $response->get_data()['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( $this->page_id, $items[0]['object_id'] );
		$this->assertSame( 'contact-us', $items[0]['translated_slug'] );
	}

	public function test_guardar_un_slug_lo_deja_como_manual(): void {
		$this->as_translator();

		$response = $this->request(
			'POST',
			array(
				'language'        => 'en_US',
				'object_type'     => 'post',
				'object_subtype'  => 'page',
				'object_id'       => $this->page_id,
				'translated_slug' => 'Contact Us',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'contact-us', $response->get_data()['translated_slug'] );

		$record = $this->slugs->find( 'post', 'page', $this->page_id, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( Status::Manual, $record->status, 'Lo escrito a mano es manual y nada automático puede pisarlo.' );
	}

	public function test_no_se_guarda_un_slug_vacio(): void {
		$this->as_translator();

		$response = $this->request(
			'POST',
			array(
				'language'        => 'en_US',
				'object_type'     => 'post',
				'object_subtype'  => 'page',
				'object_id'       => $this->page_id,
				'translated_slug' => '   ',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_no_se_inventa_filas_para_lo_que_no_se_sigue(): void {
		$this->as_translator();

		$response = $this->request(
			'POST',
			array(
				'language'        => 'en_US',
				'object_type'     => 'post',
				'object_subtype'  => 'page',
				'object_id'       => 999999,
				'translated_slug' => 'lo-que-sea',
			)
		);

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_un_idioma_desconocido_se_rechaza(): void {
		$this->as_translator();

		$response = $this->request(
			'GET',
			array(
				'language' => 'zz_ZZ',
				'url'      => home_url( '/contacto/' ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}
}
