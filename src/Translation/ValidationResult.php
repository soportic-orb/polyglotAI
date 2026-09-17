<?php
/**
 * Resultado de validar una traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Valor inmutable con el veredicto de la validación estructural.
 */
final class ValidationResult {

	/**
	 * Constructor.
	 *
	 * @param bool     $is_valid Si la traducción es utilizable.
	 * @param string[] $problems Identificadores de los problemas encontrados.
	 */
	private function __construct(
		public readonly bool $is_valid,
		public readonly array $problems
	) {}

	/**
	 * Resultado válido.
	 */
	public static function valid(): self {
		return new self( true, array() );
	}

	/**
	 * Resultado inválido.
	 *
	 * @param string[] $problems Problemas encontrados.
	 */
	public static function invalid( array $problems ): self {
		return new self( false, array_values( array_unique( $problems ) ) );
	}

	/**
	 * Resumen legible para el registro de errores.
	 */
	public function summary(): string {
		return implode( ', ', $this->problems );
	}
}
