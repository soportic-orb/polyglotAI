<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PolyglotAI\Engines\Claude\Batches;
use PolyglotAI\Engines\Claude\ClaudeClient;
use PolyglotAI\Engines\Claude\RetryPolicy;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Tests\Doubles\FakeApiKey;
use PolyglotAI\Tests\Doubles\FakeHttp;

/**
 * La capa HTTP no tenía ninguna prueba pese a ser por donde pasa todo el gasto.
 *
 * @covers \PolyglotAI\Engines\Claude\ClaudeClient
 * @covers \PolyglotAI\Engines\Claude\Batches
 */
final class ClaudeClientTest extends TestCase {

	protected function setUp(): void {
		FakeHttp::reset();
	}

	/**
	 * Cliente con reintentos inmediatos.
	 *
	 * @param string|null $key Clave.
	 */
	private function client( ?string $key = 'sk-ant-test' ): ClaudeClient {
		return new ClaudeClient( new FakeApiKey( $key ), new RetryPolicy( 5, 0 ), 5 );
	}

	public function test_manda_las_cabeceras_que_exige_la_api(): void {
		FakeHttp::push( array( 'ok' => true ) );

		$this->client()->post( '/v1/messages', array( 'model' => 'claude-sonnet-5' ) );

		$call = FakeHttp::call();

		$this->assertSame( 'https://api.anthropic.com/v1/messages', $call['url'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( 'sk-ant-test', $call['args']['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $call['args']['headers']['anthropic-version'] );
		$this->assertSame( 'application/json', $call['args']['headers']['content-type'] );
	}

	public function test_un_get_no_manda_cuerpo(): void {
		FakeHttp::push( array( 'ok' => true ) );

		$this->client()->get( '/v1/messages/batches/msgbatch_1' );

		$call = FakeHttp::call();

		$this->assertSame( 'GET', $call['args']['method'] );
		$this->assertArrayNotHasKey( 'body', $call['args'] );
	}

	public function test_sin_clave_no_sale_ninguna_peticion(): void {
		$this->expectException( EngineException::class );

		try {
			$this->client( null )->post( '/v1/messages', array() );
		} finally {
			$this->assertSame( 0, FakeHttp::count(), 'Ni siquiera se ha intentado llamar.' );
		}
	}

	public function test_reintenta_ante_un_429(): void {
		FakeHttp::push( array( 'error' => array( 'message' => 'rate limited' ) ), 429 );
		FakeHttp::push( array( 'ok' => true ) );

		$response = $this->client()->post( '/v1/messages', array() );

		$this->assertSame( array( 'ok' => true ), $response );
		$this->assertSame( 2, FakeHttp::count() );
	}

	public function test_reintenta_ante_un_fallo_de_red(): void {
		FakeHttp::push_error();
		FakeHttp::push( array( 'ok' => true ) );

		$this->assertSame( array( 'ok' => true ), $this->client()->post( '/v1/messages', array() ) );
		$this->assertSame( 2, FakeHttp::count() );
	}

	public function test_no_reintenta_ante_un_400(): void {
		// Repetir una petición mal formada la deja igual de mal formada.
		FakeHttp::push( array( 'error' => array( 'message' => 'bad request' ) ), 400 );

		$this->expectException( EngineException::class );

		try {
			$this->client()->post( '/v1/messages', array() );
		} finally {
			$this->assertSame( 1, FakeHttp::count() );
		}
	}

	public function test_un_cuerpo_que_no_es_json_es_un_error(): void {
		FakeHttp::push( '<html>502 Bad Gateway</html>' );

		$this->expectException( EngineException::class );

		$this->client()->post( '/v1/messages', array() );
	}

	public function test_el_mensaje_de_error_de_la_api_llega_al_usuario(): void {
		FakeHttp::push( array( 'error' => array( 'message' => 'credit balance is too low' ) ), 400 );

		$this->expectExceptionMessageMatches( '/credit balance is too low/' );

		$this->client()->post( '/v1/messages', array() );
	}

	public function test_crea_un_lote_y_devuelve_su_identificador(): void {
		FakeHttp::push( array( 'id' => 'msgbatch_123' ) );

		$batches = new Batches( $this->client() );

		$id = $batches->create(
			array(
				array(
					'custom_id' => 'chunk-1',
					'params'    => array( 'model' => 'claude-sonnet-5' ),
				),
			)
		);

		$this->assertSame( 'msgbatch_123', $id );
		$this->assertSame( 'https://api.anthropic.com/v1/messages/batches', FakeHttp::call()['url'] );
	}

	public function test_un_lote_vacio_no_se_envia(): void {
		$this->expectException( EngineException::class );

		try {
			( new Batches( $this->client() ) )->create( array() );
		} finally {
			$this->assertSame( 0, FakeHttp::count() );
		}
	}

	public function test_lee_el_estado_de_un_lote(): void {
		FakeHttp::push(
			array(
				'processing_status' => 'ended',
				'results_url'       => 'https://api.anthropic.com/v1/messages/batches/msgbatch_1/results',
				'request_counts'    => array(
					'succeeded' => 3,
					'errored'   => 1,
				),
			)
		);

		$status = ( new Batches( $this->client() ) )->status( 'msgbatch_1' );

		$this->assertSame( 'ended', $status['status'] );
		$this->assertSame( 3, $status['counts']['succeeded'] );
		$this->assertNotNull( $status['results_url'] );
	}

	public function test_un_lote_en_curso_no_tiene_url_de_resultados(): void {
		FakeHttp::push( array( 'processing_status' => 'in_progress' ) );

		$status = ( new Batches( $this->client() ) )->status( 'msgbatch_1' );

		$this->assertSame( 'in_progress', $status['status'] );
		$this->assertNull( $status['results_url'] );
	}

	public function test_los_resultados_se_indexan_por_custom_id(): void {
		// La API no promete orden: indexar por posición sería cruzar las
		// traducciones de unas cadenas con las de otras.
		FakeHttp::push(
			implode(
				"\n",
				array(
					'{"custom_id":"chunk-2","result":{"type":"succeeded"}}',
					'{"custom_id":"chunk-1","result":{"type":"succeeded"}}',
				)
			)
		);

		$results = ( new Batches( $this->client() ) )->results( 'https://example.org/results' );

		$this->assertSame( array( 'chunk-2', 'chunk-1' ), array_keys( $results ) );
		$this->assertSame( 'succeeded', $results['chunk-1']['result']['type'] );
	}

	public function test_una_linea_ilegible_no_tumba_el_lote(): void {
		FakeHttp::push(
			implode(
				"\n",
				array(
					'{"custom_id":"chunk-1","result":{"type":"succeeded"}}',
					'esto no es json',
					'',
					'{"sin":"custom_id"}',
					'{"custom_id":"chunk-3","result":{"type":"succeeded"}}',
				)
			)
		);

		$results = ( new Batches( $this->client() ) )->results( 'https://example.org/results' );

		// El resto de las traducciones son buenas y ya están pagadas.
		$this->assertSame( array( 'chunk-1', 'chunk-3' ), array_keys( $results ) );
	}
}
