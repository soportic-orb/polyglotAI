<?php
/**
 * Construcción del diccionario de una página.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Translation;

use PolyglotAI\Database\TranslationRepository;
use PolyglotAI\Html\ExtractedString;

/**
 * Resuelve de una sola consulta las traducciones de todas las cadenas de una
 * página, con caché de objeto.
 */
final class DictionaryFactory {

	/** Grupo de caché de objeto. */
	private const CACHE_GROUP = 'pgai_dict';

	/** Opción con la versión global del diccionario. */
	private const VERSION_OPTION = 'pgai_dict_version';

	/**
	 * Constructor.
	 *
	 * @param TranslationRepository $translations Repositorio de traducciones.
	 * @param Hasher                $hasher       Calculador de hashes.
	 * @param Normalizer            $normalizer   Normalizador.
	 */
	public function __construct(
		private readonly TranslationRepository $translations,
		private readonly Hasher $hasher,
		private readonly Normalizer $normalizer
	) {}

	/**
	 * Construye el diccionario de un conjunto de unidades.
	 *
	 * @param ExtractedString[] $units      Unidades extraídas del HTML.
	 * @param string            $language   Locale de destino.
	 * @param bool              $with_status Si se necesita también el estado de
	 *                                       cada traducción. Lo pide el editor
	 *                                       visual; una visita normal no, y por
	 *                                       eso no se paga en el camino caliente.
	 */
	public function build( array $units, string $language, bool $with_status = false ): PageDictionary {
		$wanted = array();

		foreach ( $units as $unit ) {
			if ( ! $this->normalizer->is_translatable( $this->normalizer->normalize( $unit->value ) ) ) {
				continue;
			}

			$hash            = $this->hasher->hash( $unit->value, $unit->type, $unit->context );
			$wanted[ $hash ] = $unit;
		}

		if ( array() === $wanted ) {
			return new PageDictionary( array(), array(), $this->hasher );
		}

		$hashes   = array_keys( $wanted );
		$statuses = array();

		if ( $with_status ) {
			// Sin caché: el editor tiene que ver lo que hay ahora mismo, no lo
			// que había la última vez que se pintó la página.
			$found = array();

			foreach ( $this->translations->lookup_detailed( $hashes, $language ) as $hash => $row ) {
				$statuses[ $hash ] = $row['status'];

				if ( '' !== $row['translation'] ) {
					$found[ $hash ] = $row['translation'];
				}
			}
		} else {
			$found = $this->lookup_cached( $hashes, $language );
		}

		$missing = array();

		foreach ( $wanted as $hash => $unit ) {
			if ( ! isset( $found[ $hash ] ) ) {
				$missing[ $hash ] = array(
					'unit' => $unit,
					'hash' => $hash,
				);
			}
		}

		return new PageDictionary( $found, $missing, $this->hasher, $statuses );
	}

	/**
	 * Consulta las traducciones pasando por la caché de objeto.
	 *
	 * @param string[] $hashes   Hashes.
	 * @param string   $language Locale.
	 * @return array<string, string>
	 */
	private function lookup_cached( array $hashes, string $language ): array {
		sort( $hashes );

		$key    = sprintf( '%d:%s:%s', $this->version(), $language, md5( implode( '', $hashes ) ) );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			/** @var array<string, string> $cached */
			return $cached;
		}

		$found = $this->translations->lookup( $hashes, $language );

		wp_cache_set( $key, $found, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Versión actual del diccionario.
	 */
	private function version(): int {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Invalida toda la caché de diccionarios.
	 *
	 * Se incrementa un entero en lugar de recorrer y borrar claves: no hay forma
	 * fiable de enumerar las claves de un grupo en la API de caché de objeto, y
	 * un borrado por grupo no existe en todos los backends.
	 */
	public static function invalidate(): void {
		$version = (int) get_option( self::VERSION_OPTION, 1 );

		update_option( self::VERSION_OPTION, $version + 1, true );
	}
}
