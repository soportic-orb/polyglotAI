<?php
/**
 * Desactivación del plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Bootstrap;

/**
 * Deshace lo que no debe sobrevivir a la desactivación.
 *
 * No se borra ningún dato: desactivar tiene que dejar el sitio original intacto
 * y poder revertirse sin pérdidas. El borrado solo ocurre al desinstalar, y solo
 * si el administrador lo ha pedido.
 */
final class Deactivator {

	/**
	 * Limpia tareas programadas y reglas de reescritura.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'pgai_translate_pending' );

		flush_rewrite_rules();
	}
}
