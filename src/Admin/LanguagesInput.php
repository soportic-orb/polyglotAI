<?php
/**
 * Lectura de los idiomas enviados desde los ajustes.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

/**
 * Convierte lo que llega del formulario de idiomas en algo guardable.
 *
 * Está aparte de la pantalla porque es la única parte con reglas de verdad, y
 * esas reglas protegen el enrutado (ADR-09): **dos idiomas con el mismo
 * segmento de URL harían que uno de los dos no se pudiera alcanzar nunca**, y
 * un segmento que choque con el del idioma por defecto se comería la portada.
 * Las filas que incumplen eso no se guardan a medias: se descartan enteras.
 */
final class LanguagesInput {

	/** Tratamientos admitidos. */
	public const FORMALITIES = array( 'neutral', 'informal', 'formal' );

	/**
	 * Normaliza un código de idioma a la forma de WordPress.
	 *
	 * Un administrador escribe «es-es», «ES_es» o «pt-br» con la misma
	 * naturalidad con que escribe «es_ES». Todas significan lo mismo y todas
	 * acaban en la forma canónica en vez de convertirse en idiomas distintos.
	 *
	 * @param string $raw Lo que se ha escrito.
	 */
	public static function locale( string $raw ): string {
		$clean = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', trim( $raw ) );

		if ( '' === $clean ) {
			return '';
		}

		$parts    = preg_split( '/[_-]/', $clean );
		$parts    = false === $parts ? array() : array_values( array_filter( $parts, static fn( string $p ): bool => '' !== $p ) );
		$language = strtolower( (string) ( $parts[0] ?? '' ) );

		if ( '' === $language ) {
			return '';
		}

		return isset( $parts[1] ) ? $language . '_' . strtoupper( $parts[1] ) : $language;
	}

	/**
	 * El idioma por defecto que se ha enviado.
	 *
	 * @param mixed                $row      Fila del formulario.
	 * @param array<string, mixed> $previous El que había, por si llega incompleto.
	 * @return array<string, mixed>
	 */
	public static function parse_default( $row, array $previous ): array {
		$row = is_array( $row ) ? $row : array();

		$locale = self::locale( (string) ( $row['locale'] ?? '' ) );

		if ( '' === $locale ) {
			$locale = (string) ( $previous['locale'] ?? 'es_ES' );
		}

		$slug = self::slug( (string) ( $row['slug'] ?? '' ), $locale, (string) ( $previous['slug'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $row['label'] ?? '' ) );

		return array(
			'locale' => $locale,
			'slug'   => $slug,
			'label'  => '' === $name ? $locale : $name,
		);
	}

	/**
	 * Los idiomas adicionales que se han enviado.
	 *
	 * @param mixed                            $rows         Filas del formulario.
	 * @param array<int, array<string, mixed>> $stored      Los que ya había.
	 * @param string                           $default_slug Segmento del idioma por defecto.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( $rows, array $stored, string $default_slug ): array {
		$rows = is_array( $rows ) ? $rows : array();

		$kept = array();

		// El del idioma por defecto ya está ocupado: si alguien lo repite, esa
		// fila se pierde en vez de dejar la portada inalcanzable.
		$taken = '' === $default_slug ? array() : array( $default_slug => true );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$locale = self::locale( (string) ( $row['locale'] ?? '' ) );

			if ( '' === $locale ) {
				continue;
			}

			$previous = self::find( $stored, $locale );
			$slug     = self::slug( (string) ( $row['slug'] ?? '' ), $locale, (string) ( $previous['slug'] ?? '' ), $taken );

			if ( '' === $slug || isset( $taken[ $slug ] ) ) {
				continue;
			}

			$taken[ $slug ] = true;

			$name  = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$treat = sanitize_key( (string) ( $row['formality'] ?? 'neutral' ) );

			$kept[] = array(
				'locale'    => $locale,
				'slug'      => $slug,
				'label'     => '' === $name ? $locale : $name,

				// La bandera no se edita en esta pantalla; se conserva la que
				// hubiera para no borrarla sin querer al guardar.
				'flag'      => (string) ( $previous['flag'] ?? '' ),
				'rtl'       => ! empty( $row['rtl'] ),
				'active'    => true,
				'published' => ! empty( $row['published'] ),
				'formality' => in_array( $treat, self::FORMALITIES, true ) ? $treat : 'neutral',
			);
		}

		return $kept;
	}

	/**
	 * Segmento de URL de un idioma.
	 *
	 * Manda lo escrito; si no hay nada, **el que ya tenía**, y solo como último
	 * recurso se deriva del código. El orden importa: derivarlo antes de mirar
	 * el anterior le cambiaba la URL a un idioma ya publicado —de `/es/` a
	 * `/es-es/`— por dejar el campo en blanco, y con ella todos sus enlaces.
	 *
	 * **Al derivarlo se usa el código corto**: `en_US` da `/en/`, no `/en-us/`,
	 * que es lo que espera cualquiera y lo que hace todo el mundo. La variante
	 * completa solo aparece cuando el corto ya está cogido, que es justo el caso
	 * en que hace falta distinguir: con `pt_BR` y `pt_PT` a la vez, el primero
	 * se queda `/pt/` y el segundo pasa a `/pt-pt/`.
	 *
	 * @param string              $raw      Lo que se ha escrito.
	 * @param string              $locale   Código ya normalizado.
	 * @param string              $previous El que tenía guardado, si lo tenía.
	 * @param array<string, bool> $taken    Segmentos ya ocupados.
	 */
	private static function slug( string $raw, string $locale, string $previous = '', array $taken = array() ): string {
		$slug = sanitize_title( $raw );

		if ( '' !== $slug ) {
			return $slug;
		}

		$kept = sanitize_title( $previous );

		if ( '' !== $kept ) {
			return $kept;
		}

		$short = sanitize_title( (string) strtok( str_replace( '-', '_', $locale ), '_' ) );

		if ( '' !== $short && ! isset( $taken[ $short ] ) ) {
			return $short;
		}

		return sanitize_title( str_replace( '_', '-', $locale ) );
	}

	/**
	 * El idioma guardado con ese código, si lo hay.
	 *
	 * @param array<int, array<string, mixed>> $stored Idiomas guardados.
	 * @param string                           $locale Código.
	 * @return array<string, mixed>
	 */
	private static function find( array $stored, string $locale ): array {
		foreach ( $stored as $language ) {
			if ( is_array( $language ) && (string) ( $language['locale'] ?? '' ) === $locale ) {
				return $language;
			}
		}

		return array();
	}
}
