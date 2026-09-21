<?php
/**
 * Motor de traducción sobre la API de Anthropic.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\AsyncBatchEngineInterface;
use PolyglotAI\Engines\BatchResult;
use PolyglotAI\Engines\EngineContext;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;

/**
 * Traduce lotes de cadenas con la API de mensajes de Anthropic.
 *
 * El nombre del proveedor solo aparece aquí dentro: el resto del plugin habla
 * con TranslationEngineInterface y no sabe qué hay detrás.
 */
final class ClaudeEngine implements AsyncBatchEngineInterface {

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
	 * @param Batches        $batches Lotes asíncronos.
	 */
	public function __construct(
		private readonly ClaudeClient $client,
		private readonly PromptBuilder $prompt,
		private readonly ResponseParser $parser,
		private readonly Batches $batches
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
		 * @since 2.0
		 *
		 * @param int $size Número de cadenas.
		 */
		return (int) apply_filters( 'pgai_engine_batch_size', self::BATCH_SIZE );
	}

	/**
	 * Traduce un lote de cadenas.
	 *
	 * @param TranslationRequest[] $requests Cadenas a traducir.
	 * @param EngineContext        $context  Contexto lingüístico.
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
	/**
	 * Cuenta los tokens de entrada que costaría traducir unas cadenas.
	 *
	 * Lo cuenta la propia API (`/v1/messages/count_tokens`) y no una regla
	 * casera de caracteres por token: el tokenizador depende del modelo y una
	 * estimación inventada se equivoca justo donde importa, que es al decirle a
	 * alguien cuánto va a gastar.
	 *
	 * Solo se manda **un trozo** y se multiplica por el número de trozos. El
	 * prompt del sistema es idéntico en todos y las cadenas son parecidas entre
	 * sí, así que la diferencia es pequeña; mandarlos todos para afinar
	 * significaría pagar una llamada por trozo para averiguar lo que va a
	 * costar, que es exactamente lo que se quiere evitar.
	 *
	 * @param TranslationRequest[] $requests Cadenas.
	 * @param EngineContext        $context  Contexto lingüístico.
	 * @return int Tokens de entrada estimados.
	 *
	 * @throws EngineException Si la API no responde.
	 */
	public function estimate_input_tokens( array $requests, EngineContext $context ): int {
		if ( array() === $requests ) {
			return 0;
		}

		$chunks = array_chunk( $requests, $this->max_batch_size() );
		$sample = $this->prompt->build( $chunks[0], $context, true );

		// count_tokens no acepta los campos de la respuesta.
		unset( $sample['max_tokens'], $sample['output_config'], $sample['thinking'] );

		$response = $this->client->post( '/v1/messages/count_tokens', $sample );

		$tokens = (int) ( $response['input_tokens'] ?? 0 );

		return $tokens * count( $chunks );
	}

	/**
	 * Envía un lote asíncrono.
	 *
	 * @param array<string, TranslationRequest[]> $chunks  Trozos, por identificador propio.
	 * @param EngineContext                       $context Contexto lingüístico.
	 * @return string Identificador del lote.
	 *
	 * @throws EngineException Si el lote no se puede crear.
	 */
	public function create_batch( array $chunks, EngineContext $context ): string {
		$requests = array();

		foreach ( $chunks as $custom_id => $chunk ) {
			$requests[] = array(
				'custom_id' => (string) $custom_id,
				// Una hora de caché: entre lote y lote de una traducción de
				// sitio completo pasan minutos, y con cinco el prefijo caduca
				// y se vuelve a pagar entero (ADR-05).
				'params'    => $this->prompt->build( $chunk, $context, true ),
			);
		}

		return $this->batches->create( $requests );
	}

	/**
	 * Estado de un lote.
	 *
	 * @param string $batch_id Identificador.
	 *
	 * @throws EngineException Si no se puede consultar.
	 */
	public function batch_status( string $batch_id ): string {
		return $this->batches->status( $batch_id )['status'];
	}

	/**
	 * Si un estado significa que el lote ha terminado.
	 *
	 * @param string $status Estado.
	 */
	public function batch_has_ended( string $status ): bool {
		return Batches::ENDED === $status;
	}

	/**
	 * Recoge los resultados de un lote terminado.
	 *
	 * @param string                              $batch_id Identificador.
	 * @param array<string, TranslationRequest[]> $chunks   Los mismos trozos que se enviaron.
	 * @return array<string, BatchResult>
	 *
	 * @throws EngineException Si el lote no tiene resultados que leer.
	 */
	public function collect_batch( string $batch_id, array $chunks ): array {
		$status = $this->batches->status( $batch_id );

		if ( null === $status['results_url'] ) {
			throw new EngineException( 'El lote ha terminado sin resultados que leer.', 0, false );
		}

		$raw       = $this->batches->results( $status['results_url'] );
		$collected = array();

		foreach ( $chunks as $custom_id => $chunk ) {
			$entry = $raw[ (string) $custom_id ] ?? null;

			// Un trozo sin respuesta no es un trozo traducido: se deja fuera y
			// sus cadenas se quedan pendientes para el siguiente intento.
			if ( ! is_array( $entry ) || ! is_array( $entry['result'] ?? null ) ) {
				continue;
			}

			$result = $entry['result'];

			if ( 'succeeded' !== ( $result['type'] ?? '' ) || ! is_array( $result['message'] ?? null ) ) {
				continue;
			}

			$collected[ (string) $custom_id ] = $this->parser->parse( $result['message'], $chunk );
		}

		return $collected;
	}

	/**
	 * Cancela un lote en curso.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function cancel_batch( string $batch_id ): void {
		$this->batches->cancel( $batch_id );
	}
}
