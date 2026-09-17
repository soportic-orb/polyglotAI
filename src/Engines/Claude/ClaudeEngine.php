<?php
/**
 * Motor de traducción sobre la API de Anthropic.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\BatchResult;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\TranslationEngineInterface;
use PolyglotAI\Engines\Usage;

/**
 * Traduce lotes de cadenas con la API de mensajes de Anthropic.
 *
 * El nombre del proveedor solo aparece aquí dentro: el resto del plugin habla
 * con TranslationEngineInterface y no sabe qué hay detrás.
 */
final class ClaudeEngine implements TranslationEngineInterface {

	/**
	 * Cadenas por llamada.
	 *
	 * Ni tan pocas que el prompt del sistema, que es lo caro, se pague una vez
	 * por cadena, ni tantas que una respuesta larga tope con max_tokens.
	 */
	private const BATCH_SIZE = 40;

	/**
	 * Constructor.
	 *
	 * @param ClaudeClient   $client  Cliente HTTP.
	 * @param PromptBuilder  $prompt  Constructor de peticiones.
	 * @param ResponseParser $parser  Lector de respuestas.
	 */
	public function __construct(
		private readonly ClaudeClient $client,
		private readonly PromptBuilder $prompt,
		private readonly ResponseParser $parser
	) {}

	/**
	 * Identificador del motor.
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * Nombre legible.
	 */
	public function label(): string {
		return __( 'Motor IA (Anthropic)', 'polyglot-ai' );
	}

	/**
	 * Si admite envío asíncrono por lotes.
	 */
	public function supports_async_batch(): bool {
		return true;
	}

	/**
	 * Cadenas por llamada.
	 */
	public function max_batch_size(): int {
		/**
		 * Permite ajustar cuántas cadenas se envían por llamada.
		 *
		 * @since 0.1.0
		 *
		 * @param int $size Número de cadenas.
		 */
		return (int) apply_filters( 'pgai_engine_batch_size', self::BATCH_SIZE );
	}

	/**
	 * Traduce un lote de cadenas.
	 *
	 * @param \PolyglotAI\Engines\TranslationRequest[] $requests Cadenas a traducir.
	 * @param EngineContext                            $context  Contexto lingüístico.
	 * @return BatchResult
	 */
	public function translate( array $requests, EngineContext $context ): BatchResult {
		if ( array() === $requests ) {
			return new BatchResult();
		}

		$translations = array();
		$failures     = array();
		$usage        = new Usage();

		foreach ( array_chunk( $requests, $this->max_batch_size() ) as $chunk ) {
			$response = $this->client->post( '/v1/messages', $this->prompt->build( $chunk, $context ) );
			$result   = $this->parser->parse( $response, $chunk );

			$translations += $result->translations;
			$failures     += $result->failures;
			$usage         = $usage->plus( $result->usage );
		}

		return new BatchResult( $translations, $failures, $usage );
	}
}
