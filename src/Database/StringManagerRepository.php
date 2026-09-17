<?php
/**
 * Consultas del gestor de cadenas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\Status;

/**
 * Busca, cuenta y actúa sobre cadenas en lote.
 *
 * Va aparte de SourceRepository y de TranslationRepository porque su trabajo es
 * distinto: aquellos sirven la ruta caliente —búsquedas por hash, con índice y
 * sin excusas— y este sirve una pantalla de administración, donde hay filtros,
 * paginación y búsquedas por texto que no pueden usar índice. Mezclarlos habría
 * acabado con consultas de pantalla colándose en la ruta caliente.
 */
final class StringManagerRepository {

	/**
	 * Cadenas que cumplen los criterios.
	 *
	 * @param StringQuery $query Criterios.
	 * @return array<int, array{source_id:int, hash:string, original:string, type:string, context:string|null, domain:string|null, translation:string, status:string, updated_at:string|null}>
	 */
	public function search( StringQuery $query ): array {
		global $wpdb;

		[ $where, $arguments ] = $this->conditions( $query );

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$order        = $query->ascending ? 'ASC' : 'DESC';

		// El COALESCE del SELECT va ANTES que el idioma del JOIN en el SQL, así
		// que su argumento tiene que ir el primero: prepare() los consume en el
		// orden en que aparecen los marcadores, no en el que uno los piensa.
		array_unshift( $arguments, Status::Pending->value );

		$arguments[] = $query->limit();
		$arguments[] = $query->offset();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS source_id, s.hash, s.original, s.type, s.context, s.domain,
					COALESCE(t.translation, '') AS translation,
					COALESCE(t.status, %s) AS status,
					t.updated_at
				FROM {$sources} s
				LEFT JOIN {$translations} t ON t.source_id = s.id AND t.language = %s
				WHERE {$where}
				ORDER BY s.last_seen {$order}, s.id {$order}
				LIMIT %d OFFSET %d",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static fn ( array $row ): array => array(
				'source_id'   => (int) $row['source_id'],
				'hash'        => (string) $row['hash'],
				'original'    => (string) $row['original'],
				'type'        => (string) $row['type'],
				'context'     => null === $row['context'] ? null : (string) $row['context'],
				'domain'      => null === $row['domain'] ? null : (string) $row['domain'],
				'translation' => (string) $row['translation'],
				'status'      => (string) $row['status'],
				'updated_at'  => null === $row['updated_at'] ? null : (string) $row['updated_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Cuántas cadenas cumplen los criterios.
	 *
	 * @param StringQuery $query Criterios.
	 */
	public function count( StringQuery $query ): int {
		global $wpdb;

		[ $where, $arguments ] = $this->conditions( $query );

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$sources} s
				LEFT JOIN {$translations} t ON t.source_id = s.id AND t.language = %s
				WHERE {$where}",
				$arguments
			)
		);
		// phpcs:enable

