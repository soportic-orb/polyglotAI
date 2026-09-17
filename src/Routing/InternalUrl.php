<?php
/**
 * Conversión de URLs internas a un idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Languages\Language;

/**
 * Decide si una URL es del sitio y la devuelve en otro idioma.
 *
 * Vive aparte porque la necesitan dos pasadas distintas sobre la salida: la de
 * los enlaces navegables (LinkRewriter) y la de las URLs de la cabecera que
 * emiten los plugins de SEO (Seo\HeadUrls). Tener dos copias de «qué es un
 * enlace interno» habría acabado en dos criterios distintos.
 */
final class InternalUrl {

	/**
	 * Esquemas que no son navegación dentro del sitio.
	 *
	 * @var string[]
	 */
	private const SCHEMES = array( 'mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'whatsapp:' );

	/**
	 * Rutas que nunca llevan prefijo de idioma.
	 *
	 * @var string[]
	 */
	private const SKIP_PATHS = array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-content', '/wp-includes', '/xmlrpc.php', '/feed' );

	/**
	 * Constructor.
	 *
	 * @param UrlConverter $converter Conversor de rutas.
	 * @param string       $host      Host del sitio.
	 */
	public function __construct(
		private readonly UrlConverter $converter,
		private readonly string $host
	) {}

	/**
	 * Convierte una URL al idioma dado, o null si no hay que tocarla.
	 *
	 * @param string   $url      URL original.
	 * @param Language $language Idioma de destino.
	 */
	public function convert( string $url, Language $language ): ?string {
		$url = trim( $url );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return null;
		}

		foreach ( self::SCHEMES as $scheme ) {
			if ( str_starts_with( strtolower( $url ), $scheme ) ) {
				return null;
			}
		}

		$parts = wp_parse_url( $url );

		if ( false === $parts || null === $parts ) {
			return null;
		}

		// Enlace externo.
		if ( isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( $this->host ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '/' );

		if ( ! str_starts_with( $path, '/' ) ) {
			// URL relativa al documento: el navegador ya la resuelve dentro del
			// idioma en curso, así que tocarla la rompería.
			return null;
		}

		foreach ( self::SKIP_PATHS as $skip ) {
			if ( str_starts_with( $path, $skip ) ) {
				return null;
			}
		}

		$converted = $this->converter->convert( $path, $language );

		if ( $converted === $path ) {
			return null;
		}

		$rebuilt = $converted;

		if ( isset( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}

		if ( isset( $parts['host'] ) ) {
			$rebuilt = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $rebuilt;
		}

		return $rebuilt;
	}
}
