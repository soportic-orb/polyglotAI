<?php
/**
 * Proveedor de sitemaps con las URLs de cada idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use WP_Sitemaps_Provider;

/**
 * Añade al sitemap de WordPress las URLs traducidas.
 *
 * El sitemap del núcleo solo conoce las URLs del idioma por defecto, así que un
 * buscador no tenía por dónde descubrir /en/contact-us/ salvo rastreando
 * enlaces. Este proveedor publica un sitemap por idioma.
 *
 * Es un adaptador: qué URLs hay y cómo se paginan lo decide TranslatedUrls, que
 * es lo que comparte con las rutas propias del ADR-16.
 */
final class TranslatedSitemapProvider extends WP_Sitemaps_Provider {

	/**
	 * Constructor.
	 *
	 * @param TranslatedUrls $urls Lista de URLs traducidas.
	 */
	public function __construct( private readonly TranslatedUrls $urls ) {
		$this->name        = 'pgai';
		$this->object_type = 'post';
	}

	/**
	 * Un subtipo por idioma traducible, más otro para sus términos.
	 *
	 * @return array<string, array{name:string}>
	 */
	public function get_object_subtypes() {
		$subtypes = array();

		foreach ( $this->urls->subtypes() as $subtype ) {
			$subtypes[ $subtype ] = array( 'name' => $subtype );
		}

		return $subtypes;
	}

	/**
	 * URLs de una página del sitemap.
	 *
	 * @param int    $page_num       Página.
	 * @param string $object_subtype Subtipo.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		return $this->urls->urls( (string) $object_subtype, (int) $page_num );
	}

	/**
	 * Número de páginas de un subtipo.
	 *
	 * @param string $object_subtype Subtipo.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return $this->urls->pages( (string) $object_subtype );
	}
}
