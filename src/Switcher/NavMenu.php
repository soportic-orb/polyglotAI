<?php
/**
 * Selector de idioma dentro de los menús de navegación.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Languages\Language;
use PolyglotAI\Routing\RequestContext;

/**
 * Permite colocar el selector de idioma desde Apariencia → Menús.
 *
 * Los elementos se añaden como enlaces personalizados con una URL centinela
 * (`#pgai-switcher` o `#pgai-lang-en`) y se resuelven al pintar el menú. Es
 * deliberado que lo guardado en la base de datos sea un centinela y no una URL:
 *
 * - La URL correcta depende de la página que se esté viendo y del slug traducido
 *   de esa página, así que no existe una URL que se pueda guardar una vez.
 * - Un menú guardado sigue siendo válido si se añade o se quita un idioma
 *   después: el centinela se resuelve con los idiomas que haya en ese momento.
 *
 * El elemento «selector» se convierte en un padre cuyo título es el idioma en
 * curso y cuyos hijos son los demás idiomas, que es la forma en que un menú
 * espera mostrar una lista de opciones.
 */
final class NavMenu {

	/** URL centinela del selector completo. */
	public const SWITCHER_URL = '#pgai-switcher';

	/** Prefijo de la URL centinela de un idioma concreto. */
	public const LANGUAGE_URL_PREFIX = '#pgai-lang-';

	/**
	 * Constructor.
	 *
	 * @param SwitcherRenderer $renderer Cálculo de los enlaces por idioma.
	 * @param RequestContext   $request  Contexto de la petición.
	 */
	public function __construct(
		private readonly SwitcherRenderer $renderer,
		private readonly RequestContext $request
	) {}

	/**
	 * Engancha la integración.
	 */
	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * Sustituye los centinelas por los idiomas que toquen.
	 *
	 * @param array<int, object>|mixed $items Elementos del menú.
	 * @param object|array<mixed>|null $args  Argumentos de wp_nav_menu.
	 * @return array<int, object>|mixed
	 */
	public function resolve( $items, $args = null ) {
		unset( $args );

		if ( ! is_array( $items ) ) {
			return $items;
		}

		$languages = $this->renderer->items();

		$resolved = array();

		foreach ( $items as $item ) {
			$url = is_object( $item ) && isset( $item->url ) ? (string) $item->url : '';

			if ( self::SWITCHER_URL === $url ) {
				// Sin más de un idioma el selector no tiene sentido y el
				// elemento desaparece en vez de quedarse como un enlace muerto.
				if ( array() === $languages ) {
					continue;
				}

				foreach ( $this->expand( $item, $languages ) as $expanded ) {
					$resolved[] = $expanded;
				}

				continue;
			}

			if ( str_starts_with( $url, self::LANGUAGE_URL_PREFIX ) ) {
				$single = $this->single( $item, $languages, substr( $url, strlen( self::LANGUAGE_URL_PREFIX ) ) );

				// Un idioma que ya no está activo, o que no ve este visitante,
				// no deja un enlace roto en el menú: se quita.
				if ( null !== $single ) {
					$resolved[] = $single;
				}

				continue;
			}

			$resolved[] = $item;
		}

		return $resolved;
	}

	/**
	 * Convierte el centinela del selector en un padre con un hijo por idioma.
	 *
	 * @param object                                                            $item      Elemento centinela.
	 * @param array<int, array{language: Language, url: string, current: bool}> $languages Idiomas.
	 * @return array<int, object>
	 */
	private function expand( object $item, array $languages ): array {
		$current = $this->request->language();
		$parent  = clone $item;

		$parent->url          = '#';
		$parent->title        = esc_html( $current->label );
		$parent->classes      = $this->classes( $item, array( 'pgai-menu-switcher' ) );
		$parent->has_children = true;

		$expanded = array( $parent );
		$offset   = 1;

		foreach ( $languages as $entry ) {
			if ( $entry['current'] ) {
				continue;
			}

			$child = clone $item;

			// Identificadores propios: dos elementos con el mismo ID harían que
			// el menú se pintara con clases y anidamiento cruzados.
			$child->ID               = (int) $item->ID * 1000 + $offset;
			$child->db_id            = $child->ID;
			$child->menu_item_parent = (string) $item->db_id;
			$child->url              = $entry['url'];
			$child->title            = esc_html( $entry['language']->label );
			$child->classes          = $this->classes( $item, array( 'pgai-menu-language' ) );
			$child->has_children     = false;

			$expanded[] = $child;
			++$offset;
		}

		return $expanded;
	}

	/**
	 * Resuelve el centinela de un idioma concreto.
	 *
	 * @param object                                                            $item      Elemento centinela.
	 * @param array<int, array{language: Language, url: string, current: bool}> $languages Idiomas.
	 * @param string                                                            $slug      Slug del idioma.
	 */
	private function single( object $item, array $languages, string $slug ): ?object {
		foreach ( $languages as $entry ) {
			if ( $entry['language']->slug !== $slug ) {
				continue;
			}

			$resolved = clone $item;

			$resolved->url     = $entry['url'];
			$resolved->classes = $this->classes(
				$item,
				$entry['current']
					? array( 'pgai-menu-language', 'pgai-menu-language--current' )
					: array( 'pgai-menu-language' )
			);

			// El título guardado manda: un administrador puede haber escrito
			// «EN» o «English (UK)» a propósito.
			if ( '' === trim( (string) $resolved->title ) ) {
				$resolved->title = esc_html( $entry['language']->label );
			}

			return $resolved;
		}

		return null;
	}

	/**
	 * Clases del elemento, conservando las que haya puesto el administrador.
	 *
	 * @param object   $item  Elemento original.
	 * @param string[] $extra Clases a añadir.
	 * @return string[]
	 */
	private function classes( object $item, array $extra ): array {
		$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : array();

		return array_values( array_unique( array_merge( $classes, $extra ) ) );
	}
}
