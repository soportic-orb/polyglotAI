<?php
/**
 * Idiomas configurados en el sitio.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Languages;

/**
 * Conjunto de idiomas del sitio, con el idioma por defecto aparte.
 *
 * El idioma por defecto es aquel en el que está escrito el contenido original:
 * nunca se traduce ni se almacena en el diccionario.
 */
final class LanguageRegistry {

	/**
	 * Idiomas adicionales indexados por slug.
	 *
	 * @var array<string, Language>
	 */
	private array $languages = array();

	/**
	 * Constructor.
	 *
	 * @param Language   $fallback   Idioma por defecto.
	 * @param Language[] $additional Idiomas adicionales.
	 */
	public function __construct( private readonly Language $fallback, array $additional = array() ) {
		foreach ( $additional as $language ) {
			$this->languages[ $language->slug ] = $language;
		}
	}

	/**
	 * Idioma por defecto.
	 */
	public function default_language(): Language {
		return $this->fallback;
	}

	/**
	 * Todos los idiomas, con el de por defecto primero.
	 *
	 * @return Language[]
	 */
	public function all(): array {
		return array_merge( array( $this->fallback ), array_values( $this->languages ) );
	}

	/**
	 * Idiomas adicionales activos.
	 *
	 * @return Language[]
	 */
	public function translatable(): array {
		return array_values( array_filter( $this->languages, static fn( Language $l ): bool => $l->active ) );
	}

	/**
	 * Idiomas visibles para un visitante.
	 *
	 * Los idiomas en preparación solo los ven quienes pueden traducir.
	 *
	 * @param bool $include_unpublished Si se incluyen los no publicados.
	 * @return Language[]
	 */
	public function visible( bool $include_unpublished = false ): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn( Language $l ): bool => $l->active && ( $l->published || $include_unpublished )
			)
		);
	}

	/**
	 * Busca un idioma por su slug de URL.
	 *
	 * @param string $slug Slug.
	 */
	public function by_slug( string $slug ): ?Language {
		if ( $slug === $this->fallback->slug ) {
			return $this->fallback;
		}

		return $this->languages[ $slug ] ?? null;
	}

	/**
	 * Busca un idioma por su locale.
	 *
	 * @param string $locale Locale.
	 */
	public function by_locale( string $locale ): ?Language {
		foreach ( $this->all() as $language ) {
			if ( $language->locale === $locale ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Si un slug corresponde al idioma por defecto.
	 *
	 * @param string $slug Slug.
	 */
	public function is_default( string $slug ): bool {
		return $slug === $this->fallback->slug;
	}

	/**
	 * Slugs de todos los idiomas adicionales activos.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_keys( $this->languages );
	}
}
