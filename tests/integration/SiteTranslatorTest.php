<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Jobs\Budget;
use PolyglotAI\Jobs\ContextFactory;
use PolyglotAI\Jobs\SiteRun;
use PolyglotAI\Jobs\SiteTranslator;
use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Support\Options;
use PolyglotAI\Tests\Doubles\FakeAsyncEngine;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Jobs\SiteTranslator
 * @covers \PolyglotAI\Jobs\SiteRun
 */
final class SiteTranslatorTest extends WP_UnitTestCase {

	private SourceRepository $sources;
	private TranslationRepository $translations;
	private FakeAsyncEngine $engine;
	private SiteTranslator $translator;

	/**
	 * Tablas limpias y un traductor con motor de mentira.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( array( 'translations', 'sources', 'api_log' ) as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		SiteRun::forget( 'en_US' );
		delete_option( Options::MAIN );

		$languages = new LanguageRegistry(
			new Language( 'es_ES', 'es', 'Español' ),
			array( new Language( 'en_US', 'en', 'English' ) )
		);

		$options = new Options();
		$log     = new ApiLogRepository();

		$this->sources      = new SourceRepository();
		$this->translations = new TranslationRepository( new StatusPrecedence() );
		$this->engine       = new FakeAsyncEngine();

		$this->translator = new SiteTranslator(
			$this->engine,
			$this->translations,
			$this->sources,
			$log,
			$languages,
			$options,
			new Budget( $log, $options ),
			new ContextFactory( $languages, $options )
		);
	}

	/**
	 * Registra una cadena pendiente.
	 *
	 * @param string $text Original.
	 */
	private function pending( string $text ): int {
		$id = $this->sources->remember( md5( $text ), $text, StringType::Text );

		$this->translations->mark_pending( array( $id ), 'en_US' );

		return $id;
	}

	public function test_sin_nada_pendiente_no_arranca(): void {
		$this->assertNull( $this->translator->start( 'en_US' ) );
		$this->assertSame( 0, $this->engine->created );
	}

	public function test_el_idioma_por_defecto_no_se_traduce(): void {
		$this->pending( 'Hola' );

		$this->assertNull( $this->translator->start( 'es_ES' ) );
	}

	public function test_arranca_con_lo_pendiente_contado(): void {
		$this->pending( 'Hola' );
		$this->pending( 'Adiós' );

		$run = $this->translator->start( 'en_US' );

		$this->assertNotNull( $run );
		$this->assertSame( SiteRun::QUEUED, $run->status );
		$this->assertSame( 2, $run->total );
		$this->assertSame( 0, $run->progress() );
	}

	public function test_una_pasada_envia_el_lote_y_se_queda_esperando(): void {
		$this->pending( 'Hola' );
		$this->pending( 'Adiós' );
		$this->pending( 'Gracias' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );

		$this->assertNotNull( $run );
		$this->assertSame( SiteRun::WAITING, $run->status );
		$this->assertNotNull( $run->batch_id );

		// El motor de mentira parte en trozos de dos: tres cadenas son dos.
		$this->assertCount( 2, $this->engine->sent[ $run->batch_id ] );
	}

	public function test_mientras_el_lote_no_termina_no_se_toca_nada(): void {
		$id = $this->pending( 'Hola' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );

		$this->assertNotNull( $run );
		$this->assertSame( SiteRun::WAITING, $run->status );
		$this->assertSame( Status::Pending, $this->translations->status_of( $id, 'en_US' ) );
	}

	public function test_al_terminar_el_lote_se_guardan_las_traducciones(): void {
		$hola  = $this->pending( 'Hola' );
		$adios = $this->pending( 'Adiós' );

		$this->engine->dictionary = array(
			'Hola'  => 'Hello',
			'Adiós' => 'Goodbye',
		);

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->batch_id );

		$this->engine->finish( $run->batch_id );
		$this->translator->run( 'en_US' );

		$this->assertSame( Status::Automatic, $this->translations->status_of( $hola, 'en_US' ) );
		$this->assertSame( Status::Automatic, $this->translations->status_of( $adios, 'en_US' ) );

