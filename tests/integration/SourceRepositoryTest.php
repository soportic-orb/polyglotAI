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
 * @covers \PolyglotAI\Database\SourceRepository
 */
final class SourceRepositoryTest extends WP_UnitTestCase {

	private SourceRepository $repository;

	/**
	 * Prepara las tablas.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Schema() )->install( true );

		$this->repository = new SourceRepository();
	}

	public function test_registra_una_cadena_y_devuelve_su_identificador(): void {
		$id = $this->repository->remember( md5( 'Hola' ), 'Hola', StringType::Text );

		$this->assertGreaterThan( 0, $id );
	}

	public function test_la_misma_cadena_no_se_duplica(): void {
		// Dos peticiones simultáneas pueden descubrir la misma cadena a la vez.
		$primero = $this->repository->remember( md5( 'Hola' ), 'Hola', StringType::Text );
		$segundo = $this->repository->remember( md5( 'Hola' ), 'Hola', StringType::Text );

		$this->assertSame( $primero, $segundo );
	}

	public function test_recupera_identificadores_por_hash(): void {
		$uno = $this->repository->remember( md5( 'Uno' ), 'Uno', StringType::Text );
		$dos = $this->repository->remember( md5( 'Dos' ), 'Dos', StringType::Text );

		$map = $this->repository->ids_by_hash( array( md5( 'Uno' ), md5( 'Dos' ), md5( 'Inexistente' ) ) );

		$this->assertSame( $uno, $map[ md5( 'Uno' ) ] );
		$this->assertSame( $dos, $map[ md5( 'Dos' ) ] );
		$this->assertArrayNotHasKey( md5( 'Inexistente' ), $map );
	}

	public function test_una_lista_vacia_no_consulta_la_base_de_datos(): void {
		$this->assertSame( array(), $this->repository->ids_by_hash( array() ) );
	}

	public function test_guarda_el_tipo_el_contexto_y_el_dominio(): void {
		global $wpdb;

		$id    = $this->repository->remember( md5( 'Cerrar' ), 'Cerrar', StringType::Gettext, 'boton', 'woocommerce' );
		$table = Schema::table( 'sources' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT type, context, domain FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		$this->assertSame( 'gettext', $row['type'] );
		$this->assertSame( 'boton', $row['context'] );
		$this->assertSame( 'woocommerce', $row['domain'] );
	}

	public function test_limpiar_huerfanas_conserva_lo_traducido_a_mano(): void {
		// Una cadena que ya no aparece en el sitio pero que alguien tradujo a
		// mano no se borra: puede volver a aparecer y ese trabajo no se tira.
		$translations = new TranslationRepository( new StatusPrecedence() );

		$abandonada = $this->repository->remember( md5( 'Abandonada' ), 'Abandonada', StringType::Text );
		$corregida  = $this->repository->remember( md5( 'Corregida' ), 'Corregida', StringType::Text );

		$translations->save( $corregida, 'en_US', 'Fixed by hand', Status::Manual );

		$this->antedate( array( $abandonada, $corregida ) );

		$deleted = $this->repository->delete_orphans( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$this->assertSame( 1, $deleted );
		$this->assertArrayHasKey( md5( 'Corregida' ), $this->repository->ids_by_hash( array( md5( 'Corregida' ) ) ) );
		$this->assertArrayNotHasKey( md5( 'Abandonada' ), $this->repository->ids_by_hash( array( md5( 'Abandonada' ) ) ) );
	}

	public function test_marcar_como_vistas_las_salva_de_la_limpieza(): void {
		$id = $this->repository->remember( md5( 'Viva' ), 'Viva', StringType::Text );

		$this->antedate( array( $id ) );
		$this->repository->touch( array( md5( 'Viva' ) ) );

		$deleted = $this->repository->delete_orphans( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$this->assertSame( 0, $deleted );
	}

	/**
	 * Envejece la última aparición de unas cadenas para poder probar la limpieza.
	 *
	 * @param int[] $ids Identificadores.
	 */
	private function antedate( array $ids ): void {
		global $wpdb;

		$table = Schema::table( 'sources' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_seen = %s WHERE id = %d", $old, $id ) );
		}
	}
}
