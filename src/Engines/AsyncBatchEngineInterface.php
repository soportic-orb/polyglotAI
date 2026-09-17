<?php
/**
 * Motores que admiten lotes asíncronos.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

/**
 * Envío en diferido: se manda todo y se recoge cuando esté.
 *
 * Va aparte de `TranslationEngineInterface` porque no todo motor lo admite, y
 * porque el ciclo es distinto: aquí no hay una llamada que devuelve la
 * traducción, sino tres momentos separados por horas —enviar, preguntar,
 * recoger— que alguien tiene que orquestar desde fuera.
 *
 * Quien lo use debe comprobar antes `supports_async_batch()`.
 */
interface AsyncBatchEngineInterface extends TranslationEngineInterface {

	/**
	 * Envía un lote y devuelve su identificador.
	 *
	 * @param array<string, TranslationRequest[]> $chunks  Trozos, por identificador propio.
	 * @param EngineContext                       $context Contexto lingüístico.
	 * @return string Identificador del lote en el proveedor.
	 *
	 * @throws EngineException Si el lote no se puede crear.
	 */
	public function create_batch( array $chunks, EngineContext $context ): string;

	/**
	 * Pregunta si un lote ha terminado.
	 *
	 * @param string $batch_id Identificador.
	 * @return string Estado tal como lo da el proveedor.
	 *
	 * @throws EngineException Si no se puede consultar.
	 */
	public function batch_status( string $batch_id ): string;

	/**
	 * Si un estado significa que el lote ya ha terminado.
	 *
	 * @param string $status Estado devuelto por batch_status().
	 */
	public function batch_has_ended( string $status ): bool;

	/**
	 * Recoge los resultados de un lote terminado.
	 *
	 * @param string                              $batch_id Identificador.
	 * @param array<string, TranslationRequest[]> $chunks   Los mismos trozos que se enviaron.
	 * @return array<string, BatchResult> Resultado por identificador de trozo.
	 *
	 * @throws EngineException Si no se pueden leer los resultados.
	 */
	public function collect_batch( string $batch_id, array $chunks ): array;

	/**
	 * Cancela un lote en curso.
	 *
	 * Lo ya procesado se cobra igual: cancelar detiene lo que queda.
	 *
	 * @param string $batch_id Identificador.
	 */
	public function cancel_batch( string $batch_id ): void;
}
