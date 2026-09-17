<?php
/**
 * Normalización de cadenas antes de calcular su hash.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Normaliza el texto de origen para que variantes irrelevantes de espaciado no
 * generen entradas distintas en el diccionario.
 *
 * Sin esto, "  Hola\n" y "Hola" serían dos cadenas diferentes y el diccionario
 * se llenaría de duplicados que hay que traducir (y pagar) por separado.
 */
final class Normalizer {

	/**
	 * Espacios que HTML trata como colapsables, más el espacio duro, que la
	 * HTML API ya ha decodificado a U+00A0 cuando llega aquí.
	 */
	private const WHITESPACE = "/[\x{0009}\x{000A}\x{000C}\x{000D}\x{0020}\x{00A0}\x{2028}\x{2029}]+/u";

	/**
	 * Colapsa el espacio en blanco y recorta los extremos, conservando intacto
	 * cualquier HTML interior.
	 *
	 * @param string $value Cadena original.
	 * @return string Cadena normalizada.
	 */
	public function normalize( string $value ): string {
		$collapsed = preg_replace( self::WHITESPACE, ' ', $value );

		if ( null === $collapsed ) {
			// preg_replace solo devuelve null ante UTF-8 inválido; en ese caso
			// se trabaja con el valor original antes que perder la cadena.
			$collapsed = $value;
		}

		return trim( $collapsed );
	}

	/**
	 * Si tras normalizar no queda nada traducible.
	 *
	 * Descarta cadenas vacías, puramente numéricas, de puntuación o de un solo
	 * carácter: no aportan nada y gastarían presupuesto de API.
	 *
	 * @param string $value Cadena ya normalizada.
	 */
	public function is_translatable( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		// Debe contener al menos una letra en algún alfabeto.
		if ( 1 !== preg_match( '/\p{L}/u', $value ) ) {
			return false;
		}

		// Una sola letra suelta no es una cadena traducible.
		return mb_strlen( $value, 'UTF-8' ) > 1;
	}
}
