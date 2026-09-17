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
final class TranslationLookup implements TextLookupInterface {

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
	 * Traducción de varios textos con una sola consulta.
	 *
	 * La usan los datos estructurados: un JSON-LD trae una decena de textos y
	 * aparece en todas las páginas del sitio, así que preguntar uno a uno sería
	 * una decena de consultas por visita.
	 *
	 * @param string[] $texts    Textos.
	 * @param string   $language Locale.
	 * @return array<string, string> Texto original => traducción (o el original).
	 */
	public function texts( array $texts, string $language ): array {
		$hashes = array();

		foreach ( $texts as $text ) {
			$text = (string) $text;

			if ( isset( $hashes[ $text ] ) || ! $this->normalizer->is_translatable( $this->normalizer->normalize( $text ) ) ) {
				continue;
			}

			$hashes[ $text ] = $this->hasher->hash( $text, StringType::Text, null );
		}

		if ( array() === $hashes ) {
			return array();
		}

		$found  = $this->translations->lookup( array_values( $hashes ), $language );
		$result = array();
		$new    = array();

		foreach ( $hashes as $text => $hash ) {
			$text = (string) $text;

			if ( isset( $found[ $hash ] ) ) {
				$result[ $text ] = $found[ $hash ];

				continue;
			}

			$result[ $text ] = $text;
			$new[]           = $this->sources->remember( $hash, $text, StringType::Text, null, null, $this->hasher->text_hash( $text ) );
		}

		if ( array() !== $new ) {
			// Lo que falta queda anotado para traducirlo en segundo plano, como
			// cualquier otra cadena de la página (ADR-13).
			$this->translations->mark_pending( $new, $language );
		}

		return $result;
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
		$id = $this->sources->remember( $hash, $value, $type, $context, null, $this->hasher->text_hash( $value ) );

		$this->translations->mark_pending( array( $id ), $language );

		return $value;
	}
}
