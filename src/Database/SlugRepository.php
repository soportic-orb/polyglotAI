<?php
/**
 * Acceso a los slugs traducidos.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Database;

use PolyglotAI\Translation\Status;
use PolyglotAI\Translation\StatusPrecedence;

/**
 * Lee y escribe en pgai_slugs.
 *
 * Sirve a dos caminos muy distintos y por eso expone consultas por lotes en
 * lugar de accesos de uno en uno:
 *
 * - **Salida**: al pintar una lista de entradas se piden todos los slugs de
 *   golpe, para no hacer una consulta por enlace.
 * - **Entrada (enrutado inverso)**: al recibir /en/contact-us/ se resuelven
 *   todos los segmentos de la ruta en una sola consulta, apoyada en el índice
 *   KEY(language, translated_slug).
 */
final class SlugRepository {

	/**
	 * Opción con la versión del conjunto de slugs.
	 *
	 * Se incrementa en cada escritura. Las cachés la incluyen en su clave, de
	 * modo que invalidar todo es sumar uno a un entero en vez de recorrer y
	 * borrar claves que ni siquiera se pueden enumerar.
	 */
	public const VERSION_OPTION = 'pgai_slug_version';

	/**
	 * @param StatusPrecedence $precedence Reglas de sobrescritura.
	 */
	public function __construct( private readonly StatusPrecedence $precedence ) {}

	/**
	 * Slug traducido de un objeto.
	 *
	 * @param string $object_type    post, term o base.
	 * @param string $object_subtype Tipo de contenido, taxonomía o base.
	 * @param int    $object_id      Identificador.
	 * @param string $language       Locale.
	 */
	public function find( string $object_type, string $object_subtype, int $object_id, string $language ): ?SlugRecord {
		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE object_type = %s AND object_subtype = %s AND object_id = %d AND language = %s",
				$object_type,
				$object_subtype,
				$object_id,
				$language
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? SlugRecord::from_row( $row ) : null;
	}

	/**
	 * Slugs traducidos de varios objetos del mismo tipo.
	 *
	 * Es la consulta que alimenta la reescritura de enlaces: una sola ida a la
	 * base de datos por página, en vez de una por enlace.
	 *
	 * @param string $object_type post o term.
	 * @param int[]  $object_ids  Identificadores.
	 * @param string $language    Locale.
	 * @return array<int, string> Identificador => slug traducido.
	 */
	public function for_objects( string $object_type, array $object_ids, string $language ): array {
		global $wpdb;

		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );

		if ( array() === $object_ids ) {
			return array();
		}

		$table        = Schema::table( 'slugs' );
		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$arguments    = array_merge( array( $object_type, $language ), $object_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, translated_slug FROM {$table}
				WHERE object_type = %s AND language = %s AND object_id IN ({$placeholders})
				AND translated_slug <> ''",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['object_id'] ] = (string) $row['translated_slug'];
		}

