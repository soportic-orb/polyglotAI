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

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Datos.
	 * @param int   $options Opciones de json_encode.
	 * @param int   $depth   Profundidad.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore
		return json_encode( $data, (int) $options, (int) $depth );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * @param int $min Mínimo.
	 * @param int $max Máximo.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) { // phpcs:ignore
		return random_int( (int) $min, (int) $max );
	}
}

/**
 * Opciones en memoria para la suite unitaria.
 *
 * @var array<string, mixed>
 */
$GLOBALS['pgai_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Nombre.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) { // phpcs:ignore
		return $GLOBALS['pgai_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Nombre.
	 * @param mixed  $value    Valor.
	 * @param bool   $autoload Autocarga.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore
		$GLOBALS['pgai_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Nombre.
	 * @return bool
	 */
	function delete_option( $option ) { // phpcs:ignore
		unset( $GLOBALS['pgai_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Versión mínima de esc_url para las pruebas unitarias.
	 *
	 * @param string $url URL.
	 */
	function esc_url( $url ) { // phpcs:ignore
		return str_replace( array( '"', '<', '>' ), array( '&quot;', '&lt;', '&gt;' ), (string) $url );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Versión mínima de wp_parse_url para las pruebas unitarias.
	 *
	 * @param string $url       URL.
	 * @param int    $component Componente.
	 */
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( (string) $url, (int) $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param mixed $str Texto.
	 * @return string
	 */
	function sanitize_text_field( $str ) { // phpcs:ignore
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param mixed $text Texto.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) { // phpcs:ignore
		return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
