<?php
/**
 * Etiquetas de idioma en la cabecera.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Emite el atributo lang y las alternativas hreflang.
 *
 * El SEO Pack de la fase 4 ampliará esto con canonical por idioma y con las
 * integraciones de los plugins de SEO; lo básico vive aquí desde el principio
 * porque sin hreflang una web multilingüe se penaliza a sí misma.
 */
final class HeadTags {

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
	 * Engancha las etiquetas.
	 */
	public function register(): void {
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_action( 'wp_head', array( $this, 'alternates' ), 1 );
	}

	/**
	 * Sustituye el atributo lang y añade dir en los idiomas RTL.
	 *
	 * @param string $output Atributos generados por WordPress.
	 */
	public function language_attributes( string $output ): string {
		$language = $this->request->language();

		$attributes = sprintf( 'lang="%s"', esc_attr( $language->html_lang() ) );

		if ( $language->rtl ) {
			$attributes .= ' dir="rtl"';
		}

		// Se sustituye el lang existente en lugar de añadir otro: dos atributos
		// lang en <html> es HTML inválido.
		$replaced = preg_replace( '/lang="[^"]*"/', $attributes, $output, 1 );

		return is_string( $replaced ) && str_contains( $output, 'lang=' ) ? $replaced : trim( $output . ' ' . $attributes );
	}

	/**
	 * Emite las etiquetas hreflang, incluida x-default.
	 */
	public function alternates(): void {
		$path      = $this->converter->strip( $this->request->path() );
		$languages = $this->languages->visible();

		if ( count( $languages ) < 2 ) {
			return;
		}

		foreach ( $languages as $language ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $language->html_lang() ),
				esc_url( home_url( $this->converter->convert( $path, $language ) ) )
			);
		}

		// x-default apunta al idioma por defecto: es lo que se sirve a quien no
		// encaja en ninguna de las alternativas.
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( home_url( $this->converter->convert( $path, $this->languages->default_language() ) ) )
		);
	}
}
