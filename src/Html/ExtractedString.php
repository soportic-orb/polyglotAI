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
	 * @param int|null    $outer_start  Inicio del elemento que la contiene.
	 * @param int|null    $outer_length Longitud de ese elemento.
	 */
	public function __construct(
		public readonly StringType $type,
		public readonly string $value,
		public readonly int $start,
		public readonly int $length,
		public readonly ?string $context = null,
		public readonly ?string $attribute = null,
		public readonly ?int $outer_start = null,
		public readonly ?int $outer_length = null
	) {}

	/**
	 * Inicio del elemento que contiene la cadena, o de la cadena misma.
	 *
	 * Lo usa la fusión de bloques: fusionar por el contenido produciría HTML
	 * descuadrado (un «Uno</p><p>Dos»), y lo que hay que fusionar son elementos
	 * enteros.
	 */
	public function outer_start(): int {
		return $this->outer_start ?? $this->start;
	}

	/**
	 * Primer byte posterior al elemento que contiene la cadena.
	 */
	public function outer_end(): int {
		return null === $this->outer_start || null === $this->outer_length
			? $this->end()
			: $this->outer_start + $this->outer_length;
	}

	/**
	 * Desplazamiento del primer byte posterior al fragmento.
	 */
	public function end(): int {
		return $this->start + $this->length;
	}
}
