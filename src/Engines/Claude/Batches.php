<?php
/**
 * Message Batches API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\EngineException;

/**
 * Crea y consulta lotes asíncronos.
 *
 * Traducir un sitio entero por la API normal es pagar el precio completo y
 * esperar en línea. La Batches API cuesta la mitad y devuelve el resultado
 * cuando esté: para un trabajo que nadie mira en directo, esperar unas horas a
 * cambio de la mitad de la factura es un intercambio obvio.
 *
 * **Los resultados llegan en cualquier orden.** Se indexan por `custom_id`,
 * nunca por posición; el ADR-05 lo dice y es literal: la API no promete orden.
 *
 * **Los resultados son JSONL**, una línea por respuesta, no un JSON único.
 * Descodificarlo entero fallaría, así que se lee línea a línea, y una línea
 * ilegible se salta en vez de tumbar el lote entero: el resto de las
 * traducciones son buenas y ya están pagadas.
 */
final class Batches {

	/** Ruta de la API. */
	private const PATH = '/v1/messages/batches';

	/**
	 * Estado de un lote que ya ha terminado de procesarse.
	 */
	public const ENDED = 'ended';

	/**
	 * Constructor.
	 *
	 * @param ClaudeClient $client Cliente HTTP.
	 */
	public function __construct( private readonly ClaudeClient $client ) {}

	/**
	 * Crea un lote.
	 *
	 * @param array<int, array{custom_id: string, params: array<string, mixed>}> $requests Peticiones.
	 * @return string Identificador del lote.
	 *
	 * @throws EngineException Si la API no devuelve un identificador.
	 */
	public function create( array $requests ): string {
		if ( array() === $requests ) {
			throw new EngineException( 'Un lote sin peticiones no se envía.', 0, false );
		}

		$response = $this->client->post( self::PATH, array( 'requests' => array_values( $requests ) ) );

		$id = $response['id'] ?? null;

		if ( ! is_string( $id ) || '' === $id ) {
			throw new EngineException( 'La API no ha devuelto el identificador del lote.', 0, false );
		}

		return $id;
	}

	/**
	 * Estado de un lote.
	 *
	 * @param string $batch_id Identificador.
	 * @return array{status: string, results_url: string|null, counts: array<string, int>}
	 */
	public function status( string $batch_id ): array {
		$response = $this->client->get( self::PATH . '/' . rawurlencode( $batch_id ) );

		/** @var array<string, int> $counts */
		$counts = is_array( $response['request_counts'] ?? null )
			? array_map( 'intval', $response['request_counts'] )
			: array();

		$url = $response['results_url'] ?? null;

		return array(
			'status'      => (string) ( $response['processing_status'] ?? '' ),
			'results_url' => is_string( $url ) && '' !== $url ? $url : null,
			'counts'      => $counts,
		);
	}

	/**
	 * Cancela un lote que todavía se está procesando.
	 *
	 * Lo ya procesado se cobra igual: cancelar detiene lo que queda, no
	 * devuelve el dinero de lo hecho.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function cancel( string $batch_id ): void {
		$this->client->post( self::PATH . '/' . rawurlencode( $batch_id ) . '/cancel', array() );
	}

	/**
	 * Lee los resultados de un lote terminado.
	 *
	 * @param string $results_url URL que devuelve la propia API.
	 * @return array<string, array<string, mixed>> custom_id => respuesta.
	 */
	public function results( string $results_url ): array {
		$body    = $this->client->download( $results_url );
		$results = array();

		$lines = preg_split( '/\R/', $body );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$decoded = json_decode( $line, true );

			// Una línea ilegible no tumba el lote: el resto de las traducciones
			// son buenas y ya están pagadas.
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$custom_id = $decoded['custom_id'] ?? null;

			if ( ! is_string( $custom_id ) || '' === $custom_id ) {
				continue;
			}

			$results[ $custom_id ] = $decoded;
		}

		return $results;
	}
}
