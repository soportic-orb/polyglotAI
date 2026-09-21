<?php
/**
 * Vuelta a una pantalla del plugin después de guardar.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Admin;

/**
 * Construye la URL a la que se vuelve tras un formulario de admin-post.php.
 *
 * **`menu_page_url()` no sirve aquí, y falla en silencio.** Solo sabe de páginas
 * que estén registradas, y `admin-post.php` dispara `admin_init` pero **no**
 * `admin_menu`, así que cuando corre un manejador de `admin_post_*` no hay
 * ningún menú registrado todavía y la función devuelve una cadena vacía. Con
 * eso, `add_query_arg()` produce «?pgai-saved=1», una URL relativa que
 * `wp_safe_redirect()` rechaza por no ser absoluta ni empezar por barra: no se
 * envía ninguna cabecera, el `exit` corta la ejecución y **el administrador ve
 * una página en blanco** aunque los ajustes se hayan guardado bien.
 *
 * `admin_url()` no depende de nada registrado y devuelve siempre una URL
 * absoluta, que es lo único que `wp_safe_redirect()` acepta.
 */
final class Redirect {

	/**
	 * URL de una pantalla del plugin.
	 *
	 * @param string                $slug Slug de la página.
	 * @param array<string, string> $args Parámetros añadidos.
	 */
	public static function to_page( string $slug, array $args = array() ): string {
		$url = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );

		return array() === $args ? $url : add_query_arg( $args, $url );
	}
}
