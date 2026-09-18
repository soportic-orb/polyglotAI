<?php
/**
 * Producción de las URLs traducidas que entran en un sitemap.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Seo;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;
use PolyglotAI\Routing\SlugResolver;
use PolyglotAI\Routing\UrlConverter;
use WP_Post;
use WP_Term;

/**
 * Qué URLs traducidas existen, agrupadas por subtipo y paginadas.
 *
 * Esto vivía dentro de TranslatedSitemapProvider, que es un proveedor del
 * sitemap del núcleo. Al aparecer el segundo consumidor —las rutas propias que
 * sirven los mismos sitemaps cuando un plugin de SEO ha apagado el del núcleo
 * (ADR-16)— se saca aquí: la lista de URLs no tiene por qué saber quién la va a
 * renderizar, y así las dos vías publican exactamente lo mismo.
 *
 * **El idioma va en el subtipo, no en el nombre del proveedor.** La regla de
 * reescritura del núcleo captura el nombre con `[a-z]+`, de modo que un
 * proveedor llamado «pgai-en» no encajaría con ninguna ruta y su sitemap
 * devolvería un 404. El subtipo sí admite cifras y guiones (`[a-z\d_-]+`), que
 * es lo que necesitan slugs como «pt-br».
 *
 * Las entradas y los términos van en subtipos distintos —«en» y «en-tax»—
 * porque paginar una lista que mezcla dos consultas distintas obliga a repartir
 * desplazamientos entre ellas, y eso se rompe en cuanto una de las dos cambia
 * de tamaño entre dos peticiones.
 */
final class TranslatedUrls {

	/**
	 * Sufijo del subtipo que lista términos.
	 */
	public const TAXONOMY_SUFFIX = '-tax';

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
	 * Un subtipo por idioma traducible, más otro para sus términos.
	 *
	 * @return string[]
	 */
	public function subtypes(): array {
		$subtypes = array();

		foreach ( $this->languages->visible() as $language ) {
			if ( $this->languages->is_default( $language->slug ) ) {
				continue;
			}

			$subtypes[] = $language->slug;
			$subtypes[] = $language->slug . self::TAXONOMY_SUFFIX;
		}

		return $subtypes;
	}

	/**
	 * Descompone un subtipo en idioma y si lista términos.
	 *
	 * @param string $subtype Subtipo.
	 * @return array{0: Language, 1: bool}|null
	 */
	public function resolve( string $subtype ): ?array {
		if ( '' === $subtype ) {
			return null;
		}

		// Primero el idioma tal cual: un slug puede acabar en «-tax» y manda él.
		$language = $this->languages->by_slug( $subtype );

		if ( null !== $language && ! $this->languages->is_default( $language->slug ) ) {
			return array( $language, false );
		}

		if ( ! str_ends_with( $subtype, self::TAXONOMY_SUFFIX ) ) {
			return null;
		}

		$language = $this->languages->by_slug( substr( $subtype, 0, -strlen( self::TAXONOMY_SUFFIX ) ) );

		if ( null === $language || $this->languages->is_default( $language->slug ) ) {
			return null;
		}

		return array( $language, true );
	}

	/**
	 * URLs de una página de un subtipo.
	 *
	 * @param string $subtype Subtipo.
	 * @param int    $page    Página, empezando en 1.
	 * @return array<int, array<string, string>>
	 */
	public function urls( string $subtype, int $page ): array {
		$target = $this->resolve( $subtype );

		if ( null === $target ) {
			return array();
		}

		[ $language, $terms ] = $target;

		$per_page = $this->per_page();
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		return $terms
			? $this->term_urls( $language, $per_page, $offset )
			: $this->post_urls( $language, $per_page, $offset );
	}

	/**
	 * Número de páginas de un subtipo.
	 *
	 * @param string $subtype Subtipo.
	 */
	public function pages( string $subtype ): int {
		$target = $this->resolve( $subtype );

		if ( null === $target ) {
			return 0;
		}

		[ , $terms ] = $target;

		$total = $terms ? $this->count_terms() : $this->count_posts();

		if ( 0 === $total ) {
			return 0;
		}

		return (int) ceil( $total / max( 1, $this->per_page() ) );
	}

