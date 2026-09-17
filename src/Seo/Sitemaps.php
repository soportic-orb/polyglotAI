<?php
/**
 * Registro de los sitemaps por idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use PolyglotAI\Compat\SeoPlugins;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;

/**
 * Publica las URLs traducidas allá donde haya un índice de sitemaps.
 *
 * Son dos vías con la misma lista de URLs (TranslatedUrls) y excluyentes entre
 * sí (ADR-16):
 *
 * - Si el sitemap del núcleo está en pie, un proveedor suyo.
 * - Si un plugin de SEO lo ha apagado, rutas propias enlazadas desde el índice
 *   de ese plugin.
 */
final class Sitemaps {

	/**
	 * Lista de URLs traducidas.
	 *
	 * @var TranslatedUrls
	 */
	private readonly TranslatedUrls $urls;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $languages Idiomas del sitio.
	 * @param UrlConverter     $converter Conversor de rutas.
	 * @param SlugResolver     $slugs     Traductor de slugs.
	 */
	public function __construct(
		LanguageRegistry $languages,
		UrlConverter $converter,
		SlugResolver $slugs
	) {
		$this->urls = new TranslatedUrls( $languages, $converter, $slugs );
	}

	/**
	 * Registra las dos vías.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_provider' ), 20 );

		$sitemap = new StandaloneSitemap( $this->urls );

		$sitemap->register();

		( new SeoPlugins( $sitemap ) )->register();
	}

	/**
	 * Añade el proveedor al servidor de sitemaps del núcleo.
	 */
	public function add_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || array() === $this->urls->subtypes() ) {
			return;
		}

		wp_register_sitemap_provider( 'pgai', new TranslatedSitemapProvider( $this->urls ) );
	}
}
