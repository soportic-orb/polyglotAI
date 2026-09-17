<?php
/**
 * Lectura de la cabecera Accept-Language.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Detection;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Elige el idioma del sitio que mejor encaja con el del navegador.
 *
 * Es la única fuente de detección de la v1: nada de GeoIP ni de bases de datos
 * que descargar. El país no es el idioma —en Bélgica se habla neerlandés y
 * francés, y un español en Berlín sigue queriendo leer en español—, así que la
 * cabecera que el propio visitante envía es mejor señal que su dirección IP.
 *
 * El emparejamiento va de lo más preciso a lo menos:
 *
 * 1. Locale exacto: `pt-BR` con un sitio en `pt_BR`.
 * 2. Mismo idioma base: `pt-PT` con un sitio que solo tiene `pt_BR`, porque un
 *    portugués de Portugal lee mejor el portugués de Brasil que el español.
 *
 * Se respeta el factor `q`: un navegador que pide `de;q=0.9, en;q=1.0` prefiere
 * el inglés aunque el alemán aparezca antes.
 */
final class BrowserLanguage {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 */
	public function __construct( private readonly LanguageRegistry $languages ) {}

	/**
	 * Idioma preferido del visitante, o null si ninguno encaja.
	 *
	 * @param string $header Contenido de Accept-Language.
	 */
	public function preferred( string $header ): ?Language {
		$candidates = $this->parse( $header );

		if ( array() === $candidates ) {
			return null;
		}

		$available = $this->languages->visible();

		// Primera pasada: locale exacto. Se agota toda la lista del navegador
		// antes de conformarse con una coincidencia parcial, porque un «es-MX»
		// exacto más abajo es mejor que un «es» aproximado más arriba.
		foreach ( $candidates as $candidate ) {
			foreach ( $available as $language ) {
				if ( $this->normalize( $language->locale ) === $candidate ) {
					return $language;
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			$base = (string) strtok( $candidate, '-' );

			foreach ( $available as $language ) {
				if ( $language->code() === $base ) {
					return $language;
				}
			}
		}

		return null;
	}

	/**
	 * Idiomas de la cabecera, del más preferido al menos.
	 *
	 * @param string $header Contenido de Accept-Language.
	 * @return string[] Etiquetas en minúsculas con guion, p. ej. «pt-br».
	 */
	public function parse( string $header ): array {
		$header = trim( $header );

		if ( '' === $header ) {
			return array();
		}

		$entries = array();

		foreach ( explode( ',', $header ) as $position => $part ) {
			$pieces = explode( ';', $part );
			$tag    = $this->normalize( trim( (string) array_shift( $pieces ) ) );

			if ( '' === $tag || '*' === $tag ) {
				continue;
			}

			$quality = 1.0;

			foreach ( $pieces as $piece ) {
				$piece = trim( $piece );

				if ( ! str_starts_with( strtolower( $piece ), 'q=' ) ) {
					continue;
				}

				$value = substr( $piece, 2 );

				// Una q que no es un número es una cabecera mal formada, no una
				// preferencia: el navegador ha nombrado ese idioma y se queda.
				if ( is_numeric( $value ) ) {
					$quality = (float) $value;
				}
			}

			// q=0 significa «esto no lo quiero», no «me da igual».
			if ( $quality <= 0 ) {
				continue;
			}

			$entries[] = array(
				'tag'      => $tag,
				'quality'  => $quality,
				'position' => (int) $position,
			);
		}

		// El orden de la cabecera desempata: a igual q, manda quien va antes.
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['quality'] === $b['quality']
					? $a['position'] <=> $b['position']
					: $b['quality'] <=> $a['quality'];
			}
		);

		return array_values( array_unique( array_column( $entries, 'tag' ) ) );
	}

	/**
	 * Deja una etiqueta de idioma en minúsculas y con guion.
	 *
	 * @param string $tag Etiqueta.
	 */
	private function normalize( string $tag ): string {
		return strtolower( str_replace( '_', '-', $tag ) );
	}
}
