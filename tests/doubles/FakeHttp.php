<?php
/**
 * Capa HTTP de mentira para las pruebas unitarias.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Doubles;

use WP_Error;

/**
 * Sirve respuestas de una cola y apunta lo que se le ha pedido.
 *
 * Así el cliente de la API se prueba de verdad —cabeceras, método, reintentos,
 * decodificación— sin salir a la red y sin levantar WordPress.
 */
final class FakeHttp {

	/**
	 * Respuestas pendientes de servir.
	 *
	 * @var array<int, array<string, mixed>|WP_Error>
	 */
	private static array $queue = array();

	/**
	 * Llamadas registradas.
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private static array $calls = array();

	/**
	 * Vacía la cola y el registro.
	 */
	public static function reset(): void {
		self::$queue = array();
		self::$calls = array();
	}

	/**
	 * Añade una respuesta correcta a la cola.
	 *
	 * @param mixed                 $body    Cuerpo: un array se codifica como JSON.
	 * @param int                   $status  Código de estado.
	 * @param array<string, string> $headers Cabeceras.
	 */
	public static function push( mixed $body, int $status = 200, array $headers = array() ): void {
		self::$queue[] = array(
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'headers'  => $headers,
			'response' => array(
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			),
		);
	}

	/**
	 * Añade un fallo de red a la cola.
	 *
	 * @param string $message Mensaje.
	 */
	public static function push_error( string $message = 'Se ha agotado el tiempo de espera' ): void {
		self::$queue[] = new WP_Error( 'http_request_failed', $message );
	}

	/**
	 * Sirve la siguiente respuesta y registra la llamada.
	 *
	 * @param string               $url       URL pedida.
	 * @param array<string, mixed> $arguments Argumentos.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function handle( string $url, array $arguments ): array|WP_Error {
		self::$calls[] = array(
			'url'  => $url,
			'args' => $arguments,
		);

		$next = array_shift( self::$queue );

		return null === $next
			? new WP_Error( 'pgai_test_empty', 'No quedan respuestas en la cola.' )
			: $next;
	}

	/**
	 * Llamadas registradas.
	 *
	 * @return array<int, array{url: string, args: array<string, mixed>}>
	 */
	public static function calls(): array {
		return self::$calls;
	}

	/**
	 * Cuántas llamadas se han hecho.
	 */
	public static function count(): int {
		return count( self::$calls );
	}

	/**
	 * Una llamada concreta.
	 *
	 * @param int $index Posición, desde 0.
	 * @return array{url: string, args: array<string, mixed>}
	 */
	public static function call( int $index = 0 ): array {
		return self::$calls[ $index ] ?? array(
			'url'  => '',
			'args' => array(),
		);
	}
}
