<?php
/**
 * Idioma de la petición en curso.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Routing;

use PolyglotAI\Languages\Language;
use PolyglotAI\Languages\LanguageRegistry;

/**
 * Resuelve una sola vez el idioma de la petición.
 *
 * La URL se analiza una vez por petición y el resultado se guarda: el idioma se
 * consulta decenas de veces durante el renderizado y volver a parsear la ruta
 * cada vez sería gasto puro.
 */
final class RequestContext {

	/**
	 * Idioma resuelto, o null si aún no se ha calculado.
	 *
	 * @var Language|null
	 */
	private ?Language $language = null;

	/**
	 * Ruta pedida por el visitante, CON su prefijo de idioma.
	 *
	 * Se guarda antes de que RequestRouter se lo quite a REQUEST_URI. Es la
	 * única copia fiable de dónde está el visitante, y por eso todo lo que
	 * necesita saberlo la pide aquí en lugar de volver a leer REQUEST_URI, que
	 * para entonces ya está modificado.
	 *
	 * @var string|null
	 */
	private ?string $path = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $registry  Idiomas del sitio.
	 * @param UrlConverter     $converter Conversor de URLs.
	 */
	public function __construct(
		private readonly LanguageRegistry $registry,
		private readonly UrlConverter $converter
	) {}

	/**
	 * Idioma de la petición.
	 */
	public function language(): Language {
		if ( null === $this->language ) {
			$this->language = $this->converter->detect( $this->path() );
		}

		return $this->language;
	}

	/**
	 * Si la petición es del idioma por defecto y no hay nada que traducir.
	 */
	public function is_default(): bool {
		return $this->registry->is_default( $this->language()->slug );
	}

	/**
	 * Fuerza un idioma. Solo para el editor visual y los tests.
	 *
	 * @param Language $language Idioma.
	 */
	public function force( Language $language ): void {
		$this->language = $language;
	}

	/**
	 * Guarda la ruta original de la petición.
	 *
	 * @param string $path Ruta con su prefijo de idioma.
	 */
	public function set_path( string $path ): void {
		$this->path = $path;
	}

	/**
	 * Ruta pedida por el visitante, con su prefijo de idioma.
	 */
	public function path(): string {
		if ( null !== $this->path ) {
			return $this->path;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		$path = wp_parse_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}
}
