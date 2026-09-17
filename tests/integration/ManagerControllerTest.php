<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\StringManagerRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Rest\ManagerController;
use PolyglotAI\Support\Capabilities;
use PolyglotAI\Support\TranslatorLanguages;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Rest\ManagerController
 * @covers \PolyglotAI\Database\StringManagerRepository
 */
final class ManagerControllerTest extends WP_UnitTestCase {

	private SourceRepository $sources;
	private TranslationRepository $translations;
	private LanguageRegistry $languages;

	/**
	 * Tablas limpias y la ruta del gestor registrada en solitario.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb, $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

		foreach ( array( 'translations', 'sources' ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		$this->languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array(
				new Language( 'en_US', 'en', 'English' ),
				new Language( 'ca', 'ca', 'Català' ),
			)
		);

		$this->sources      = new SourceRepository();
		$this->translations = new TranslationRepository( new StatusPrecedence() );

		$controller = new ManagerController(
			$this->languages,
			new StringManagerRepository(),
			$this->translations
		);

		// El plugin ya registra esta ruta con su propio registro de idiomas,
		// vacío en un WordPress recién instalado: se deja solo la del test.
		remove_all_actions( 'rest_api_init' );

		add_action(
			'rest_api_init',
			static function () use ( $controller ): void {
				$controller->register_routes();
			}
		);

		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Registra una cadena y, si se pide, su traducción.
	 *
	 * @param string      $text        Original.
	 * @param string      $translation Traducción.
	 * @param Status|null $status      Estado.
	 * @param StringType  $type        Tipo.
	 */
	private function string( string $text, string $translation = '', ?Status $status = null, StringType $type = StringType::Text ): int {
		$id = $this->sources->remember( md5( $text . $type->value ), $text, $type );

		if ( null !== $status ) {
			$this->translations->save( $id, 'en_US', $translation, $status );
		}

		return $id;
	}

