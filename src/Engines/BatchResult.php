<?php
/**
 * Resultado de traducir un lote.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

/**
 * Traducciones obtenidas, fallos por cadena y consumo de la llamada.
 *
 * Un lote puede tener éxito parcial: eso no es un error, es lo normal cuando una
 * cadena suelta no pasa la validación estructural.
 */
final class BatchResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $translations Id => traducción.
	 * @param array<string, string> $failures     Id => motivo del fallo.
	 * @param Usage                 $usage        Consumo de la llamada.
	 */
	public function __construct(
		public readonly array $translations = array(),
		public readonly array $failures = array(),
		public readonly Usage $usage = new Usage()
	) {}

	/**
	 * Si no se ha traducido ninguna cadena.
	 */
	public function is_empty(): bool {
		return array() === $this->translations;
	}
}