	/**
	 * Cuántas URLs tiene un subtipo en total.
	 *
	 * @param string $subtype Subtipo.
	 */
	public function total( string $subtype ): int {
		$target = $this->resolve( $subtype );

		if ( null === $target ) {
			return 0;
		}

		[ , $terms ] = $target;

		return $terms ? $this->count_terms() : $this->count_posts();
	}

	/**
	 * URLs por página, según el ajuste del núcleo.
	 */
	public function per_page(): int {
		return max( 1, (int) wp_sitemaps_get_max_urls( 'post' ) );
	}

	/**
	 * URLs de entradas.
	 *
	 * @param Language $language Idioma.
	 * @param int      $per_page Tamaño de página.
	 * @param int      $offset   Desplazamiento.
	 * @return array<int, array<string, string>>
	 */
	private function post_urls( Language $language, int $per_page, int $offset ): array {
		$posts = get_posts(
			array(
				'post_type'        => $this->post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => $per_page,
				'offset'           => $offset,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);

		$urls = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$url = $this->translate( (string) get_permalink( $post ), $language );

			if ( '' === $url ) {
				continue;
			}

			$urls[] = array(
				'loc'     => $url,
				'lastmod' => (string) wp_date( DATE_W3C, (int) get_post_timestamp( $post, 'modified' ) ),
			);
		}

		return $urls;
	}

	/**
	 * URLs de términos.
	 *
	 * @param Language $language Idioma.
	 * @param int      $per_page Tamaño de página.
	 * @param int      $offset   Desplazamiento.
	 * @return array<int, array<string, string>>
	 */
	private function term_urls( Language $language, int $per_page, int $offset ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomies(),
				'hide_empty' => true,
				'number'     => $per_page,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$urls = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$link = get_term_link( $term );

			if ( ! is_string( $link ) ) {
				continue;
			}

			$url = $this->translate( $link, $language );

			if ( '' !== $url ) {
				$urls[] = array( 'loc' => $url );
			}
		}

		return $urls;
	}

	/**
	 * Devuelve una URL del idioma por defecto en otro idioma.
	 *
	 * @param string   $url      URL original.
	 * @param Language $language Idioma de destino.
	 */
	private function translate( string $url, Language $language ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			return '';
		}

		// Los enlaces del sitemap se generan sin prefijo porque la petición del
		// sitemap va en el idioma por defecto: hay que traducir el slug y poner
		// el prefijo, en ese orden.
		$translated = $this->slugs->to_translated( $this->converter->strip( $path ), $language->locale );

		return str_replace( $path, $this->converter->convert( $translated, $language ), $url );
	}

	/**
	 * Tipos de contenido que entran en el sitemap.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = array();

		// El criterio es el mismo que usa el sitemap del núcleo: público y que
		// no sea un adjunto. **No se mira `publicly_queryable`**, aunque parezca
		// lo razonable: el núcleo registra `page` con `publicly_queryable` en
		// false —las páginas no se consultan con `?page=`, se resuelven por su
		// ruta— y filtrando por ahí se quedaban fuera del sitemap todas las
		// páginas del sitio, que en la mayoría son casi todo el contenido.
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$types[] = (string) $type->name;
			}
		}

		return $types;
	}

	/**
	 * Taxonomías que entran en el sitemap.
	 *
	 * @return string[]
	 */
	private function taxonomies(): array {
		$names = array();

		// Igual que arriba: el mismo criterio que el sitemap del núcleo.
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$names[] = (string) $taxonomy->name;
		}

		return $names;
	}

	/**
	 * Entradas publicadas.
	 */
	private function count_posts(): int {
		$total = 0;

		foreach ( $this->post_types() as $type ) {
			$counts = wp_count_posts( $type );

			$total += (int) ( $counts->publish ?? 0 );
		}

		return $total;
	}

	/**
	 * Términos con contenido.
	 */
	private function count_terms(): int {
		$count = wp_count_terms(
			array(
				'taxonomy'   => $this->taxonomies(),
				'hide_empty' => true,
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
