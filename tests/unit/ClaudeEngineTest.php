<?php
/**
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests;

use PolyglotAI\Engines\Claude\PromptBuilder;
use PolyglotAI\Engines\Claude\ResponseParser;
use PolyglotAI\Engines\Claude\ResponseSchema;
use PolyglotAI\Engines\Claude\RetryPolicy;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Translation\StringType;
use PolyglotAI\Translation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PolyglotAI\Engines\Claude\PromptBuilder
 * @covers \PolyglotAI\Engines\Claude\ResponseParser
 * @covers \PolyglotAI\Engines\Claude\RetryPolicy
 */
final class ClaudeEngineTest extends TestCase {

	private function contexto(): EngineContext {
		return new EngineContext(
			'es_ES',
			'en_US',
			'español',
			'inglés',
			'formal',
			'Tienda de ropa con envío a toda Europa.',
			array( 'carrito' => 'cart' ),
			array( 'ACME', 'Polyglot AI' )
		);
	}

	/**
	 * @return TranslationRequest[]
	 */
	private function lote(): array {
		return array(
			new TranslationRequest( '1', 'Añadir al carrito', StringType::Text ),
			new TranslationRequest( '2', 'Hola <b>món</b>', StringType::Block ),
			new TranslationRequest( '3', 'Un gato', StringType::Attribute, 'alt' ),
		);
	}

	public function test_la_peticion_lleva_el_modelo_y_la_salida_estructurada(): void {
		$body = ( new PromptBuilder( 'claude-sonnet-5' ) )->build( $this->lote(), $this->contexto() );

		$this->assertSame( 'claude-sonnet-5', $body['model'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( ResponseSchema::get(), $body['output_config']['format']['schema'] );
	}

	public function test_el_esquema_es_identico_entre_lotes_distintos(): void {
		// Un esquema estable aprovecha la caché de compilación de esquemas de la
		// API. Uno generado a medida en cada lote la pagaría siempre.
		$primero = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );
		$segundo = ( new PromptBuilder() )->build(
			array( new TranslationRequest( '9', 'Otra cosa', StringType::Text ) ),
			$this->contexto()
		);

		$this->assertSame(
			$primero['output_config']['format']['schema'],
			$segundo['output_config']['format']['schema']
		);
	}

	public function test_el_esquema_respeta_el_subconjunto_admitido(): void {
		$schema = ResponseSchema::get();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertFalse( $schema['properties']['translations']['items']['additionalProperties'] );
		$this->assertSame( array( 'id', 'text' ), $schema['properties']['translations']['items']['required'] );

		// La API no admite restricciones de longitud ni numéricas.
		$serialized = (string) json_encode( $schema );

		foreach ( array( 'minLength', 'maxLength', 'minimum', 'maximum', 'multipleOf', '$ref' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $serialized );
		}
	}

	public function test_el_punto_de_corte_de_cache_va_en_el_ultimo_bloque_del_sistema(): void {
		// El orden de renderizado es tools, system y messages: si el corte no
		// estuviera al final del prompt del sistema, las cadenas del lote
		// quedarían dentro del prefijo cacheado y lo invalidarían en cada lote.
		$body   = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );
		$system = $body['system'];
		$last   = array_key_last( $system );

		$this->assertSame( array( 'type' => 'ephemeral' ), $system[ $last ]['cache_control'] );

		foreach ( $system as $index => $block ) {
			if ( $index !== $last ) {
				$this->assertArrayNotHasKey( 'cache_control', $block );
			}
		}
	}

	public function test_la_ttl_larga_solo_se_pide_cuando_se_configura(): void {
		$corta = ( new PromptBuilder( 'claude-sonnet-5', 'low', false, 5 ) )->build( $this->lote(), $this->contexto() );
		$larga = ( new PromptBuilder( 'claude-sonnet-5', 'low', false, 60 ) )->build( $this->lote(), $this->contexto() );

		$this->assertArrayNotHasKey( 'ttl', $corta['system'][ array_key_last( $corta['system'] ) ]['cache_control'] );
		$this->assertSame( '1h', $larga['system'][ array_key_last( $larga['system'] ) ]['cache_control']['ttl'] );
	}

