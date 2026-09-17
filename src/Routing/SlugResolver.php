<?php
/**
 * Enrutado inverso de slugs.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Database\SlugRepository;

/**
 * Convierte una ruta con slugs traducidos en la ruta que WordPress entiende.
 *
 * /en/contact-us/ tiene que acabar resolviendo a la página cuyo slug real es
 * «contacto». Como el prefijo de idioma ya se ha quitado antes (RequestRouter),
 * aquí solo queda sustituir los segmentos.
 *
 * Se resuelve **la ruta entera en una sola consulta**, apoyada en el índice
 * KEY(language, translated_slug): un enlace jerárquico tiene varios segmentos y
 * una consulta por segmento pondría la ruta caliente a merced de la profundidad
 * de la jerarquía.
 *
 * Los segmentos que no son traducciones —«page», un número de paginación, un
 * año— no salen en el mapa y se dejan como estaban.
 */
final class SlugResolver {

	private const CACHE_GROUP = 'pgai_slugs';

	/**
	 * Constructor.
	 *
	 * @param SlugRepository $slugs Almacén de slugs.
	 */
	public function __construct( private readonly SlugRepository $slugs ) {}

	/**
	 * Devuelve la ruta con los slugs traducidos sustituidos por los originales.
	 *
	 * @param string $path     Ruta sin prefijo de idioma.
	 * @param string $language Locale.
	 */
	public function to_original( string $path, string $language ): string {
		return $this->map_path( $path, $language, false );
	}

	/**
	 * Devuelve la ruta con los slugs originales sustituidos por los traducidos.
	 *
	 * @param string $path     Ruta sin prefijo de idioma.
	 * @param string $language Locale.
	 */
	public function to_translated( string $path, string $language ): string {
		return $this->map_path( $path, $language, true );
	}

	/**
	 * La misma ruta en varios idiomas, con los slugs de cada uno.
	 *
	 * La piden los hreflang. Una ruta que llega en catalán lleva slugs
	 * catalanes: para dar la versión inglesa hay que volver primero al original
	 * y traducir desde ahí. Todo ello con **una sola consulta** para todos los
	 * idiomas, porque si no una página en ocho idiomas serían ocho.
	 *
	 * @param string   $path    Ruta sin prefijo de idioma, tal como ha llegado.
	 * @param string   $current Locale en el que ha llegado.
	 * @param string[] $locales Locales que se quieren.
	 * @return array<string, string> Locale => ruta.
	 */
	public function paths_by_language( string $path, string $current, array $locales ): array {
		$original = $this->to_original( $path, $current );
		$segments = $this->segments( $original );
		$paths    = array();

		foreach ( $locales as $locale ) {
			$paths[ $locale ] = $original;
		}

		if ( array() === $segments || array() === $locales ) {
			return $paths;
		}

		foreach ( $this->translations_by_language( $locales, $segments ) as $locale => $map ) {
			if ( ! isset( $paths[ $locale ] ) || array() === $map ) {
				continue;
			}

			$paths[ $locale ] = $this->apply( $original, $segments, $map );
		}

		return $paths;
	}

	/**
	 * Traducciones de unos segmentos en varios idiomas, cacheadas.
	 *
	 * @param string[] $locales  Locales.
	 * @param string[] $segments Segmentos.
	 * @return array<string, array<string, string>>
	 */
	private function translations_by_language( array $locales, array $segments ): array {
		$decoded = array_map( 'rawurldecode', $segments );

		sort( $decoded );
		sort( $locales );

		$key = 'alt:' . $this->slugs->version() . ':' . md5( implode( ',', $locales ) . "\x1e" . implode( "\x1f", $decoded ) );

		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/** @var array<string, array<string, string>> $cached */
			return $cached;
		}

		$map = $this->slugs->translations_by_language( $locales, $decoded );

		wp_cache_set( $key, $map, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $map;
	}

	/**
	 * Sustituye los segmentos de una ruta.
	 *
	 * @param string $path     Ruta.
	 * @param string $language Locale.
	 * @param bool   $forward  True: original => traducido. False: al revés.
	 */
	private function map_path( string $path, string $language, bool $forward ): string {
		$segments = $this->segments( $path );

		if ( array() === $segments ) {
			return $path;
		}

		$map = $this->lookup( $segments, $language, $forward );

		if ( array() === $map ) {
			return $path;
		}

		return $this->apply( $path, $segments, $map );
	}

	/**
	 * Reconstruye una ruta sustituyendo los segmentos que estén en el mapa.
	 *
	 * @param string                $path     Ruta original.
	 * @param string[]              $segments Sus segmentos.
	 * @param array<string, string> $map      Sustituciones.
	 */
	private function apply( string $path, array $segments, array $map ): string {
		$changed = false;

		foreach ( $segments as $index => $segment ) {
			$decoded = rawurldecode( $segment );

			if ( ! isset( $map[ $decoded ] ) ) {
				continue;
			}

			$segments[ $index ] = rawurlencode( $map[ $decoded ] );
			$changed            = true;
		}

		if ( ! $changed ) {
			return $path;
		}

		$rebuilt = '/' . implode( '/', $segments );

		// Se conserva la barra final: WordPress redirige si no coincide con la
		// estructura de enlaces permanentes, y esa redirección cuesta una
		// petición entera.
		if ( str_ends_with( $path, '/' ) ) {
			$rebuilt .= '/';
		}

		return $rebuilt;
	}

	/**
	 * Segmentos de una ruta, sin los vacíos.
	 *
	 * @param string $path Ruta.
	 * @return string[]
	 */
	private function segments( string $path ): array {
		return array_values( array_filter( explode( '/', trim( $path, '/' ) ), static fn ( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Mapa de sustitución de una ruta, cacheado por petición y en caché de objeto.
	 *
	 * @param string[] $segments Segmentos.
	 * @param string   $language Locale.
	 * @param bool     $forward  Sentido de la conversión.
	 * @return array<string, string>
	 */
	private function lookup( array $segments, string $language, bool $forward ): array {
		$decoded = array_map( 'rawurldecode', $segments );

		sort( $decoded );

		$key = ( $forward ? 'fwd:' : 'rev:' ) . $this->slugs->version() . ':' . $language . ':' . md5( implode( "\x1f", $decoded ) );

		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/** @var array<string, string> $cached */
			return $cached;
		}

		$map = $forward
			? $this->slugs->translations( $language, $decoded )
			: $this->slugs->originals( $language, $decoded );

		wp_cache_set( $key, $map, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $map;
	}
}
