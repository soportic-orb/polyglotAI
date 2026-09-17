<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SlugRecord;
use PolyglotAI\Database\SlugRepository;
use PolyglotAI\Jobs\Budget;
use PolyglotAI\Jobs\ContextFactory;
use PolyglotAI\Jobs\SlugTranslator;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;
use PolyglotAI\Tests\Doubles\FakeEngine;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Jobs\SlugTranslator
 */
final class SlugTranslatorTest extends WP_UnitTestCase {

	private SlugRepository $slugs;
	private FakeEngine $engine;
	private SlugTranslator $translator;

	/**
	 * Tablas limpias y un traductor con motor de mentira.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( array( 'slugs', 'api_log' ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$options = new Options();
		$log     = new ApiLogRepository();

		$this->slugs      = new SlugRepository( new StatusPrecedence() );
		$this->engine     = new FakeEngine();
		$this->translator = new SlugTranslator(
			$this->engine,
			$this->slugs,
			$log,
			$languages,
			$options,
			new Budget( $log, $options ),
			new ContextFactory( $languages, $options )
		);
	}

	/**
	 * Anota un slug pendiente.
	 *
	 * @param string $original Slug original.
	 * @param int    $id       Identificador.
	 */
	private function pending( string $original = 'mi-primera-entrada', int $id = 7 ): void {
		$this->slugs->track( 'post', 'post', $id, 'en_US', $original );
	}

	public function test_manda_el_slug_como_frase_y_guarda_el_resultado_como_slug(): void {
		$this->pending();

		$this->engine->dictionary = array( 'mi primera entrada' => 'My First Post' );

		$this->assertSame( 1, $this->translator->run( 'en_US' ) );

		// Pedirle a un modelo que traduzca «mi-primera-entrada» es pedirle que
		// adivine: se le manda la frase y se normaliza la respuesta.
		$this->assertSame( array( 'mi primera entrada' ), $this->engine->received );

		$record = $this->slugs->find( 'post', 'post', 7, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( 'my-first-post', $record->translated_slug );
		$this->assertSame( Status::Automatic, $record->status );
	}

	public function test_no_pisa_un_slug_escrito_a_mano(): void {
		$this->slugs->save(
			new SlugRecord( 'post', 'post', 7, 'en_US', 'mi-primera-entrada', 'my-post', Status::Manual )
		);

		$this->engine->dictionary = array( 'mi primera entrada' => 'Otro' );

		$this->translator->run( 'en_US' );

		$record = $this->slugs->find( 'post', 'post', 7, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( 'my-post', $record->translated_slug );
	}

	public function test_una_traduccion_sin_nada_utilizable_no_se_guarda(): void {
		$this->pending();

		// Mejor seguir sirviendo el slug original que uno vacío.
		$this->engine->dictionary = array( 'mi primera entrada' => '!!!' );

		$this->assertSame( 0, $this->translator->run( 'en_US' ) );

		$record = $this->slugs->find( 'post', 'post', 7, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( '', $record->translated_slug );
		$this->assertSame( Status::Pending, $record->status );
	}

	public function test_un_error_del_motor_no_toca_nada(): void {
		$this->pending();

		$this->engine->fail_with = 'La API no responde';

		$this->assertSame( 0, $this->translator->run( 'en_US' ) );

		$record = $this->slugs->find( 'post', 'post', 7, 'en_US' );

		$this->assertNotNull( $record );
		$this->assertSame( Status::Pending, $record->status, 'Sigue pendiente para el siguiente intento.' );
	}

	public function test_un_idioma_que_no_existe_no_llama_al_motor(): void {
		$this->pending();

		$this->assertSame( 0, $this->translator->run( 'zz_ZZ' ) );
		$this->assertSame( array(), $this->engine->received );
	}

	public function test_sin_nada_pendiente_no_llama_al_motor(): void {
		$this->assertSame( 0, $this->translator->run( 'en_US' ) );
		$this->assertSame( array(), $this->engine->received );
	}

	public function test_desambigua_dos_slugs_que_se_traducen_igual(): void {
		$this->pending( 'contacto', 7 );
		$this->pending( 'contactar', 9 );

		$this->engine->dictionary = array(
			'contacto'  => 'Contact',
			'contactar' => 'Contact',
		);

		$this->assertSame( 2, $this->translator->run( 'en_US' ) );

		$first  = $this->slugs->find( 'post', 'post', 7, 'en_US' );
		$second = $this->slugs->find( 'post', 'post', 9, 'en_US' );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertNotSame(
			$first->translated_slug,
			$second->translated_slug,
			'Dos URLs iguales harían ambiguo el enrutado inverso.'
		);
	}

	public function test_anota_el_consumo(): void {
		$this->pending();

		$this->engine->dictionary = array( 'mi primera entrada' => 'My First Post' );

		$this->translator->run( 'en_US' );

		global $wpdb;
		$table = Schema::table( 'api_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		$this->assertSame( 1, $rows );
	}
}
