<?php
/**
 * Idiomas que puede traducir cada persona.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Support;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Restringe a un traductor a los idiomas que se le hayan asignado.
 *
 * Un sitio en ocho idiomas rara vez tiene una persona que los domine todos: lo
 * normal es contratar a alguien para el alemán y a otra persona para el
 * japonés. Sin esta restricción, cualquiera de las dos podría corregir —y
 * empeorar— el idioma de la otra sin saberlo.
 *
 * **La lista vacía significa «todos».** Es deliberado: quien no configure nada
 * se encuentra el comportamiento de siempre, y el administrador no tiene que
 * repasar los usuarios existentes al actualizar el plugin.
 *
 * Quien puede gestionar los ajustes no se restringe nunca: un administrador que
 * se quedara fuera de un idioma por un descuido no tendría cómo volver a entrar.
 */
final class TranslatorLanguages {

	/** Meta de usuario donde se guardan los locales. */
	public const META_KEY = 'pgai_languages';

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct( private readonly LanguageRegistry $languages ) {}

	/**
	 * Locales asignados a un usuario, tal como están guardados.
	 *
	 * @param int $user_id Identificador del usuario.
	 * @return string[] Vacío si no hay restricción.
	 */
	public function assigned( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$known = array_map(
			static fn ( Language $language ): string => $language->locale,
			$this->languages->translatable()
		);

		// Un idioma que ya no existe en el sitio se ignora: si no, quitar un
		// idioma dejaría traductores restringidos a nada.
		return array_values( array_intersect( array_map( 'strval', $stored ), $known ) );
	}

	/**
	 * Guarda los locales de un usuario.
	 *
	 * @param int      $user_id Identificador del usuario.
	 * @param string[] $locales Locales.
	 */
	public function assign( int $user_id, array $locales ): void {
		$known = array_map(
			static fn ( Language $language ): string => $language->locale,
			$this->languages->translatable()
		);

		$clean = array_values(
			array_unique(
				array_intersect( array_map( 'sanitize_text_field', $locales ), $known )
			)
		);

		if ( array() === $clean ) {
			delete_user_meta( $user_id, self::META_KEY );

			return;
		}

		update_user_meta( $user_id, self::META_KEY, $clean );
	}

	/**
	 * Si un usuario puede traducir a un idioma.
	 *
	 * @param int    $user_id  Identificador del usuario.
	 * @param string $language Locale.
	 */
	public function allows( int $user_id, string $language ): bool {
		if ( user_can( $user_id, Capabilities::MANAGE_SETTINGS ) ) {
			return true;
		}

		$assigned = $this->assigned( $user_id );

		return array() === $assigned || in_array( $language, $assigned, true );
	}

	/**
	 * Idiomas que un usuario puede traducir.
	 *
	 * @param int $user_id Identificador del usuario.
	 * @return Language[]
	 */
	public function for_user( int $user_id ): array {
		$assigned = $this->assigned( $user_id );

		if ( array() === $assigned || user_can( $user_id, Capabilities::MANAGE_SETTINGS ) ) {
			return $this->languages->translatable();
		}

		return array_values(
			array_filter(
				$this->languages->translatable(),
				static fn ( Language $language ): bool => in_array( $language->locale, $assigned, true )
			)
		);
	}
}
