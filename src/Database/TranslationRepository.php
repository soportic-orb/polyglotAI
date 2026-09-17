<?php
/**
 * Acceso a las traducciones.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;

/**
 * Lee y escribe en pgai_translations.
 *
 * Toda escritura pasa por StatusPrecedence: es el único punto del plugin que
 * puede pisar una traducción existente, y por eso la regla vive en un solo
 * sitio (ADR-07).
 */
final class TranslationRepository {

	/**
	 * Constructor.
	 *
	 * @param StatusPrecedence $precedence Reglas de sobrescritura.
	 */
	public function __construct( private readonly StatusPrecedence $precedence ) {}

	/**
	 * Traducciones de un conjunto de hashes en un idioma.
	 *
	 * Es la consulta caliente: una por página e idioma.
	 *
	 * @param string[] $hashes   Hashes de las cadenas.
	 * @param string   $language Locale de destino.
	 * @return array<string, string> Hash => traducción.
	 */
	public function lookup( array $hashes, string $language ): array {
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
				"SELECT s.hash, t.translation
				FROM {$translations} t
				INNER JOIN {$sources} s ON s.id = t.source_id
				WHERE t.language = %s
				AND t.status <> 'pending'
				AND t.status <> 'error'
				AND s.hash IN ({$placeholders})",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['hash'] ] = (string) $row['translation'];
		}

		return $map;
	}

	/**
	 * Traducciones con su estado, para el editor visual.
	 *
	 * A diferencia de lookup(), incluye también lo pendiente y lo erróneo: el
	 * editor necesita ver y corregir precisamente eso.
	 *
	 * @param string[] $hashes   Hashes de las cadenas.
	 * @param string   $language Locale de destino.
	 * @return array<string, array{translation:string, status:string}>
	 */
	public function lookup_detailed( array $hashes, string $language ): array {
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
				"SELECT s.hash, t.translation, t.status
				FROM {$translations} t
				INNER JOIN {$sources} s ON s.id = t.source_id
				WHERE t.language = %s AND s.hash IN ({$placeholders})",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['hash'] ] = array(
				'translation' => (string) $row['translation'],
				'status'      => (string) $row['status'],
			);
		}

		return $map;
	}

	/**
	 * Estado actual de una traducción.
	 *
	 * @param int    $source_id Identificador de la cadena original.
	 * @param string $language  Locale.
	 */
	public function status_of( int $source_id, string $language ): ?Status {
		global $wpdb;

		$table = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$table} WHERE source_id = %d AND language = %s", $source_id, $language )
		);
		// phpcs:enable

		return is_string( $value ) ? Status::tryFrom( $value ) : null;
	}

	/**
	 * Guarda una traducción si la precedencia de estados lo permite.
	 *
	 * @param int         $source_id   Identificador de la cadena original.
	 * @param string      $language    Locale.
	 * @param string      $translation Traducción.
	 * @param Status      $status      Estado entrante.
	 * @param bool        $forced      Si se ha pedido retraducir explícitamente.
	 * @param string|null $engine      Motor que la ha generado.
	 * @param string|null $model       Modelo que la ha generado.
	 * @return bool Si se ha guardado.
	 */
	public function save( int $source_id, string $language, string $translation, Status $status, bool $forced = false, ?string $engine = null, ?string $model = null ): bool {
		global $wpdb;

		$current = $this->status_of( $source_id, $language );

		if ( ! $this->precedence->can_overwrite( $current, $status, $forced ) ) {
			return false;
		}

		$table = Schema::table( 'translations' );
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_id, language, translation, status, engine, model, reviewed_by, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %d, %s)
				ON DUPLICATE KEY UPDATE
					translation = VALUES(translation),
					status = VALUES(status),
					engine = VALUES(engine),
					model = VALUES(model),
					reviewed_by = VALUES(reviewed_by),
					updated_at = VALUES(updated_at)",
				$source_id,
				$language,
				$translation,
				$status->value,
				$engine,
				$model,
				$status->is_human() ? get_current_user_id() : 0,
				$now
			)
		);
		// phpcs:enable

		/**
		 * Se dispara tras guardar una traducción.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $source_id Identificador de la cadena original.
		 * @param string $language  Locale.
		 * @param Status $status    Estado guardado.
		 */
		do_action( 'pgai_translation_saved', $source_id, $language, $status );

		return true;
	}

	/**
	 * Marca cadenas como pendientes de traducir.
	 *
	 * Es lo que hace la traducción en segundo plano al detectar cadenas nuevas
	 * (ADR-13): no traduce, solo anota que faltan.
	 *
	 * @param int[]  $source_ids Identificadores de cadenas.
	 * @param string $language   Locale.
	 * @return int Filas nuevas.
	 */
	public function mark_pending( array $source_ids, string $language ): int {
		global $wpdb;

		if ( array() === $source_ids ) {
			return 0;
		}

		$table  = Schema::table( 'translations' );
		$now    = current_time( 'mysql', true );
		$values = array();
		$args   = array();

		foreach ( $source_ids as $source_id ) {
			$values[] = '(%d, %s, %s, %s, %s)';
			array_push( $args, $source_id, $language, '', Status::Pending->value, $now );
		}

		$rows = implode( ',', $values );

		// IGNORE para no tocar lo que ya exista: si la cadena ya está traducida
		// o revisada, esto no debe degradarla.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (source_id, language, translation, status, updated_at) VALUES {$rows}",
				$args
			)
		);
		// phpcs:enable
	}

	/**
	 * Marca una traducción como fallida.
	 *
	 * @param int    $source_id Identificador de la cadena original.
	 * @param string $language  Locale.
	 * @param string $reason    Motivo del fallo.
	 */
	public function mark_error( int $source_id, string $language, string $reason ): void {
		global $wpdb;

		$current = $this->status_of( $source_id, $language );

		// Un fallo del motor no puede degradar una traducción que ya existía.
		if ( null !== $current && $current->is_human() ) {
			return;
		}

		$table = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_id, language, translation, status, updated_at)
				VALUES (%d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
				$source_id,
				$language,
				'',
				Status::Error->value,
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable

		do_action( 'pgai_translation_failed', $source_id, $language, $reason );
	}

	/**
	 * Cadenas pendientes de traducir en un idioma.
	 *
	 * @param string $language Locale.
	 * @param int    $limit    Número máximo de filas.
	 * @return array<int, array{source_id:int, hash:string, original:string, type:string, context:string|null}>
	 */
	public function pending( string $language, int $limit = 50 ): array {
		global $wpdb;

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// Las imágenes quedan fuera: su valor es una URL y mandarla a un
				// motor de traducción solo conseguiría romper la imagen.
				"SELECT t.source_id, s.hash, s.original, s.type, s.context
				FROM {$translations} t
				INNER JOIN {$sources} s ON s.id = t.source_id
				WHERE t.language = %s AND t.status = %s AND s.type <> 'image'
				ORDER BY t.source_id ASC
				LIMIT %d",
				$language,
				Status::Pending->value,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static fn( array $row ): array => array(
				'source_id' => (int) $row['source_id'],
				'hash'      => (string) $row['hash'],
				'original'  => (string) $row['original'],
				'type'      => (string) $row['type'],
				'context'   => null === $row['context'] ? null : (string) $row['context'],
			),
			(array) $rows
		);
	}

	/**
	 * Recuento de traducciones por estado en un idioma.
	 *
	 * @param string $language Locale.
	 * @return array<string, int> Estado => número de cadenas.
	 */
	public function counts( string $language ): array {
		global $wpdb;

		$table = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE language = %s GROUP BY status", $language ),
			ARRAY_A
		);
		// phpcs:enable

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
