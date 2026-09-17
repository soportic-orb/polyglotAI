<?php
/**
 * Registro de bloques de traducción fusionados.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

/**
 * Guarda qué cadenas se han fusionado en una sola unidad de traducción.
 *
 * Una fusión se define por la SECUENCIA de hashes de las cadenas fusionadas, no
 * por una posición ni por un selector CSS: así sobrevive a que el tema cambie de
 * maquetación, y deja de aplicarse por sí sola si el contenido cambia de verdad.
 *
 * Se identifica por el hash de su primera cadena, que es también lo que permite
 * deshacerla desde el editor.
 */
final class MergeRegistry {

	/** Opción donde se guardan las fusiones. Sin autocarga: puede crecer. */
	private const OPTION = 'pgai_merges';

	/**
	 * Grupos en memoria durante la petición.
	 *
	 * @var array<string, string[]>|null
	 */
	private ?array $cache = null;

	/**
	 * Todos los grupos, indexados por el hash de su primera cadena.
	 *
	 * @return array<string, string[]>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			$this->cache = is_array( $stored ) ? $stored : array();
		}

		return $this->cache;
	}

	/**
	 * Registra una fusión.
	 *
	 * @param string[] $hashes Hashes en el orden en que aparecen en la página.
	 * @return string|null Identificador del grupo, o null si no es fusionable.
	 */
	public function add( array $hashes ): ?string {
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );

		// Fusionar una sola cadena no significa nada.
		if ( count( $hashes ) < 2 ) {
			return null;
		}

		$groups = $this->all();
		$first  = $hashes[0];

		// Una cadena no puede pertenecer a dos fusiones: se quitan las que la
		// contengan antes de crear la nueva.
		foreach ( $hashes as $hash ) {
			$groups = $this->without( $groups, $hash );
		}

		$groups[ $first ] = $hashes;

		$this->save( $groups );

		return $first;
	}

	/**
	 * Deshace la fusión que contiene un hash.
	 *
	 * @param string $hash Hash de cualquiera de sus cadenas.
	 * @return bool Si había algo que deshacer.
	 */
	public function remove( string $hash ): bool {
		$groups = $this->all();
		$after  = $this->without( $groups, $hash );

		if ( $after === $groups ) {
			return false;
		}

		$this->save( $after );

		return true;
	}

	/**
	 * Devuelve los grupos sin aquel que contenga un hash.
	 *
	 * @param array<string, string[]> $groups Grupos.
	 * @param string                  $hash   Hash.
	 * @return array<string, string[]>
	 */
	private function without( array $groups, string $hash ): array {
		foreach ( $groups as $first => $members ) {
			if ( in_array( $hash, $members, true ) ) {
				unset( $groups[ $first ] );
			}
		}

		return $groups;
	}

	/**
	 * Guarda los grupos.
	 *
	 * @param array<string, string[]> $groups Grupos.
	 */
	private function save( array $groups ): void {
		update_option( self::OPTION, $groups, false );

		$this->cache = $groups;

		// Las unidades de la página cambian, así que los diccionarios en caché
		// dejan de ser válidos.
		DictionaryFactory::invalidate();
	}
}
