<?php
/**
 * Error irrecuperable de un motor de traducción.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Engines;

use RuntimeException;

/**
 * Fallo que impide procesar un lote entero.
 */
final class EngineException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message    Mensaje.
	 * @param int    $status     Código HTTP, o 0 si no lo hay.
	 * @param bool   $retryable  Si merece la pena reintentar.
	 */
	public function __construct( string $message, public readonly int $status = 0, public readonly bool $retryable = false ) {
		parent::__construct( $message, $status );
	}
}
