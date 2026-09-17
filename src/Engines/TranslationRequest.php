<?php
/**
 * Cadena enviada a un motor de traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

use PolyglotAI\Translation\StringType;

/**
 * Una cadena concreta a traducir, con lo que el motor necesita saber de ella.
 */
final class TranslationRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $id      Identificador dentro del lote. Es el que
	 *                             devuelve el motor para emparejar la respuesta.
	 * @param string      $text    Texto a traducir.
	 * @param StringType  $type    Tipo de cadena.
	 * @param string|null $context Contexto (nombre del atributo, contexto de
	 *                             gettext, clave de la meta...).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $text,
		public readonly StringType $type,
		public readonly ?string $context = null
	) {}
}
