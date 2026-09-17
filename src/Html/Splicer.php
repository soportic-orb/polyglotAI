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
 * Se aplican de derecha a izquierda para que los desplazamientos calculados
 * durante el barrido sigan siendo válidos: si se aplicaran de izquierda a
 * derecha, la primera sustitución de longitud distinta desplazaría todas las
 * posteriores.
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
			static fn( Replacement $a, Replacement $b ): int => $b->start <=> $a->start
		);

		$length       = strlen( $subject );
		$previous_end = PHP_INT_MAX;

		foreach ( $replacements as $replacement ) {
			if ( $replacement->start < 0 || $replacement->end() > $length ) {
				throw new InvalidArgumentException(
					sprintf( 'Sustitución fuera de rango: %d..%d sobre %d bytes.', $replacement->start, $replacement->end(), $length )
				);
			}

			if ( $replacement->end() > $previous_end ) {
				throw new InvalidArgumentException(
					sprintf( 'Sustituciones solapadas en el byte %d.', $replacement->start )
				);
			}

			$subject      = substr_replace( $subject, $replacement->text, $replacement->start, $replacement->length );
			$previous_end = $replacement->start;
		}

		return $subject;
	}
}
