<?php
/**
 * Sustitución pendiente sobre el HTML original.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Intervalo de bytes que debe sustituirse por un texto nuevo.
 */
final class Replacement {

	/**
	 * Constructor.
	 *
	 * @param int    $start  Desplazamiento inicial en bytes.
	 * @param int    $length Longitud en bytes del fragmento sustituido.
	 * @param string $text   Texto nuevo, ya escapado para su contexto.
	 */
	public function __construct(
		public readonly int $start,
		public readonly int $length,
		public readonly string $text
	) {}

	/**
	 * Desplazamiento del primer byte posterior al fragmento.
	 */
	public function end(): int {
		return $this->start + $this->length;
	}
}
