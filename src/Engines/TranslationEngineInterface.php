<?php
/**
 * Contrato de los motores de traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

/**
 * Motor capaz de traducir lotes de cadenas.
 *
 * Existe para que el plugin no quede atado a un proveedor: la implementación
 * principal usa la API de Anthropic, pero nada del resto del código la conoce.
 */
interface TranslationEngineInterface {

	/**
	 * Identificador estable del motor, el que se guarda en el registro.
	 */
	public function id(): string;

	/**
	 * Nombre legible para la interfaz.
	 */
	public function label(): string;

	/**
	 * Traduce un lote de cadenas.
	 *
	 * Nunca lanza por un fallo de una cadena concreta: los fallos individuales
	 * viajan en el resultado. Solo lanza si el lote entero no se ha podido
	 * procesar.
	 *
	 * @param TranslationRequest[] $requests Cadenas a traducir.
	 * @param EngineContext        $context  Contexto lingüístico.
	 * @return BatchResult Traducciones y fallos.
	 *
	 * @throws EngineException Si la llamada falla por completo.
	 */
	public function translate( array $requests, EngineContext $context ): BatchResult;

	/**
	 * Si el motor admite envío asíncrono por lotes, más barato.
	 */
	public function supports_async_batch(): bool;

	/**
	 * Número máximo de cadenas que conviene enviar en una sola llamada.
	 */
	public function max_batch_size(): int;
}
