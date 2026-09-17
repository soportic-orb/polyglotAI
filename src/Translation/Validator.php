<?php
/**
 * Validación estructural de las traducciones devueltas por un motor.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use WP_HTML_Tag_Processor;

/**
 * Comprueba que una traducción conserva la estructura del original.
 *
 * Un modelo de lenguaje puede perder una etiqueta, reordenar un placeholder o
 * "arreglar" una URL. Si eso llega al HTML de la página, rompe el diseño o el
 * texto. Toda traducción pasa por aquí antes de guardarse; si no valida, se
 * descarta, se marca como error y se sigue sirviendo el original.
 */
final class Validator {

	/**
	 * Atributos cuyo valor sí es texto traducible y por tanto puede cambiar.
	 * El valor del resto debe salir idéntico.
	 *
	 * @var string[]
	 */
	private const TRANSLATABLE_ATTRIBUTES = array( 'alt', 'title', 'placeholder', 'aria-label', 'aria-placeholder', 'value' );

	/** Placeholders de printf y de plantillas habituales. */
	private const PLACEHOLDER_PATTERN = '/%(?:\d+\$)?[sdfu]|%%|\{\{?\w+\}?\}|###[A-Z0-9_]+###/u';

	/** Apertura y cierre de shortcodes. */
	private const SHORTCODE_PATTERN = '/\[\/?[a-zA-Z0-9_\-]+(?:[^\]]*)\]/u';

	/** URLs absolutas y protocol-relative. */
	private const URL_PATTERN = '#(?:https?:)?//[^\s"\'<>\]\)]+#u';

	/** Direcciones de correo. */
	private const EMAIL_PATTERN = '/[\w.+-]+@[\w-]+\.[\w.-]+/u';

	/**
	 * Valida una traducción contra su original.
	 *
	 * @param string     $original    Texto original.
	 * @param string     $translation Traducción propuesta.
	 * @param StringType $type        Tipo de cadena.
	 * @return ValidationResult Resultado con los motivos de fallo, si los hay.
	 */
	public function validate( string $original, string $translation, StringType $type ): ValidationResult {
		$problems = array();

		if ( '' === trim( $translation ) && '' !== trim( $original ) ) {
			return ValidationResult::invalid( array( 'empty_translation' ) );
		}

		if ( $type->is_html() ) {
			$problems = array_merge( $problems, $this->compare_markup( $original, $translation ) );
		} elseif ( str_contains( $translation, '<' ) && ! str_contains( $original, '<' ) ) {
			// El motor ha introducido marcado en una cadena que era texto plano.
			$problems[] = 'unexpected_markup';
		}

		$problems = array_merge(
			$problems,
			$this->compare_tokens( $original, $translation, self::PLACEHOLDER_PATTERN, 'placeholders' ),
			$this->compare_tokens( $original, $translation, self::SHORTCODE_PATTERN, 'shortcodes' ),
			$this->compare_tokens( $original, $translation, self::URL_PATTERN, 'urls' ),
			$this->compare_tokens( $original, $translation, self::EMAIL_PATTERN, 'emails' )
		);

		return array() === $problems ? ValidationResult::valid() : ValidationResult::invalid( $problems );
	}

	/**
	 * Compara la secuencia de etiquetas y atributos de dos fragmentos de HTML.
	 *
	 * @param string $original    HTML original.
	 * @param string $translation HTML traducido.
	 * @return string[] Motivos de fallo.
	 */
	private function compare_markup( string $original, string $translation ): array {
		$expected = $this->tag_signature( $original );
		$actual   = $this->tag_signature( $translation );

		if ( count( $expected ) !== count( $actual ) ) {
			return array( 'tag_count_mismatch' );
		}

		foreach ( $expected as $index => $tag ) {
			if ( $tag['name'] !== $actual[ $index ]['name'] || $tag['closer'] !== $actual[ $index ]['closer'] ) {
				return array( 'tag_sequence_mismatch' );
			}

			if ( array_keys( $tag['attributes'] ) !== array_keys( $actual[ $index ]['attributes'] ) ) {
				return array( 'attribute_set_mismatch' );
			}

			foreach ( $tag['attributes'] as $name => $value ) {
				if ( in_array( $name, self::TRANSLATABLE_ATTRIBUTES, true ) ) {
					continue;
				}

				if ( $value !== $actual[ $index ]['attributes'][ $name ] ) {
					return array( 'attribute_value_mismatch' );
				}
			}
		}

		return array();
	}

	/**
	 * Extrae la firma de etiquetas de un fragmento de HTML.
	 *
	 * @param string $html Fragmento.
	 * @return array<int, array{name:string, closer:bool, attributes:array<string, string|bool|null>}>
	 */
	private function tag_signature( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$signature = array();

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$attributes = array();

			foreach ( $processor->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}

			ksort( $attributes );

			$signature[] = array(
				'name'       => (string) $processor->get_tag(),
				'closer'     => $processor->is_tag_closer(),
				'attributes' => $attributes,
			);
		}

		return $signature;
	}

	/**
	 * Compara el multiconjunto de coincidencias de un patrón entre original y
	 * traducción.
	 *
	 * Se compara como multiconjunto ordenado, no como secuencia: un idioma puede
	 * legítimamente reordenar "%1$s de %2$s", pero no puede perder ni inventar
	 * un placeholder.
	 *
	 * @param string $original    Texto original.
	 * @param string $translation Traducción.
	 * @param string $pattern     Expresión regular.
	 * @param string $label       Nombre del problema si no coinciden.
	 * @return string[] Motivos de fallo.
	 */
	private function compare_tokens( string $original, string $translation, string $pattern, string $label ): array {
		preg_match_all( $pattern, $original, $expected );
		preg_match_all( $pattern, $translation, $actual );

		$expected = $expected[0];
		$actual   = $actual[0];

		sort( $expected );
		sort( $actual );

		return $expected === $actual ? array() : array( $label . '_mismatch' );
	}
}