		$after = $this->translator->status( 'en_US' );
		$this->assertNotNull( $after );
		$this->assertSame( 2, $after->done );
	}

	public function test_lo_que_falla_la_validacion_queda_en_error(): void {
		$hola = $this->pending( 'Hola' );
		$roto = $this->pending( 'Con <b>etiqueta' );

		$this->engine->dictionary = array( 'Hola' => 'Hello' );
		$this->engine->failing    = array( 'Con <b>etiqueta' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->batch_id );

		$this->engine->finish( $run->batch_id );
		$this->translator->run( 'en_US' );

		$this->assertSame( Status::Automatic, $this->translations->status_of( $hola, 'en_US' ) );
		$this->assertSame( Status::Error, $this->translations->status_of( $roto, 'en_US' ) );

		$after = $this->translator->status( 'en_US' );
		$this->assertNotNull( $after );
		$this->assertSame( 1, $after->failed );
	}

	public function test_la_pasada_termina_cuando_no_queda_nada(): void {
		$this->pending( 'Hola' );

		$this->engine->dictionary = array( 'Hola' => 'Hello' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->batch_id );

		$this->engine->finish( $run->batch_id );
		$this->translator->run( 'en_US' );

		// La vuelta siguiente no encuentra nada pendiente y da por terminado.
		$this->translator->run( 'en_US' );

		$done = $this->translator->status( 'en_US' );

		$this->assertNotNull( $done );
		$this->assertSame( SiteRun::DONE, $done->status );
		$this->assertFalse( $done->is_active() );
		$this->assertSame( 100, $done->progress() );
	}

	public function test_pausar_detiene_el_trabajo_sin_cancelar_el_lote(): void {
		$this->pending( 'Hola' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$paused = $this->translator->pause( 'en_US' );

		$this->assertNotNull( $paused );
		$this->assertSame( SiteRun::PAUSED, $paused->status );
		$this->assertSame( array(), $this->engine->cancelled, 'Lo ya enviado se cobra igual: no se cancela.' );

		// Una pasada estando en pausa no hace nada.
		$this->translator->run( 'en_US' );

		$still = $this->translator->status( 'en_US' );
		$this->assertNotNull( $still );
		$this->assertSame( SiteRun::PAUSED, $still->status );
	}

	public function test_reanudar_vuelve_a_esperar_por_el_mismo_lote(): void {
		$id = $this->pending( 'Hola' );

		$this->engine->dictionary = array( 'Hola' => 'Hello' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );
		$this->assertNotNull( $run );
		$batch_id = (string) $run->batch_id;

		$this->translator->pause( 'en_US' );
		$resumed = $this->translator->resume( 'en_US' );

		$this->assertNotNull( $resumed );
		$this->assertSame( SiteRun::WAITING, $resumed->status );
		$this->assertSame( $batch_id, $resumed->batch_id, 'El lote sigue siendo el mismo: no se reenvía ni se paga dos veces.' );

		$this->engine->finish( $batch_id );
		$this->translator->run( 'en_US' );

		$this->assertSame( Status::Automatic, $this->translations->status_of( $id, 'en_US' ) );
		$this->assertSame( 1, $this->engine->created, 'No se ha creado ningún lote de más.' );
	}

	public function test_cancelar_cancela_el_lote_y_olvida_la_pasada(): void {
		$this->pending( 'Hola' );

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );
		$this->assertNotNull( $run );

		$this->translator->cancel( 'en_US' );

		$this->assertSame( array( $run->batch_id ), $this->engine->cancelled );
		$this->assertNull( $this->translator->status( 'en_US' ) );
	}

	public function test_un_fallo_del_motor_deja_la_pasada_en_error(): void {
		$this->pending( 'Hola' );

		$this->engine->fail_on_create = 'La clave no vale';

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );

		$this->assertNotNull( $run );
		$this->assertSame( SiteRun::FAILED, $run->status );
		$this->assertSame( 'La clave no vale', $run->message );

		// Y queda anotado en el registro de consumo, con su motivo.
		global $wpdb;
		$table = Schema::table( 'api_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status <> 'ok'" );

		$this->assertSame( 1, $errors );
	}

	public function test_el_tope_de_presupuesto_para_el_trabajo(): void {
		$this->pending( 'Hola' );

		( new Options() )->update( array( 'monthly_token_limit' => 1 ) );

		( new ApiLogRepository() )->record(
			'fake-async',
			'modelo',
			'en_US',
			1,
			new \PolyglotAI\Engines\Usage( 100, 50 )
		);

		$this->translator->start( 'en_US' );
		$this->translator->run( 'en_US' );

		$run = $this->translator->status( 'en_US' );

		$this->assertNotNull( $run );
		$this->assertSame( SiteRun::FAILED, $run->status );
		$this->assertSame( 0, $this->engine->created, 'No se ha llamado a la API.' );
	}

	public function test_estima_lo_que_costaria_traducir_lo_pendiente(): void {
		$this->pending( 'Hola' );
		$this->pending( 'Adiós' );
		$this->pending( 'Gracias' );

		$estimate = $this->translator->estimate( 'en_US' );

		$this->assertSame( 3, $estimate['strings'] );

		// El motor de mentira cobra diez por cadena y parte en trozos de dos:
		// una muestra de dos cadenas son veinte tokens, y hacen falta dos
		// trozos para tres cadenas.
		$this->assertSame( 40, $estimate['input_tokens'] );
	}

	public function test_sin_nada_pendiente_la_estimacion_es_cero(): void {
		$this->assertSame(
			array(
				'strings'      => 0,
				'input_tokens' => 0,
			),
			$this->translator->estimate( 'en_US' )
		);
	}

	public function test_no_pisa_lo_que_ha_escrito_una_persona(): void {
		$id = $this->pending( 'Hola' );

		$this->translations->save( $id, 'en_US', 'Hello, corregido', Status::Manual );

		$this->engine->dictionary = array( 'Hola' => 'Hello' );

		// Ya no está pendiente, así que no entra en el lote.
		$this->assertNull( $this->translator->start( 'en_US' ) );
	}
}
