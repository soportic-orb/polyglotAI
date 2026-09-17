<?php
/**
 * Búsqueda de la traducción de cadenas sueltas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use PolyglotAI\Database\SourceRepository;
use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Html\ExtractedString;

/**
 * Traduce cadenas que no vienen de un barrido de página.
 *
 * Lo usan los correos y cualquier integración que tenga un texto suelto y
 * necesite su versión en otro idioma. Registra lo que no encuentra, de modo que
 * un asunto de correo nuevo aparece en el gestor de cadenas la primera vez que
 * se envía.
 */
final class TranslationLookup {

	/**
	 * Constructor.
	 *
	 * @param SourceRepository      $sources      Repositorio de cadenas.
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param Hasher                $hasher       Calculador de hashes.
	 * @param Normalizer            $normalizer   Normalizador.
	 */
	public function __construct(
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly Hasher $hasher,
		private readonly Normalizer $normalizer
	) {}

	/**
	 * Traducción de un texto plano, o el original si no la hay.
	 *
	 * @param string $text     Texto.
	 * @param string $language Locale.
	 */
	public function text( string $text, string $language ): string {
		return $this->translate( $text, StringType::Text, null, $language );
	}

	/**
	 * Traducción de una unidad extraída de un documento.
	 *
	 * @param ExtractedString $unit     Unidad.
	 * @param string          $language Locale.
	 */
	public function for_unit( ExtractedString $unit, string $language ): ?string {
		$translated = $this->translate( $unit->value, $unit->type, $unit->context, $language );

		return $translated === $unit->value ? null : $translated;
	}

	/**
	 * Busca una traducción y registra la cadena si no existía.
	 *
	 * @param string      $value    Texto.
	 * @param StringType  $type     Tipo.
	 * @param string|null $context  Contexto.
	 * @param string      $language Locale.
	 */
	private function translate( string $value, StringType $type, ?string $context, string $language ): string {
		if ( ! $this->normalizer->is_translatable( $this->normalizer->normalize( $value ) ) ) {
			return $value;
		}

		$hash  = $this->hasher->hash( $value, $type, $context );
		$found = $this->translations->lookup( array( $hash ), $language );

		if ( isset( $found[ $hash ] ) ) {
			return $found[ $hash ];
		}

		// La cadena queda anotada para que aparezca en el gestor y se traduzca
		// en segundo plano: la próxima vez que se envíe ya irá traducida.
		$id = $this->sources->remember( $hash, $value, $type, $context );

		$this->translations->mark_pending( array( $id ), $language );

		return $value;
	}
}
