<?php
/**
 * Registro del sitemap por idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;

/**
 * Engancha el proveedor de sitemaps traducidos al de WordPress.
 *
 * Solo toca el sitemap del núcleo. Yoast, Rank Math, SEOPress y All in One SEO
 * sustituyen el sitemap del núcleo por el suyo y cada uno tiene su propia forma
 * de ampliarlo; esa integración se aborda en la fase 8, que es cuando esos
 * plugins estarán instalados en el entorno de pruebas y se podrá comprobar que
 * funciona en vez de suponerlo (ADR-16).
 */
final class Sitemaps {

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param UrlConverter     $converter Conversor de rutas.
	 * @param SlugResolver     $slugs     Traductor de slugs.
	 */
	public function __construct(
		private readonly LanguageRegistry $languages,
		private readonly UrlConverter $converter,
		private readonly SlugResolver $slugs
	) {}

	/**
	 * Registra el proveedor.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_provider' ), 20 );
	}

	/**
	 * Añade el proveedor al servidor de sitemaps.
	 */
	public function add_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || array() === $this->languages->translatable() ) {
			return;
		}

		wp_register_sitemap_provider(
			'pgai',
			new TranslatedSitemapProvider( $this->languages, $this->converter, $this->slugs )
		);
	}
}
