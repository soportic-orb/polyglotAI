<?php
/**
 * Menús distintos por idioma.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Switcher;

use PolyglotAI\Routing\RequestContext;
use PolyglotAI\Support\Options;

/**
 * Sustituye el menú de una ubicación cuando el idioma tiene el suyo.
 *
 * Traducir las etiquetas de un menú no basta: un sitio en dos idiomas suele
 * querer menús distintos, porque no todas las páginas existen en todos los
 * idiomas y el orden que funciona en uno no es el que funciona en otro.
 *
 * Se sustituye el menú entero, no sus elementos: así el administrador lo compone
 * en Apariencia → Menús con las herramientas de siempre, en vez de aprenderse
 * una pantalla nuestra.
 *
 * El idioma por defecto no se configura: su menú es el que ya tiene asignado la
 * ubicación del tema. Configurarlo aparte solo añadiría un sitio más donde
 * mirar cuando el menú no es el que se esperaba.
 */
final class MenuLocations {

	/** Clave de los ajustes. */
	public const OPTION_KEY = 'menus';

	/**
	 * Constructor.
	 *
	 * @param Options        $options Ajustes.
	 * @param RequestContext $request Contexto de la petición.
	 */
	public function __construct(
		private readonly Options $options,
		private readonly RequestContext $request
	) {}

	/**
	 * Engancha la sustitución.
	 */
	public function register(): void {
		add_filter( 'wp_nav_menu_args', array( $this, 'swap' ) );
	}

	/**
	 * Cambia el menú de la ubicación si el idioma tiene uno propio.
	 *
	 * @param array<string, mixed>|mixed $args Argumentos de wp_nav_menu().
	 * @return array<string, mixed>|mixed
	 */
	public function swap( $args ) {
		if ( ! is_array( $args ) || $this->request->is_default() ) {
			return $args;
		}

		$location = (string) ( $args['theme_location'] ?? '' );

		if ( '' === $location ) {
			return $args;
		}

		$menu = $this->menu_for( $location, $this->request->language()->locale );

		if ( 0 === $menu ) {
			return $args;
		}

		// `menu` manda sobre `theme_location` dentro de wp_nav_menu().
		$args['menu'] = $menu;

		return $args;
	}

	/**
	 * Menú configurado para una ubicación y un idioma.
	 *
	 * @param string $location Ubicación del tema.
	 * @param string $language Locale.
	 * @return int Identificador del menú, o 0 si no hay o ya no existe.
	 */
	public function menu_for( string $location, string $language ): int {
		$map = $this->map();
		$id  = (int) ( $map[ $language ][ $location ] ?? 0 );

		if ( $id <= 0 ) {
			return 0;
		}

		// Un menú borrado deja la asignación apuntando a nada: se comprueba,
		// porque wp_nav_menu() con un menú inexistente no pinta nada y el sitio
		// se quedaría sin navegación en ese idioma.
		return false === wp_get_nav_menu_object( $id ) ? 0 : $id;
	}

	/**
	 * Asignaciones guardadas.
	 *
	 * @return array<string, array<string, int>> Locale => (ubicación => menú).
	 */
	public function map(): array {
		$stored = $this->options->get( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$map = array();

		foreach ( $stored as $language => $locations ) {
			if ( ! is_array( $locations ) ) {
				continue;
			}

			foreach ( $locations as $location => $menu ) {
				$map[ (string) $language ][ (string) $location ] = (int) $menu;
			}
		}

		return $map;
	}
}
