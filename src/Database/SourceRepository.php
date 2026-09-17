<?php
/**
 * Acceso a las cadenas originales.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\StringType;

/**
 * Lee y escribe en pgai_sources.
 *
 * Una cadena original existe una sola vez, con independencia de a cuántos
 * idiomas se traduzca.
 */
final class SourceRepository {

	/**
	 * Identificadores de una lista de hashes.
	 *
	 * @param string[] $hashes Hashes.
	 * @return array<string, int> Hash => id.
	 */
	public function ids_by_hash( array $hashes ): array {
		global $wpdb;

		if ( array() === $hashes ) {
			return array();
		}

		$table        = Schema::table( 'sources' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, hash FROM {$table} WHERE hash IN ({$placeholders})", $hashes ),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['hash'] ] = (int) $row['id'];
		}

		return $map;
	}

	/**
	 * Detalle completo de unas cadenas, con su traducción en un idioma.
	 *
	 * Es la consulta que alimenta el editor visual: en una sola pasada devuelve
	 * el original, el tipo, el contexto, la traducción y su estado.
	 *
	 * @param string[] $hashes   Hashes.
	 * @param string   $language Locale.
	 * @return array<string, array{source_id:int, original:string, type:string, context:string|null, translation:string, status:string}>
	 */
	public function details_by_hash( array $hashes, string $language ): array {
		global $wpdb;

		if ( array() === $hashes ) {
			return array();
		}

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$arguments    = array_merge( array( $language ), $hashes );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.hash, s.original, s.type, s.context,
					COALESCE(t.translation, '') AS translation,
					COALESCE(t.status, 'pending') AS status
				FROM {$sources} s
				LEFT JOIN {$translations} t ON t.source_id = s.id AND t.language = %s
				WHERE s.hash IN ({$placeholders})",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$details = array();

		foreach ( (array) $rows as $row ) {
			$details[ (string) $row['hash'] ] = array(
				'source_id'   => (int) $row['id'],
				'original'    => (string) $row['original'],
				'type'        => (string) $row['type'],
				'context'     => null === $row['context'] ? null : (string) $row['context'],
				'translation' => (string) $row['translation'],
				'status'      => (string) $row['status'],
			);
		}

		return $details;
	}

	/**
	 * Registra una cadena original, o actualiza su última aparición.
	 *
	 * @param string      $hash     Hash.
	 * @param string      $original Texto original.
	 * @param StringType  $type     Tipo.
	 * @param string|null $context  Contexto.
	 * @param string|null $domain   Dominio de gettext.
	 * @return int Identificador de la cadena.
	 */
	public function remember( string $hash, string $original, StringType $type, ?string $context = null, ?string $domain = null ): int {
		global $wpdb;

		$table = Schema::table( 'sources' );
		$now   = current_time( 'mysql', true );

		// INSERT ... ON DUPLICATE KEY evita la carrera entre dos peticiones que
		// descubren la misma cadena a la vez.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (hash, type, domain, context, original, first_seen, last_seen)
				VALUES (%s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)",
				$hash,
				$type->value,
				$domain,
				$context,
				$original,
				$now,
				$now
			)
		);

		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s", $hash ) );
		// phpcs:enable

		return $id;
	}

	/**
	 * Marca como vistas un conjunto de cadenas.
	 *
	 * @param string[] $hashes Hashes.
	 */
	public function touch( array $hashes ): void {
		global $wpdb;

		if ( array() === $hashes ) {
			return;
		}

		$table        = Schema::table( 'sources' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$arguments    = array_merge( array( current_time( 'mysql', true ) ), $hashes );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET last_seen = %s WHERE hash IN ({$placeholders})", $arguments )
		);
		// phpcs:enable
	}

	/**
	 * Borra las cadenas que no se han visto desde una fecha y no tienen
	 * traducción humana.
	 *
	 * @param string $before Fecha límite en formato MySQL UTC.
	 * @return int Filas borradas.
	 */
	public function delete_orphans( string $before ): int {
		global $wpdb;

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM {$sources} s
				WHERE s.last_seen < %s
				AND NOT EXISTS (
					SELECT 1 FROM {$translations} t
					WHERE t.source_id = s.id AND t.status IN ('manual', 'reviewed')
				)",
				$before
			)
		);
		// phpcs:enable
	}
}
