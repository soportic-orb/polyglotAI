<?php
/**
 * Consumo de tokens de una llamada a la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

/**
 * Tokens consumidos, desglosados.
 *
 * La lectura y la escritura de caché se guardan aparte porque tienen precio
 * distinto del token de entrada normal: sin ese desglose la estimación de coste
 * del panel sería falsa.
 */
final class Usage {

	/**
	 * Constructor.
	 *
	 * @param int $input_tokens          Tokens de entrada facturados a precio normal.
	 * @param int $output_tokens         Tokens generados.
	 * @param int $cache_read_tokens     Tokens servidos desde la caché de prompt.
	 * @param int $cache_creation_tokens Tokens escritos en la caché de prompt.
	 */
	public function __construct(
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0,
		public readonly int $cache_read_tokens = 0,
		public readonly int $cache_creation_tokens = 0
	) {}

	/**
	 * Construye el consumo a partir del bloque usage de una respuesta.
	 *
	 * @param array<string, mixed> $usage Bloque usage.
	 */
	public static function from_response( array $usage ): self {
		return new self(
			(int) ( $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['output_tokens'] ?? 0 ),
			(int) ( $usage['cache_read_input_tokens'] ?? 0 ),
			(int) ( $usage['cache_creation_input_tokens'] ?? 0 )
		);
	}

	/**
	 * Suma dos consumos.
	 *
	 * @param Usage $other Otro consumo.
	 */
	public function plus( Usage $other ): self {
		return new self(
			$this->input_tokens + $other->input_tokens,
			$this->output_tokens + $other->output_tokens,
			$this->cache_read_tokens + $other->cache_read_tokens,
			$this->cache_creation_tokens + $other->cache_creation_tokens
		);
	}

	/**
	 * Si la caché de prompt ha servido algo.
	 *
	 * Un false constante entre lotes del mismo idioma indica que el prompt del
	 * sistema no llega al mínimo cacheable del modelo y que se está pagando de
	 * más sin que la API avise.
	 */
	public function used_cache(): bool {
		return $this->cache_read_tokens > 0;
	}
}