	/**
	 * Inicia sesión con alguien que puede traducir.
	 *
	 * @param bool $can_review Si además puede revisar.
	 */
	private function as_translator( bool $can_review = true ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = get_user_by( 'id', $user_id );

		$this->assertNotFalse( $user );
		$user->add_cap( Capabilities::TRANSLATE );

		if ( $can_review ) {
			$user->add_cap( Capabilities::REVIEW );
		}

		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Lanza una petición REST.
	 *
	 * @param string               $method Método.
	 * @param string               $route  Ruta.
	 * @param array<string, mixed> $params Parámetros.
	 */
	private function request( string $method, string $route, array $params ) {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_sin_permiso_no_se_listan_las_cadenas(): void {
		wp_set_current_user( 0 );

		$this->assertSame(
			401,
			$this->request( 'GET', '/pgai/v1/manager', array( 'language' => 'en_US' ) )->get_status()
		);
	}

	public function test_lista_las_cadenas_con_su_estado(): void {
		$this->as_translator();

		$this->string( 'Hola', 'Hello', Status::Automatic );
		$this->string( 'Adiós' );

		$response = $this->request( 'GET', '/pgai/v1/manager', array( 'language' => 'en_US' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['total'] );

		$by_original = array_column( $data['items'], null, 'original' );

		$this->assertSame( 'Hello', $by_original['Hola']['translation'] );
		$this->assertSame( 'automatic', $by_original['Hola']['status'] );

		// Una cadena sin fila de traducción está pendiente aunque en la tabla
		// no haya nada que lo diga.
		$this->assertSame( 'pending', $by_original['Adiós']['status'] );
	}

	public function test_filtra_por_estado(): void {
		$this->as_translator();

		$this->string( 'Hola', 'Hello', Status::Automatic );
		$this->string( 'Adiós' );
		$this->string( 'Gracias', 'Thanks', Status::Manual );

		$pending = $this->request(
			'GET',
			'/pgai/v1/manager',
			array(
				'language' => 'en_US',
				'status'   => 'pending',
			)
		);

		$this->assertSame( 1, $pending->get_data()['total'] );
		$this->assertSame( 'Adiós', $pending->get_data()['items'][0]['original'] );

		$manual = $this->request(
			'GET',
			'/pgai/v1/manager',
			array(
				'language' => 'en_US',
				'status'   => 'manual',
			)
		);

		$this->assertSame( 1, $manual->get_data()['total'] );
	}

	public function test_filtra_por_tipo(): void {
		$this->as_translator();

		$this->string( 'Hola', 'Hello', Status::Automatic );
		$this->string( 'Guardar', 'Save', Status::Automatic, StringType::Gettext );

		$response = $this->request(
			'GET',
			'/pgai/v1/manager',
			array(
				'language' => 'en_US',
				'type'     => 'gettext',
			)
		);

		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( 'Guardar', $response->get_data()['items'][0]['original'] );
	}

	public function test_busca_en_el_original_y_en_la_traduccion(): void {
		$this->as_translator();

		$this->string( 'Añadir al carrito', 'Add to cart', Status::Automatic );
		$this->string( 'Finalizar compra', 'Checkout', Status::Automatic );

		$this->assertSame(
			1,
			$this->request(
				'GET',
				'/pgai/v1/manager',
				array(
					'language' => 'en_US',
					'search'   => 'carrito',
				)
			)->get_data()['total']
		);

		$this->assertSame(
			1,
			$this->request(
				'GET',
				'/pgai/v1/manager',
				array(
					'language' => 'en_US',
					'search'   => 'Checkout',
				)
			)->get_data()['total']
		);
	}

	public function test_pagina(): void {
		$this->as_translator();

		for ( $index = 0; $index < 5; $index++ ) {
			$this->string( 'Cadena ' . $index );
		}

		$response = $this->request(
			'GET',
			'/pgai/v1/manager',
			array(
				'language' => 'en_US',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$data = $response->get_data();

		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 5, $data['total'] );
		$this->assertSame( 3, $data['pages'] );
	}

	public function test_dar_por_revisada_solo_toca_lo_automatico(): void {
		$this->as_translator();

		$automatic = $this->string( 'Hola', 'Hello', Status::Automatic );
		$manual    = $this->string( 'Gracias', 'Thanks', Status::Manual );
		$pending   = $this->string( 'Adiós' );

		$response = $this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'review',
				'source_ids' => array( $automatic, $manual, $pending ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['affected'] );

		$this->assertSame( Status::Reviewed, $this->translations->status_of( $automatic, 'en_US' ) );
		$this->assertSame( Status::Manual, $this->translations->status_of( $manual, 'en_US' ), 'Lo manual no baja a revisada.' );
	}

	public function test_quien_no_puede_revisar_no_revisa(): void {
		$this->as_translator( false );

		$id = $this->string( 'Hola', 'Hello', Status::Automatic );

		$response = $this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'review',
				'source_ids' => array( $id ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_retraducir_deja_las_cadenas_pendientes(): void {
		$this->as_translator();

		$id = $this->string( 'Hola', 'Hello', Status::Automatic );

		$this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'retranslate',
				'source_ids' => array( $id ),
			)
		);

		$this->assertSame( Status::Pending, $this->translations->status_of( $id, 'en_US' ) );
	}

	public function test_retraducir_no_toca_lo_que_ha_escrito_una_persona(): void {
		$this->as_translator();

		$manual   = $this->string( 'Gracias', 'Thanks', Status::Manual );
		$reviewed = $this->string( 'Hola', 'Hello', Status::Reviewed );

		$response = $this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'retranslate',
				'source_ids' => array( $manual, $reviewed ),
			)
		);

		$this->assertSame( 0, $response->get_data()['affected'] );
		$this->assertSame( Status::Manual, $this->translations->status_of( $manual, 'en_US' ) );
		$this->assertSame( Status::Reviewed, $this->translations->status_of( $reviewed, 'en_US' ) );
	}

	public function test_retraducir_recupera_tambien_lo_que_quedo_en_error(): void {
		$this->as_translator();

		$id = $this->string( 'Hola', '', Status::Error );

		$this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'retranslate',
				'source_ids' => array( $id ),
			)
		);

		$this->assertSame( Status::Pending, $this->translations->status_of( $id, 'en_US' ) );
	}

	public function test_borrar_una_traduccion_no_borra_el_original(): void {
		$this->as_translator();

		$id = $this->string( 'Hola', 'Hello', Status::Automatic );

		$this->request(
			'POST',
			'/pgai/v1/manager/bulk',
			array(
				'language'   => 'en_US',
				'action'     => 'delete',
				'source_ids' => array( $id ),
			)
		);

		$this->assertNull( $this->translations->status_of( $id, 'en_US' ) );

		// El original vuelve a aparecer en el barrido: perderlo solo
		// conseguiría que hubiera que redescubrirlo.
		$response = $this->request( 'GET', '/pgai/v1/manager', array( 'language' => 'en_US' ) );

		$this->assertSame( 1, $response->get_data()['total'] );
	}

	public function test_un_idioma_no_asignado_se_rechaza(): void {
		$user_id = $this->as_translator();

		( new TranslatorLanguages( $this->languages ) )->assign( $user_id, array( 'ca' ) );

		$this->assertSame(
			403,
			$this->request( 'GET', '/pgai/v1/manager', array( 'language' => 'en_US' ) )->get_status()
		);

		$this->assertSame(
			200,
			$this->request( 'GET', '/pgai/v1/manager', array( 'language' => 'ca' ) )->get_status()
		);
	}
}
