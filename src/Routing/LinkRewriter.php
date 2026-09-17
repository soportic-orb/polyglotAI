<?php
/**
 * Reescritura de enlaces internos al idioma en curso.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Html\OffsetTagProcessor;
use PolyglotAI\Html\Replacement;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Languages\Language;

/**
 * Añade el segmento de idioma a los enlaces internos del documento.
 *
 * Se hace sobre el HTML renderizado, no filtrando cada función de WordPress que
 * genera URLs: así funciona también con los enlaces que escriben los temas y los
 * constructores a mano.
 *
 * Es una pasada INDEPENDIENTE, posterior a la de traducción. Hacerlo en la misma
 * pasada provocaría sustituciones solapadas cuando un enlace vive dentro de una
 * unidad de bloque, y el empalme rechaza los solapamientos.
 */
final class LinkRewriter {

	/**
	 * Atributos que contienen una URL navegable.
	 *
	 * No incluye src: apunta a recursos (imágenes, scripts), que no tienen versión
	 * por idioma y romperían si se les añadiera el prefijo.
	 *
	 * @var array<string, string[]>
	 */
	private const ATTRIBUTES = array(
		'A'    => array( 'href' ),
		'AREA' => array( 'href' ),
		'FORM' => array( 'action' ),
		'LINK' => array( 'href' ),
	);

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
	 * @param TagScanner   $scanner   Analizador de etiquetas.
	 * @param Splicer      $splicer   Aplicador de sustituciones.
	 * @param string       $host      Host del sitio.
	 */
	public function __construct(
		private readonly UrlConverter $converter,
		private readonly TagScanner $scanner,
		private readonly Splicer $splicer,
		private readonly string $host
	) {}

	/**
	 * Reescribe los enlaces internos del documento.
	 *
	 * @param string   $html     Documento.
	 * @param Language $language Idioma de destino.
	 */
	public function rewrite( string $html, Language $language ): string {
		$processor    = new OffsetTagProcessor( $html );
		$replacements = array();

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
				continue;
			}

			$tag = (string) $processor->get_tag();

			if ( ! isset( self::ATTRIBUTES[ $tag ] ) ) {
				continue;
			}

			// Los <link rel="canonical"> y "alternate" los gestiona el SEO Pack:
			// aquí solo interesan los enlaces navegables.
			if ( 'LINK' === $tag ) {
				continue;
			}

			$span = $processor->token_span();

			if ( null === $span ) {
				continue;
			}

			foreach ( self::ATTRIBUTES[ $tag ] as $attribute ) {
				$value = $processor->get_attribute( $attribute );

				if ( ! is_string( $value ) ) {
					continue;
				}

				$rewritten = $this->convert( $value, $language );

				if ( null === $rewritten ) {
					continue;
				}

				$spans = $this->scanner->attribute_spans( substr( $html, $span[0], $span[1] ) );

				if ( ! isset( $spans[ $attribute ] ) ) {
					continue;
				}

				$replacements[] = new Replacement(
					$span[0] + $spans[ $attribute ][0],
					$spans[ $attribute ][1],
					'"' . esc_url( $rewritten ) . '"'
				);
			}
		}

		if ( array() === $replacements ) {
			return $html;
		}

		return $this->splicer->apply( $html, $replacements );
	}

	/**
	 * Convierte una URL al idioma dado, o null si no hay que tocarla.
	 *
	 * @param string   $url      URL original.
	 * @param Language $language Idioma de destino.
	 */
	private function convert( string $url, Language $language ): ?string {
		$url = trim( $url );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return null;
		}

		foreach ( array( 'mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'whatsapp:' ) as $scheme ) {
			if ( str_starts_with( strtolower( $url ), $scheme ) ) {
				return null;
			}
		}

		$parts = wp_parse_url( $url );

		if ( false === $parts ) {
			return null;
		}

		// Enlace externo.
		if ( isset( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $this->host ) ) {
			return null;
		}

		$path = $parts['path'] ?? '/';

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
