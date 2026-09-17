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
	 * Constructor.
	 *
	 * @param InternalUrl $urls    Conversor de URLs internas.
	 * @param TagScanner  $scanner Analizador de etiquetas.
	 * @param Splicer     $splicer Aplicador de sustituciones.
	 */
	public function __construct(
		private readonly InternalUrl $urls,
		private readonly TagScanner $scanner,
		private readonly Splicer $splicer
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

				$rewritten = $this->urls->convert( $value, $language );

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
}
