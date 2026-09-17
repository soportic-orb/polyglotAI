<?php
/**
 * URLs de la cabecera que emiten los plugins de SEO.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use PolyglotAI\Html\OffsetTagProcessor;
use PolyglotAI\Html\Replacement;
use PolyglotAI\Html\Splicer;
use PolyglotAI\Html\TagScanner;
use PolyglotAI\Languages\Language;
use PolyglotAI\Routing\InternalUrl;

/**
 * Pone el prefijo de idioma en las URLs de la cabecera.
 *
 * Yoast, Rank Math, SEOPress y All in One SEO emiten su propia URL canónica,
 * su og:url y su paginación. Todas salen de get_permalink(), así que ya llevan
 * el slug traducido; lo que les falta es el prefijo de idioma, porque ni
 * home_url() ni ellos saben nada de él.
 *
 * **Se corrige sobre el HTML final y no con los filtros de cada plugin.** Es
 * una decisión, no una comodidad (ADR-15): son cuatro plugins con cuatro juegos
 * de hooks que cambian entre versiones mayores, y cualquiera de los cuatro
 * puede no estar instalado. Una pasada sobre la salida funciona con los cuatro,
 * con sus versiones futuras y con el sexto plugin de SEO que aparezca, sin
 * tener que reconocerlo.
 *
 * Lo que **no** se toca es `rel="alternate"`: ahí viven nuestros propios
 * hreflang, que apuntan a propósito a otros idiomas, y también los feeds RSS.
 * Convertirlos al idioma en curso sería estropear justo lo que acabamos de
 * emitir bien.
 */
final class HeadUrls {

	/**
	 * Valores de rel cuya URL es la de esta página en este idioma.
	 *
	 * @var array<string, true>
	 */
	private const REWRITABLE_REL = array(
		'canonical' => true,
		'shortlink' => true,
		'prev'      => true,
		'next'      => true,
	);

	/**
	 * Metaetiquetas cuyo contenido es la URL de esta página.
	 *
	 * @var array<string, true>
	 */
	private const URL_META = array(
		'og:url'      => true,
		'twitter:url' => true,
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
	 * Reescribe las URLs de la cabecera.
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

			if ( 'LINK' !== $tag && 'META' !== $tag ) {
				continue;
			}

			$attribute = 'LINK' === $tag ? 'href' : 'content';

			if ( ! $this->is_target( $processor, $tag ) ) {
				continue;
			}

			$value = $processor->get_attribute( $attribute );

			if ( ! is_string( $value ) ) {
				continue;
			}

			$converted = $this->urls->convert( $value, $language );

			if ( null === $converted ) {
				continue;
			}

			$span = $processor->token_span();

			if ( null === $span ) {
				continue;
			}

			$spans = $this->scanner->attribute_spans( substr( $html, $span[0], $span[1] ) );

			if ( ! isset( $spans[ $attribute ] ) ) {
				continue;
			}

			$replacements[] = new Replacement(
				$span[0] + $spans[ $attribute ][0],
				$spans[ $attribute ][1],
				'"' . esc_url( $converted ) . '"'
			);
		}

		return array() === $replacements ? $html : $this->splicer->apply( $html, $replacements );
	}

	/**
	 * Si la etiqueta es una de las que llevan la URL de esta página.
	 *
	 * @param OffsetTagProcessor $processor Analizador situado en la etiqueta.
	 * @param string             $tag       Nombre de la etiqueta.
	 */
	private function is_target( OffsetTagProcessor $processor, string $tag ): bool {
		if ( 'LINK' === $tag ) {
			$rel = $processor->get_attribute( 'rel' );

			return is_string( $rel ) && isset( self::REWRITABLE_REL[ strtolower( trim( $rel ) ) ] );
		}

		foreach ( array( 'property', 'name' ) as $key ) {
			$value = $processor->get_attribute( $key );

			if ( is_string( $value ) && isset( self::URL_META[ strtolower( trim( $value ) ) ] ) ) {
				return true;
			}
		}

		return false;
	}
}