		return null === $total ? 0 : (int) $total;
	}

	/**
	 * Identificadores que cumplen los criterios, sin paginar.
	 *
	 * La usan las acciones en lote cuando se pide «todo lo filtrado» y no solo
	 * lo que se ve en pantalla. Va acotada: una acción sobre cien mil cadenas
	 * no cabe en una petición y hay que trocearla desde el panel.
	 *
	 * @param StringQuery $query Criterios.
	 * @param int         $limit Máximo de identificadores.
	 * @return int[]
	 */
	public function ids( StringQuery $query, int $limit = 1000 ): array {
		global $wpdb;

		[ $where, $arguments ] = $this->conditions( $query );

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		$arguments[] = max( 1, $limit );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.id
				FROM {$sources} s
				LEFT JOIN {$translations} t ON t.source_id = s.id AND t.language = %s
				WHERE {$where}
				ORDER BY s.id ASC
				LIMIT %d",
				$arguments
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Marca traducciones como revisadas.
	 *
	 * Solo pasa a revisada una traducción automática: una cadena sin traducir
	 * no se puede dar por buena, y una que ya es manual no gana nada bajando a
	 * revisada.
	 *
	 * @param int[]  $source_ids Identificadores.
	 * @param string $language   Locale.
	 * @param int    $user_id    Quien revisa.
	 * @return int Filas cambiadas.
	 */
	public function mark_reviewed( array $source_ids, string $language, int $user_id ): int {
		global $wpdb;

		$source_ids = array_values( array_unique( array_map( 'intval', $source_ids ) ) );

		if ( array() === $source_ids ) {
			return 0;
		}

		$table        = Schema::table( 'translations' );
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		$arguments    = array_merge(
			array( Status::Reviewed->value, $user_id, current_time( 'mysql', true ), $language, Status::Automatic->value ),
			$source_ids
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, reviewed_by = %d, updated_at = %s
				WHERE language = %s AND status = %s AND source_id IN ({$placeholders})",
				$arguments
			)
		);
		// phpcs:enable

		return false === $changed ? 0 : (int) $changed;
	}

	/**
	 * Devuelve traducciones al estado pendiente para que se vuelvan a traducir.
	 *
	 * No vale `mark_pending()`: aquella inserta con IGNORE porque su trabajo es
	 * encolar cadenas nuevas sin degradar lo que ya exista (ADR-13), de modo que
	 * sobre una traducción ya hecha no haría absolutamente nada.
	 *
	 * Retraducir es justamente la petición explícita que el ADR-07 exige para
	 * escribir sobre una traducción automática. Lo que ha tocado una persona
	 * —revisada o manual— sigue sin tocarse ni aquí: para retraducir eso hay que
	 * cambiarle el estado a mano primero.
	 *
	 * @param int[]  $source_ids Identificadores.
	 * @param string $language   Locale.
	 * @return int Filas devueltas a pendiente.
	 */
	public function mark_for_retranslation( array $source_ids, string $language ): int {
		global $wpdb;

		$source_ids = array_values( array_unique( array_map( 'intval', $source_ids ) ) );

		if ( array() === $source_ids ) {
			return 0;
		}

		$table        = Schema::table( 'translations' );
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		$arguments    = array_merge(
			array(
				Status::Pending->value,
				current_time( 'mysql', true ),
				$language,
				Status::Automatic->value,
				Status::Error->value,
			),
			$source_ids
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s, updated_at = %s
				WHERE language = %s AND status IN (%s, %s) AND source_id IN ({$placeholders})",
				$arguments
			)
		);
		// phpcs:enable

		return false === $changed ? 0 : (int) $changed;
	}

	/**
	 * Borra las traducciones de unas cadenas en un idioma.
	 *
	 * No borra la cadena original: volverá a aparecer en el barrido de la
	 * página y perderla solo conseguiría que hubiera que redescubrirla.
	 *
	 * @param int[]  $source_ids Identificadores.
	 * @param string $language   Locale.
	 * @return int Filas borradas.
	 */
	public function delete_translations( array $source_ids, string $language ): int {
		global $wpdb;

		$source_ids = array_values( array_unique( array_map( 'intval', $source_ids ) ) );

		if ( array() === $source_ids ) {
			return 0;
		}

		$table        = Schema::table( 'translations' );
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
		$arguments    = array_merge( array( $language ), $source_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE language = %s AND source_id IN ({$placeholders})",
				$arguments
			)
		);
		// phpcs:enable

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Condiciones SQL y sus argumentos.
	 *
	 * El primer argumento es siempre el idioma del JOIN, que va antes que
	 * cualquier condición del WHERE.
	 *
	 * @param StringQuery $query Criterios.
	 * @return array{0: string, 1: array<int, scalar>}
	 */
	private function conditions( StringQuery $query ): array {
		global $wpdb;

		$where     = array( '1=1' );
		$arguments = array( $query->language );

		if ( null !== $query->status ) {
			if ( Status::Pending === $query->status ) {
				// Una cadena sin fila de traducción está pendiente aunque en la
				// tabla no haya nada que lo diga.
				$where[]     = '(t.status IS NULL OR t.status = %s)';
				$arguments[] = $query->status->value;
			} else {
				$where[]     = 't.status = %s';
				$arguments[] = $query->status->value;
			}
		}

		if ( null !== $query->type ) {
			$where[]     = 's.type = %s';
			$arguments[] = $query->type->value;
		}

		if ( '' !== trim( $query->search ) ) {
			$like        = '%' . $wpdb->esc_like( trim( $query->search ) ) . '%';
			$where[]     = '(s.original LIKE %s OR t.translation LIKE %s OR s.context LIKE %s)';
			$arguments[] = $like;
			$arguments[] = $like;
			$arguments[] = $like;
		}

		return array( implode( ' AND ', $where ), $arguments );
	}
}
