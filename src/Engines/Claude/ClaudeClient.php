<?php
/**
 * Cliente HTTP de la API de Anthropic.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

use PolyglotAI\Engines\EngineException;
use PolyglotAI\Support\ApiKey;
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
	 * @param ApiKey      $api_key Custodia de la clave.
	 * @param RetryPolicy $retry   Política de reintentos.
	 * @param int         $timeout Tiempo de espera en segundos.
	 */
	public function __construct(
		private readonly ApiKey $api_key,
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
		$key = $this->api_key->get();

		if ( null === $key ) {
			throw new EngineException( 'No hay ninguna clave de API configurada.', 0, false );
		}

		$payload = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $payload ) {
			throw new EngineException( 'No se ha podido serializar la petición.', 0, false );
		}

		$attempt = 0;

		while ( true ) {
			++$attempt;

			$response = wp_remote_post(
				self::BASE_URL . $path,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'content-type'      => 'application/json',
						'x-api-key'         => $key,
						'anthropic-version' => self::API_VERSION,
					),
					'body'    => $payload,
				)
			);

			$failure = $this->failure_from( $response );

			if ( null === $failure ) {
				/** @var array<string, mixed> $decoded */
				$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

				if ( ! is_array( $decoded ) ) {
					throw new EngineException( 'La API ha devuelto una respuesta que no es JSON.', 0, false );
				}

				return $decoded;
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
			 * @since 0.1.0
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
	 * @param array<string, mixed>|WP_Error $response Respuesta de wp_remote_post.
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
