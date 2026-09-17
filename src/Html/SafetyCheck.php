<?php
/**
 * Comprobación de integridad del documento tras sustituir.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

/**
 * Última red de seguridad antes de servir una página traducida.
 *
 * Ante cualquier duda se sirve el original: una página sin traducir es un
 * inconveniente, una página con el JavaScript roto es una avería.
 */
final class SafetyCheck {

	/**
	 * Si el documento resultante conserva la estructura crítica del original.
	 *
	 * @param string $original Documento antes de sustituir.
	 * @param string $result   Documento después de sustituir.
	 */
	public function passes( string $original, string $result ): bool {
		if ( '' === $result ) {
			return false;
		}

		if ( $this->doctype( $original ) !== $this->doctype( $result ) ) {
			return false;
		}

		foreach ( array( 'script', 'style' ) as $tag ) {
			if ( substr_count( strtolower( $original ), '<' . $tag ) !== substr_count( strtolower( $result ), '<' . $tag ) ) {
				return false;
			}

			if ( substr_count( strtolower( $original ), '</' . $tag ) !== substr_count( strtolower( $result ), '</' . $tag ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Doctype del documento, normalizado.
	 *
	 * @param string $html Documento.
	 */
	private function doctype( string $html ): string {
		$prefix = ltrim( substr( $html, 0, 200 ) );

		if ( 1 !== preg_match( '/^<!DOCTYPE[^>]*>/i', $prefix, $matches ) ) {
			return '';
		}

		return strtolower( $matches[0] );
	}
}
