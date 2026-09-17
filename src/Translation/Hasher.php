<?php
/**
 * Cálculo del hash que identifica una cadena en el diccionario.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Genera la clave estable de una cadena.
 *
 * El hash incluye el tipo, el contexto y el dominio además del texto: la misma
 * palabra como texto de un botón y como slug son cadenas distintas y pueden
 * traducirse de forma distinta.
 */
final class Hasher {

	/** Separador que no puede aparecer en el texto (unit separator). */
	private const SEP = "\x1f";

	/**
	 * Constructor.
	 *
	 * @param Normalizer $normalizer Normalizador de cadenas.
	 */
	public function __construct( private readonly Normalizer $normalizer ) {}

	/**
	 * Calcula el hash de una cadena.
	 *
	 * @param string      $original Texto original, sin normalizar.
	 * @param StringType  $type     Tipo de cadena.
	 * @param string|null $context  Contexto (p. ej. el contexto de gettext).
	 * @param string|null $domain   Dominio de gettext, si aplica.
	 * @return string Hash de 32 caracteres hexadecimales.
	 */
	public function hash( string $original, StringType $type, ?string $context = null, ?string $domain = null ): string {
		$payload = implode(
			self::SEP,
			array(
				$this->normalizer->normalize( $original ),
				$type->value,
				$context ?? '',
				$domain ?? '',
			)
		);

		return md5( $payload );
	}

	/**
	 * Hash solo del texto normalizado, sin tipo ni contexto.
	 *
	 * Es lo que permite reconocer que «Añadir al carrito» como texto de un
	 * botón y como atributo `title` son la misma frase, aunque su hash completo
	 * sea distinto a propósito. Lo usa la memoria de traducción.
	 *
	 * @param string $original Texto original.
	 * @return string Hash de 32 caracteres hexadecimales.
	 */
	public function text_hash( string $original ): string {
		return md5( $this->normalizer->normalize( $original ) );
	}
}
