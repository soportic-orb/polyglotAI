<?php
/**
 * Stubs mínimos de WordPress para la suite unitaria.
 *
 * Solo lo que la HTML API del core y las clases puras del plugin necesitan para
 * cargarse. Nada que simule lógica de negocio: si un test necesita más que esto,
 * pertenece a la suite de integración, no a la unitaria.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * @param string $function_name Nombre de la función.
	 * @param string $message       Mensaje.
	 * @param string $version       Versión.
	 */
	function _doing_it_wrong( $function_name, $message, $version ) { // phpcs:ignore
		throw new RuntimeException( "_doing_it_wrong: {$function_name}: {$message}" );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Texto.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Texto.
	 * @return string
	 */
	function esc_attr( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	/**
	 * @return string[]
	 */
	function wp_kses_uri_attributes() { // phpcs:ignore
		return array( 'href', 'src', 'action', 'formaction', 'poster', 'srcset' );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook_name Nombre del hook.
	 * @param mixed  ...$args   Argumentos.
	 */
	function do_action( $hook_name, ...$args ) { // phpcs:ignore
		// Sin sistema de hooks en la suite unitaria.
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook_name Nombre del hook.
	 * @param mixed  $value     Valor.
	 * @param mixed  ...$args   Argumentos.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) { // phpcs:ignore
		return $value;
	}
}
