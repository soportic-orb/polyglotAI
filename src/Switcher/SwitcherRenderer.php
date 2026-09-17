<?php
/**
 * Pintado del selector de idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;

/**
 * Construye el selector de idioma, en el formato que se le pida.
 *
 * Es el único sitio donde se decide a qué URL lleva cada idioma. El shortcode,
 * el bloque, el elemento de menú y el selector flotante son cuatro envoltorios
 * de esto: si cada uno hubiera calculado sus enlaces, cada uno habría tenido su
 * propia versión del error que este renderizador existe para evitar.
 *
 * **Cada idioma lleva sus propios slugs.** Estando en /ca/contacte/, el enlace
 * al inglés es /en/contact-us/ y no /en/contacte/: hay que volver al slug
 * original y traducir desde ahí, igual que hacen los hreflang.
 */
final class SwitcherRenderer {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param RequestContext   $request   Contexto de la petición.
	 * @param UrlConverter     $converter Conversor de rutas.
	 * @param SlugResolver     $slugs     Traductor de slugs.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly RequestContext $request,
		private readonly UrlConverter $converter,
		private readonly SlugResolver $slugs
	) {}

	/**
	 * Idiomas del selector con su URL en la página actual.
	 *
	 * @param bool $hide_current Si se omite el idioma en curso.
	 * @return array<int, array{language: Language, url: string, current: bool}>
	 */
	public function items( bool $hide_current = false ): array {
		$current = $this->request->language();

		// Un traductor ve también los idiomas en preparación para poder
		// revisarlos antes de publicarlos.
		$languages = $this->languages->visible( current_user_can( Capabilities::TRANSLATE ) );

		if ( count( $languages ) < 2 ) {
			return array();
		}

		$paths = $this->slugs->paths_by_language(
			$this->converter->strip( $this->request->path() ),
			$current->locale,
			array_map( static fn ( Language $language ): string => $language->locale, $languages )
		);

		$items = array();

		foreach ( $languages as $language ) {
			$is_current = $language->slug === $current->slug;

			if ( $is_current && $hide_current ) {
				continue;
			}

			$items[] = array(
				'language' => $language,
				'url'      => home_url(
					$this->converter->convert( $paths[ $language->locale ] ?? '/', $language )
				),
				'current'  => $is_current,
			);
		}

		return $items;
	}

	/**
	 * Genera el HTML del selector.
	 *
	 * @param array<string, string> $attributes Opciones de presentación.
	 */
	public function render( array $attributes = array() ): string {
		$attributes = array_merge(
			array(
				'display'      => 'name',
				'layout'       => 'list',
				'hide_current' => 'no',
				'class'        => '',
			),
			$attributes
		);

		$items = $this->items( 'yes' === $attributes['hide_current'] );

		if ( array() === $items ) {
			return '';
		}

		$classes = 'pgai-switcher pgai-switcher--' . sanitize_html_class( (string) $attributes['layout'] );

		if ( '' !== (string) $attributes['class'] ) {
			$classes .= ' ' . implode(
				' ',
				array_map( 'sanitize_html_class', explode( ' ', (string) $attributes['class'] ) )
			);
		}

		return sprintf(
			'<nav class="%1$s" aria-label="%2$s"><ul class="pgai-switcher__list">%3$s</ul></nav>',
			esc_attr( $classes ),
			esc_attr__( 'Selector de idioma', 'polyglot-ai' ),
			$this->list_items( $items, (string) $attributes['display'] )
		);
	}

	/**
	 * Elementos de la lista.
	 *
	 * @param array<int, array{language: Language, url: string, current: bool}> $items   Idiomas.
	 * @param string                                                            $display Modo de presentación.
	 */
	private function list_items( array $items, string $display ): string {
		$html = '';

		foreach ( $items as $item ) {
			$language = $item['language'];

			$html .= sprintf(
				'<li class="pgai-switcher__item%1$s"><a class="pgai-switcher__link" href="%2$s" hreflang="%3$s" lang="%3$s"%4$s>%5$s</a></li>',
				$item['current'] ? ' pgai-switcher__item--current' : '',
				esc_url( $item['url'] ),
				esc_attr( $language->html_lang() ),
				// aria-current dice al lector de pantalla cuál es el idioma en
				// curso; sin esto el selector es una lista de enlaces iguales.
				$item['current'] ? ' aria-current="true"' : '',
				$this->label( $language, $display )
			);
		}

		return $html;
	}

	/**
	 * Contenido del enlace de un idioma, ya escapado.
	 *
	 * @param Language $language Idioma.
	 * @param string   $display  Modo: name, code, both, flag o flag_name.
	 */
	private function label( Language $language, string $display ): string {
		$name = esc_html( $language->label );
		$code = esc_html( strtoupper( $language->code() ) );
		$flag = $this->flag( $language );

		return match ( $display ) {
			'code'      => $code,
			'both'      => $name . ' (' . $code . ')',
			'flag'      => '' === $flag ? $name : $flag,
			'flag_name' => '' === $flag ? $name : $flag . ' ' . $name,
			default     => $name,
		};
	}

	/**
	 * Bandera del idioma, marcada como decorativa.
	 *
	 * Una bandera no es un idioma —el español no es de España, ni el inglés de
	 * Estados Unidos—, así que se esconde del lector de pantalla y el nombre
	 * queda disponible en el title del enlace.
	 *
	 * @param Language $language Idioma.
	 */
	private function flag( Language $language ): string {
		if ( '' === $language->flag ) {
			return '';
		}

		return sprintf(
			'<span class="pgai-switcher__flag" aria-hidden="true">%s</span><span class="screen-reader-text">%s</span>',
			esc_html( $language->flag ),
			esc_html( $language->label )
		);
	}
}
