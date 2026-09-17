<?php
/**
 * Tope mensual de consumo.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Jobs;

use PolyglotAI\Database\ApiLogRepository;
use PolyglotAI\Support\Options;

/**
 * Única autoridad sobre si queda presupuesto para llamar a la API.
 *
 * La consultan todos los caminos que gastan dinero: la traducción en segundo
 * plano, la de los slugs y la sugerencia del editor. Tener el cálculo en un
 * solo sitio es lo que garantiza que el tope signifique lo mismo en todos.
 */
final class Budget {

	/**
	 * Constructor.
	 *
	 * @param ApiLogRepository $log     Registro de consumo.
	 * @param Options          $options Ajustes.
	 */
	public function __construct(
		private readonly ApiLogRepository $log,
		private readonly Options $options
	) {}

	/**
	 * Si se ha alcanzado el tope mensual de tokens.
	 *
	 * El tope corta también el gasto que provoca el tráfico de visitantes, no
	 * solo el de las traducciones lanzadas a mano.
	 */
	public function exhausted(): bool {
		$limit = (int) $this->options->get( 'monthly_token_limit', 0 );

		if ( $limit <= 0 ) {
			return false;
		}

		$usage = $this->log->usage_since( gmdate( 'Y-m-01 00:00:00' ) );

		// La lectura de caché no se suma: su precio es una fracción del token de
		// entrada normal y contarla como tal falsearía el tope.
		return ( $usage['input'] + $usage['output'] + $usage['cache_creation'] ) >= $limit;
	}
}
