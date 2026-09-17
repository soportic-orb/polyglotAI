<?php
/**
 * Stubs de la capa HTTP de WordPress para las pruebas unitarias.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

use PolyglotAI\Tests\Doubles\FakeHttp;

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Versión mínima de WP_Error.
	 */
	class WP_Error { // phpcs:ignore

		/**
		 * Constructor.
		 *
		 * @param string $code    Código.
		 * @param string $message Mensaje.
		 */
		public function __construct(
			private readonly string $code = '',
			private readonly string $message = ''
		) {}

		/**
		 * Código del error.
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Mensaje del error.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * Sirve la siguiente respuesta de la cola de FakeHttp.
	 *
	 * @param string               $url       URL.
	 * @param array<string, mixed> $arguments Argumentos.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_remote_request( $url, $arguments = array() ) { // phpcs:ignore
		return FakeHttp::handle( (string) $url, (array) $arguments );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Cuerpo de una respuesta.
	 *
	 * @param array<string, mixed>|WP_Error $response Respuesta.
	 */
	function wp_remote_retrieve_body( $response ) { // phpcs:ignore
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Código de estado.
	 *
	 * @param array<string, mixed>|WP_Error $response Respuesta.
	 */
	function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_message' ) ) {
	/**
	 * Mensaje de estado.
	 *
	 * @param array<string, mixed>|WP_Error $response Respuesta.
	 */
	function wp_remote_retrieve_response_message( $response ) { // phpcs:ignore
		return is_array( $response ) ? (string) ( $response['response']['message'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * Una cabecera de la respuesta.
	 *
	 * @param array<string, mixed>|WP_Error $response Respuesta.
	 * @param string                        $header   Nombre.
	 */
	function wp_remote_retrieve_header( $response, $header ) { // phpcs:ignore
		if ( ! is_array( $response ) || ! is_array( $response['headers'] ?? null ) ) {
			return '';
		}

		return (string) ( $response['headers'][ strtolower( (string) $header ) ] ?? '' );
	}
}