	public function test_el_prompt_del_sistema_lleva_idiomas_glosario_y_exclusiones(): void {
		$body   = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );
		$system = implode( "\n", array_column( $body['system'], 'text' ) );

		$this->assertStringContainsString( 'es_ES', $system );
		$this->assertStringContainsString( 'en_US', $system );
		$this->assertStringContainsString( 'formal', $system );
		$this->assertStringContainsString( 'carrito => cart', $system );
		$this->assertStringContainsString( 'ACME', $system );
		$this->assertStringContainsString( 'Tienda de ropa', $system );
	}

	public function test_las_cadenas_del_lote_no_estan_en_el_prompt_cacheado(): void {
		$body   = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );
		$system = implode( "\n", array_column( $body['system'], 'text' ) );

		$this->assertStringNotContainsString( 'Añadir al carrito', $system );
		$this->assertStringContainsString( 'Añadir al carrito', $body['messages'][0]['content'] );
	}

	public function test_el_razonamiento_esta_desactivado_por_defecto(): void {
		$body = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );

		$this->assertSame( array( 'type' => 'disabled' ), $body['thinking'] );
		$this->assertSame( 'low', $body['output_config']['effort'] );

		$con_razonamiento = ( new PromptBuilder( 'claude-sonnet-5', 'medium', true ) )->build( $this->lote(), $this->contexto() );

		$this->assertSame( array( 'type' => 'adaptive' ), $con_razonamiento['thinking'] );
	}

	public function test_max_tokens_crece_con_el_lote_pero_esta_acotado(): void {
		$pequeno = ( new PromptBuilder() )->build( $this->lote(), $this->contexto() );
		$grande  = ( new PromptBuilder() )->build(
			array_map(
				static fn( int $i ): TranslationRequest => new TranslationRequest(
					(string) $i,
					str_repeat( 'texto largo ', 200 ),
					StringType::Text
				),
				range( 1, 40 )
			),
			$this->contexto()
		);

		$this->assertGreaterThanOrEqual( 4096, $pequeno['max_tokens'] );
		$this->assertGreaterThan( $pequeno['max_tokens'], $grande['max_tokens'] );
		$this->assertLessThanOrEqual( 16000, $grande['max_tokens'] );
	}

	private function parser(): ResponseParser {
		return new ResponseParser( new Validator() );
	}

	/**
	 * @param array<int, array<string, string>> $translations Traducciones devueltas.
	 * @param string                            $stop_reason Motivo de parada.
	 * @return array<string, mixed>
	 */
	private function respuesta( array $translations, string $stop_reason = 'end_turn' ): array {
		return array(
			'stop_reason' => $stop_reason,
			'content'     => array(
				array(
					'type' => 'text',
					'text' => (string) json_encode( array( 'translations' => $translations ) ),
				),
			),
			'usage'       => array(
				'input_tokens'                => 100,
				'output_tokens'               => 50,
				'cache_read_input_tokens'     => 900,
				'cache_creation_input_tokens' => 0,
			),
		);
	}

	public function test_empareja_las_traducciones_por_identificador(): void {
		$result = $this->parser()->parse(
			$this->respuesta(
				array(
					// Deliberadamente desordenadas: el emparejamiento es por id.
					array(
						'id'   => '3',
						'text' => 'A cat',
					),
					array(
						'id'   => '1',
						'text' => 'Add to cart',
					),
					array(
						'id'   => '2',
						'text' => 'Hello <b>world</b>',
					),
				)
			),
			$this->lote()
		);

		$this->assertSame(
			array(
				'3' => 'A cat',
				'1' => 'Add to cart',
				'2' => 'Hello <b>world</b>',
			),
			$result->translations
		);
		$this->assertSame( array(), $result->failures );
	}

	public function test_descarta_las_traducciones_que_no_validan(): void {
		$result = $this->parser()->parse(
			$this->respuesta(
				array(
					array(
						'id'   => '1',
						'text' => 'Add to cart',
					),
					array(
						'id'   => '2',
						'text' => 'Hello world',
					),
					array(
						'id'   => '3',
						'text' => 'A cat',
					),
				)
			),
			$this->lote()
		);

		$this->assertArrayNotHasKey( '2', $result->translations );
		$this->assertArrayHasKey( '2', $result->failures );
		$this->assertStringContainsString( 'tag_count_mismatch', $result->failures['2'] );

		// Un fallo suelto no invalida el resto del lote.
		$this->assertCount( 2, $result->translations );
	}

	public function test_marca_como_ausentes_las_cadenas_que_no_vuelven(): void {
		$result = $this->parser()->parse(
			$this->respuesta(
				array(
					array(
						'id'   => '1',
						'text' => 'Add to cart',
					),
				)
			),
			$this->lote()
		);

		$this->assertSame( 'missing_from_response', $result->failures['2'] );
		$this->assertSame( 'missing_from_response', $result->failures['3'] );
	}

	public function test_distingue_una_respuesta_truncada(): void {
		$result = $this->parser()->parse(
			$this->respuesta(
				array(
					array(
						'id'   => '1',
						'text' => 'Add to cart',
					),
				),
				'max_tokens'
			),
			$this->lote()
		);

		$this->assertSame( 'truncated_response', $result->failures['2'] );
	}

	public function test_ignora_los_identificadores_inventados(): void {
		$result = $this->parser()->parse(
			$this->respuesta(
				array(
					array(
						'id'   => '1',
						'text' => 'Add to cart',
					),
					array(
						'id'   => '999',
						'text' => 'Inventada',
					),
				)
			),
			$this->lote()
		);

		$this->assertArrayNotHasKey( '999', $result->translations );
	}

	public function test_registra_el_consumo_desglosado(): void {
		$usage = $this->parser()->parse( $this->respuesta( array() ), $this->lote() )->usage;

		$this->assertSame( 100, $usage->input_tokens );
		$this->assertSame( 50, $usage->output_tokens );
		$this->assertSame( 900, $usage->cache_read_tokens );
		$this->assertTrue( $usage->used_cache() );
	}

	public function test_un_rechazo_del_modelo_no_se_reintenta(): void {
		$this->expectException( EngineException::class );

		$this->parser()->parse(
			array(
				'stop_reason' => 'refusal',
				'content'     => array(),
				'usage'       => array(),
			),
			$this->lote()
		);
	}

	public function test_una_respuesta_sin_json_marca_todo_como_ausente(): void {
		$result = $this->parser()->parse(
			array(
				'stop_reason' => 'end_turn',
				'content'     => array(
					array(
						'type' => 'text',
						'text' => 'esto no es json',
					),
				),
				'usage'       => array(),
			),
			$this->lote()
		);

		$this->assertTrue( $result->is_empty() );
		$this->assertCount( 3, $result->failures );
	}

	/**
	 * @param int  $status    Código HTTP.
	 * @param bool $expected  Si debe reintentarse.
	 *
	 * @dataProvider proveedor_de_reintentos
	 */
	public function test_decide_que_codigos_se_reintentan( int $status, bool $expected ): void {
		$this->assertSame( $expected, ( new RetryPolicy() )->is_retryable( $status ) );
	}

	/**
	 * @return array<string, array{0:int, 1:bool}>
	 */
	public static function proveedor_de_reintentos(): array {
		return array(
			'limite de peticiones' => array( 429, true ),
			'sobrecarga'           => array( 529, true ),
			'error del servidor'   => array( 500, true ),
			'clave invalida'       => array( 401, false ),
			'peticion mal formada' => array( 400, false ),
			'no encontrado'        => array( 404, false ),
		);
	}

	public function test_la_espera_crece_y_se_detiene_al_agotar_los_intentos(): void {
		$policy = new RetryPolicy( 3, 2, 60 );

		$primera = $policy->delay_for( 1 );
		$segunda = $policy->delay_for( 2 );

		$this->assertNotNull( $primera );
		$this->assertNotNull( $segunda );
		$this->assertGreaterThanOrEqual( 2, $primera );
		$this->assertGreaterThanOrEqual( 4, $segunda );
		$this->assertNull( $policy->delay_for( 3 ), 'Al llegar al máximo de intentos no debe haber más espera.' );
	}

	public function test_la_cabecera_del_servidor_manda_sobre_el_calculo_propio(): void {
		$this->assertSame( 7, ( new RetryPolicy( 5, 2, 60 ) )->delay_for( 1, 7 ) );
		$this->assertSame( 60, ( new RetryPolicy( 5, 2, 60 ) )->delay_for( 1, 9999 ), 'La espera debe quedar acotada.' );
	}
}
