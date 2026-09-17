<?php
/**
 * Registro de consumo de la API.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Engines\Usage;

/**
 * Anota cada llamada a la API en pgai_api_log.
 *
 * Sin este registro no hay forma de saber qué se está gastando ni de aplicar el
 * tope mensual, y la estimación de coste del panel sería inventada.
 */
final class ApiLogRepository {

	/**
	 * Registra una llamada.
	 *
	 * @param string      $engine   Identificador del motor.
	 * @param string|null $model    Modelo.
	 * @param string|null $language Locale de destino.
	 * @param int         $strings  Cadenas del lote.
	 * @param Usage       $usage    Consumo.
	 * @param string      $status   Resultado: ok o error.
	 * @param string|null $error    Mensaje de error.
	 * @param string|null $batch_id Identificador del lote asíncrono.
	 */
	public function record( string $engine, ?string $model, ?string $language, int $strings, Usage $usage, string $status = 'ok', ?string $error = null, ?string $batch_id = null ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( 'api_log' ),
			array(
				'created_at'            => current_time( 'mysql', true ),
				'engine'                => $engine,
				'model'                 => $model,
				'language'              => $language,
				'batch_id'              => $batch_id,
				'strings'               => $strings,
				'input_tokens'          => $usage->input_tokens,
				'output_tokens'         => $usage->output_tokens,
				'cache_read_tokens'     => $usage->cache_read_tokens,
				'cache_creation_tokens' => $usage->cache_creation_tokens,
				'status'                => $status,
				'error'                 => $error,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Tokens consumidos desde una fecha.
	 *
	 * La lectura de caché cuenta aparte porque su precio es una fracción del
	 * token de entrada normal: sumarla al total daría un gasto falso.
	 *
	 * @param string $since Fecha en formato MySQL UTC.
	 * @return array{input:int, output:int, cache_read:int, cache_creation:int}
	 */
	public function usage_since( string $since ): array {
		global $wpdb;

		$table = Schema::table( 'api_log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(input_tokens), 0) AS input_tokens,
					COALESCE(SUM(output_tokens), 0) AS output_tokens,
					COALESCE(SUM(cache_read_tokens), 0) AS cache_read_tokens,
					COALESCE(SUM(cache_creation_tokens), 0) AS cache_creation_tokens
				FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'input'          => (int) ( $row['input_tokens'] ?? 0 ),
			'output'         => (int) ( $row['output_tokens'] ?? 0 ),
			'cache_read'     => (int) ( $row['cache_read_tokens'] ?? 0 ),
			'cache_creation' => (int) ( $row['cache_creation_tokens'] ?? 0 ),
		);
	}
}
