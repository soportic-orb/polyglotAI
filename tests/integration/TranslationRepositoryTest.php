<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\Schema;
use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;
use PolyglotAI\Translation\StringType;
use WP_UnitTestCase;

/**
 * Comprueba contra una base de datos real el criterio de aceptación más
 * importante del encargo: las correcciones manuales no se sobrescriben nunca
 * por la traducción automática.
 *
 * @covers \PolyglotAI\Database\TranslationRepository
 */
final class TranslationRepositoryTest extends WP_UnitTestCase {

	private TranslationRepository $repository;
	private SourceRepository $sources;

	/**
	 * Vacía las tablas del plugin antes de cada test.
	 *
	 * El esquema ya existe: lo crea el arranque de la suite. Aquí solo se
	 * borran filas, que es una operación transaccional y por tanto compatible
	 * con el aislamiento entre tests de WP_UnitTestCase.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( Schema::names() as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$table}`" );
		}

		$this->repository = new TranslationRepository( new StatusPrecedence() );
		$this->sources    = new SourceRepository();
	}

	/**
	 * Registra una cadena original y devuelve su identificador.
	 *
	 * @param string $text Texto.
	 */
	private function source( string $text = 'Añadir al carrito' ): int {
		return $this->sources->remember( md5( $text ), $text, StringType::Text );
	}

	public function test_guarda_y_recupera_una_traduccion(): void {
		$id = $this->source();

		$this->assertTrue( $this->repository->save( $id, 'en_US', 'Add to cart', Status::Automatic ) );
		$this->assertSame(
			array( md5( 'Añadir al carrito' ) => 'Add to cart' ),
			$this->repository->lookup( array( md5( 'Añadir al carrito' ) ), 'en_US' )
		);
	}

	public function test_la_traduccion_automatica_no_pisa_una_correccion_manual(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to basket', Status::Manual );

		$this->assertFalse(
			$this->repository->save( $id, 'en_US', 'Add to cart', Status::Automatic ),
			'Una escritura automática sobre una traducción manual debe rechazarse.'
		);

		$this->assertSame(
			'Add to basket',
			$this->repository->lookup( array( md5( 'Añadir al carrito' ) ), 'en_US' )[ md5( 'Añadir al carrito' ) ]
		);
	}

	public function test_ni_siquiera_forzando_la_retraduccion(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to basket', Status::Reviewed );

		$this->assertFalse( $this->repository->save( $id, 'en_US', 'Add to cart', Status::Automatic, true ) );
		$this->assertSame( Status::Reviewed, $this->repository->status_of( $id, 'en_US' ) );
	}

	public function test_una_persona_si_puede_corregir_cualquier_cosa(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to cart', Status::Automatic );

		$this->assertTrue( $this->repository->save( $id, 'en_US', 'Add to basket', Status::Manual ) );
		$this->assertSame( Status::Manual, $this->repository->status_of( $id, 'en_US' ) );
	}

	public function test_retraducir_solo_pisa_lo_automatico_si_se_pide(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Primera', Status::Automatic );

		$this->assertFalse( $this->repository->save( $id, 'en_US', 'Segunda', Status::Automatic ) );
		$this->assertTrue( $this->repository->save( $id, 'en_US', 'Segunda', Status::Automatic, true ) );
	}

	public function test_cada_idioma_es_independiente(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to cart', Status::Manual );
		$this->repository->save( $id, 'ca', 'Afegir al carro', Status::Automatic );

		$this->assertSame( Status::Manual, $this->repository->status_of( $id, 'en_US' ) );
		$this->assertSame( Status::Automatic, $this->repository->status_of( $id, 'ca' ) );
	}

	public function test_lo_pendiente_y_lo_erroneo_no_se_sirve(): void {
		$hash = md5( 'Añadir al carrito' );
		$id   = $this->source();

		$this->repository->mark_pending( array( $id ), 'en_US' );
		$this->assertSame( array(), $this->repository->lookup( array( $hash ), 'en_US' ) );

		$this->repository->mark_error( $id, 'en_US', 'tag_count_mismatch' );
		$this->assertSame( array(), $this->repository->lookup( array( $hash ), 'en_US' ) );
	}

	public function test_marcar_como_pendiente_no_degrada_lo_ya_traducido(): void {
		// Es lo que hace la traducción en segundo plano en cada visita: no puede
		// borrar el trabajo hecho.
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to cart', Status::Manual );
		$this->repository->mark_pending( array( $id ), 'en_US' );

		$this->assertSame( Status::Manual, $this->repository->status_of( $id, 'en_US' ) );
	}

	public function test_un_fallo_del_motor_no_degrada_una_traduccion_humana(): void {
		$id = $this->source();

		$this->repository->save( $id, 'en_US', 'Add to cart', Status::Reviewed );
		$this->repository->mark_error( $id, 'en_US', 'placeholders_mismatch' );

		$this->assertSame( Status::Reviewed, $this->repository->status_of( $id, 'en_US' ) );
	}

	public function test_lista_lo_pendiente_de_un_idioma(): void {
		$primero = $this->source( 'Uno' );
		$segundo = $this->source( 'Dos' );

		$this->repository->mark_pending( array( $primero, $segundo ), 'en_US' );
		$this->repository->save( $primero, 'en_US', 'One', Status::Automatic, true );

		$pending = $this->repository->pending( 'en_US' );

		$this->assertCount( 1, $pending );
		$this->assertSame( 'Dos', $pending[0]['original'] );
	}

	public function test_cuenta_las_cadenas_por_estado(): void {
		$this->repository->save( $this->source( 'Uno' ), 'en_US', 'One', Status::Automatic );
		$this->repository->save( $this->source( 'Dos' ), 'en_US', 'Two', Status::Manual );
		$this->repository->save( $this->source( 'Tres' ), 'en_US', 'Three', Status::Manual );

		$counts = $this->repository->counts( 'en_US' );

		$this->assertSame( 1, $counts['automatic'] );
		$this->assertSame( 2, $counts['manual'] );
	}

	public function test_la_busqueda_masiva_devuelve_solo_lo_pedido(): void {
		$uno = $this->source( 'Uno' );
		$dos = $this->source( 'Dos' );

		$this->repository->save( $uno, 'en_US', 'One', Status::Automatic );
		$this->repository->save( $dos, 'en_US', 'Two', Status::Automatic );

		$found = $this->repository->lookup( array( md5( 'Uno' ) ), 'en_US' );

		$this->assertCount( 1, $found );
		$this->assertArrayHasKey( md5( 'Uno' ), $found );
	}
}
