<?php
/**
 * Conversión de URLs entre idiomas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Añade y quita el segmento de idioma de las rutas del sitio.
 *
 * Trabaja sobre rutas, no sobre URLs completas, para poder probarse sin
 * WordPress y para no depender de cómo esté configurado el home.
 */
final class UrlConverter {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages       Idiomas del sitio.
	 * @param string           $base_path       Ruta base del sitio, con barras a
	 *                                          ambos lados. En una instalación en
	 *                                          subdirectorio sería /blog/.
	 * @param bool             $prefix_default  Si el idioma por defecto también
	 *                                          lleva su segmento en la URL.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly string $base_path = '/',
		private readonly bool $prefix_default = false
	) {}

	/**
	 * Detecta el idioma de una ruta.
	 *
	 * @param string $path Ruta de la petición, incluida la base del sitio.
	 */
	public function detect( string $path ): Language {
		$segment = $this->first_segment( $path );

		if ( '' === $segment ) {
			return $this->languages->default_language();
		}

		$language = $this->languages->by_slug( $segment );

		if ( null === $language || ! $language->active ) {
			return $this->languages->default_language();
		}

		// Sin prefijo para el idioma por defecto, /es/algo no es su URL
		// canónica aunque «es» sea un slug conocido.
		if ( $this->languages->is_default( $segment ) && ! $this->prefix_default ) {
			return $this->languages->default_language();
		}

		return $language;
	}

	/**
	 * Quita el segmento de idioma de una ruta.
	 *
	 * @param string $path Ruta.
	 */
	public function strip( string $path ): string {
		$segment = $this->first_segment( $path );

		if ( '' === $segment || null === $this->languages->by_slug( $segment ) ) {
			return $path;
		}

		$rest = substr( $path, strlen( $this->base_path ) + strlen( $segment ) );

		if ( '' === $rest ) {
			$rest = '/';
		}

		return rtrim( $this->base_path, '/' ) . $rest;
	}

	/**
	 * Devuelve la ruta equivalente en otro idioma.
	 *
	 * @param string   $path     Ruta en cualquier idioma.
	 * @param Language $language Idioma de destino.
	 */
	public function convert( string $path, Language $language ): string {
		$clean = $this->strip( $path );

		if ( $this->languages->is_default( $language->slug ) && ! $this->prefix_default ) {
			return $clean;
		}

		$suffix = substr( $clean, strlen( rtrim( $this->base_path, '/' ) ) );

		return rtrim( $this->base_path, '/' ) . '/' . $language->slug . $suffix;
	}

	/**
	 * Primer segmento de la ruta después de la base del sitio.
	 *
	 * @param string $path Ruta.
	 */
	private function first_segment( string $path ): string {
		$base = $this->base_path;

		if ( ! str_starts_with( $path, $base ) ) {
			return '';
		}

		$relative = substr( $path, strlen( $base ) );
		$question = strpos( $relative, '?' );

		if ( false !== $question ) {
			$relative = substr( $relative, 0, $question );
		}

		$slash = strpos( $relative, '/' );

		return false === $slash ? $relative : substr( $relative, 0, $slash );
	}
}
