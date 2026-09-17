<?php
/**
 * Clave de API de mentira para las pruebas.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Tests\Doubles;

use PolyglotAI\Support\ApiKeyInterface;

/**
 * Devuelve la clave que se le diga.
 *
 * La de verdad la descifra de la base de datos, y para eso hace falta
 * WordPress entero.
 */
final class FakeApiKey implements ApiKeyInterface {

	/**
	 * Constructor.
	 *
	 * @param string|null $key Clave a devolver.
	 */
	public function __construct( private readonly ?string $key = 'sk-ant-test' ) {}

	/**
	 * Clave configurada.
	 */
	public function get(): ?string {
		return $this->key;
	}
}
