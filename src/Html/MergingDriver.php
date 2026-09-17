<?php
/**
 * Driver que aplica los bloques de traducción fusionados.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Html;

use PolyglotAI\Translation\Hasher;
use PolyglotAI\Translation\MergeRegistry;
use PolyglotAI\Translation\StringType;

/**
 * Envuelve otro driver y funde las cadenas que el traductor haya agrupado.
 *
 * Va por fuera del driver de extracción a propósito: extraer es una operación
 * sobre el documento y fusionar es una decisión del traductor. Mantenerlas
 * separadas deja el extractor probándose contra HTML puro, sin necesidad de
 * base de datos.
 */
final class MergingDriver implements DocumentDriverInterface {

	/**
	 * Constructor.
	 *
	 * @param DocumentDriverInterface $inner    Driver real de extracción.
	 * @param MergeRegistry           $registry Fusiones registradas.
	 * @param Hasher                  $hasher   Calculador de hashes.
	 */
	public function __construct(
		private readonly DocumentDriverInterface $inner,
		private readonly MergeRegistry $registry,
		private readonly Hasher $hasher
	) {}

	/**
	 * Identificador del driver.
	 */
	public function name(): string {
		return $this->inner->name() . '+merge';
	}

	/**
	 * Extrae las unidades aplicando las fusiones.
	 *
	 * @param string $html Documento HTML completo.
	 * @return ExtractedString[]
	 */
	public function extract( string $html ): array {
		$units  = $this->inner->extract( $html );
		$groups = $this->registry->all();

		if ( array() === $groups || array() === $units ) {
			return $units;
		}

		$hashes = array();

		foreach ( $units as $index => $unit ) {
			$hashes[ $index ] = $this->hasher->hash( $unit->value, $unit->type, $unit->context );
		}

		$result = array();
		$total  = count( $units );

		for ( $index = 0; $index < $total; $index++ ) {
			$group = $groups[ $hashes[ $index ] ] ?? null;
			$last  = null === $group ? null : $this->matches_from( $hashes, $index, $group, $total );

			if ( null === $last ) {
				$result[] = $units[ $index ];

				continue;
			}

			// Se fusionan ELEMENTOS enteros, no sus contenidos: fusionar por el
			// contenido dejaría un fragmento descuadrado del tipo
			// «Uno</p><p>Dos», que ni el motor ni la validación pueden tratar.
			$start = $units[ $index ]->outer_start();
			$end   = $units[ $last ]->outer_end();

			$result[] = new ExtractedString(
				StringType::Block,
				substr( $html, $start, $end - $start ),
				$start,
				$end - $start,
				// El identificador del grupo viaja en el contexto: forma parte
				// del hash, de modo que un bloque fusionado es una cadena
				// distinta de las que lo componen, y permite deshacer la fusión
				// desde el editor.
				'merge:' . $hashes[ $index ]
			);

			$index = $last;
		}

		return $result;
	}

	/**
	 * Comprueba si un grupo empieza en una posición concreta.
	 *
	 * @param array<int, string> $hashes Hashes de las unidades, por posición.
	 * @param int                $from   Posición de partida.
	 * @param string[]           $group  Hashes del grupo.
	 * @param int                $total  Número de unidades.
	 * @return int|null Posición de la última unidad del grupo, o null si no encaja.
	 */
	private function matches_from( array $hashes, int $from, array $group, int $total ): ?int {
		$last = $from + count( $group ) - 1;

		if ( $last >= $total ) {
			return null;
		}

		foreach ( $group as $offset => $hash ) {
			if ( $hashes[ $from + $offset ] !== $hash ) {
				return null;
			}
		}

		return $last;
	}
}
