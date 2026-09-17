<?php
/**
 * Aplicación de sustituciones sobre una cadena.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use InvalidArgumentException;

/**
 * Empalma sustituciones en el HTML original.
 *
 * El original no se toca: se recorre de izquierda a derecha copiando los trozos
 * que quedan entre sustitución y sustitución, y el resultado se monta de una vez
 * al final. Así los desplazamientos calculados durante el barrido siguen siendo
 * válidos hasta el último momento, porque nunca hay una cadena a medio sustituir
 * sobre la que se hayan movido.
 *
 * **Esto costaba antes O(sustituciones × documento).** La primera versión
 * aplicaba `substr_replace()` sobre el propio documento de derecha a izquierda,
 * que también mantiene válidos los desplazamientos, pero copia el documento
 * ENTERO en cada sustitución. En una página de 128 KB con 1552 sustituciones eso
 * son 200 MB de copias; en una de 254 KB, casi 800 MB, y por eso el coste de
 * empalmar crecía más deprisa que el tamaño de la página —el problema que el
 * ADR-08 daba por resuelto y que solo se veía en páginas grandes de Divi o
 * Elementor. Montarlo por trozos copia cada byte una vez.
 */
final class Splicer {

	/**
	 * Aplica un conjunto de sustituciones.
	 *
	 * @param string        $subject      HTML original.
	 * @param Replacement[] $replacements Sustituciones, en cualquier orden.
	 * @return string HTML resultante.
	 *
	 * @throws InvalidArgumentException Si dos sustituciones se solapan o alguna
	 *                                  cae fuera de la cadena.
	 */
	public function apply( string $subject, array $replacements ): string {
		if ( array() === $replacements ) {
			return $subject;
		}

		usort(
			$replacements,
			static fn( Replacement $a, Replacement $b ): int => $a->start <=> $b->start
		);

		$length = strlen( $subject );
		$pieces = array();
		$cursor = 0;

		foreach ( $replacements as $replacement ) {
			if ( $replacement->start < 0 || $replacement->end() > $length ) {
				throw new InvalidArgumentException(
					sprintf( 'Sustitución fuera de rango: %d..%d sobre %d bytes.', $replacement->start, $replacement->end(), $length )
				);
			}

			if ( $replacement->start < $cursor ) {
				throw new InvalidArgumentException(
					sprintf( 'Sustituciones solapadas en el byte %d.', $replacement->start )
				);
			}

			if ( $replacement->start > $cursor ) {
				$pieces[] = substr( $subject, $cursor, $replacement->start - $cursor );
			}

			$pieces[] = $replacement->text;
			$cursor   = $replacement->end();
		}

		if ( $cursor < $length ) {
			$pieces[] = substr( $subject, $cursor );
		}

		return implode( '', $pieces );
	}
}
