<?php
/**
 * Registro de motores de traducción disponibles.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

use InvalidArgumentException;

/**
 * Colección de motores registrados.
 *
 * Existe para que añadir un proveedor nuevo sea registrar una clase, sin tocar
 * nada del resto del plugin.
 */
final class EngineRegistry {

	/**
	 * Motores registrados por identificador.
	 *
	 * @var array<string, TranslationEngineInterface>
	 */
	private array $engines = array();

	/**
	 * Registra un motor.
	 *
	 * @param TranslationEngineInterface $engine Motor.
	 */
	public function register( TranslationEngineInterface $engine ): void {
		$this->engines[ $engine->id() ] = $engine;
	}

	/**
	 * Recupera un motor por identificador.
	 *
	 * @param string $id Identificador.
	 *
	 * @throws InvalidArgumentException Si el motor no está registrado.
	 */
	public function get( string $id ): TranslationEngineInterface {
		if ( ! isset( $this->engines[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Motor de traducción desconocido: %s', $id ) );
		}

		return $this->engines[ $id ];
	}

	/**
	 * Si un motor está registrado.
	 *
	 * @param string $id Identificador.
	 */
	public function has( string $id ): bool {
		return isset( $this->engines[ $id ] );
	}

	/**
	 * Identificador => nombre legible, para los desplegables del panel.
	 *
	 * @return array<string, string>
	 */
	public function choices(): array {
		$choices = array();

		foreach ( $this->engines as $id => $engine ) {
			$choices[ $id ] = $engine->label();
		}

		return $choices;
	}
}
