<?php
/**
 * Lo que hay que dejar montado para que el plugin funcione.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Bootstrap;

use PolyglotAI\Database\Schema;
use PolyglotAI\Support\Capabilities;

/**
 * Un único sitio que sabe qué significa «instalar».
 *
 * Lo llaman dos: el activador, cuando el administrador activa el plugin, y el
 * actualizador, cuando llega una versión nueva. Hacen falta los dos porque
 * **`register_activation_hook()` no se dispara al actualizar**: WordPress lo
 * ejecuta al activar, y una actualización desde el panel no desactiva y vuelve
 * a activar. Sin el segundo, una versión que cambiara el esquema se instalaría
 * sobre las tablas viejas y se quedaría así.
 *
 * Es idempotente: `Schema::install()` no toca nada si la versión guardada ya es
 * la actual, y volver a dar capacidades que ya se tienen no hace nada.
 */
final class Installer {

	/** Opción con la versión que dejó instalada la última pasada. */
	public const VERSION_OPTION = 'pgai_installed_version';

	/** Opción con las tablas que no se han podido crear. */
	public const ERRORS_OPTION = 'pgai_install_errors';

	/**
	 * Monta lo que haga falta y devuelve lo que no se haya podido montar.
	 *
	 * @param string $version Versión que se está instalando.
	 * @return string[] Tablas que faltan. Vacío si ha ido bien.
	 */
	public static function run( string $version ): array {
		$missing = ( new Schema() )->install();

		( new Capabilities() )->install();

		update_option( self::VERSION_OPTION, $version, true );

		if ( array() === $missing ) {
			delete_option( self::ERRORS_OPTION );
		} else {
			update_option( self::ERRORS_OPTION, $missing, false );
		}

		return $missing;
	}
}
