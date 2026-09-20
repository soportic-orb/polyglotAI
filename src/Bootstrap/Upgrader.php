<?php
/**
 * Puesta al día al actualizar el plugin.
 *
 * @package PolyglotAI
 */

declare(strict_types=1);

namespace PolyglotAI\Bootstrap;

/**
 * Vuelve a montar lo que haga falta cuando cambia la versión instalada.
 *
 * `register_activation_hook()` no se dispara al actualizar desde el panel, así
 * que sin esto una versión nueva con un esquema distinto se quedaría con las
 * tablas de la anterior. Se comprueba en el escritorio, que es donde se
 * actualiza, y la comprobación es leer una opción autocargada: en una visita
 * normal no se hace nada.
 */
final class Upgrader {

	/**
	 * Constructor.
	 *
	 * @param string $version Versión del plugin en ejecución.
	 */
	public function __construct( private readonly string $version ) {}

	/**
	 * Engancha la comprobación.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Monta lo que falte si la versión ha cambiado.
	 */
	public function maybe_upgrade(): void {
		if ( (string) get_option( Installer::VERSION_OPTION, '' ) === $this->version ) {
			return;
		}

		Installer::run( $this->version );
	}

	/**
	 * Avisa si alguna tabla no se ha podido crear.
	 *
	 * Sin esto el plugin se activa, parece que todo ha ido bien y luego no
	 * guarda ninguna traducción. Es el fallo más desconcertante que puede tener
	 * una instalación, y casi siempre es que el usuario de la base de datos no
	 * tiene permiso para crear tablas.
	 */
	public function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = get_option( Installer::ERRORS_OPTION, array() );

		if ( ! is_array( $missing ) || array() === $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
			esc_html__( 'Polyglot AI no ha podido crear sus tablas.', 'polyglot-ai' ),
			esc_html__( 'El plugin no puede guardar traducciones hasta que existan. Suele significar que el usuario de la base de datos no tiene permiso para crear tablas.', 'polyglot-ai' ),
			esc_html( implode( ', ', array_map( 'strval', $missing ) ) )
		);
	}
}
