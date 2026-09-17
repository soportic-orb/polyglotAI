<?php
/**
 * Slugs traducidos en los enlaces que genera WordPress.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Database\SlugRepository;
use WP_Post;
use WP_Term;

/**
 * Hace que los enlaces de WordPress apunten al slug traducido.
 *
 * Es la cara opuesta de SlugResolver: este deshace la traducción al entrar y
 * aquel la aplica al salir. Se hace con los filtros de enlaces permanentes y no
 * en el búfer de salida porque así también salen bien los enlaces que no pasan
 * por el HTML de la página —la URL canónica, los hreflang, los sitemaps, las
 * respuestas REST— y porque aquí se sabe **de qué objeto** es cada enlace: se
 * traduce el segmento que le corresponde, no cualquier trozo de la URL que se
 * parezca a un slug.
 *
 * El prefijo de idioma no se toca aquí: lo pone LinkRewriter sobre la salida.
 *
 * Coste: los slugs de las entradas de la consulta principal se piden de una vez
 * en cuanto WordPress las tiene (`the_posts`), así que pintar una lista de
 * cincuenta enlaces no son cincuenta consultas sino una.
 */
final class PermalinkTranslator {

	/**
	 * Slugs traducidos ya conocidos en esta petición.
	 *
	 * Clave «tipo:id», valor el slug traducido o '' si no hay.
	 *
	 * @var array<string, string>
	 */
	private array $memo = array();

	/**
	 * Bases reescritas traducidas, o null si aún no se han pedido.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $bases = null;

	/**
	 * Constructor.
	 *
	 * @param SlugRepository $slugs   Almacén de slugs.
	 * @param RequestContext $request Contexto de la petición.
	 */
	public function __construct(
		private readonly SlugRepository $slugs,
		private readonly RequestContext $request
	) {}

	/**
	 * Engancha los filtros de enlaces permanentes.
	 */
	public function register(): void {
		add_filter( 'the_posts', array( $this, 'warm' ) );
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_page_link' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 3 );
	}

