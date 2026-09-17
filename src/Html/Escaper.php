<?php
/**
 * Escapado de traducciones según su contexto en el HTML.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Translation\StringType;

/**
 * Convierte una traducción en el texto exacto que se escribe en el documento.
 *
 * La HTML API entrega el texto y los atributos DECODIFICADOS, así que al
 * escribir de vuelta hay que volver a codificar lo mínimo imprescindible. Se
 * escapan solo los caracteres que cambiarían la estructura del documento: no se
 * convierte el resto a entidades, de modo que el UTF-8 se conserva tal cual.
 */
final class Escaper {

	/**
	 * Escapa una traducción para el contexto de su unidad.
	 *
	 * @param StringType $type        Tipo de la unidad.
	 * @param string     $translation Traducción.
	 * @return string Texto listo para empalmar en el documento.
	 */
	public function escape( StringType $type, string $translation ): string {
		return match ( $type ) {
			// El bloque ES HTML: se escribe tal cual. La defensa aquí no es el
			// escapado sino wp_kses en la capa de almacenamiento (ADR-12).
			StringType::Block => $translation,

			// El intervalo de un atributo incluye sus comillas, si las tenía.
			// Se escribe siempre entrecomillado, de modo que un atributo sin
			// comillas en el original queda bien formado tras traducir.
			StringType::Attribute, StringType::Meta => '"' . str_replace(
				array( '&', '<', '"' ),
				array( '&amp;', '&lt;', '&quot;' ),
				$translation
			) . '"',

			default => str_replace( array( '&', '<' ), array( '&amp;', '&lt;' ), $translation ),
		};
	}
}
