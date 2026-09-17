<?php
/**
 * Política de reintentos ante fallos de la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines\Claude;

/**
 * Decide si merece la pena reintentar y cuánto esperar.
 *
 * Espera exponencial con dispersión: sin la dispersión, varios procesos que
 * chocan con el mismo 429 reintentarían a la vez y volverían a chocar.
 */
final class RetryPolicy {

	/**
	 * Constructor.
	 *
	 * @param int $max_attempts Número total de intentos, incluido el primero.
	 * @param int $base_delay   Espera base en segundos.
	 * @param int $max_delay    Espera máxima en segundos.
	 */
	public function __construct(
		private readonly int $max_attempts = 5,
		private readonly int $base_delay = 2,
		private readonly int $max_delay = 60
	) {}

	/**
	 * Si un código de estado admite reintento.
	 *
	 * 429 es límite de peticiones y 529 sobrecarga del servicio: ambos se
	 * resuelven esperando. Un 4xx distinto de 429 es un error nuestro y
	 * reintentarlo solo gasta tiempo.
	 *
	 * @param int $status Código HTTP.
	 */
	public function is_retryable( int $status ): bool {
		return 429 === $status || $status >= 500;
	}

	/**
	 * Segundos de espera antes del siguiente intento.
	 *
	 * @param int      $attempt     Número del intento que acaba de fallar, desde 1.
	 * @param int|null $retry_after Valor de la cabecera Retry-After, si viene.
	 * @return int|null Segundos a esperar, o null si no hay que reintentar más.
	 */
	public function delay_for( int $attempt, ?int $retry_after = null ): ?int {
		if ( $attempt >= $this->max_attempts ) {
			return null;
		}

		// La cabecera del servidor manda sobre nuestro cálculo.
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after, $this->max_delay );
		}

		$delay  = $this->base_delay * ( 2 ** ( $attempt - 1 ) );
		$jitter = wp_rand( 0, max( 1, (int) ( $delay / 2 ) ) );

		return (int) min( $delay + $jitter, $this->max_delay );
	}

	/**
	 * Número total de intentos.
	 */
	public function max_attempts(): int {
		return $this->max_attempts;
	}
}
