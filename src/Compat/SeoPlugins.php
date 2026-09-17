<?php
/**
 * Enlaza nuestros sitemaps desde el índice del plugin de SEO activo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Compat;

use PolyglotAI\Seo\StandaloneSitemap;

/**
 * Mete los sitemaps por idioma en el índice de Yoast, Rank Math, SEOPress o
 * All in One SEO.
 *
 * Los cuatro apagan el sitemap del núcleo y publican el suyo, de modo que las
 * URLs traducidas dejaban de ser descubribles (ADR-16). Cada uno tiene su
 * propia forma de ampliar el índice, y las cuatro se reducen a lo mismo:
 * «añade estas URLs a tu índice». Lo único que cambia es el formato de la
 * entrada, comprobado en el código de cada plugin:
 *
 * | Plugin        | Hook                             | Entrada                                  |
 * |---------------|----------------------------------|------------------------------------------|
 * | Yoast         | `wpseo_sitemap_index_links`      | `['loc' => …, 'lastmod' => …]`           |
 * | Rank Math     | `rank_math/sitemap/index`        | XML en crudo que se concatena            |
 * | SEOPress      | `seopress_sitemaps_external_link`| `['sitemap_url' => …, 'sitemap_last_mod' => …]` |
 * | All in One SEO| `aioseo_sitemap_indexes`         | `['loc' => …, 'lastmod' => …, 'count' => …]`    |
 *
 * **No se detecta qué plugin hay instalado.** Los cuatro filtros se registran
 * siempre: el que no tenga plugin detrás no se dispara nunca y no cuesta nada,
 * mientras que reconocer cada plugin por su constante de versión es justo el
 * tipo de código que se queda obsoleto en la siguiente versión mayor —el mismo
 * razonamiento del ADR-15.
 *
 * **No se anida un índice dentro de otro.** El protocolo de sitemaps.org solo
 * admite `<sitemap>` apuntando a archivos de URLs, no a otros índices; Google
 * lo tolera, pero no hace falta apoyarse en eso pudiendo enlazar los archivos
 * uno a uno, que es lo que ya sabemos enumerar.
 */
final class SeoPlugins {

	/**
	 * Constructor.
	 *
	 * @param StandaloneSitemap $sitemap Sitemaps propios.
	 */
	public function __construct( private readonly StandaloneSitemap $sitemap ) {}

	/**
	 * Engancha los cuatro filtros.
	 */
	public function register(): void {
		add_filter( 'wpseo_sitemap_index_links', array( $this, 'yoast' ) );
		add_filter( 'rank_math/sitemap/index', array( $this, 'rank_math' ) );
		add_filter( 'seopress_sitemaps_external_link', array( $this, 'seopress' ) );
		add_filter( 'aioseo_sitemap_indexes', array( $this, 'aioseo' ) );
	}

	/**
	 * Yoast SEO.
	 *
	 * @param mixed $links Enlaces del índice.
	 * @return array<int, array<string, string>>
	 */
	public function yoast( $links ): array {
		$links = is_array( $links ) ? $links : array();

		foreach ( $this->files() as $file ) {
			$links[] = array(
				'loc'     => $file['loc'],
				'lastmod' => $file['lastmod'],
			);
		}

		return $links;
	}

	/**
	 * Rank Math, que concatena XML en crudo.
	 *
	 * @param mixed $xml XML que se añade al final del índice.
	 */
	public function rank_math( $xml ): string {
		$xml = is_string( $xml ) ? $xml : '';

		foreach ( $this->files() as $file ) {
			$entry = "\t<sitemap>\n\t\t<loc>" . esc_url( $file['loc'] ) . "</loc>\n";

			if ( '' !== $file['lastmod'] ) {
				$entry .= "\t\t<lastmod>" . esc_xml( $file['lastmod'] ) . "</lastmod>\n";
			}

			$xml .= $entry . "\t</sitemap>\n";
		}

		return $xml;
	}

	/**
	 * SEOPress.
	 *
	 * El filtro llega con `null` cuando nadie ha añadido nada.
	 *
	 * @param mixed $sitemaps Sitemaps externos.
	 * @return array<int, array<string, string>>|null
	 */
	public function seopress( $sitemaps ): ?array {
		$sitemaps = is_array( $sitemaps ) ? $sitemaps : array();

		foreach ( $this->files() as $file ) {
			$sitemaps[] = array(
				'sitemap_url'      => $file['loc'],
				'sitemap_last_mod' => $file['lastmod'],
			);
		}

		return array() === $sitemaps ? null : $sitemaps;
	}

	/**
	 * All in One SEO.
	 *
	 * @param mixed $indexes Índices.
	 * @return array<int, array<string, mixed>>
	 */
	public function aioseo( $indexes ): array {
		$indexes = is_array( $indexes ) ? $indexes : array();

		foreach ( $this->files() as $file ) {
			$indexes[] = array(
				'loc'     => $file['loc'],
				'lastmod' => $file['lastmod'],
				'count'   => $file['count'],
			);
		}

		return $indexes;
	}

	/**
	 * Archivos que se pueden anunciar.
	 *
	 * Solo se anuncia lo que servimos: si el sitemap del núcleo sigue en pie,
	 * las URLs traducidas ya están dentro de él y estas rutas no responden.
	 *
	 * @return array<int, array{loc:string, lastmod:string, count:int}>
	 */
	private function files(): array {
		if ( ! $this->sitemap->available() ) {
			return array();
		}

		return $this->sitemap->files();
	}
}
