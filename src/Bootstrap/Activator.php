<?php
/**
 * Activación del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Bootstrap;

use PolyglotAI\Database\Schema;
use PolyglotAI\Support\Capabilities;

/**
 * Prepara la instalación al activar el plugin.
 */
final class Activator {

	/**
	 * Crea tablas, roles y reglas de reescritura.
	 */
	public static function activate(): void {
		( new Schema() )->install();
		( new Capabilities() )->install();

		// Las reglas de idioma se registran en init; hay que regenerar para que
		// las URLs con prefijo funcionen desde la primera petición.
		flush_rewrite_rules();
	}
}
