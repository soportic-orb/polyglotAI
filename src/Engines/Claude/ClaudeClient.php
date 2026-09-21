<?php
/**
 * Cliente HTTP de la API de Anthropic.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\EngineException;
use PolyglotAI\Support\ApiKeyInterface;
use WP_Error;

/**
 * Envía peticiones a la API de Anthropic con wp_remote_post.
 *
 * No se usa el SDK oficial a propósito: un plugin de WordPress no puede
 * arrastrar un árbol de dependencias de Composer al vendor/ de un sitio ajeno
 * sin arriesgarse a colisiones de versiones con otros plugins.
 */
final class ClaudeClient {

	/** Base de la API. */
	private const BASE_URL = 'https://api.anthropic.com';

	/** Versión de la API, obligatoria en cada llamada. */
	private const API_VERSION = '2023-06-01';

	/**
	 * Constructor.
	 *
	 * @param ApiKeyInterface $api_key Custodia de la clave.
	 * @param RetryPolicy     $retry   Política de reintentos.
	 * @param int             $timeout Tiempo de espera en segundos.
	 */
	public function __construct(
		private readonly ApiKeyInterface $api_key,
		private readonly RetryPolicy $retry,
		private readonly int $timeout = 120
	) {}

	/**
	 * Hace una petición POST y devuelve el cuerpo decodificado.
	 *
	 * @param string               $path Ruta relativa, p. ej. /v1/messages.
	 * @param array<string, mixed> $body Cuerpo de la petición.
	 * @return array<string, mixed>
	 *
	 * @throws EngineException Si la llamada falla tras agotar los reintentos.
	 */
	public function post( string $path, array $body ): array {
		return $this->decode( $this->request( 'POST', self::BASE_URL . $path, $body ) );
	}

	/**
	 * Hace una petición GET y devuelve el cuerpo decodificado.
	 *
	 * @param string $path Ruta relativa, p. ej. /v1/messages/batches/msgbatch_1.
	 * @return array<string, mixed>
	 *
	 * @throws EngineException Si la llamada falla tras agotar los reintentos.
	 */
	public function get( string $path ): array {
		return $this->decode( $this->request( 'GET', self::BASE_URL . $path, null ) );
	}

	/**
	 * Descarga un cuerpo sin decodificar.
	 *
	 * La usan los resultados de un lote, que llegan en JSONL —una línea por
	 * respuesta— y no como un JSON único: decodificarlo entero fallaría.
	 *
	 * @param string $url URL absoluta que devuelve la propia API.
	 * @return string Cuerpo tal cual.
	 *
	 * @throws EngineException Si la llamada falla tras agotar los reintentos.
	 */
	public function download( string $url ): string {
		return (string) wp_remote_retrieve_body( $this->request( 'GET', $url, null ) );
	}

	/**
	 * Decodifica el cuerpo de una respuesta.
	 *
	 * @param array<string, mixed> $response Respuesta correcta.
	 * @return array<string, mixed>
	 *
	 * @throws EngineException Si el cuerpo no es JSON.
	 */
	private function decode( array $response ): array {
		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			throw new EngineException( 'La API ha devuelto una respuesta que no es JSON.', 0, false );
		}

		return $decoded;
	}

	/**
	 * Hace la llamada, con reintentos, y devuelve la respuesta correcta.
	 *
	 * @param string                    $method Método HTTP.
	 * @param string                    $url    URL absoluta.
	 * @param array<string, mixed>|null $body   Cuerpo, si lo hay.
	 * @return array<string, mixed>
	 *
	 * @throws EngineException Si la llamada falla tras agotar los reintentos.
	 */
	private function request( string $method, string $url, ?array $body ): array {
		$key = $this->api_key->get();

		if ( null === $key ) {
			throw new EngineException( 'No hay ninguna clave de API configurada.', 0, false );
		}

		$arguments = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'content-type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
			),
		);

		if ( null !== $body ) {
			$payload = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $payload ) {
				throw new EngineException( 'No se ha podido serializar la petición.', 0, false );
			}

			$arguments['body'] = $payload;
		}

		$attempt = 0;

		while ( true ) {
			++$attempt;

			$response = wp_remote_request( $url, $arguments );

			$failure = $this->failure_from( $response );

			if ( null === $failure ) {
				/** @var array<string, mixed> $response */
				return $response;
			}

			if ( ! $failure->retryable ) {
				throw $failure;
			}

			$delay = $this->retry->delay_for(
				$attempt,
				$response instanceof WP_Error ? null : $this->retry_after( $response )
			);

			if ( null === $delay ) {
				throw $failure;
			}

			/**
			 * Se dispara antes de esperar para reintentar una llamada a la API.
			 *
			 * @since 2.0
			 *
			 * @param int             $attempt Intento que acaba de fallar.
			 * @param int             $delay   Segundos de espera.
			 * @param EngineException $failure Fallo que provoca el reintento.
			 */
			do_action( 'pgai_engine_retry', $attempt, $delay, $failure );

			sleep( $delay );
		}
	}

	/**
	 * Traduce una respuesta de WordPress en un fallo, si lo es.
	 *
	 * @param array<string, mixed>|WP_Error $response Respuesta de wp_remote_request.
	 * @return EngineException|null Null si la respuesta es correcta.
	 */
	private function failure_from( array|WP_Error $response ): ?EngineException {
		if ( $response instanceof WP_Error ) {
			// Un fallo de red o un tiempo de espera agotado sí merece reintento.
			return new EngineException( $response->get_error_message(), 0, true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return null;
		}

		return new EngineException(
			sprintf( 'La API ha respondido %d: %s', $status, $this->error_message( $response ) ),
			$status,
			$this->retry->is_retryable( $status )
		);
	}

	/**
	 * Mensaje de error que devuelve la API.
	 *
	 * @param array<string, mixed> $response Respuesta.
	 */
	private function error_message( array $response ): string {
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $decoded ) && is_array( $decoded['error'] ?? null ) && is_string( $decoded['error']['message'] ?? null ) ) {
			return $decoded['error']['message'];
		}

		return (string) wp_remote_retrieve_response_message( $response );
	}

	/**
	 * Valor de la cabecera Retry-After, si viene.
	 *
	 * @param array<string, mixed> $response Respuesta.
	 */
	private function retry_after( array $response ): ?int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( ! is_string( $header ) || '' === $header || ! ctype_digit( $header ) ) {
			return null;
		}

		return (int) $header;
	}
}
