<?php
/**
 * Cadena localizada en el HTML de una página.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Translation\StringType;

/**
 * Unidad de traducción con su posición exacta en el documento.
 *
 * La posición es un intervalo de bytes sobre el HTML original. Toda la
 * sustitución del plugin se hace empalmando estos intervalos, nunca
 * re-serializando el documento: así todo byte que no tocamos sale idéntico.
 */
final class ExtractedString {

	/**
	 * Constructor.
	 *
	 * @param StringType  $type      Tipo de cadena.
	 * @param string      $value     Valor a traducir. Decodificado para texto y
	 *                               atributos; HTML en crudo para bloques.
	 * @param int         $start     Desplazamiento en bytes donde empieza el
	 *                               fragmento sustituible.
	 * @param int         $length    Longitud en bytes del fragmento sustituible.
	 * @param string|null $context   Contexto para el hash y para el traductor.
	 * @param string|null $attribute Nombre del atributo, si el tipo lo es.
	 */
	public function __construct(
		public readonly StringType $type,
		public readonly string $value,
		public readonly int $start,
		public readonly int $length,
		public readonly ?string $context = null,
		public readonly ?string $attribute = null
	) {}

	/**
	 * Desplazamiento del primer byte posterior al fragmento.
	 */
	public function end(): int {
		return $this->start + $this->length;
	}
}
