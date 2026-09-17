<?php
/**
 * Lectura de la respuesta de la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\BatchResult;
use PolyglotAI\Engines\EngineException;
use PolyglotAI\Engines\TranslationRequest;
use PolyglotAI\Engines\Usage;
use PolyglotAI\Translation\Validator;

/**
 * Convierte la respuesta de /v1/messages en un resultado de lote.
 *
 * Aquí es donde se decide qué se guarda y qué se descarta: ninguna traducción
 * llega a la base de datos sin pasar la validación estructural.
 */
final class ResponseParser {

	/**
	 * Constructor.
	 *
	 * @param Validator $validator Validador estructural.
	 */
	public function __construct( private readonly Validator $validator ) {}

	/**
	 * Interpreta la respuesta.
	 *
	 * @param array<string, mixed> $response Respuesta ya decodificada.
	 * @param TranslationRequest[] $requests Cadenas enviadas, indexadas por id.
	 * @return BatchResult
	 *
	 * @throws EngineException Si la respuesta no es utilizable en absoluto.
	 */
	public function parse( array $response, array $requests ): BatchResult {
		$usage       = Usage::from_response( is_array( $response['usage'] ?? null ) ? $response['usage'] : array() );
		$stop_reason = is_string( $response['stop_reason'] ?? null ) ? $response['stop_reason'] : '';

		$by_id = array();

		foreach ( $requests as $request ) {
			$by_id[ $request->id ] = $request;
		}

		if ( 'refusal' === $stop_reason ) {
			// El modelo ha declinado la petición. Reintentar no arregla nada.
			throw new EngineException(
				'El modelo ha rechazado traducir este lote.',
				0,
				false
			);
		}

		$decoded = $this->decode_payload( $response );
		$results = array();
		$failures = array();

		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || ! is_string( $entry['text'] ?? null ) ) {
				continue;
			}

			$id = $entry['id'];

			if ( ! isset( $by_id[ $id ] ) ) {
				// Identificador inventado: se ignora en silencio.
				continue;
			}

			$request    = $by_id[ $id ];
			$validation = $this->validator->validate( $request->text, $entry['text'], $request->type );

			if ( ! $validation->is_valid ) {
				$failures[ $id ] = $validation->summary();
				continue;
			}

			$results[ $id ] = $entry['text'];
		}

		foreach ( $by_id as $id => $request ) {
			if ( isset( $results[ $id ] ) || isset( $failures[ $id ] ) ) {
				continue;
			}

			$failures[ $id ] = 'max_tokens' === $stop_reason ? 'truncated_response' : 'missing_from_response';
		}

		return new BatchResult( $results, $failures, $usage );
	}

	/**
	 * Extrae la lista de traducciones del cuerpo de la respuesta.
	 *
	 * La salida estructurada garantiza que el primer bloque de texto es JSON
	 * válido contra el esquema, pero una respuesta truncada por max_tokens puede
	 * llegar incompleta: por eso se decodifica con cuidado en vez de confiar.
	 *
	 * @param array<string, mixed> $response Respuesta decodificada.
	 * @return array<int, mixed>
	 *
	 * @throws EngineException Si no hay contenido interpretable.
	 */
	private function decode_payload( array $response ): array {
		$content = $response['content'] ?? null;

		if ( ! is_array( $content ) ) {
			throw new EngineException( 'La respuesta no contiene bloques de contenido.', 0, false );
		}

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || 'text' !== ( $block['type'] ?? '' ) || ! is_string( $block['text'] ?? null ) ) {
				continue;
			}

			$payload = json_decode( $block['text'], true );

			if ( is_array( $payload ) && is_array( $payload['translations'] ?? null ) ) {
				return $payload['translations'];
			}
		}

		// Sin traducciones legibles: no es un fallo del lote entero, se marcarán
		// todas las cadenas como ausentes.
		return array();
	}
}
