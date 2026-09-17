<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Integration;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Database\Schema;
use PolyglotAI\Engines\Usage;
use WP_UnitTestCase;

/**
 * @covers \PolyglotAI\Database\ApiLogRepository
 */
final class ApiLogStatsTest extends WP_UnitTestCase {

	private ApiLogRepository $log;

	/**
	 * Registro limpio.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$table = Schema::table( 'api_log' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->log = new ApiLogRepository();
	}

	public function test_agrupa_el_consumo_por_mes(): void {
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 10, new Usage( 100, 50, 20, 30 ) );
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 5, new Usage( 40, 20, 10, 0 ) );

		$months = $this->log->monthly();

		$this->assertCount( 1, $months );
		$this->assertSame( gmdate( 'Y-m' ), $months[0]['month'] );
		$this->assertSame( 2, $months[0]['calls'] );
		$this->assertSame( 15, $months[0]['strings'] );
		$this->assertSame( 140, $months[0]['input'] );
		$this->assertSame( 70, $months[0]['output'] );

		// La lectura de caché va aparte de los tokens de entrada: cuesta una
		// fracción de su precio y sumarla sin distinguir daría un consumo que
		// no se parece a la factura.
		$this->assertSame( 30, $months[0]['cache_read'] );
		$this->assertSame( 30, $months[0]['cache_creation'] );
	}

	public function test_cuenta_los_errores_aparte(): void {
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 10, new Usage( 100, 50 ) );
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 0, new Usage(), 'error', 'La API no responde' );

		$months = $this->log->monthly();

		$this->assertSame( 2, $months[0]['calls'] );
		$this->assertSame( 1, $months[0]['errors'] );
	}

	public function test_devuelve_los_ultimos_errores(): void {
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 10, new Usage( 100, 50 ) );
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'ca', 0, new Usage(), 'error', 'Tiempo agotado' );
		$this->log->record( 'anthropic', 'claude-sonnet-5', 'en_US', 0, new Usage(), 'error', 'Clave rechazada' );

		$errors = $this->log->recent_errors();

		$this->assertCount( 2, $errors );

		// El más reciente primero, que es el que interesa cuando algo falla.
		$this->assertSame( 'Clave rechazada', $errors[0]['error'] );
		$this->assertSame( 'Tiempo agotado', $errors[1]['error'] );
	}

	public function test_sin_llamadas_no_hay_nada_que_contar(): void {
		$this->assertSame( array(), $this->log->monthly() );
		$this->assertSame( array(), $this->log->recent_errors() );
	}
}
