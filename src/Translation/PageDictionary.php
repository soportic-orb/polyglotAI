<?php
/**
 * Diccionario de una página concreta.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use PolyglotAI\Html\ExtractedString;

/**
 * Traducciones disponibles para las cadenas de una página.
 *
 * Se construye de una sola consulta por página e idioma y se consulta en
 * memoria: el objetivo de rendimiento del plugin no admite una consulta por
 * cadena (ADR-08).
 */
final class PageDictionary {

	/**
	 * Constructor.
	 *
	 * @param array<string, string>                                   $translations Hash => traducción.
	 * @param array<string, array{unit:ExtractedString, hash:string}> $missing      Cadenas sin traducir, por hash.
	 * @param Hasher                                                  $hasher       Calculador de hashes.
	 */
	public function __construct(
		private readonly array $translations,
		private readonly array $missing,
		private readonly Hasher $hasher
	) {}

	/**
	 * Traducción de una unidad, si la hay.
	 *
	 * @param ExtractedString $unit Unidad extraída del HTML.
	 */
	public function get( ExtractedString $unit ): ?string {
		return $this->translations[ $this->hasher->hash( $unit->value, $unit->type, $unit->context ) ] ?? null;
	}

	/**
	 * Cadenas de la página que aún no tienen traducción.
	 *
	 * @return array<string, array{unit:ExtractedString, hash:string}>
	 */
	public function missing(): array {
		return $this->missing;
	}

	/**
	 * Si falta algo por traducir.
	 */
	public function has_missing(): bool {
		return array() !== $this->missing;
	}

	/**
	 * Número de cadenas traducidas.
	 */
	public function count(): int {
		return count( $this->translations );
	}
}
