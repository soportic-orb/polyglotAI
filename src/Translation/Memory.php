<?php
/**
 * Memoria de traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use PolyglotAI\Database\Schema;

/**
 * Reaprovecha traducciones ya hechas del mismo texto.
 *
 * El hash de una cadena incluye su tipo y su contexto a propósito (ADR-03), de
 * modo que «Añadir al carrito» como texto de un botón y como atributo `title`
 * son dos cadenas distintas. Eso está bien para poder traducirlas distinto si
 * hace falta, pero **por defecto no hay ninguna razón para pagar dos veces por
 * la misma frase**.
 *
 * La búsqueda es por `text_hash` —el hash solo del texto normalizado— y por
 * tanto es exacta y va por índice. No hay similitud por distancia de edición:
 * encontrar «Añadir al carrito ahora» a partir de «Añadir al carrito» exigiría
 * un índice de trigramas o FULLTEXT sobre un LONGTEXT, y el ahorro no compensa
 * ni el coste de escritura ni el riesgo de reutilizar una traducción que no
 * era. Queda anotado como limitación conocida, no como olvido.
 *
 * Solo se reaprovecha lo que ya está traducido de verdad —automático, revisado
 * o manual—, nunca lo pendiente ni lo que quedó en error.
 */
final class Memory {

	/**
	 * Busca traducciones ya hechas para unas cadenas pendientes.
	 *
	 * @param int[]  $source_ids Identificadores de cadenas sin traducir.
	 * @param string $language   Locale.
	 * @return array<int, string> Identificador => traducción encontrada.
	 */
	public function lookup( array $source_ids, string $language ): array {
		global $wpdb;

		$source_ids = array_values( array_unique( array_map( 'intval', $source_ids ) ) );

		if ( array() === $source_ids ) {
			return array();
		}

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );

		$arguments = array_merge(
			array( $language ),
			$source_ids,
			array( Status::Pending->value, Status::Error->value )
		);

		// Se une la tabla de cadenas consigo misma por text_hash: a la
		// izquierda las que faltan, a la derecha cualquier otra que diga lo
		// mismo y ya tenga traducción en este idioma.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT missing.id AS source_id, t.translation, t.status
				FROM {$sources} missing
				INNER JOIN {$sources} known
					ON known.text_hash = missing.text_hash AND known.id <> missing.id
				INNER JOIN {$translations} t
					ON t.source_id = known.id AND t.language = %s
				WHERE missing.id IN ({$placeholders})
					AND missing.text_hash <> ''
					AND t.translation <> ''
					AND t.status NOT IN (%s, %s)
				ORDER BY missing.id ASC, FIELD(t.status, 'manual', 'reviewed', 'automatic') ASC",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable

		$found = array();

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['source_id'];

			// El ORDER BY pone primero lo que ha tocado una persona: si la
			// misma frase está traducida a mano en un sitio y por una máquina
			// en otro, se copia la buena.
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = (string) $row['translation'];
			}
		}

		return $found;
	}
}
