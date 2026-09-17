<?php
/**
 * Análisis de los bytes de un token de etiqueta.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Localiza intervalos de bytes dentro del texto en crudo de una etiqueta.
 *
 * WP_HTML_Tag_Processor sabe decir qué atributos tiene una etiqueta y cuál es
 * su valor decodificado, pero no en qué bytes está ese valor. Como toda la
 * sustitución del plugin es un empalme sobre el original (ver ADR-01), hace
 * falta esa posición, y este analizador la calcula.
 *
 * Trabaja siempre sobre desplazamientos RELATIVOS al inicio del token.
 */
final class TagScanner {

	/** Caracteres que HTML considera espacio en blanco dentro de una etiqueta. */
	private const WHITESPACE = " \t\f\r\n";

	/**
	 * Intervalos de los valores de los atributos de una etiqueta.
	 *
	 * El intervalo INCLUYE las comillas cuando las hay. Quien sustituye escribe
	 * siempre un valor entre comillas dobles, de modo que un atributo sin
	 * comillas en el original queda correctamente entrecomillado tras traducir.
	 *
	 * Ante un atributo duplicado gana el primero, igual que hacen los
	 * navegadores y la propia HTML API.
	 *
	 * @param string $raw Texto en crudo del token de etiqueta.
	 * @return array<string, array{0:int, 1:int}> Nombre en minúsculas => [inicio, longitud].
	 */
	public function attribute_spans( string $raw ): array {
		$spans  = array();
		$length = strlen( $raw );
		$at     = $this->skip_tag_name( $raw );

		while ( $at < $length ) {
			$at = $this->skip_whitespace( $raw, $at );

			if ( $at >= $length || '>' === $raw[ $at ] ) {
				break;
			}

			if ( '/' === $raw[ $at ] ) {
				++$at;
				continue;
			}

			$name_start = $at;

			while ( $at < $length && false === strpbrk( $raw[ $at ], self::WHITESPACE . '=/>' ) ) {
				++$at;
			}

			if ( $at === $name_start ) {
				// Carácter que no puede iniciar un nombre de atributo; avanzar
				// para no quedarse en bucle ante marcado malformado.
				++$at;
				continue;
			}

			$name = strtolower( substr( $raw, $name_start, $at - $name_start ) );
			$at   = $this->skip_whitespace( $raw, $at );

			if ( $at >= $length || '=' !== $raw[ $at ] ) {
				// Atributo booleano: sin valor que traducir.
				continue;
			}

			++$at;
			$at = $this->skip_whitespace( $raw, $at );

			if ( $at >= $length ) {
				break;
			}

			$value_start = $at;
			$quote       = $raw[ $at ];

			if ( '"' === $quote || "'" === $quote ) {
				$closing = strpos( $raw, $quote, $at + 1 );

				if ( false === $closing ) {
					break;
				}

				$at = $closing + 1;
			} else {
				while ( $at < $length && false === strpbrk( $raw[ $at ], self::WHITESPACE . '>' ) ) {
					++$at;
				}
			}

			if ( ! isset( $spans[ $name ] ) ) {
				$spans[ $name ] = array( $value_start, $at - $value_start );
			}
		}

		return $spans;
	}

	/**
	 * Desplazamiento del primer byte posterior al `>` de la etiqueta de apertura.
	 *
	 * @param string $raw Texto en crudo del token de etiqueta.
	 * @return int|null Null si la etiqueta no está cerrada.
	 */
	public function opening_tag_end( string $raw ): ?int {
		$length = strlen( $raw );
		$at     = $this->skip_tag_name( $raw );

		while ( $at < $length ) {
			$character = $raw[ $at ];

			if ( '>' === $character ) {
				return $at + 1;
			}

			if ( '"' === $character || "'" === $character ) {
				$closing = strpos( $raw, $character, $at + 1 );

				if ( false === $closing ) {
					return null;
				}

				$at = $closing + 1;
				continue;
			}

			++$at;
		}

		return null;
	}

	/**
	 * Intervalo del contenido interior de un elemento entregado como token único
	 * (TITLE, TEXTAREA y demás elementos RCDATA/RAWTEXT).
	 *
	 * @param string $raw Texto en crudo del token completo, con su cierre.
	 * @param string $tag Nombre de etiqueta en mayúsculas.
	 * @return array{0:int, 1:int}|null [inicio, longitud] relativos al token, o
	 *                                  null si el elemento no está cerrado.
	 */
	public function inner_span( string $raw, string $tag ): ?array {
		$start = $this->opening_tag_end( $raw );

		if ( null === $start ) {
			return null;
		}

		$closer = '</' . strtolower( $tag );
		$end    = strripos( $raw, $closer );

		if ( false === $end || $end < $start ) {
			return null;
		}

		return array( $start, $end - $start );
	}

	/**
	 * Salta `<`, la barra de cierre opcional y el nombre de la etiqueta.
	 *
	 * @param string $raw Texto en crudo del token.
	 */
	private function skip_tag_name( string $raw ): int {
		$length = strlen( $raw );
		$at     = 0;

		if ( $at < $length && '<' === $raw[ $at ] ) {
			++$at;
		}

		if ( $at < $length && '/' === $raw[ $at ] ) {
			++$at;
		}

		while ( $at < $length && false === strpbrk( $raw[ $at ], self::WHITESPACE . '/>' ) ) {
			++$at;
		}

		return $at;
	}

	/**
	 * Salta espacio en blanco.
	 *
	 * @param string $raw Texto en crudo.
	 * @param int    $at  Posición inicial.
	 */
	private function skip_whitespace( string $raw, int $at ): int {
		$length = strlen( $raw );

		while ( $at < $length && false !== strpbrk( $raw[ $at ], self::WHITESPACE ) ) {
			++$at;
		}

		return $at;
	}
}
