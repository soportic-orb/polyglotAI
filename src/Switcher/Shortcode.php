<?php
/**
 * Selector de idioma como shortcode.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Routing\UrlConverter;
use PolyglotAI\Support\Capabilities;

/**
 * Selector básico de idioma.
 *
 * La fase 5 añade el bloque de Gutenberg, el elemento de menú, el selector
 * flotante y los widgets de los constructores; este shortcode es el mínimo para
 * poder navegar entre idiomas desde la fase 1.
 */
final class Shortcode {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param RequestContext   $request   Contexto de la petición.
	 * @param UrlConverter     $converter Conversor de rutas.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly RequestContext $request,
		private readonly UrlConverter $converter
	) {}

	/**
	 * Registra el shortcode.
	 */
	public function register(): void {
		add_shortcode( 'pgai_language_switcher', array( $this, 'render' ) );
	}

	/**
	 * Genera el selector.
	 *
	 * @param array<string, string>|string $attributes Atributos del shortcode.
	 * @return string HTML del selector.
	 */
	public function render( array|string $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'display'      => 'name',
				'hide_current' => 'no',
			),
			is_array( $attributes ) ? $attributes : array(),
			'pgai_language_switcher'
		);

		$current = $this->request->language();

		// Un traductor ve también los idiomas en preparación para poder
		// revisarlos antes de publicarlos.
		$languages = $this->languages->visible( current_user_can( Capabilities::TRANSLATE ) );

		if ( count( $languages ) < 2 ) {
			return '';
		}

		$path  = $this->converter->strip( $this->request->path() );
		$items = '';

		foreach ( $languages as $language ) {
			$is_current = $language->slug === $current->slug;

			if ( $is_current && 'yes' === $attributes['hide_current'] ) {
				continue;
			}

			$items .= sprintf(
				'<li class="pgai-switcher__item%1$s"><a href="%2$s" hreflang="%3$s" lang="%3$s">%4$s</a></li>',
				$is_current ? ' pgai-switcher__item--current' : '',
				esc_url( home_url( $this->converter->convert( $path, $language ) ) ),
				esc_attr( $language->html_lang() ),
				esc_html( $this->label( $language, $attributes['display'] ) )
			);
		}

		return sprintf(
			'<nav class="pgai-switcher" aria-label="%s"><ul class="pgai-switcher__list">%s</ul></nav>',
			esc_attr__( 'Selector de idioma', 'polyglot-ai' ),
			$items
		);
	}

	/**
	 * Texto del enlace de un idioma.
	 *
	 * @param Language $language Idioma.
	 * @param string   $display  Modo: name, code o both.
	 */
	private function label( Language $language, string $display ): string {
		return match ( $display ) {
			'code'  => strtoupper( $language->code() ),
			'both'  => sprintf( '%s (%s)', $language->label, strtoupper( $language->code() ) ),
			default => $language->label,
		};
	}
}