	/**
	 * Precarga los slugs de las entradas que WordPress acaba de recuperar.
	 *
	 * @param WP_Post[]|mixed $posts Entradas de la consulta.
	 * @return WP_Post[]|mixed Las mismas, sin tocar.
	 */
	public function warm( $posts ) {
		if ( ! is_array( $posts ) || array() === $posts || ! $this->active() ) {
			return $posts;
		}

		$ids = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$ids[] = (int) $post->ID;
			}
		}

		$this->prime( 'post', $ids );

		return $posts;
	}

	/**
	 * Traduce el slug de una entrada o de un tipo de contenido propio.
	 *
	 * @param string       $permalink Enlace.
	 * @param WP_Post|null $post      Entrada.
	 */
	public function filter_post_link( $permalink, $post ): string {
		$permalink = (string) $permalink;

		if ( ! $post instanceof WP_Post || ! $this->active() ) {
			return $permalink;
		}

		return $this->replace( $permalink, $this->post_map( $post ) );
	}

	/**
	 * Traduce el slug de una página y los de sus ascendientes.
	 *
	 * @param string   $link    Enlace.
	 * @param int|null $post_id Identificador.
	 */
	public function filter_page_link( $link, $post_id = 0 ): string {
		$link = (string) $link;
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ! $this->active() ) {
			return $link;
		}

		return $this->replace( $link, $this->post_map( $post ) );
	}

	/**
	 * Traduce el slug de un término, los de sus ascendientes y su base.
	 *
	 * @param string       $termlink Enlace.
	 * @param WP_Term|null $term     Término.
	 * @param string       $taxonomy Taxonomía.
	 */
	public function filter_term_link( $termlink, $term = null, $taxonomy = '' ): string {
		$termlink = (string) $termlink;

		if ( ! $term instanceof WP_Term || ! $this->active() ) {
			return $termlink;
		}

		$ids = array( (int) $term->term_id );

		foreach ( get_ancestors( (int) $term->term_id, (string) $taxonomy, 'taxonomy' ) as $ancestor ) {
			$ids[] = (int) $ancestor;
		}

		$this->prime( 'term', $ids );

		$map = $this->base_map();

		foreach ( $ids as $id ) {
			$source = get_term( $id, (string) $taxonomy );

			if ( ! $source instanceof WP_Term ) {
				continue;
			}

			$translated = $this->translated( 'term', $id );

			if ( '' !== $translated ) {
				$map[ (string) $source->slug ] = $translated;
			}
		}

		return $this->replace( $termlink, $map );
	}

	/**
	 * Mapa de sustitución de una entrada: ella, sus ascendientes y las bases.
	 *
	 * @param WP_Post $post Entrada.
	 * @return array<string, string>
	 */
	private function post_map( WP_Post $post ): array {
		$ids = array( (int) $post->ID );

		foreach ( get_post_ancestors( $post ) as $ancestor ) {
			$ids[] = (int) $ancestor;
		}

		$this->prime( 'post', $ids );

		$map = $this->base_map();

		foreach ( $ids as $id ) {
			$source = get_post( $id );

			if ( ! $source instanceof WP_Post ) {
				continue;
			}

			$translated = $this->translated( 'post', $id );

			if ( '' !== $translated ) {
				$map[ (string) $source->post_name ] = $translated;
			}
		}

		return $map;
	}

	/**
	 * Sustituye segmentos de la ruta de una URL.
	 *
	 * Se trabaja por segmentos y no con str_replace sobre la URL entera: un
	 * slug corto como «es» aparecería dentro de otras palabras y dentro del
	 * dominio.
	 *
	 * @param string                $url URL.
	 * @param array<string, string> $map Slug original => slug traducido.
	 */
	private function replace( string $url, array $map ): string {
		if ( array() === $map ) {
			return $url;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {
			return $url;
		}

		$segments = explode( '/', $path );
		$changed  = false;

		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$decoded = rawurldecode( $segment );

			if ( ! isset( $map[ $decoded ] ) ) {
				continue;
			}

			$segments[ $index ] = rawurlencode( $map[ $decoded ] );
			$changed            = true;
		}

		if ( ! $changed ) {
			return $url;
		}

		// Se sustituye solo la ruta, dejando esquema, dominio y consulta como
		// estaban.
		$position = strpos( $url, $path );

		if ( false === $position ) {
			return $url;
		}

		return substr_replace( $url, implode( '/', $segments ), $position, strlen( $path ) );
	}

	/**
	 * Pide de una vez los slugs que aún no se conocen.
	 *
	 * @param string $object_type post o term.
	 * @param int[]  $ids         Identificadores.
	 */
	private function prime( string $object_type, array $ids ): void {
		$missing = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $this->memo[ $object_type . ':' . $id ] ) ) {
				$missing[] = (int) $id;
			}
		}

		if ( array() === $missing ) {
			return;
		}

		$found = $this->slugs->for_objects( $object_type, $missing, $this->request->language()->locale );

		// Los que no tienen traducción se anotan igualmente, para no volver a
		// preguntar por ellos en el resto de la petición.
		foreach ( $missing as $id ) {
			$this->memo[ $object_type . ':' . $id ] = (string) ( $found[ $id ] ?? '' );
		}
	}

	/**
	 * Slug traducido de un objeto ya precargado.
	 *
	 * @param string $object_type post o term.
	 * @param int    $id          Identificador.
	 */
	private function translated( string $object_type, int $id ): string {
		return $this->memo[ $object_type . ':' . $id ] ?? '';
	}

	/**
	 * Bases reescritas traducidas, pedidas una sola vez por petición.
	 *
	 * @return array<string, string>
	 */
	private function base_map(): array {
		if ( null === $this->bases ) {
			$this->bases = $this->slugs->base_slugs( $this->request->language()->locale );
		}

		return $this->bases;
	}

	/**
	 * Si hay algo que traducir en esta petición.
	 */
	private function active(): bool {
		// En el escritorio los enlaces tienen que seguir siendo los de verdad:
		// el editor de entradas edita el slug original.
		return ! is_admin() && ! $this->request->is_default();
	}
}