		return $map;
	}

	/**
	 * Bases reescritas traducidas de un idioma.
	 *
	 * Son pocas y se necesitan en casi todas las páginas, así que se piden
	 * enteras de una vez.
	 *
	 * @param string $language Locale.
	 * @return array<string, string> Base => slug traducido.
	 */
	public function bases( string $language ): array {
		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_subtype, translated_slug FROM {$table}
				WHERE object_type = 'base' AND language = %s AND translated_slug <> ''",
				$language
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['object_subtype'] ] = (string) $row['translated_slug'];
		}

		return $map;
	}

	/**
	 * Bases reescritas de un idioma, por su slug original.
	 *
	 * El panel las quiere indexadas por subtipo y así las devuelve bases(). La
	 * reescritura de enlaces, en cambio, trabaja sobre segmentos de ruta y
	 * necesita buscarlas por el slug que aparece en la URL.
	 *
	 * @param string $language Locale.
	 * @return array<string, string> Slug original => slug traducido.
	 */
	public function base_slugs( string $language ): array {
		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT original_slug, translated_slug FROM {$table}
				WHERE object_type = 'base' AND language = %s AND translated_slug <> ''",
				$language
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['original_slug'] ] = (string) $row['translated_slug'];
		}

		return $map;
	}

	/**
	 * Devuelve los slugs originales de unos slugs traducidos.
	 *
	 * Es el enrutado inverso: se le pasan todos los segmentos de la ruta pedida
	 * y devuelve los que resultan ser traducciones. Los que no lo son no salen
	 * en el mapa y el llamante los deja como estaban.
	 *
	 * @param string   $language Locale.
	 * @param string[] $slugs    Segmentos de la ruta.
	 * @return array<string, string> Slug traducido => slug original.
	 */
	public function originals( string $language, array $slugs ): array {
		global $wpdb;

		$slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ), static fn ( string $slug ): bool => '' !== $slug ) ) );

		if ( array() === $slugs ) {
			return array();
		}

		$table        = Schema::table( 'slugs' );
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$arguments    = array_merge( array( $language ), $slugs );

		// Se ordena por id para que un empate dé siempre el mismo resultado.
		// Los empates no deberían existir: unique_slug() los evita al escribir.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT translated_slug, original_slug FROM {$table}
				WHERE language = %s AND translated_slug IN ({$placeholders})
				ORDER BY id ASC",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$translated = (string) $row['translated_slug'];

			if ( ! isset( $map[ $translated ] ) ) {
				$map[ $translated ] = (string) $row['original_slug'];
			}
		}

		return $map;
	}

	/**
	 * Devuelve los slugs traducidos de unos slugs originales.
	 *
	 * Es la cara opuesta de originals(): la usa la reescritura de enlaces para
	 * pedir de una vez toda la cadena de slugs de un enlace jerárquico.
	 *
	 * @param string   $language Locale.
	 * @param string[] $slugs    Slugs originales.
	 * @return array<string, string> Slug original => slug traducido.
	 */
	public function translations( string $language, array $slugs ): array {
		global $wpdb;

		$slugs = array_values(
			array_unique(
				array_filter( array_map( 'strval', $slugs ), static fn ( string $slug ): bool => '' !== $slug )
			)
		);

		if ( array() === $slugs ) {
			return array();
		}

		$table        = Schema::table( 'slugs' );
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$arguments    = array_merge( array( $language ), $slugs );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT original_slug, translated_slug FROM {$table}
				WHERE language = %s AND original_slug IN ({$placeholders})
				AND translated_slug <> ''
				ORDER BY id ASC",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$original = (string) $row['original_slug'];

			if ( ! isset( $map[ $original ] ) ) {
				$map[ $original ] = (string) $row['translated_slug'];
			}
		}

		return $map;
	}

	/**
	 * Estado del slug de un objeto.
	 *
	 * @param string $object_type    post, term o base.
	 * @param string $object_subtype Tipo de contenido, taxonomía o base.
	 * @param int    $object_id      Identificador.
	 * @param string $language       Locale.
	 */
	public function status_of( string $object_type, string $object_subtype, int $object_id, string $language ): ?Status {
		$record = $this->find( $object_type, $object_subtype, $object_id, $language );

		return null === $record ? null : $record->status;
	}

	/**
	 * Guarda un slug traducido, respetando la precedencia de estados.
	 *
	 * @param SlugRecord $record Slug.
	 * @param bool       $forced True si una persona ha pedido retraducir.
	 * @return bool True si se ha escrito.
	 */
	public function save( SlugRecord $record, bool $forced = false ): bool {
		global $wpdb;

		$current = $this->status_of(
			$record->object_type,
			$record->object_subtype,
			$record->object_id,
			$record->language
		);

		if ( ! $this->precedence->can_overwrite( $current, $record->status, $forced ) ) {
			return false;
		}

		$slug = sanitize_title( $record->translated_slug );

		if ( '' === $slug ) {
			return false;
		}

		$record = $record->with_translated_slug( $this->unique_slug( $record->with_translated_slug( $slug ) ) );

		$table = Schema::table( 'slugs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$written = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table}
					(object_type, object_subtype, object_id, language, original_slug, translated_slug, status, updated_at)
				VALUES (%s, %s, %d, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					original_slug = VALUES(original_slug),
					translated_slug = VALUES(translated_slug),
					status = VALUES(status),
					updated_at = VALUES(updated_at)",
				$record->object_type,
				$record->object_subtype,
				$record->object_id,
				$record->language,
				$record->original_slug,
				$record->translated_slug,
				$record->status->value,
				current_time( 'mysql', true )
			)
		);

		if ( false === $written ) {
			return false;
		}

		$this->bump_version();

		return true;
	}

	/**
	 * Versión actual del conjunto de slugs.
	 */
	public function version(): int {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Invalida todo lo cacheado sobre slugs.
	 */
	private function bump_version(): void {
		update_option( self::VERSION_OPTION, $this->version() + 1, true );
	}

	/**
	 * Slug libre dentro de un idioma.
	 *
	 * Dos objetos distintos con el mismo slug traducido harían ambiguo el
	 * enrutado inverso: /en/contacto/ no sabría a cuál de los dos ir. Se
	 * resuelve como lo hace WordPress con los suyos, añadiendo un sufijo
	 * numérico, y así el enrutado inverso puede seguir siendo una sola consulta
	 * sin desempates.
	 *
	 * @param SlugRecord $record Slug propuesto.
	 */
	private function unique_slug( SlugRecord $record ): string {
		global $wpdb;

		$table = Schema::table( 'slugs' );
		$slug  = $record->translated_slug;
		$try   = $slug;
		$index = 1;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		while ( $index < 100 ) {
			$taken = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE language = %s AND translated_slug = %s
					AND NOT (object_type = %s AND object_subtype = %s AND object_id = %d)
					LIMIT 1",
					$record->language,
					$try,
					$record->object_type,
					$record->object_subtype,
					$record->object_id
				)
			);

			if ( null === $taken ) {
				return $try;
			}

			++$index;
			$try = $slug . '-' . $index;
		}
		// phpcs:enable

		return $slug . '-' . (string) $record->object_id;
	}

	/**
	 * Borra los slugs de un objeto en todos los idiomas.
	 *
	 * @param string $object_type post o term.
	 * @param int    $object_id   Identificador.
	 * @return int Filas borradas.
	 */
	public function delete_for_object( string $object_type, int $object_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			Schema::table( 'slugs' ),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);

		if ( false === $deleted || 0 === (int) $deleted ) {
			return 0;
		}

		$this->bump_version();

		return (int) $deleted;
	}

	/**
	 * Slugs que aún no tienen traducción en un idioma.
	 *
	 * @param string $language Locale.
	 * @param int    $limit    Máximo de filas.
	 * @return SlugRecord[]
	 */
	public function pending( string $language, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'slugs' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE language = %s AND status = %s
				ORDER BY id ASC LIMIT %d",
				$language,
				Status::Pending->value,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static fn ( array $row ): SlugRecord => SlugRecord::from_row( $row ),
			(array) $rows
		);
	}
}
