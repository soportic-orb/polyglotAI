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
 * Cada alternativa lleva **los slugs de su propio idioma**. La ruta que llega
 * en catalán trae slugs catalanes, así que para dar la versión inglesa hay que
 * volver primero al slug original y traducir desde ahí; dar por buena la ruta
 * recibida habría publicado ocho URLs que no existen.
 */
final class HeadTags {

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
	 * Engancha las etiquetas.
	 */
	public function register(): void {
		add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
		add_action( 'wp_head', array( $this, 'alternates' ), 1 );

		// El canónico de WordPress ya lleva el slug traducido, porque sale de
		// get_permalink(); lo que le falta es el prefijo de idioma.
		add_filter( 'wp_get_canonical_url', array( $this, 'canonical' ) );
	}

	/**
	 * Devuelve la URL canónica al idioma en curso.
	 *
	 * @param string|false $url URL canónica de WordPress.
	 * @return string|false
	 */
	public function canonical( $url ) {
		if ( ! is_string( $url ) || $this->request->is_default() ) {
			return $url;
		}

		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$converted = $this->converter->convert( $path, $this->request->language() );

		return $converted === $path ? $url : str_replace( $path, $converted, $url );
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
		$languages = $this->languages->visible();

		if ( count( $languages ) < 2 ) {
			return;
		}

		$fallback = $this->languages->default_language();
		$paths    = $this->paths( $languages, $fallback );

		foreach ( $languages as $language ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $language->html_lang() ),
				esc_url( home_url( $this->converter->convert( $paths[ $language->locale ] ?? '/', $language ) ) )
			);
		}

		// x-default apunta al idioma por defecto: es lo que se sirve a quien no
		// encaja en ninguna de las alternativas.
		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( home_url( $this->converter->convert( $paths[ $fallback->locale ] ?? '/', $fallback ) ) )
		);
	}

	/**
	 * Ruta de la página en cada idioma, con los slugs de cada uno.
	 *
	 * @param Language[] $languages Idiomas visibles.
	 * @param Language   $fallback  Idioma por defecto.
	 * @return array<string, string> Locale => ruta.
	 */
	private function paths( array $languages, Language $fallback ): array {
		$locales = array_map( static fn ( Language $l ): string => $l->locale, $languages );

		$locales[] = $fallback->locale;

		return $this->slugs->paths_by_language(
			$this->converter->strip( $this->request->path() ),
			$this->request->language()->locale,
			array_values( array_unique( $locales ) )
		);
	}
}
